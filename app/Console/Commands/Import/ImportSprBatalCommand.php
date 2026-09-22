<?php

namespace App\Console\Commands\Import;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Services\Import\SalesResolver;
use App\Services\Import\SopRowParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Impor SPR batal & pindah blok historis dari DATA BATAL.xlsx.
 *
 * Import SOP dulu melewatkan sebagian penjualan yang berakhir batal, sehingga
 * penomoran SPR bolong. Berkas ini melengkapinya: tiap baris punya unit,
 * tanggal, harga, uang muka, nomor kuitansi, dan alasannya. Kategori dibedakan
 * lewat warna latar kolom nomor SPR — merah batal, hijau pindah blok, kuning
 * ganti nama.
 *
 * Satu baris bisa memuat beberapa nomor, misalnya "00038/00160/00165". Yang
 * berlaku hanya nomor terakhir; sebelumnya cuma jejak pergantian nomor untuk
 * konsumen dan unit yang sama, dan disimpan sebagai catatan.
 *
 * Hanya nomor yang belum ada di database yang dibuat. Yang sudah ada tidak
 * disentuh sama sekali — statusnya urusan terpisah.
 *
 * Tanpa --commit hanya melaporkan.
 */
class ImportSprBatalCommand extends Command
{
    protected $signature = 'import:spr-batal
        {--file=DATA BATAL.xlsx : Berkas sumber, relatif ke root project}
        {--sheet= : Nama sheet. Kosongkan untuk memakai sheet pertama}
        {--start-row=7 : Baris awal data}
        {--commit : Simpan. Tanpa ini hanya melaporkan}';

    protected $description = 'Impor SPR batal & pindah blok historis yang belum ada di database';

    /** Warna latar kolom nomor SPR menandai kategorinya. */
    private const WARNA_KATEGORI = [
        'FFFF0000' => 'BATAL',
        'FF00B050' => 'PINDAH BLOK',
        'FFFFFF00' => 'GANTI NAMA',
    ];

    /** Kata kunci di kolom KET → alasan pembatalan di master. */
    private const ALASAN_KET = [
        'TIDAK KOORPERATIF' => 'Konsumen Tidak Kooperatif',
        'TIDAK KOOPERATIF' => 'Konsumen Tidak Kooperatif',
        'TOLAK BANK' => 'Tolak Bank',
    ];

    /** Kalau KET tidak dikenali, kategorinya yang menentukan. */
    private const ALASAN_KATEGORI = [
        'BATAL' => 'Mengundurkan diri',
        'PINDAH BLOK' => 'Pindah Kavling',
    ];

