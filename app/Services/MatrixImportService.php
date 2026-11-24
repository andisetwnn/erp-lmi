<?php

namespace App\Services;

use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Membaca berkas Matrix (Mikro / Makro A / Makro B / Non Lot / Sudah Akad) jadi baris tabel.
 *
 * Berkasnya disusun manual dan diekspor dari Google Sheets, jadi ada tiga hal
 * yang harus ditangani: banyak sel berisi rumus yang tidak bisa dihitung ulang
 * di sini (nilai tersimpannya yang dipakai), tanggal kadang berupa angka seri
 * kadang teks "14/03/2026", dan tiap sheet dipecah jadi beberapa tahap yang
 * masing-masing mengulang barisan judul kolom.
 */
class MatrixImportService
{
    /**
     * Sheet yang diambil, beserta peta kolomnya.
     *
     * Berkas dari Admin KPR berisi sheet lain juga — Rekap, Realisasi Akad, FU UM,
     * dan beberapa sheet kerja. Semuanya sengaja dilewati; hanya lima di bawah ini
     * yang dibaca.
     *
     * `nama` berisi lebih dari satu kemungkinan karena sheet akad pernah ditulis
     * "AKAD" dan pernah "SUDAH AKAD". Yang pertama ditemukan itu yang dipakai.
     *
     * Non Lot bergeser satu kolom karena tidak punya SUB-CONT, tapi punya BBA
     * dan tidak punya blok teknik — makanya petanya dipisah, bukan dipaksa sama.
     *
     * @var list<array{nama: list<string>, kategori: string, header: int, kolom: array<string, string>}>
     */
    public const PETA_SHEET = [
        ['nama' => ['MIKRO'], 'kategori' => 'mikro', 'header' => 8, 'kolom' => self::KOLOM_BERLOT],
        ['nama' => ['MAKRO A'], 'kategori' => 'makro_a', 'header' => 8, 'kolom' => self::KOLOM_BERLOT],
        ['nama' => ['MAKRO B'], 'kategori' => 'makro_b', 'header' => 8, 'kolom' => self::KOLOM_BERLOT],
        ['nama' => ['NON LOT'], 'kategori' => 'non_lot', 'header' => 7, 'kolom' => self::KOLOM_NON_LOT],
        ['nama' => ['SUDAH AKAD', 'AKAD'], 'kategori' => 'akad', 'header' => 5, 'kolom' => self::KOLOM_BERLOT],
    ];

    private const KOLOM_BERLOT = [
        'nama' => 'B', 'blok' => 'C', 'nomor_unit' => 'D', 'sales' => 'E', 'tanggal' => 'F',
        'luas_bangunan' => 'G', 'luas_tanah' => 'H', 'tipe' => 'I', 'lot' => 'J', 'subcon' => 'K',
        'progres' => 'L', 'ajb_notaris' => 'M', 'total_harga_jual' => 'N', 'kpr' => 'O',
        'sbum' => 'P', 'total_um' => 'Q', 'tgl_bayar_terakhir' => 'R', 'akumulasi_um' => 'S',
        'persen_um' => 'T', 'sisa_um' => 'U',
        'bm' => 'V', 'wcr' => 'W', 'sp3k' => 'X', 'exp_sp3k' => 'Y', 'lpa' => 'Z', 'rencana_akad' => 'AA',
        'bank_ko' => 'AB', 'bank_fl' => 'AC', 'bank_ta' => 'AD',
        'legal_imb' => 'AE', 'legal_slf' => 'AF', 'legal_hgb' => 'AG',
        'teknik_sa' => 'AH', 'teknik_ja' => 'AI', 'teknik_kw' => 'AJ', 'teknik_sb' => 'AK',
        'sikumbang' => 'AL', 'ktp' => 'AM', 'keterangan' => 'AN',
    ];

