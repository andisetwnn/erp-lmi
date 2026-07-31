<?php

namespace App\Console\Commands;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Bank;
use App\Models\Master\Booking;
use App\Models\Master\Customer;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerKontakDarurat;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\SprTerminPembayaran;
use App\Models\Master\TempatKerja;
use App\Models\Master\TipeRumah;
use App\Models\Master\VirtualAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import data historis GRHA ARYANA dari MASTER DATA.xlsx.
 * Sumber: 5 sheet — MASTER DATA 1, KONSUMEN AKTIF, UM, REFUND UM, LIST VA.
 * Idempotent: pakai firstOrCreate. Aman di-run ulang.
 */
class ImportKonsumenOnProgress extends Command
{
    protected $signature = 'import:konsumen-on-progress
                            {--file=MASTER DATA.xlsx : Path xlsx sumber}
                            {--dry-run : Tampilkan summary, tidak insert}';

    protected $description = 'Import data historis konsumen GRHA ARYANA (ON PROGRESS + AKAD + BATAL) ke sistem';

    // Counters
    private array $counts = [
        'rumah' => 0, 'prospect' => 0, 'customer' => 0, 'booking' => 0,
        'spr' => 0, 'termin' => 0, 'realisasi' => 0, 'va' => 0, 'refund' => 0, 'skip' => 0,
        'enriched' => 0, 'kondar' => 0, 'tempat_kerja' => 0,
    ];

    // Lookups
    private array $lookupSprByNama = [];   // sheet 2: nama → nomor SPR (Excel format like "00047")
    private array $lookupSprByBlokUnit = []; // sheet 2: blok-unit → nomor SPR
    private array $realisasiByKey = [];    // sheet UM: "nama|blok-unit" → list of realisasi rows
    private array $refundByKey = [];       // sheet REFUND: "nama|blok-unit" → refund row
    private array $vaByBlokUnit = [];      // sheet LIST VA: "blok-unit" → VA row

    // 8 SPR batal yang confirmed dari user (nama Excel → SPR excel-format)
    private array $sprBatalManual = [
        'Michael frans nico sibuea' => '00005',
        'Aris Padil S.Pd'           => '00017',
        'Marni'                     => '00084',
        'MOCHAMMAD SYIVA TALIF AL KABRIR/DINA MARINA' => '00050',
        'FAHRUL ROZI'               => '00130', // batal — dedup dengan aktif R66
        'AZMIL HABLILLAH'           => '00055',
        'Raka Wira Satria'          => '00095',
        'Ade Kurniawan/Siti Nur Fadila' => '00114', // batal — dedup dengan aktif R126
    ];
    // Panji Ilham batal masih pending (unit DD-05), 4 lain juga pending → skip untuk sekarang.

    private ?User $userFinance = null;
    private ?User $userPm = null;
    private ?User $userApprover = null;
    private ?Bank $bankBtn = null;
    private ?AlasanPembatalan $alasanBatal = null;

    public function handle(): int
    {
        $path = $this->option('file');
        if (! file_exists($path)) {
            $this->error("File tidak ditemukan: {$path}");
            return self::FAILURE;
        }

        $this->info("Reading: {$path}");
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);

        // Preflight master lookup
        $proyek = Proyek::where('kode_surat', 'GA')->first();
        if (! $proyek) {
            $this->error('Proyek GA (Grha Aryana) belum di-seed.');
            return self::FAILURE;
        }
        $tipeArjuna = TipeRumah::where('proyek_id', $proyek->id)->where('tipe', 'Arjuna 30/60')->first();
        $tipeBima = TipeRumah::where('proyek_id', $proyek->id)->where('tipe', 'Bima 36/78')->first();
        if (! $tipeArjuna || ! $tipeBima) {
            $this->error('Tipe Arjuna / Bima belum ada. Run TipeRumahSeeder.');
            return self::FAILURE;
        }
        $this->bankBtn = Bank::where('nama', 'like', '%BTN%')->first();
        $this->alasanBatal = AlasanPembatalan::where('nama', 'Konsumen Tidak Kooperatif')->first();
        // Verifikator SPR historis: Uli (Finance) yang konfirmasi UTJ + approve,
        // Febry (PM) yang tanda tangan PM. Fallback ke role kalau username tidak ada.
        $this->userFinance = User::where('username', 'uli')->first() ?: User::role('finance')->first();
        $this->userPm = User::where('username', 'febri')->first() ?: User::role('project-manager')->first();
        $this->userApprover = $this->userPm ?: User::role('super-admin')->first();

        if (! $this->userFinance || ! $this->userPm) {
            $this->warn('User Finance (uli) / PM (febri) belum ada di seeder. Field TTD & approver akan null.');
        }

        // Preload lookup tables
        $this->preloadKonsumenAktif($ss);
        $this->preloadUm($ss);
        $this->preloadRefundUm($ss);
        $this->preloadListVa($ss);