    public function __construct(
        private readonly SopRowParser $parser,
        private readonly SalesResolver $sales,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $berkas = (string) $this->option('file');
        $path = is_file($berkas) ? $berkas : base_path($berkas);

        if (! is_file($path)) {
            $this->error("Berkas tidak ditemukan: $path");

            return self::FAILURE;
        }

        $proyek = Proyek::orderBy('id')->first();

        if (! $proyek) {
            $this->error('Belum ada proyek di master.');

            return self::FAILURE;
        }

        $sheet = $this->bukaSheet($path);

        if (! $sheet instanceof Worksheet) {
            $this->error('Sheet tidak ditemukan.');

            return self::FAILURE;
        }

        [$akanDibuat, $sudahAda, $bermasalah] = $this->telaah($sheet, $proyek);

        $this->laporkan($akanDibuat, $sudahAda, $bermasalah);

        if ($bermasalah !== []) {
            $this->newLine();
            $this->error('Ada baris yang belum bisa diproses. Perbaiki dulu — tidak ada yang disimpan.');

            return self::FAILURE;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('Belum ada yang disimpan. Tambahkan --commit kalau laporan di atas sudah sesuai.');

            return self::SUCCESS;
        }

        if ($akanDibuat === []) {
            $this->info('Tidak ada SPR baru untuk dibuat.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => array_walk($akanDibuat, fn (array $b) => $this->buat($b, $proyek)));

        $this->newLine();
        $this->info(count($akanDibuat).' SPR batal dibuat.');

        return self::SUCCESS;
    }

    private function bukaSheet(string $path): ?Worksheet
    {
        $spreadsheet = (new Xlsx)->load($path);
        $nama = (string) $this->option('sheet');

        return $nama !== '' ? $spreadsheet->getSheetByName($nama) : $spreadsheet->getSheet(0);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, string>>, 2: list<string>}
     */
    private function telaah(Worksheet $sheet, Proyek $proyek): array
    {
        $akanDibuat = $sudahAda = $bermasalah = [];
        $mulai = (int) $this->option('start-row');

        for ($r = $mulai; $r <= $sheet->getHighestDataRow(); $r++) {
            $nama = $this->parser->parseText($sheet->getCell("D$r")->getValue());

            if ($nama === '') {
                continue;
            }

            $kategori = self::WARNA_KATEGORI[
                $sheet->getStyle("F$r")->getFill()->getStartColor()->getARGB()
            ] ?? 'BATAL';

            $unit = $this->parser->parseText($sheet->getCell("H$r")->getValue());
            $rumah = $this->cariRumah($proyek->id, $unit);
            $tglJual = $this->parser->parseDate($sheet->getCell("B$r")->getValue());

            // Satu sel bisa memuat beberapa nomor, "00038/00160/00165". Yang
            // berlaku cuma yang terakhir; sebelumnya jejak pergantian nomor
            // untuk konsumen dan unit yang sama, bukan penjualan tersendiri.
            // Aturannya sama dengan import SOP, jadi parsernya dipakai ulang.
            ['active' => $nomor, 'old' => $nomorLama] = $this->parser->parseSprNumbers(
                $this->parser->parseText($sheet->getCell("F$r")->getValue())
            );

            if ($nomor === null) {
                $bermasalah[] = "r$r $nama: nomor SPR kosong";

                continue;
            }

            // Dicocokkan lewat nomor urutnya saja, bukan nomor lengkap: awalan
            // tahun/bulan di sistem diambil dari tanggal SPR-nya sendiri dan bisa
            // berbeda dari tanggal jual di berkas ini. Kalau dicocokkan utuh,
            // SPR yang sudah ada akan terbaca hilang lalu dibuat kembar.
            $adaSebelumnya = Spr::where('nomor_spr', 'like', '%/'.str_pad($nomor, 4, '0', STR_PAD_LEFT))->first();

            if ($adaSebelumnya) {
                $sudahAda[] = ['nomor' => $adaSebelumnya->nomor_spr, 'nama' => $nama];

                continue;
            }

            if (! $tglJual) {
                $bermasalah[] = "r$r $nama: tanggal jual tidak terbaca";

                continue;
            }

            if (! $rumah) {
                $bermasalah[] = "r$r $nama: unit \"$unit\" tidak ada di master rumah";

                continue;
            }

            $ket = $this->parser->parseText($sheet->getCell("U$r")->getValue());
            $alasan = $this->alasan($kategori, $ket);

            if (! $alasan) {
                $bermasalah[] = "r$r $nama: alasan pembatalan untuk \"$ket\" tidak ada di master";

                continue;
            }

            // Sales wajib terisi di prospect, booking, dan SPR. Nama panggilan
            // di berkas ini dipadankan lewat resolver yang sama dengan import SOP.
            $namaSales = $this->parser->parseSalesName($sheet->getCell("G$r")->getValue());
            $sales = $this->sales->resolve($namaSales);

            if (! $sales) {
                $bermasalah[] = "r$r $nama: sales \"$namaSales\" tidak ada di master";

                continue;
            }

            $akanDibuat[] = [
                'baris' => $r,
                'kategori' => $kategori,
                'alasan_id' => $alasan->id,
                'alasan' => $alasan->nama,
                'nomor_spr' => $this->parser->formatNomorSpr($nomor, $tglJual),
                'nomor_lama' => $nomorLama,
                'nama' => $nama,
                'unit' => $unit,
                'rumah_id' => $rumah->id,
                'tgl_jual' => $tglJual,
                'tgl_proses' => $this->parser->parseDate($sheet->getCell("C$r")->getValue()),
                'sales_id' => $sales->id,
                'total_harga' => $this->parser->parseNominal($sheet->getCell("K$r")->getValue()),
                'um_net' => $this->parser->parseNominal($sheet->getCell("L$r")->getValue()),
                'utj' => $this->parser->parseNominal($sheet->getCell("N$r")->getValue()),
                'akumulasi_um' => $this->parser->parseNominal($this->nilaiSel($sheet, "P$r")),
                'kwitansi' => $this->kwitansi($sheet, $r),
                'tgl_setor' => $this->parser->parseTglSetor($sheet->getCell("O$r")->getValue()),
                'telepon' => $this->parser->parsePhone($sheet->getCell("T$r")->getValue()),
                'alamat' => $this->parser->parseText($sheet->getCell("S$r")->getValue()),
                'ket' => $ket,
            ];
        }

        return [$akanDibuat, $sudahAda, $bermasalah];
    }

    /** Sel berisi rumus dibaca dari nilai tersimpannya. */
    private function nilaiSel(Worksheet $sheet, string $ref): mixed
    {
        $cell = $sheet->getCell($ref);

        return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
    }

    /**
     * Nomor kuitansi UTJ.
     *
     * Sebagian sel dipaksa jadi teks di Excel sehingga menyisakan petik di depan.
     * Nomor yang sudah dipakai kuitansi lain dikosongkan — kolomnya unik, dan
     * lebih baik kehilangan satu nomor daripada seluruh impor gagal.
     */
    private function kwitansi(Worksheet $sheet, int $r): ?string
    {
        $kwt = ltrim($this->parser->parseText($this->nilaiSel($sheet, "M$r")), "'");

        if ($kwt === '') {
            return null;
        }

        return SprRealisasiPembayaran::where('nomor_kwitansi', $kwt)->exists() ? null : $kwt;
    }

    /**
     * Alasan pembatalan dari kolom KET, jatuh ke kategori kalau tidak dikenali.
     *
     * Sengaja hanya mengembalikan alasan yang benar-benar ada di master. Nama
     * yang tidak cocok lebih baik ditolak terang-terangan daripada disimpan
     * kosong — SPR batal tanpa alasan tidak terbaca di laporan pembatalan.
     */
    private function alasan(string $kategori, string $ket): ?AlasanPembatalan
    {
        $ket = strtoupper($ket);

        foreach (self::ALASAN_KET as $kunci => $nama) {
            if (str_contains($ket, $kunci)) {
                return $this->cariAlasan($nama);
            }
        }

        $bawaan = self::ALASAN_KATEGORI[$kategori] ?? null;

        return $bawaan ? $this->cariAlasan($bawaan) : null;
    }

    private function cariAlasan(string $nama): ?AlasanPembatalan
    {
        return AlasanPembatalan::whereRaw('UPPER(nama) = ?', [strtoupper($nama)])->first();
    }

    /** Unit ditulis "DA-07" atau "DB-5" — nol depan tidak seragam. */
    private function cariRumah(int $proyekId, string $unit): ?Rumah
    {
        if (! preg_match('/^([A-Za-z]+)\s*-\s*(\d+)$/', trim($unit), $cocok)) {
            return null;
        }

        return Rumah::where('proyek_id', $proyekId)
            ->whereRaw('UPPER(blok) = ?', [strtoupper($cocok[1])])
            ->get()
            ->first(fn (Rumah $r) => ltrim($r->nomor_unit, '0') === ltrim($cocok[2], '0'));
    }

    /**
     * @param  array<string, mixed>  $b
     */
    private function buat(array $b, Proyek $proyek): void
    {
        $prospect = ProspectCustomer::create([
            'sales_id' => $b['sales_id'],
            'proyek_id' => $proyek->id,
            'nama_lengkap' => $b['nama'],
            'hp' => $b['telepon'],
            'alamat' => $b['alamat'] !== '' ? $b['alamat'] : null,
            'sumber' => 'Walk-in',
            'status' => 'batal',
        ]);

        $booking = Booking::create([
            'sales_id' => $b['sales_id'],
            'proyek_id' => $proyek->id,
            'prospect_customer_id' => $prospect->id,
            'rumah_id' => $b['rumah_id'],
            'tanggal_booking' => $b['tgl_jual']->toDateString(),
            'tanggal_expired' => $b['tgl_jual']->addDays(7)->toDateString(),
            'status' => 'batal',
        ]);

        // Yang setorannya baru sebatas UTJ tidak dikembalikan — UTJ hangus saat
        // konsumen mundur. Kalau ternyata sudah menyetor lebih, keputusannya
        // diserahkan ke keuangan, bukan diputuskan oleh impor.
        $adaSetoranLebih = $b['akumulasi_um'] > $b['utj'];

        $spr = Spr::create([
            'booking_id' => $booking->id,
            'sales_id' => $b['sales_id'],
            'prospect_customer_id' => $prospect->id,
            'rumah_id' => $b['rumah_id'],
            'kategori' => 'subsidi',
            'nomor_spr' => $b['nomor_spr'],
            'tanggal_spr' => $b['tgl_jual']->toDateString(),
            'harga_jual' => $b['total_harga'],
            'total_harga' => $b['total_harga'],
            'um_net' => $b['um_net'],
            'utj_nominal' => $b['utj'],
            'utj_tanggal_transaksi' => $b['tgl_setor']?->toDateString(),
            'utj_metode' => 'transfer',
            'jenis_pembayaran' => 'kpr',
            'status' => 'cancelled',
            'alasan_pembatalan_id' => $b['alasan_id'],
            'cancel_keterangan' => $b['ket'] !== '' ? $b['ket'] : null,
            'cancelled_at' => ($b['tgl_proses'] ?? $b['tgl_jual'])->toDateString(),
            'refund_status' => $adaSetoranLebih ? 'pending' : 'tidak_ada_refund',
            'refund_amount' => 0,
            'catatan' => $b['nomor_lama'] !== []
                ? 'Nomor SPR sebelumnya: '.implode(', ', $b['nomor_lama'])
                : null,
        ]);

        if ($b['utj'] > 0) {
            SprRealisasiPembayaran::create([
                'spr_id' => $spr->id,
                'jenis' => 'bf',
                'tanggal_bayar' => ($b['tgl_setor'] ?? $b['tgl_jual'])->toDateString(),
                'jumlah' => $b['utj'],
                'nomor_kwitansi' => $b['kwitansi'],
                'metode' => 'transfer',
                'keterangan' => 'UTJ dari DATA BATAL.xlsx',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $akanDibuat
     * @param  list<array<string, string>>  $sudahAda
     * @param  list<string>  $bermasalah
     */
    private function laporkan(array $akanDibuat, array $sudahAda, array $bermasalah): void
    {
        $rp = fn (float $v) => number_format($v, 0, ',', '.');

        $this->newLine();
        $this->line('  Sudah ada, dilewati : '.count($sudahAda));
        $this->line('  Akan dibuat         : '.count($akanDibuat));
        $this->line('  Bermasalah          : '.count($bermasalah));

        if ($akanDibuat !== []) {
            $this->newLine();
            $this->line(sprintf('  %-4s %-18s %-8s %-22s %14s %10s %-8s %-9s %s',
                'BRS', 'NO SPR', 'UNIT', 'NAMA', 'HARGA', 'UTJ', 'KWITANSI', 'REFUND', 'ALASAN'));

            foreach ($akanDibuat as $b) {
                $this->line(sprintf('  %-4d %-18s %-8s %-22s %14s %10s %-8s %-9s %s',
                    $b['baris'], $b['nomor_spr'], $b['unit'],
                    mb_substr($b['nama'], 0, 22), $rp($b['total_harga']), $rp($b['utj']),
                    $b['kwitansi'] ?? '-',
                    $b['akumulasi_um'] > $b['utj'] ? 'pending' : 'tidak ada',
                    $b['alasan']));
            }
        }

        if ($sudahAda !== []) {
            $this->newLine();
            $this->line('  Sudah ada di database:');
            $this->line('    '.implode(', ', array_column($sudahAda, 'nomor')));
        }

        foreach ($bermasalah as $pesan) {
            $this->warn('  '.$pesan);
        }
    }
}