    private const KOLOM_NON_LOT = [
        'nama' => 'B', 'blok' => 'C', 'nomor_unit' => 'D', 'sales' => 'E', 'tanggal' => 'F',
        'luas_bangunan' => 'G', 'luas_tanah' => 'H', 'tipe' => 'I', 'lot' => 'J',
        'progres' => 'K', 'ajb_notaris' => 'L', 'total_harga_jual' => 'M', 'kpr' => 'N',
        'bba' => 'O', 'sbum' => 'P', 'total_um' => 'Q', 'tgl_bayar_terakhir' => 'R',
        'akumulasi_um' => 'S', 'persen_um' => 'T', 'sisa_um' => 'U',
        'bm' => 'V', 'wcr' => 'W', 'sp3k' => 'X', 'exp_sp3k' => 'Y', 'lpa' => 'Z', 'rencana_akad' => 'AA',
        'bank_ko' => 'AB', 'bank_fl' => 'AC', 'bank_ta' => 'AD',
        'legal_imb' => 'AE', 'legal_slf' => 'AF', 'legal_hgb' => 'AG',
        'sikumbang' => 'AH', 'ktp' => 'AI', 'keterangan' => 'AJ',
    ];

    /** Kolom yang isinya tanggal, dipakai saat menentukan cara membaca sel. */
    private const KOLOM_TANGGAL = [
        'tanggal', 'tgl_bayar_terakhir', 'bm', 'wcr', 'sp3k', 'exp_sp3k', 'lpa', 'rencana_akad',
    ];

    private const KOLOM_UANG = [
        'ajb_notaris', 'total_harga_jual', 'kpr', 'bba', 'sbum',
        'total_um', 'akumulasi_um', 'sisa_um',
    ];

    private const KOLOM_ANGKA = ['luas_bangunan', 'luas_tanah'];

    /**
     * Nama bulan yang dipakai Admin KPR saat mengetik tanggal sebagai teks,
     * mis. "29 OKTOBER 2026" atau "16/okt/2026". Bahasa Inggris ikut didaftar
     * karena sebagian sel berasal dari tempelan Google Sheets.
     */
    private const BULAN = [
        'jan' => 1, 'januari' => 1, 'january' => 1,
        'feb' => 2, 'peb' => 2, 'februari' => 2, 'pebruari' => 2, 'february' => 2,
        'mar' => 3, 'maret' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'mei' => 5, 'may' => 5,
        'jun' => 6, 'juni' => 6, 'june' => 6,
        'jul' => 7, 'juli' => 7, 'july' => 7,
        'agu' => 8, 'agt' => 8, 'ags' => 8, 'agus' => 8, 'agust' => 8, 'agustus' => 8, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10, 'oct' => 10, 'october' => 10,
        'nov' => 11, 'nop' => 11, 'november' => 11, 'nopember' => 11,
        'des' => 12, 'desember' => 12, 'dec' => 12, 'december' => 12,
    ];

    /**
     * Tulisan di kolom B yang bentuknya mirip judul tahap, padahal cuma catatan.
     *
     * "NOTED :" muncul persis di bawah tahap STOCK, jadi kalau ikut dianggap tahap,
     * unit yang sebenarnya STOCK jadi salah label.
     */
    private const BUKAN_TAHAP = ['NOTED', 'NOTE', 'CATATAN', 'KETERANGAN'];

    /**
     * Terjemahan kode subcon ke nama aslinya, dibaca dari kepala berkas.
     *
     * Kolom subcon di Matrix diisi singkatan ("MSM") semata karena nama panjangnya
     * tidak muat di sel. Nama lengkapnya didaftarkan di pojok kiri atas tiap sheet,
     * di kolom F sebelah label SUBKON.
     *
     * @var array<string, string>
     */
    private array $legendaSubcon = [];

    /**
     * Sel tanggal yang ada isinya tapi tidak bisa dibaca, untuk dilaporkan ke pengunggah.
     *
     * @var list<array{sheet: string, baris: int, unit: string, nama: string, kolom: string, isi: string}>
     */
    private array $tidakTerbaca = [];