        $this->info(sprintf(
            'Lookups loaded: %d SPR by nama, %d realisasi keys, %d refund, %d VA',
            count($this->lookupSprByNama),
            count($this->realisasiByKey),
            count($this->refundByKey),
            count($this->vaByBlokUnit),
        ));

        $dryRun = $this->option('dry-run');

        DB::beginTransaction();
        try {
            $this->processMasterData1($ss, $proyek, $tipeArjuna, $tipeBima);
            $this->processListVa($proyek);
            $this->enrichFromDetailKonsumen($ss);

            if ($dryRun) {
                DB::rollBack();
                $this->warn("\n[DRY RUN] Rolled back — tidak ada perubahan.");
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("\nError: ".$e->getMessage());
            $this->error('At: '.$e->getFile().':'.$e->getLine());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }

        $this->newLine(2);
        $this->info('=== HASIL IMPORT ===');
        foreach ($this->counts as $k => $v) {
            $this->info(sprintf('%-12s: %d', $k, $v));
        }

        return self::SUCCESS;
    }

    /** Preload sheet KONSUMEN AKTIF → nama → nomor SPR. */
    private function preloadKonsumenAktif($ss): void
    {
        try {
            $sheet = $ss->getSheetByName('KONSUMEN AKTIF');
            if (! $sheet) return;
            $rows = $sheet->toArray(null, true, true, true);
            foreach ($rows as $r) {
                $nama = trim((string) ($r['B'] ?? ''));
                $spr = trim((string) ($r['J'] ?? ''));
                $blok = trim((string) ($r['C'] ?? ''));
                $unit = trim((string) ($r['D'] ?? ''));

                if ($nama && $spr && ! in_array(strtoupper($nama), ['NAMA', 'NO'])) {
                    // Multi-SPR "00049/00085" → ambil terakhir
                    $parts = array_map('trim', explode('/', $spr));
                    $last = end($parts);
                    if (preg_match('/^\d+$/', $last)) {
                        $sprPadded = str_pad($last, 5, '0', STR_PAD_LEFT);
                        $this->lookupSprByNama[$this->normNama($nama)] = $sprPadded;

                        // Also index by blok-unit for fallback lookup
                        if ($blok && $unit) {
                            $key = strtoupper($blok).'-'.str_pad($unit, 2, '0', STR_PAD_LEFT);
                            $this->lookupSprByBlokUnit[$key] = $sprPadded;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Sheet KONSUMEN AKTIF tidak ditemukan: '.$e->getMessage());
        }
    }

    /** Preload sheet UM → key "nama|blok-unit" → list of realisasi. */
    private function preloadUm($ss): void
    {
        try {
            $sheet = $ss->getSheetByName('UM');
            if (! $sheet) return;
            $rows = $sheet->toArray(null, true, true, true);
            foreach ($rows as $r) {
                $nama = trim((string) ($r['B'] ?? ''));
                $blokRaw = trim((string) ($r['E'] ?? ''));
                $ket = strtoupper(trim((string) ($r['L'] ?? '')));
                $utj = $this->parseNum($r['H'] ?? 0);
                $um = $this->parseNum($r['I'] ?? 0);
                $jumlah = $this->parseNum($r['K'] ?? 0);
                if (! $nama || ! $blokRaw || ! in_array($ket, ['UTJ', 'UM'])) continue;
                if ($jumlah <= 0) continue;

                $blokUnit = $this->normBlokUnit($blokRaw);
                if (! $blokUnit) continue;

                $key = $this->normNama($nama).'|'.$blokUnit;
                $this->realisasiByKey[$key][] = [
                    'tanggal_bayar' => $this->parseExcelDate($r['C'] ?? null),
                    'nomor_kwitansi' => trim((string) ($r['D'] ?? '')) ?: null,
                    'jenis' => $ket === 'UTJ' ? 'bf' : 'um',
                    'jumlah' => $jumlah,
                    'metode' => $this->parseMetode($r['M'] ?? null),
                ];
            }
        } catch (\Throwable $e) {
            $this->warn('Sheet UM tidak bisa dibaca: '.$e->getMessage());
        }
    }

    /** Preload sheet REFUND UM → key nama|blok-unit → refund info. */
    private function preloadRefundUm($ss): void
    {
        try {
            $sheet = $ss->getSheetByName('REFUND UM');
            if (! $sheet) return;
            $rows = $sheet->toArray(null, true, true, true);
            foreach ($rows as $r) {
                $nama = trim((string) ($r['B'] ?? ''));
                $blokRaw = trim((string) ($r['E'] ?? ''));
                if (! $nama || ! $blokRaw || strtoupper($nama) === 'NAMA') continue;

                $blokUnit = $this->normBlokUnit($blokRaw);
                if (! $blokUnit) continue;

                $refundAmount = $this->parseNum($r['K'] ?? 0);
                $totalBfUm = $this->parseNum($r['J'] ?? 0);
                $sudahDibayar = $r['O'] ?? null;
                // O bisa string date "20 Juni 2026" atau serial
                $tglDibayar = null;
                if (is_string($sudahDibayar) && trim($sudahDibayar) !== '') {
                    try {
                        $tglDibayar = Carbon::parse($sudahDibayar);
                    } catch (\Throwable $e) {
                        $tglDibayar = null;
                    }
                } else {
                    $tglDibayar = $this->parseExcelDate($sudahDibayar);
                }

                $this->refundByKey[$this->normNama($nama).'|'.$blokUnit] = [
                    'refund_amount' => $refundAmount ?: $totalBfUm,
                    'refund_at' => $tglDibayar,
                    'refund_status' => $tglDibayar ? 'full' : 'pending',
                ];
            }
        } catch (\Throwable $e) {
            $this->warn('Sheet REFUND UM tidak bisa dibaca: '.$e->getMessage());
        }
    }

    /** Preload sheet LIST VA → key blok-unit → VA info. */
    private function preloadListVa($ss): void
    {
        try {
            $sheet = $ss->getSheetByName('LIST VA');
            if (! $sheet) return;
            $rows = $sheet->toArray(null, true, true, true);
            foreach ($rows as $r) {
                $blokRaw = trim((string) ($r['C'] ?? ''));
                $noUnit = trim((string) ($r['D'] ?? ''));
                $nomorVa = trim((string) ($r['F'] ?? ''));
                if (! $blokRaw || ! $noUnit || ! $nomorVa || strtoupper($blokRaw) === 'BLOK') continue;

                $blok = strtoupper($blokRaw);
                $unit = str_pad($noUnit, 2, '0', STR_PAD_LEFT);
                $key = $blok.'-'.$unit;

                $this->vaByBlokUnit[$key] = [
                    'nama' => trim((string) ($r['B'] ?? '')),
                    'nomor_va' => $nomorVa,
                    'tgl_akad' => $this->parseExcelDate($r['E'] ?? null),
                ];
            }
        } catch (\Throwable $e) {
            $this->warn('Sheet LIST VA tidak bisa dibaca: '.$e->getMessage());
        }
    }

    /** Iterate sheet MASTER DATA 1 per section. */
    private function processMasterData1($ss, Proyek $proyek, TipeRumah $tipeArjuna, TipeRumah $tipeBima): void
    {
        $sheet = $ss->getSheetByName('MASTER DATA 1');
        if (! $sheet) {
            throw new \RuntimeException('Sheet MASTER DATA 1 tidak ditemukan.');
        }
        $rows = $sheet->toArray(null, true, true, true);

        // Section boundaries — updated per Excel July 2026 (header shifted, sections re-ranged)
        $sections = [
            ['name' => 'ON PROGRESS', 'start' => 8,   'end' => 154, 'status' => 'approved'],
            ['name' => 'AKAD',        'start' => 180, 'end' => 260, 'status' => 'akad'],
            ['name' => 'BATAL',       'start' => 261, 'end' => 320, 'status' => 'cancelled'],
        ];

        foreach ($sections as $sec) {
            $this->info("\n→ Section: {$sec['name']}");
            $sectionCount = 0;
            for ($i = $sec['start']; $i <= $sec['end']; $i++) {
                $r = $rows[$i] ?? [];
                $nama = trim((string) ($r['C'] ?? ''));
                if (! $nama || in_array(strtoupper($nama), ['NAMA', 'TOTAL', ''])) continue;
                // Skip summary/header rows di section BATAL
                if ($sec['name'] === 'BATAL' && ! isset($this->sprBatalManual[$nama])) {
                    continue; // hanya import yang ada nomor SPR di sprBatalManual
                }

                try {
                    $this->processRow($r, $sec['status'], $proyek, $tipeArjuna, $tipeBima);
                    $sectionCount++;
                } catch (\Throwable $e) {
                    $this->counts['skip']++;
                    $this->warn("  R{$i} skip ({$nama}): ".$e->getMessage());
                }
            }
            $this->info("  Section {$sec['name']}: {$sectionCount} processed");
        }
    }

    private function processRow(array $r, string $status, Proyek $proyek, TipeRumah $tipeArjuna, TipeRumah $tipeBima): void
    {
        // Kolom MASTER DATA 1 (per header row 5):
        // C=NAMA, D=BLOK, E=SALES, F=NIK, G=ALAMAT, H=NO.TELP, I=NAMA TYPE, J=TYPE, K=HARGA JUAL, ...
        $namaRaw = trim((string) ($r['C'] ?? ''));
        // Ambil nama suami (yang pertama sebelum "/")
        $nama = str_contains($namaRaw, '/') ? trim(explode('/', $namaRaw)[0]) : $namaRaw;
        $blokRaw = trim((string) ($r['D'] ?? ''));
        $salesKode = $this->normalizeSalesKode((string) ($r['E'] ?? ''));
        $nikRaw = trim((string) ($r['F'] ?? ''));
        $nik = str_contains($nikRaw, '/') ? trim(explode('/', $nikRaw)[0]) : $nikRaw;
        $alamat = trim((string) ($r['G'] ?? ''));
        $telp = trim((string) ($r['H'] ?? ''));
        $tipeNama = strtoupper(trim((string) ($r['I'] ?? '')));

        if (! $blokRaw || ! $salesKode) {
            throw new \RuntimeException('NIK/blok/sales kosong');
        }

        // NIK boleh kosong untuk BATAL awal (customer batal sebelum lengkap), tapi tetap perlu nama
        if (! $nik && $status !== 'cancelled') {
            throw new \RuntimeException('NIK kosong');
        }

        $blokUnit = $this->normBlokUnit($blokRaw);
        if (! $blokUnit) throw new \RuntimeException("Format blok invalid: {$blokRaw}");
        [$blok, $unit] = explode('-', $blokUnit);

        $sales = Sales::where('kode', $salesKode)->orWhere('dbos_username', strtolower($salesKode))->first();
        if (! $sales) throw new \RuntimeException("Sales '{$salesKode}' tidak ditemukan di master");

        $tipe = str_contains($tipeNama, 'BIMA') ? $tipeBima : $tipeArjuna;

        // Rumah — firstOrCreate
        $rumah = Rumah::firstOrCreate(
            ['proyek_id' => $proyek->id, 'blok' => $blok, 'nomor_unit' => $unit],
            [
                'tipe_rumah_id' => $tipe->id,
                'biaya_tambahan' => $this->parseNum($r['M'] ?? 0),
                'discount' => $this->parseNum($r['N'] ?? 0),
                'status' => $status === 'cancelled' ? 'available' : 'terjual',
            ],
        );
        if ($rumah->wasRecentlyCreated) $this->counts['rumah']++;

        // HP wajib NOT NULL. Kalau kosong, derive dari NIK (10 digit terakhir dengan prefix 08).
        $hp = $telp ?: ($nik ? '08'.substr(preg_replace('/\D/', '', $nik), -10) : null);
        if (! $hp) {
            throw new \RuntimeException('HP dan NIK dua-duanya kosong, tidak bisa derive');
        }

        // Prospect customer — dedup by NIK (fallback: nama+blok kalau NIK kosong)
        $prospectKey = $nik ? ['nik' => $nik] : ['nama_lengkap' => $nama, 'proyek_id' => $proyek->id];
        $prospect = ProspectCustomer::where($prospectKey)->first();
        if (! $prospect) {
            $prospect = ProspectCustomer::create([
                'proyek_id' => $proyek->id,
                'sales_id' => $sales->id,
                'nama_lengkap' => $nama,
                'nik' => $nik ?: null,
                'hp' => $hp,
                'sumber' => 'Walk-in',
                'status' => 'finish',
                'alamat' => $alamat ?: null,
                'bank_id' => $this->bankBtn?->id,
            ]);
            $this->counts['prospect']++;
        }

        // Customer master — auto-copy dari prospect (dedup by NIK)
        if ($nik) {
            $customer = Customer::where('nik', $nik)->first();
            if (! $customer) {
                Customer::create([
                    'proyek_id' => $proyek->id,
                    'nama_lengkap' => $nama,
                    'nik' => $nik,
                    'hp' => $hp,
                    'hp_2' => $prospect->hp_2,
                    'sumber' => $prospect->sumber,
                    'npwp' => $prospect->npwp,
                    'foto_ktp' => $prospect->foto_ktp,
                    // Biodata KTP
                    'tempat_lahir' => $prospect->tempat_lahir,
                    'tanggal_lahir' => $prospect->tanggal_lahir,
                    'jenis_kelamin' => $prospect->jenis_kelamin,
                    'agama' => $prospect->agama,
                    'status_perkawinan' => $prospect->status_perkawinan,
                    // Alamat
                    'alamat_ktp' => $alamat ?: null,
                    'rt_rw' => $prospect->rt_rw,
                    'provinsi_code' => $prospect->provinsi_code,
                    'provinsi_nama' => $prospect->provinsi_nama,
                    'kota_code' => $prospect->kota_code,
                    'kota_nama' => $prospect->kota_nama,
                    'kecamatan_code' => $prospect->kecamatan_code,
                    'kecamatan_nama' => $prospect->kecamatan_nama,
                    'kelurahan_code' => $prospect->kelurahan_code,
                    'kelurahan_nama' => $prospect->kelurahan_nama,
                    // Pekerjaan
                    'tempat_kerja_id' => $prospect->tempat_kerja_id,
                    'jenis_pekerjaan' => $prospect->pekerjaan_ktp,
                    'penghasilan_bulanan' => $prospect->penghasilan_bulanan,
                    // Rekening
                    'bank_id' => $this->bankBtn?->id,
                    'rekening_atas_nama' => $nama,
                    // BI Checking
                    'bi_kol' => $prospect->bi_kol,
                    'bi_dbr' => $prospect->bi_dbr,
                    'bi_keterangan' => $prospect->bi_keterangan,
                    // Catatan
                    'catatan' => $prospect->catatan,
                ]);
                $this->counts['customer']++;
            }
        }

        // Cari nomor SPR
        $nomorSprSource = $this->getNomorSprSource($r, $nama, $status, $blokUnit);
        if (! $nomorSprSource) {
            throw new \RuntimeException("Nomor SPR tidak ketemu");
        }

        // Tentukan tanggal booking dari realisasi UTJ di sheet UM
        $key = $this->normNama($nama).'|'.$blokUnit;
        $realisasiList = $this->realisasiByKey[$key] ?? [];
        $utjRow = collect($realisasiList)->firstWhere('jenis', 'bf');
        $bfTgl = $utjRow['tanggal_bayar'] ?? null;
        $tanggalBooking = $bfTgl ?: Carbon::create(2023, 6, 1);
        $tanggalSpr = $tanggalBooking;

        // Format nomor SPR sistem: SPR/YYYY/MM/XXXX
        $nomorSpr = sprintf('SPR/%s/%s/%s',
            $tanggalSpr->format('Y'),
            $tanggalSpr->format('m'),
            str_pad($nomorSprSource, 4, '0', STR_PAD_LEFT),
        );

        // Booking — 1 rumah bisa punya 2 booking kalau ada batal + aktif (dedup Fahrul/Ade/Panji)
        // Untuk kasus batal, buat booking khusus dengan status 'batal'
        $bookingStatus = match ($status) {
            'akad' => 'akad',
            'cancelled' => 'batal',
            default => 'sukses',
        };
        $booking = Booking::create([
            'sales_id' => $sales->id,
            'proyek_id' => $proyek->id,
            'rumah_id' => $rumah->id,
            'prospect_customer_id' => $prospect->id,
            'tanggal_booking' => $tanggalBooking,
            'tanggal_expired' => $tanggalBooking->copy()->addDays(1),
            'status' => $bookingStatus,
        ]);
        $this->counts['booking']++;

        // Parse harga
        $hargaJual = $this->parseNum($r['K'] ?? 0);
        $biayaAdmin = $this->parseNum($r['L'] ?? 0);
        $biayaHook = $this->parseNum($r['M'] ?? 0);
        $diskon = $this->parseNum($r['N'] ?? 0);
        $allIn = $this->parseNum($r['O'] ?? 0);
        $kpr = $this->parseNum($r['P'] ?? 0);
        $um = $this->parseNum($r['Q'] ?? 0);
        $sbum = $this->parseNum($r['R'] ?? 0);
        $umNet = $this->parseNum($r['T'] ?? 0);
        $tglCairBum = $this->parseExcelDate($r['S'] ?? null);

        $utjNominal = $utjRow['jumlah'] ?? $this->parseNum($r['W'] ?? 0) ?: 500000;

        // Cek SPR existing (by nomor)
        $spr = Spr::where('nomor_spr', $nomorSpr)->first();
        if (! $spr) {
            $sprData = [
                'booking_id' => $booking->id,
                'sales_id' => $sales->id,
                'prospect_customer_id' => $prospect->id,
                'rumah_id' => $rumah->id,
                'kategori' => $tipe->kategori ?? 'subsidi',
                'nomor_spr' => $nomorSpr,
                'tanggal_spr' => $tanggalSpr,
                'harga_jual' => $hargaJual,
                'biaya_tambahan' => $biayaAdmin + $biayaHook,
                'diskon' => $diskon,
                'kelebihan_tanah_m2' => 0,
                'harga_per_m2' => 0,
                'total_harga' => $allIn ?: ($hargaJual + $biayaAdmin + $biayaHook - $diskon),
                'jenis_pembayaran' => $kpr > 0 ? 'kpr' : 'cash',
                'bank_kpr_id' => $kpr > 0 ? $this->bankBtn?->id : null,
                'dp_persen' => $allIn > 0 ? round(($um / $allIn) * 100, 2) : 0,
                'dp_nominal' => $um,
                'sbum' => $sbum,
                'tgl_cair_bum' => $tglCairBum,
                'um_net' => $umNet ?: max(0, $um - $sbum),
                'nilai_kpr' => $kpr,
                // Fallback tanggal kalau UTJ realisasi belum ada di sheet UM: pakai tanggal booking.
                'utj_nominal' => $utjNominal,
                'utj_tanggal_bayar' => $bfTgl ?: $tanggalBooking,
                'utj_metode' => $utjRow['metode'] ?? 'transfer',
                'utj_tanggal_transaksi' => $bfTgl ?: $tanggalBooking,
                'utj_nominal_aktual' => $utjNominal,
                'utj_confirmed_by_user_id' => $this->userFinance?->id,
                'utj_confirmed_at' => ($bfTgl ?: $tanggalBooking)->copy()->addHours(2),
                'status' => $status,
                'approved_by_user_id' => $this->userFinance?->id,
                'approved_at' => ($bfTgl ?: $tanggalBooking)->copy()->addHours(2),
                'pm_approved_by_user_id' => $this->userPm?->id,
                'pm_approved_at' => ($bfTgl ?: $tanggalBooking)->copy()->addHours(3),
                // TTD snapshot — anggap SPR historis ini sudah ditandatangani PM & Finance.
                'ttd_sales_path' => $sales->tanda_tangan_path,
                'ttd_finance_path' => $this->userFinance?->tanda_tangan_path,
                'ttd_pm_path' => $this->userPm?->tanda_tangan_path,
            ];

            // Untuk BATAL, isi field pembatalan + refund
            if ($status === 'cancelled') {
                $refund = $this->refundByKey[$key] ?? null;
                $sprData['alasan_pembatalan_id'] = $this->alasanBatal?->id;
                $sprData['cancelled_at'] = $bfTgl?->copy()->addDays(30);
                $sprData['cancelled_by_user_id'] = $this->userPm?->id;
                if ($refund) {
                    $sprData['refund_amount'] = $refund['refund_amount'];
                    $sprData['refund_at'] = $refund['refund_at'];
                    $sprData['refund_status'] = $refund['refund_status'];
                    if ($refund['refund_at']) $this->counts['refund']++;
                }
            }

            $spr = Spr::create($sprData);
            $this->counts['spr']++;
        }

        // 4 termin equal split — jadwal saja (booking + 30/60/90/120 hari)
        $umNetFinal = (float) $spr->um_net;
        $terminAmount = round(max(0, $umNetFinal - $utjNominal) / 4, 2);
        for ($n = 1; $n <= 4; $n++) {
            $existing = SprTerminPembayaran::where('spr_id', $spr->id)
                ->where('jenis', 'um')->where('urutan', $n)->first();
            if ($existing) continue;
            SprTerminPembayaran::create([
                'spr_id' => $spr->id,
                'jenis' => 'um',
                'urutan' => $n,
                'tanggal_jadwal' => $tanggalBooking->copy()->addDays(30 * $n),
                'jumlah_jadwal' => $terminAmount,
            ]);
            $this->counts['termin']++;
        }
        // BF termin (jadwal UTJ)
        $bfExisting = SprTerminPembayaran::where('spr_id', $spr->id)
            ->where('jenis', 'bf')->first();
        if (! $bfExisting) {
            SprTerminPembayaran::create([
                'spr_id' => $spr->id,
                'jenis' => 'bf',
                'urutan' => 0,
                'tanggal_jadwal' => $tanggalBooking,
                'jumlah_jadwal' => $utjNominal,
            ]);
            $this->counts['termin']++;
        }

        // Realisasi dari sheet UM — nomor_kwitansi unique global; skip kalau tanggal kosong.
        foreach ($realisasiList as $rl) {
            if (! $rl['tanggal_bayar']) continue; // tanggal wajib NOT NULL
            if ($rl['nomor_kwitansi']) {
                $exists = SprRealisasiPembayaran::where('nomor_kwitansi', $rl['nomor_kwitansi'])->exists();
                if ($exists) continue;
            }
            SprRealisasiPembayaran::create([
                'spr_id' => $spr->id,
                'jenis' => $rl['jenis'],
                'tanggal_bayar' => $rl['tanggal_bayar'],
                'jumlah' => $rl['jumlah'],
                'nomor_kwitansi' => $rl['nomor_kwitansi'],
                'metode' => $rl['metode'],
                'input_by_user_id' => $this->userFinance?->id,
            ]);
            $this->counts['realisasi']++;
        }
    }

    /** Get NO. SPR — untuk BATAL: prioritas sprBatalManual. Untuk AKTIF/AKAD: lookup sheet 2. */
    private function getNomorSprSource(array $r, string $nama, string $status, string $blokUnit): ?string
    {
        // BATAL: manual map dulu supaya tidak keliru ambil SPR aktif untuk nama sama.
        if ($status === 'cancelled') {
            $fullName = trim((string) ($r['C'] ?? ''));
            if (isset($this->sprBatalManual[$fullName])) {
                return str_pad($this->sprBatalManual[$fullName], 5, '0', STR_PAD_LEFT);
            }
        }

        $fromRow = trim((string) ($r['B'] ?? ''));
        if ($fromRow) {
            $parts = array_map('trim', explode('/', $fromRow));
            $last = end($parts);
            if (preg_match('/^\d+$/', $last)) return str_pad($last, 5, '0', STR_PAD_LEFT);
        }

        $normNama = $this->normNama($nama);
        if (isset($this->lookupSprByNama[$normNama])) {
            return $this->lookupSprByNama[$normNama];
        }

        // Fallback: lookup by blok-unit
        if (isset($this->lookupSprByBlokUnit[$blokUnit])) {
            return $this->lookupSprByBlokUnit[$blokUnit];
        }

        return null;
    }

    /** Process LIST VA sheet → virtual_account records. */
    private function processListVa(Proyek $proyek): void
    {
        $this->info("\n→ Section: LIST VA");
        foreach ($this->vaByBlokUnit as $blokUnit => $va) {
            [$blok, $unit] = explode('-', $blokUnit);
            $rumah = Rumah::where('proyek_id', $proyek->id)
                ->where('blok', $blok)->where('nomor_unit', $unit)->first();
            if (! $rumah) continue;

            VirtualAccount::firstOrCreate(
                ['nomor_va' => $va['nomor_va']],
                [
                    'proyek_id' => $proyek->id,
                    'bank_id' => $this->bankBtn?->id,
                    'rumah_id' => $rumah->id,
                    'is_aktif' => true,
                ],
            );
            $this->counts['va']++;
        }
    }

    /**
     * Enrich prospect_customer dari sheet DETAIL KONSUMEN.
     * Fields: sumber, tempat_kerja (via TempatKerja), pekerjaan_ktp, penghasilan_bulanan,
     * bi_kol, kontak darurat (nama + nomor), HP fallback.
     */
    private function enrichFromDetailKonsumen($ss): void
    {
        $sheet = $ss->getSheetByName('DETAIL KONSUMEN');
        if (! $sheet) {
            $this->warn('Sheet DETAIL KONSUMEN tidak ditemukan, skip enrichment.');
            return;
        }

        $this->info("\n→ Section: DETAIL KONSUMEN (enrich biodata)");
        $rows = $sheet->toArray(null, true, true, true);

        foreach ($rows as $rowNum => $r) {
            $no = trim((string) ($r['A'] ?? ''));
            if (! is_numeric($no)) continue; // skip judul/header

            $namaRaw = trim((string) ($r['B'] ?? ''));
            if (! $namaRaw) continue;

            // Cari prospect dengan beberapa strategi matching
            $prospect = $this->findProspectByFuzzyName($namaRaw);
            if (! $prospect) {
                $this->warn("  DETAIL R{$rowNum} skip: prospect '{$namaRaw}' tidak ditemukan di MASTER DATA 1");
                continue;
            }

            $updates = [];

            // D: Sumber leads
            $sumberRaw = trim((string) ($r['D'] ?? ''));
            if ($sumberRaw) {
                $updates['sumber'] = $this->normalizeSumber($sumberRaw);
            }

            // E: HP — override kalau berbeda (Excel lebih otoritatif dari NIK-derived)
            $hpRaw = trim((string) ($r['E'] ?? ''));
            if ($hpRaw) {
                $hpClean = '0'.preg_replace('/\D/', '', $hpRaw);
                $hpClean = preg_replace('/^00+/', '0', $hpClean);
                if (strlen($hpClean) >= 10 && $hpClean !== $prospect->hp) {
                    $updates['hp'] = $hpClean;
                }
            }

            // F: Alamat pekerjaan + G: Pekerjaan → TempatKerja (dedup by nama)
            $alamatKerja = trim((string) ($r['F'] ?? ''));
            $pekerjaan = trim((string) ($r['G'] ?? ''));
            if ($alamatKerja) {
                $tk = TempatKerja::firstOrCreate(
                    ['nama' => $alamatKerja],
                    ['bidang_usaha' => $pekerjaan ?: null, 'alamat' => $alamatKerja],
                );
                if ($tk->wasRecentlyCreated) $this->counts['tempat_kerja']++;
                $updates['tempat_kerja_id'] = $tk->id;
            }
            if ($pekerjaan) {
                $updates['pekerjaan_ktp'] = $pekerjaan;
            }

            // J: Gaji / Penghasilan
            $gaji = $this->parseNum($r['J'] ?? 0);
            if ($gaji > 0) {
                $updates['penghasilan_bulanan'] = $gaji;
            }

            // K: BI check — parse "KOL 1" → bi_kol = '1'
            $biCheck = strtoupper(trim((string) ($r['K'] ?? '')));
            if (preg_match('/KOL\s*(\d)/i', $biCheck, $m)) {
                $updates['bi_kol'] = $m[1];
                // Default DBR aman kalau belum diisi (nanti bisa di-refine admin)
                if ($prospect->bi_dbr === null) {
                    $updates['bi_dbr'] = 0;
                }
            }

            if (! empty($updates)) {
                $prospect->update($updates);
                $this->counts['enriched']++;
            }

            // H + I: Kontak darurat (dedup by nama+prospect)
            $kondarNama = trim((string) ($r['H'] ?? ''));
            $kondarHp = trim((string) ($r['I'] ?? ''));
            if ($kondarNama && $kondarHp) {
                $hpKondar = '0'.preg_replace('/\D/', '', $kondarHp);
                $hpKondar = preg_replace('/^00+/', '0', $hpKondar);
                $existing = ProspectCustomerKontakDarurat::where('prospect_customer_id', $prospect->id)
                    ->where('nama', $kondarNama)->first();
                if (! $existing) {
                    ProspectCustomerKontakDarurat::create([
                        'prospect_customer_id' => $prospect->id,
                        'nama' => $kondarNama,
                        'hubungan' => 'lainnya',
                        'nomor_telepon' => $hpKondar,
                    ]);
                    $this->counts['kondar']++;
                }
            }
        }
    }

    /**
     * Cari prospect dengan fuzzy matching:
     * 1. Exact match (case insensitive)
     * 2. Strip suffix dalam kurung "(Cash)", "(Komersil)", dll
     * 3. Split "/" — coba suami dan istri sebagai nama primer
     * 4. Fallback: LIKE substring match (kalau nama panjang)
     */
    private function findProspectByFuzzyName(string $namaRaw): ?ProspectCustomer
    {
        $tries = [];
        $tries[] = trim($namaRaw);
        // Suami (sebelum "/")
        if (str_contains($namaRaw, '/')) {
            $parts = array_map('trim', explode('/', $namaRaw));
            $tries[] = $parts[0] ?? '';
            $tries[] = $parts[1] ?? '';
        }
        // Strip suffix "(...)"
        foreach ($tries as $t) {
            $stripped = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $t));
            if ($stripped !== $t) $tries[] = $stripped;
        }
        $tries = array_unique(array_filter(array_map('trim', $tries)));

        foreach ($tries as $candidate) {
            $p = ProspectCustomer::whereRaw('LOWER(nama_lengkap) = ?', [strtolower($candidate)])->first();
            if ($p) return $p;
        }

        // Fuzzy LIKE — cocok kalau nama panjang punya common prefix (>= 15 chars)
        $primary = $tries[0] ?? '';
        if (strlen($primary) >= 15) {
            $p = ProspectCustomer::whereRaw('LOWER(nama_lengkap) LIKE ?', ['%'.strtolower($primary).'%'])->first();
            if ($p) return $p;
        }

        return null;
    }

    private function normalizeSumber(string $raw): string
    {
        $r = strtoupper($raw);
        return match (true) {
            str_contains($r, 'SOSMED') || str_contains($r, 'IKALN') || str_contains($r, 'IKLAN') => 'Iklan Sosmed',
            str_contains($r, 'WALK') => 'Walk-in',
            str_contains($r, 'REFERR') || str_contains($r, 'RUJUKAN') || str_contains($r, 'REFERENSI') => 'Referensi',
            str_contains($r, 'PAMERAN') || str_contains($r, 'EXPO') => 'Pameran',
            str_contains($r, 'CANVASSING') => 'Canvassing',
            default => trim($raw),
        };
    }

    // ============ HELPERS ============

    private function normalizeSalesKode(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        if (str_contains($raw, '/')) {
            $raw = trim(explode('/', $raw)[0]);
        }
        // Excel sometimes has typo "ARIPIN" for "ARIFIN"
        return match ($raw) {
            'ARIPIN' => 'ARIFIN',
            default => $raw,
        };
    }

    /** Normalize nama untuk lookup: lowercase, trim, remove suffix (CASH), (Komersil), (S.Pd), dll. */
    private function normNama(string $s): string
    {
        $s = trim($s);
        // Ambil nama sebelum "/" (suami)
        if (str_contains($s, '/')) $s = trim(explode('/', $s)[0]);
        // Hapus suffix dalam kurung
        $s = preg_replace('/\s*\([^)]*\)\s*/', ' ', $s);
        $s = preg_replace('/\s+S\.?Pd\.?/i', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return strtolower(trim($s));
    }

    /** Normalize blok+unit ke format "AB-02" (2-digit unit padded). */
    private function normBlokUnit(string $raw): ?string
    {
        if (! preg_match('/^([A-Z]+\d*)\s*[-\s]+\s*(\w+)$/i', trim($raw), $m)) return null;
        $blok = strtoupper($m[1]);
        $unit = str_pad(strtoupper($m[2]), 2, '0', STR_PAD_LEFT);
        return $blok.'-'.$unit;
    }

    private function parseMetode($v): string
    {
        $v = strtoupper(trim((string) $v));
        return in_array($v, ['TUNAI', 'CASH', 'TN']) ? 'tunai' : 'transfer';
    }

    private function parseNum($v): float
    {
        if ($v === null || $v === '' || $v === '-') return 0;
        if (is_numeric($v)) return (float) $v;
        $clean = preg_replace('/[^\d.]/', '', (string) $v);
        return $clean === '' ? 0 : (float) $clean;
    }

    private function parseExcelDate($v): ?Carbon
    {
        if ($v === null || $v === '' || $v === '-' || $v === '0' || $v === 0) return null;
        if (! is_numeric($v)) {
            try {
                return Carbon::parse((string) $v);
            } catch (\Throwable $e) {
                return null;
            }
        }
        $serial = (float) $v;
        if ($serial < 100) return null;
        try {
            $dt = ExcelDate::excelToDateTimeObject($serial);
            return Carbon::instance($dt);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