    /**
     * Baca berkas lalu simpan. Unggahan lama tidak dihapus — laporan selalu
     * memakai yang terbaru, dan yang lama tetap bisa ditengok kalau angkanya
     * dipertanyakan.
     */
    public function impor(
        string $path,
        string $namaFile,
        ?int $userId = null,
        ?string $mingguKe = null,
        ?string $periode = null,
    ): MatrixImport {
        $spreadsheet = $this->baca($path);
        $meta = $this->bacaMeta($this->cariSheet($spreadsheet, ['MIKRO']));

        // Minggu dan periode dipilih pengunggah, bukan dipercaya dari judul berkas:
        // judul di sheet MIKRO diketik tangan dan kerap lupa diganti — berkas
        // 7 Oktober pernah masuk masih bertuliskan "Per September 2026".
        $meta['minggu_ke'] = $mingguKe ?? $meta['minggu_ke'];
        $meta['periode'] = $periode ?? $meta['periode'];
        $this->legendaSubcon = $this->bacaLegendaSubcon($spreadsheet);
        $this->tidakTerbaca = [];

        return DB::transaction(function () use ($spreadsheet, $namaFile, $userId, $meta) {
            $import = MatrixImport::create([
                'nama_file' => $namaFile,
                'proyek' => $meta['proyek'],
                'minggu_ke' => $meta['minggu_ke'],
                'periode' => $meta['periode'],
                'uploaded_by_user_id' => $userId,
            ]);

            $total = 0;

            foreach (self::PETA_SHEET as $peta) {
                $sheet = $this->cariSheet($spreadsheet, $peta['nama']);

                if (! $sheet instanceof Worksheet) {
                    continue;
                }

                foreach ($this->bacaSheet($sheet, $peta) as $baris) {
                    MatrixUnit::create($baris + [
                        'matrix_import_id' => $import->id,
                        'kategori' => $peta['kategori'],
                    ]);
                    $total++;
                }
            }

            if ($total === 0) {
                throw new RuntimeException(
                    'Tidak ada baris unit yang terbaca. Pastikan berkasnya Matrix dengan sheet Mikro, Makro A, Makro B, Non Lot, dan Sudah Akad.'
                );
            }

            $import->update([
                'jumlah_baris' => $total,
                'tidak_terbaca' => $this->tidakTerbaca ?: null,
            ]);

            return $import->refresh();
        });
    }

    /**
     * Sheet pertama yang namanya cocok. Nama dibandingkan tanpa peduli huruf besar
     * kecil dan spasi berlebih — berkasnya diketik manual tiap minggu.
     *
     * @param  list<string>  $kandidat
     */
    private function cariSheet(Spreadsheet $spreadsheet, array $kandidat): ?Worksheet
    {
        foreach ($kandidat as $nama) {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                if (strcasecmp(trim($sheet->getTitle()), $nama) === 0) {
                    return $sheet;
                }
            }
        }

        return null;
    }

    private function baca(string $path): Spreadsheet
    {
        $reader = new Xlsx;
        $reader->setLoadSheetsOnly(array_merge(...array_column(self::PETA_SHEET, 'nama')));

        $spreadsheet = $reader->load($path);

        if ($this->cariSheet($spreadsheet, ['MIKRO']) === null) {
            throw new RuntimeException('Sheet MIKRO tidak ditemukan — berkas ini sepertinya bukan Matrix.');
        }

        return $spreadsheet;
    }

    /**
     * Judul di pojok kiri atas: nama proyek, minggu ke berapa, dan periodenya.
     *
     * @return array{proyek: ?string, minggu_ke: ?string, periode: ?string}
     */
    /**
     * Pilihan minggu laporan: minggu ke-berapa di dalam bulannya, mis.
     * "Minggu ke-2 · Oktober 2026". Ditawarkan seluruh minggu bulan lalu dan
     * bulan berjalan — bulan lalu untuk berkas yang terlambat diunggah.
     *
     * Minggu ke-N = tanggal 1–7, 8–14, 15–21, 22–28, 29 dst. Sederhana dan bisa
     * ditebak tanpa melihat kalender.
     *
     * @return list<array{kunci: string, minggu: int, bulan: string, label: string}>
     */
    public static function pilihanMinggu(?\DateTimeInterface $hari = null): array
    {
        $hari = Carbon::instance($hari ?? now());
        $pilihan = [];

        foreach ([$hari->copy()->startOfMonth()->subMonth(), $hari->copy()->startOfMonth()] as $bulan) {
            foreach (range(1, (int) ceil($bulan->daysInMonth / 7)) as $minggu) {
                $pilihan[] = [
                    'kunci' => $minggu.'|'.$bulan->format('Y-m'),
                    'minggu' => $minggu,
                    'bulan' => $bulan->format('Y-m'),
                    'label' => 'Minggu ke-'.$minggu.' · '.$bulan->locale('id')->translatedFormat('F Y'),
                ];
            }
        }

        return $pilihan;
    }

    /** Pilihan yang cocok dengan hari ini — bawaan dropdown. */
    public static function mingguIni(?\DateTimeInterface $hari = null): string
    {
        $hari = Carbon::instance($hari ?? now());

        return (int) ceil($hari->day / 7).'|'.$hari->format('Y-m');
    }

    /** "Per Oktober 2026" dari "2026-10" — bentuk yang sama dengan judul di berkas Matrix. */
    public static function teksPeriode(string $bulan): string
    {
        return 'Per '.Carbon::createFromFormat('!Y-m', $bulan)->locale('id')->translatedFormat('F Y');
    }

    /** "MINGGU KE-25" — bentuk yang sama dengan judul di berkas Matrix. */
    public static function teksMinggu(int $minggu): string
    {
        return 'MINGGU KE-'.$minggu;
    }

    /**
     * Kode subcon dan kepanjangannya, mis. "MSM : MUATIARA S MIZAN".
     *
     * Hanya kolom F yang dibaca. Kolom K dan P memakai bentuk yang sama untuk
     * kode bank dan pekerjaan teknik, dan itu bukan subcon.
     *
     * @return array<string, string>
     */
    private function bacaLegendaSubcon(Spreadsheet $spreadsheet): array
    {
        $legenda = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            for ($r = 1; $r <= 8; $r++) {
                $isi = trim((string) $this->nilai($sheet->getCell("F$r")));

                if ($isi === '' || ! preg_match('/^([A-Z]{2,4})\s*:\s*(.+)$/u', $isi, $cocok)) {
                    continue;
                }

                $legenda[strtoupper($cocok[1])] = trim($cocok[2]);
            }
        }

        return $legenda;
    }

    private function bacaMeta(?Worksheet $mikro): array
    {
        if (! $mikro instanceof Worksheet) {
            return ['proyek' => null, 'minggu_ke' => null, 'periode' => null];
        }

        $teks = function (string $sel) use ($mikro): ?string {
            $nilai = trim((string) $this->nilai($mikro->getCell($sel)));

            return $nilai === '' ? null : $nilai;
        };

        return [
            'proyek' => $teks('A2'),
            'minggu_ke' => $teks('A3'),
            'periode' => $teks('A5'),
        ];
    }

    /**
     * Telusuri sheet dari atas ke bawah sambil mengingat tahap yang sedang dibaca.
     *
     * @param  array{kategori: string, header: int, kolom: array<string, string>}  $peta
     * @return list<array<string, mixed>>
     */
    private function bacaSheet(Worksheet $sheet, array $peta): array
    {
        $kolom = $peta['kolom'];
        $hasil = [];
        $seksi = '';
        $tertinggi = $sheet->getHighestDataRow();

        // Mulai dari baris pertama, bukan dari baris judul kolom: label tahap
        // pertama ("ACC SP3K") berada tepat di atasnya dan akan terlewat.
        for ($r = 1; $r <= $tertinggi; $r++) {
            $a = trim((string) $this->nilai($sheet->getCell("A$r")));
            $b = trim((string) $this->nilai($sheet->getCell("B$r")));
            $c = trim((string) $this->nilai($sheet->getCell("C$r")));

            // Judul tahap: hanya kolom B yang terisi, mis. "ACC SP3K", "CASH".
            if ($a === '' && $b !== '' && $c === '') {
                $label = $this->bersihkanSeksi($b);

                // Catatan dilewati begitu saja supaya tahap yang sedang dibaca tetap.
                if (! in_array(mb_strtoupper($label), self::BUKAN_TAHAP, true)) {
                    $seksi = $label;
                }

                continue;
            }

            // Barisan judul kolom diulang di tiap tahap — lewati.
            if (strcasecmp($a, 'NO') === 0) {
                continue;
            }

            if (! is_numeric($a) || $b === '') {
                continue;
            }

            $hasil[] = $this->bacaBaris($sheet, $r, $kolom, $seksi, (int) $a);
        }

        return $hasil;
    }

    /**
     * @param  array<string, string>  $kolom
     * @return array<string, mixed>
     */
    private function bacaBaris(Worksheet $sheet, int $r, array $kolom, string $seksi, int $urutan): array
    {
        $baris = ['seksi' => $seksi, 'urutan' => $urutan];
        $gagal = [];

        foreach ($kolom as $field => $huruf) {
            $sel = $sheet->getCell($huruf.$r);
            $mentah = $this->nilai($sel);

            $baris[$field] = match (true) {
                in_array($field, self::KOLOM_TANGGAL, true) => $this->keTanggal($mentah, $this->formatBulanDulu($sel)),
                in_array($field, self::KOLOM_UANG, true) => $this->keUang($mentah),
                in_array($field, self::KOLOM_ANGKA, true) => $this->keBulat($mentah),
                $field === 'subcon' => $this->keSubcon($mentah),
                $field === 'progres' => $this->kePersen($mentah),
                $field === 'persen_um' => $this->kePecahan($mentah),
                default => $this->keTeks($mentah),
            };

            if (in_array($field, self::KOLOM_TANGGAL, true) && $baris[$field] === null
                && is_scalar($mentah) && trim((string) $mentah) !== '') {
                $gagal[$field] = trim((string) $mentah);
            }
        }

        $baris['nama'] = $baris['nama'] ?? '';

        foreach ($gagal as $field => $isi) {
            $this->tidakTerbaca[] = [
                'sheet' => $sheet->getTitle(),
                'baris' => $r,
                'unit' => (new MatrixUnit(['blok' => $baris['blok'] ?? null, 'nomor_unit' => $baris['nomor_unit'] ?? null]))->kode_unit,
                'nama' => $baris['nama'],
                'kolom' => $field,
                'isi' => $isi,
            ];
        }

        return $baris;
    }

    /**
     * Sel berisi rumus dibaca dari nilai yang tersimpan di berkas.
     *
     * Rumusnya banyak yang memanggil fungsi khusus Google Sheets (__xludf) dan
     * tidak mungkin dihitung ulang di sini, jadi hasil terakhir yang tersimpan
     * itulah yang dianggap benar.
     */
    private function nilai(Cell $cell): mixed
    {
        if (! $cell->isFormula()) {
            return $cell->getValue();
        }

        $tersimpan = $cell->getOldCalculatedValue();

        // Rumus yang gagal di berkas aslinya ikut tersimpan sebagai teks error.
        if (is_string($tersimpan) && str_starts_with($tersimpan, '#')) {
            return null;
        }

        return $tersimpan;
    }

    /** "NOTED :" dan sejenisnya dirapikan supaya jadi label tahap yang bersih. */
    private function bersihkanSeksi(string $teks): string
    {
        return trim(rtrim(trim($teks), ':'));
    }

    /**
     * Kode subcon diganti nama aslinya. Kode yang tidak ada di legenda dibiarkan
     * apa adanya — lebih baik tampil sebagai singkatan daripada jadi nama karangan.
     */
    private function keSubcon(mixed $v): ?string
    {
        $kode = $this->keTeks($v);

        if ($kode === null) {
            return null;
        }

        return $this->legendaSubcon[strtoupper($kode)] ?? $kode;
    }

    private function keTeks(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        if (is_bool($v)) {
            return $v ? 'YA' : null;
        }

        $teks = trim((string) $v);

        return $teks === '' ? null : $teks;
    }

    private function keBulat(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private function keUang(mixed $v): float
    {
        if (is_numeric($v)) {
            return round((float) $v, 2);
        }

        // Kadang diketik "185.000.000" atau "185,000,000" sebagai teks.
        if (is_string($v)) {
            $bersih = preg_replace('/[^0-9,.\-]/', '', $v) ?? '';
            $bersih = str_replace(['.', ','], '', $bersih);

            if ($bersih !== '' && is_numeric($bersih)) {
                return round((float) $bersih, 2);
            }
        }

        return 0.0;
    }

    /**
     * Progres bangunan. Di berkasnya bisa berupa pecahan (0,9441), persen bulat
     * (94,41), atau teks "94,41%" — ketiganya dinormalkan ke satuan persen.
     */
    private function kePersen(mixed $v): ?float
    {
        $angka = $this->angkaPersen($v);

        if ($angka === null) {
            return null;
        }

        return round($angka <= 1.0 ? $angka * 100 : $angka, 3);
    }

    /** Kolom % pembayaran disimpan sebagai pecahan (1 = lunas). */
    private function kePecahan(mixed $v): ?float
    {
        $angka = $this->angkaPersen($v);

        if ($angka === null) {
            return null;
        }

        return round($angka > 1.0 ? $angka / 100 : $angka, 4);
    }

    private function angkaPersen(mixed $v): ?float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }

        if (! is_string($v)) {
            return null;
        }

        $teks = str_replace(['%', ' '], '', trim($v));
        $teks = str_replace(',', '.', $teks);

        return is_numeric($teks) ? (float) $teks : null;
    }

    /**
     * Sel bertampilan bulan-dulu ("m/d/yyyy"), bawaan Google Sheets berlokal Amerika.
     *
     * Admin KPR selalu mengetik dd/mm/yyyy. Di sel seperti ini "10/8/2026"
     * (10 Agustus) tersimpan sebagai 8 Oktober, sedangkan "27/8/2026" tidak
     * dikenali sebagai tanggal dan tetap berupa teks.
     */
    private function formatBulanDulu(Cell $sel): bool
    {
        $format = preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $sel->getStyle()->getNumberFormat()->getFormatCode());

        return (bool) preg_match('#^\s*m{1,2}[/.\-]d{1,2}[/.\-]y#i', (string) $format);
    }

    /**
     * Tanggal bisa berupa angka seri Excel atau teks "14/03/2026".
     *
     * Ambang 25000 (± tahun 1968) dipakai supaya angka kecil seperti nomor lot
     * atau progres tidak ikut terbaca sebagai tanggal. Angka seri dari sel
     * bertampilan bulan-dulu dibaca seperti yang terlihat di layar, yaitu
     * dd/mm — kecuali tanggalnya di atas 12, yang hanya bisa berarti satu hal.
     */
    private function keTanggal(mixed $v, bool $bulanDulu = false): ?string
    {
        if (is_numeric($v)) {
            $seri = (float) $v;

            if ($seri < 25000 || $seri > 80000) {
                return null;
            }

            $tanggal = Carbon::createFromTimestampUTC((int) round(($seri - 25569) * 86400));

            if ($bulanDulu && $tanggal->day <= 12) {
                return $this->susunTanggal($tanggal->month, $tanggal->day, $tanggal->year);
            }

            return $tanggal->toDateString();
        }

        if (! is_string($v)) {
            return null;
        }

        $teks = mb_strtolower(trim($v));

        if ($teks === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $teks, $m)) {
            return $this->susunTanggal((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // Bulan berhuruf diganti angka, lalu semua pemisah diseragamkan:
        // "29 oktober 2026", "16/okt/2026", dan "14-9-26" jadi bentuk d/m/y.
        $teks = preg_replace_callback('/\p{L}+/u', fn (array $kata) => isset(self::BULAN[$kata[0]])
            ? '/'.self::BULAN[$kata[0]].'/'
            : $kata[0], $teks);
        $teks = preg_replace('#[\s./-]+#', '/', trim($teks, ' ./-'));

        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $teks, $m)) {
            return null;
        }

        // Tahun dua digit selalu berarti 20xx. Dulu "14/9/26" terbaca tahun 0026 —
        // di layar tetap tampil 14/09/26, tapi hitungan masa berlaku SP3K kacau.
        $tahun = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

        return $this->susunTanggal((int) $m[1], (int) $m[2], $tahun);
    }

    /** Tanggal yang mustahil (31/2) atau tahunnya di luar kewajaran dianggap tidak terbaca. */
    private function susunTanggal(int $hari, int $bulan, int $tahun): ?string
    {
        if ($tahun < 2000 || $tahun > 2100 || ! checkdate($bulan, $hari, $tahun)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
    }
}
