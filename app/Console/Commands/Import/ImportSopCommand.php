<?php

namespace App\Console\Commands\Import;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\BiayaTambahanRealisasi;
use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\SprTerminPembayaran;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\Import\SalesResolver;
use App\Services\Import\SopRowParser;
use App\Support\SprJadwalTermin;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Import historis GRHA ARYANA dari file DATA MASTER GRHA ARYANA.xlsx — sheet SOP.
 *
 * Struktur SOP (header di R7, data R8..R~217):
 * 93 kolom A→CO: identitas, harga, KPR, 17 slot termin (BF/UTJ + UM1..UM17),
 * SP3K, akad, serah terima kunci, bank per record.
 *
 * Fase per row (dalam 1 DB transaction untuk keseluruhan):
 *   1. upsert ProspectCustomer (dedup by NIK)
 *   2. upsert Rumah (by proyek_id + blok + nomor_unit)
 *   3. create Booking (histori — status=sukses)
 *   4. create SPR (nomor terakhir kalau multi, catatan nomor lama)
 *   5. create SprPemberkasan kalau ada bank/SP3K
 *   6. create SprRealisasiPembayaran (BF + UM1..UM7)
 *
 * Multi-nomor SPR (mis "00022/00162"):
 *   - Nomor aktif = paling terakhir → jadi SPR utama
 *   - Nomor lama disimpan di kolom `catatan` SPR aktif: "Nomor SPR sebelumnya (pindah kavling / ganti nama): 00022"
 *   - Tidak generate SPR batal terpisah (agar rumah_id valid semua)
 *
 * Usage:
 *   php artisan import:sop --dry-run     # preview tanpa write
 *   php artisan import:sop --force       # skip konfirmasi
 *   php artisan import:sop --limit=10    # test dgn 10 baris pertama
 */
class ImportSopCommand extends Command
{
    protected $signature = 'import:sop
        {--file=DATA MASTER GRHA ARYANA.xlsx : Path xlsx sumber (relatif ke root)}
        {--sheet=SOP : Nama sheet}
        {--start-row=8 : Baris awal data}
        {--dry-run : Preview tanpa write ke DB}
        {--force : Skip konfirmasi}
        {--limit=0 : Batasi jumlah row diproses (0 = semua)}
        {--skip-existing : Mode aman production — data yang sudah ada di tujuan menang, SPR baru tetap masuk}';

    protected $description = 'Import historis Grha Aryana dari sheet SOP (DATA MASTER GRHA ARYANA.xlsx)';

    protected SopRowParser $parser;

    protected SalesResolver $salesResolver;

    protected int $proyekId;

    protected int $alasanPindahKavlingId;

    /** User approver historis — Febry (PM/approver umum) & Uli (Finance). */
    protected ?int $approverPmId = null;

    protected ?int $approverFinanceId = null;

    /** @var array<string, int> Cache prospect by NIK */
    protected array $prospectByNik = [];

    /** @var array<string, int> Cache rumah by "blok|unit" */
    protected array $rumahByBlokUnit = [];

    /** @var array<string, int> Cache tipe_rumah by LB (int) */
    protected array $tipeByLb = [];

    protected array $stats = [
        'row_processed' => 0,
        'row_skipped' => 0,
        'prospect_created' => 0,
        'prospect_updated' => 0,
        'rumah_created' => 0,
        'rumah_updated' => 0,
        'booking_created' => 0,
        'spr_created' => 0,
        'pemberkasan_created' => 0,
        'realisasi_created' => 0,
        'termin_created' => 0,
        'biaya_tambahan_plafon_set' => 0,
        'biaya_tambahan_realisasi_created' => 0,
        'prod_wins_prospect' => 0,
        'prod_wins_rumah' => 0,
    ];

    /**
     * Penghitung alasan baris dilewati. Sengaja dipisah dari $stats: nilainya di-set tepat
     * sebelum baris digagalkan, jadi tidak boleh ikut dikembalikan saat state di-rollback.
     *
     * @var array<string, int>
     */
    protected array $skipStats = [
        'skip_existing_spr' => 0,
        'skip_existing_unit_ada_spr' => 0,
    ];

    protected array $warnings = [];

    /**
     * Pembayaran yang nomor kwitansinya kembar dan salah satunya diberi tanda.
     * Dilaporkan mencolok di akhir supaya bisa dicocokkan ke kwitansi fisik.
     *
     * @var array<int, array{sel: string, nomor: string, jenis: string, nominal: float, tanggal: string, aksi: string}>
     */
    protected array $kwitansiDitandai = [];

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        $this->parser = new SopRowParser;
        $this->salesResolver = new SalesResolver;

        $file = base_path((string) $this->option('file'));
        if (! file_exists($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('╔════════════════════════════════════════════════╗');
        $this->info('║  IMPORT SOP — Grha Aryana                     ║');
        $this->info('╚════════════════════════════════════════════════╝');
        $this->line("File   : $file");
        $this->line('Sheet  : '.$this->option('sheet'));
        $this->line('Mode   : '.($dryRun ? 'DRY-RUN' : 'WRITE'));
        if ($limit > 0) {
            $this->line("Limit  : $limit rows");
        }
        $this->newLine();

        // Prerequisite: proyek + alasan pindah kavling
        $proyek = Proyek::first();
        if (! $proyek) {
            $this->error('Belum ada Proyek di sistem. Seed proyek dulu.');

            return self::FAILURE;
        }
        $this->proyekId = $proyek->id;
        $this->line("Proyek : #{$proyek->id} — {$proyek->nama_proyek}");

        $alasan = AlasanPembatalan::firstOrCreate(
            ['nama' => 'Pindah Kavling / Ganti Nama'],
            ['dapat_meneruskan_angsuran' => true, 'is_aktif' => true]
        );
        $this->alasanPindahKavlingId = $alasan->id;

        // Resolve approver historis — Febry (PM/approver umum), Uli (Finance)
        $this->approverPmId = User::where('username', 'febri')->value('id');
        $this->approverFinanceId = User::where('username', 'uli')->value('id');
        $this->line("Approver PM (Febri): #{$this->approverPmId}");
        $this->line("Approver Finance (Uli): #{$this->approverFinanceId}");

        // Prewarm cache tipe_rumah by LB
        foreach (TipeRumah::all() as $t) {
            $this->tipeByLb[(int) $t->luas_bangunan] = $t->id;
        }
        $this->line('Tipe rumah cache: '.json_encode($this->tipeByLb));
        $this->newLine();

        // Konfirmasi
        if (! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('Lanjut import ke DB?', true)) {
                $this->warn('Dibatalkan.');

                return self::FAILURE;
            }
        }

        // Load excel
        $this->line('Loading Excel...');
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getSheetByName((string) $this->option('sheet'));
        if (! $sheet) {
            $this->error('Sheet tidak ditemukan.');

            return self::FAILURE;
        }
        $highestRow = $sheet->getHighestRow();
        $startRow = (int) $this->option('start-row');
        $endRow = $highestRow;
        if ($limit > 0) {
            $endRow = min($highestRow, $startRow + $limit - 1);
        }
        $this->line("Rows R$startRow..R$endRow");

        DB::beginTransaction();
        try {
            $bar = $this->output->createProgressBar($endRow - $startRow + 1);
            $bar->start();

            for ($r = $startRow; $r <= $endRow; $r++) {
                $noSpr = trim((string) $sheet->getCell("E$r")->getValue());
                if ($noSpr === '') {
                    $this->stats['row_skipped']++;
                    $bar->advance();

                    continue;
                }

                // Snapshot state in-memory: kalau baris gagal, savepoint di-rollback dan
                // cache id/stats harus ikut mundur — kalau tidak, baris berikutnya memakai
                // id record yang sudah tidak ada lagi di DB.
                $cacheProspek = $this->prospectByNik;
                $cacheRumah = $this->rumahByBlokUnit;
                $cacheStats = $this->stats;

                try {
                    // Nested transaction = SAVEPOINT, jadi baris yang gagal tidak
                    // meninggalkan SPR/prospect setengah jadi di dalam transaksi besar.
                    DB::transaction(fn () => $this->processRow($sheet, $r));
                    $this->stats['row_processed']++;
                } catch (\Throwable $e) {
                    $this->prospectByNik = $cacheProspek;
                    $this->rumahByBlokUnit = $cacheRumah;
                    $this->stats = $cacheStats;

                    $this->warnings[] = "R$r [SPR $noSpr]: ".$e->getMessage();
                    $this->stats['row_skipped']++;
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine(2);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: rollback semua perubahan.');
            } else {
                DB::commit();
                $this->info('✓ COMMIT sukses.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('FATAL: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }

        // Report
        $this->newLine();
        $this->info('=== STATS ===');
        foreach ($this->stats + $this->skipStats as $k => $v) {
            $this->line("  $k: $v");
        }

        if ($this->warnings) {
            $this->newLine();
            $this->warn('WARNINGS ('.count($this->warnings).'):');
            foreach (array_slice($this->warnings, 0, 20) as $w) {
                $this->line("  $w");
            }
            if (count($this->warnings) > 20) {
                $this->line('  ... ('.(count($this->warnings) - 20).' more)');
            }

            // Save full log
            $logPath = storage_path('app/private/import-log/sop_warnings_'.date('Ymd_His').'.log');
            @mkdir(dirname($logPath), 0755, true);
            file_put_contents($logPath, implode("\n", $this->warnings));
            $this->line("  Full log: $logPath");
        }

        $this->laporkanKwitansiDitandai();

        return self::SUCCESS;
    }

    /**
     * Laporkan nomor kwitansi kembar yang salah satunya diberi tanda.
     *
     * Sengaja ditaruh paling akhir dengan total rupiah: pembayarannya memang tercatat
     * semua, tapi penomorannya menyimpang dari kwitansi fisik dan harus dicocokkan.
     */
    protected function laporkanKwitansiDitandai(): void
    {
        if (! $this->kwitansiDitandai) {
            return;
        }

        $total = array_sum(array_column($this->kwitansiDitandai, 'nominal'));

        $this->newLine();
        $this->warn('╔══════════════════════════════════════════════════════════════════╗');
        $this->warn(sprintf('║  %d NOMOR KWITANSI KEMBAR — TOTAL Rp %-27s ║',
            count($this->kwitansiDitandai), number_format($total, 0, ',', '.')));
        $this->warn('╚══════════════════════════════════════════════════════════════════╝');
        $this->line('  Semua pembayaran TERCATAT. Yang tanggalnya paling baru diberi tanda,');
        $this->line('  yang lebih lama tetap memegang nomor asli.');
        $this->newLine();

        foreach ($this->kwitansiDitandai as $k) {
            $this->line(sprintf('  sel %-8s kwitansi %-8s %-3s Rp %14s  %s   %s',
                $k['sel'], $k['nomor'], strtoupper($k['jenis']),
                number_format($k['nominal'], 0, ',', '.'), $k['tanggal'], $k['aksi']));
        }

        $this->newLine();
        $this->warn('  Cocokkan ke kwitansi fisik, lalu betulkan nomornya lewat UI.');
    }

    /**
     * Proses 1 row Excel.
     */
    protected function processRow(Worksheet $sh, int $r): void
    {
        // === 1. Baca semua cell yg dibutuhkan (apply parseText utk text field yg bisa kena formula error) ===
        $row = [
            'bf_nominal' => $this->parser->parseNominal($sh->getCell("AD$r")->getValue()),
            'tgl_jual' => $sh->getCell("B$r")->getValue(),
            'nama' => $this->parser->parseText($sh->getCell("C$r")->getValue()),
            'lot' => (int) $this->parser->parseNominal($sh->getCell("D$r")->getValue()),
            'no_spr_raw' => $this->parser->parseText($sh->getCell("E$r")->getValue()),
            'sales_raw' => $sh->getCell("F$r")->getValue(),
            'blok' => $this->parser->parseText($sh->getCell("G$r")->getValue()),
            'unit' => $this->parser->parseText($sh->getCell("H$r")->getValue()),
            'lb' => $this->parser->parseNominal($sh->getCell("I$r")->getValue()),
            'lt' => $this->parser->parseNominal($sh->getCell("J$r")->getValue()),
            'alamat' => $this->parser->parseText($sh->getCell("K$r")->getValue()),
            'telepon' => $this->parser->parsePhone($this->parser->parseText($sh->getCell("L$r")->getValue())),
            'npwp' => $this->parser->parseText($sh->getCell("M$r")->getValue()),
            'nik' => $this->parser->parseText($sh->getCell("N$r")->getValue()),
            'harga_jual' => $this->parser->parseNominal($sh->getCell("O$r")->getValue()),
            'biaya_samping_gang' => $this->parser->parseNominal($sh->getCell("P$r")->getValue()),
            'biaya_pinggir' => $this->parser->parseNominal($sh->getCell("Q$r")->getValue()),
            'biaya_depan' => $this->parser->parseNominal($sh->getCell("R$r")->getValue()),
            'biaya_pdam' => $this->parser->parseNominal($sh->getCell("S$r")->getValue()),
            'biaya_lain2' => $this->parser->parseNominal($sh->getCell("T$r")->getValue()),
            'biaya_tambah_ruang' => $this->parser->parseNominal($sh->getCell("U$r")->getValue()),
            'diskon_um' => $this->parser->parseNominal($sh->getCell("V$r")->getValue()),
            'permohonan_kpr' => $this->parser->parseNominal($sh->getCell("X$r")->getValue()),
            'acc_kpr' => $this->parser->parseNominal($sh->getCell("Y$r")->getValue()),
            'sbum' => $this->parser->parseNominal($sh->getCell("AA$r")->getValue()),
            'sp3k_flag' => trim((string) $sh->getCell("CH$r")->getValue()),
            'sp3k_tgl' => $sh->getCell("CI$r")->getValue(),
            'sp3k_exp' => $sh->getCell("CJ$r")->getValue(),
            'cash_kpr' => trim((string) $sh->getCell("CK$r")->getValue()),
            'tgl_akad' => $sh->getCell("CL$r")->getValue(),
            'ket' => trim((string) $sh->getCell("CN$r")->getValue()),
        ];

        // === 2. Parse ===
        $tglSpr = $this->parser->parseDate($row['tgl_jual']);
        if (! $tglSpr) {
            throw new \RuntimeException('Tanggal SPR tidak valid');
        }

        $sprNums = $this->parser->parseSprNumbers($row['no_spr_raw']);
        if (! $sprNums['active']) {
            throw new \RuntimeException('NO SPR tidak dapat diparse');
        }

        // Guard: --skip-existing mode (production-safe — data yang sudah ada di prod menang)
        //
        // Skip hanya kalau transaksinya memang sudah tercatat di tujuan:
        //   1. nomor SPR-nya sudah ada, atau
        //   2. unitnya sudah dipegang SPR aktif lain (transaksi yang sama, nomor beda).
        // Prospect/rumah yang kebetulan sudah ada BUKAN alasan skip — record prod dipakai
        // ulang apa adanya (lihat upsertProspect/upsertRumah), SPR historisnya tetap masuk.
        if ($this->option('skip-existing')) {
            $nomorSprCheck = $this->parser->formatNomorSpr($sprNums['active'], $tglSpr);
            $blokCheck = $this->parser->parseText($row['blok']);
            $unitCheck = $this->parser->parseNomorUnit($row['unit']);

            if (Spr::where('nomor_spr', $nomorSprCheck)->exists()) {
                $this->skipStats['skip_existing_spr']++;
                throw new \RuntimeException("SPR $nomorSprCheck sudah ada di DB (skip-existing)");
            }

            $rumahExisting = Rumah::where('proyek_id', $this->proyekId)
                ->where('blok', $blokCheck)->where('nomor_unit', $unitCheck)->first();

            if ($rumahExisting) {
                $sprAktif = Spr::where('rumah_id', $rumahExisting->id)
                    ->whereNotIn('status', ['batal', 'reject', 'draft'])
                    ->first();
                if ($sprAktif) {
                    $this->skipStats['skip_existing_unit_ada_spr']++;
                    throw new \RuntimeException(
                        "Unit $blokCheck-$unitCheck sudah dipegang {$sprAktif->nomor_spr} ({$sprAktif->status}) — prod menang"
                    );
                }
            }
        }

        $salesNama = $this->parser->parseSalesName($row['sales_raw']);
        $sales = $this->salesResolver->resolve($salesNama);
        if (! $sales) {
            throw new \RuntimeException("Sales tidak ditemukan: \"$salesNama\"");
        }

        [$jenisPembayaran, $bankKode] = $this->parser->parseBank($row['cash_kpr']);

        // === 3. Upsert Prospect Customer (dedup by NIK) ===
        $prospect = $this->upsertProspect($row, $sales->id);

        // === 4. Upsert Rumah (by proyek + blok + unit) ===
        $rumah = $this->upsertRumah($row);

        // === 5. Create Booking (histori) ===
        $booking = Booking::create([
            'sales_id' => $sales->id,
            'proyek_id' => $this->proyekId,
            'rumah_id' => $rumah->id,
            'prospect_customer_id' => $prospect->id,
            'tanggal_booking' => $tglSpr->toDateString(),
            'tanggal_expired' => $tglSpr->addDays(30)->toDateTimeString(),
            'status' => 'sukses',
        ]);
        $this->stats['booking_created']++;

        // === 6. Create SPR ===
        $nomorSpr = $this->parser->formatNomorSpr($sprNums['active'], $tglSpr);

        // Mapping harga:
        //   - spr.biaya_tambahan (UI: "Biaya Administrasi") = BIAYA LAIN2 (T)
        //   - Q (BIAYA PINGGIR), R (BIAYA DEPAN), U (BIAYA MENAMBAH RUANG)
        //     TIDAK masuk SPR — dipindah ke tabel biaya_tambahan_realisasi (biaya tambahan unit terpisah)
        //   - total_harga = harga_jual + biaya_lain2 - diskon_um (Q/R/U diluar)
        $biayaAdm = $row['biaya_lain2'];
        $totalHarga = $row['harga_jual'] + $biayaAdm - $row['diskon_um'];
        // dp_nominal = TOTAL_HARGA - ACC_KPR ; um_net = dp_nominal - SBUM
        $dpNominal = max(0, $totalHarga - $row['acc_kpr']);
        $umNet = max(0, $dpNominal - $row['sbum']);

        $tglAkad = $this->parser->parseDate($row['tgl_akad']);
        $status = $tglAkad ? 'akad' : 'approved';

        // Kategori: default subsidi untuk LB 30 (harga 185jt), komersial untuk LB 36+
        $kategori = ($row['lb'] >= 36) ? 'komersial' : 'subsidi';

        // Catatan pindah kavling / ganti nama untuk multi-nomor
        $catatan = null;
        if (! empty($sprNums['old'])) {
            $catatan = 'Nomor SPR sebelumnya (pindah kavling / ganti nama): '.implode(', ', $sprNums['old']);
        }
        if ($row['ket'] !== '') {
            $catatan = trim(($catatan ?? '')."\nCatatan Excel: {$row['ket']}");
        }

        $spr = Spr::create([
            'booking_id' => $booking->id,
            'sales_id' => $sales->id,
            'prospect_customer_id' => $prospect->id,
            'rumah_id' => $rumah->id,
            'kategori' => $kategori,
            'nomor_spr' => $nomorSpr,
            'tanggal_spr' => $tglSpr->toDateString(),
            'harga_jual' => $row['harga_jual'],
            'biaya_tambahan' => $biayaAdm,
            'ppn' => 0,
            'diskon' => $row['diskon_um'],
            'total_harga' => $totalHarga,
            'jenis_pembayaran' => $jenisPembayaran,
            'sbum' => $row['sbum'],
            'dp_nominal' => $dpNominal,
            'um_net' => $umNet,
            'nilai_kpr' => $row['acc_kpr'] > 0 ? $row['acc_kpr'] : $row['permohonan_kpr'],
            'tgl_akad' => $tglAkad?->toDateString(),
            // UTJ nominal ambil dari kolom AD Excel (BF/UTJ) — kalau kosong fallback 500rb default Grha Aryana
            'utj_nominal' => $row['bf_nominal'] > 0 ? $row['bf_nominal'] : 500000,
            'utj_metode' => 'transfer',
            'status' => $status,
            'catatan' => $catatan,
            'alasan_pembatalan_id' => empty($sprNums['old']) ? null : $this->alasanPindahKavlingId,
            // Workflow backfill — historis semua sudah "sampai tahap seluruhnya".
            // TTD konsumen & e-materai sudah dilakukan manual sebelum sistem baru (tanpa file digital).
            'approved_by_user_id' => $this->approverPmId,
            'pm_approved_by_user_id' => $this->approverPmId,
            'approved_at' => $tglSpr->addDays(1)->toDateTimeString(),
            'pm_approved_at' => $tglSpr->addDays(1)->toDateTimeString(),
            'konsumen_signed_at' => $tglSpr->addDays(1)->toDateTimeString(),
            'materai_stamped_at' => $tglSpr->addDays(1)->toDateTimeString(),
            'materai_by_user_id' => $this->approverPmId,
            'spr_finalized_at' => $tglSpr->addDays(1)->toDateTimeString(),
        ]);
        $this->stats['spr_created']++;

        // Rumah status di-handle SprObserver — SPR approved/akad → rumah 'terjual' auto.
        // (Konvensi sistem: 'terjual' = unit sudah di-lock oleh SPR, bukan hanya sudah akad.)

        // === 7. Create SprPemberkasan kalau ada bank/SP3K ===
        if ($bankKode || $row['sp3k_flag'] || $this->parser->parseDate($row['sp3k_tgl'])) {
            SprPemberkasan::create([
                'spr_id' => $spr->id,
                'bank_kode' => $bankKode,
                'sp3k_tanggal' => $this->parser->parseDate($row['sp3k_tgl'])?->toDateString(),
                'sp3k_expired' => $this->parser->parseDate($row['sp3k_exp'])?->toDateString(),
                'sp3k_nominal' => $row['acc_kpr'],
            ]);
            $this->stats['pemberkasan_created']++;
        }

        // === 7b. Biaya Tambahan Unit (Q + R + U) ===
        // Plafon selalu di-set di master rumah.
        // Realisasi: hanya untuk SPR yang sudah akad (asumsi: biaya tambahan lunas sebelum akad).
        // SPR belum akad → admin input realisasi manual via UI saat customer bayar.
        $biayaExtras = [
            'Biaya Pinggir' => $row['biaya_pinggir'],
            'Biaya Depan' => $row['biaya_depan'],
            'Biaya Menambah Ruangan' => $row['biaya_tambah_ruang'],
        ];
        $totalPlafonBiaya = array_sum($biayaExtras);
        if ($totalPlafonBiaya > 0) {
            $rumah->update(['biaya_tambahan' => $totalPlafonBiaya]);
            $this->stats['biaya_tambahan_plafon_set']++;

            // Kalau SPR sudah akad → realisasi biaya tambahan dianggap lunas (100%)
            if ($tglAkad) {
                foreach ($biayaExtras as $ket => $jumlah) {
                    if ($jumlah > 0) {
                        // Buku kuitansi biaya tambahan terpisah dari UM — nomornya lanjut
                        // dari isi tabel ini sendiri, jadi boleh sama dengan nomor di
                        // spr_realisasi_pembayaran tanpa dianggap bentrok.
                        $nomorKuitansi = BiayaTambahanRealisasi::generateNextNomor();
                        BiayaTambahanRealisasi::create([
                            'rumah_id' => $rumah->id,
                            'spr_id' => $spr->id,
                            'tanggal_bayar' => $tglAkad->toDateString(),
                            'nomor_kuitansi' => $nomorKuitansi,
                            'jumlah' => $jumlah,
                            'metode' => 'transfer',
                            'keterangan' => $ket,
                            'input_by_user_id' => $this->approverFinanceId,
                        ]);
                        $this->stats['biaya_tambahan_realisasi_created']++;
                    }
                }
            }
        }

        // === 8. Generate 4 termin default (BF + 4 UM + SBUM opsional) ===
        $this->generateTermin($spr, $tglSpr);

        // === 9. Create SprRealisasiPembayaran (BF + UM1..UM7) — sekaligus backfill UTJ SPR ===
        $bfInfo = $this->createRealisasi($sh, $r, $spr->id);
        if ($bfInfo) {
            $spr->update([
                'utj_tanggal_bayar' => $bfInfo['tgl'],
                'utj_tanggal_transaksi' => $bfInfo['tgl'],
                'utj_nominal_aktual' => $bfInfo['nominal'],
                'utj_confirmed_at' => Carbon::parse($bfInfo['tgl'])->setTime(12, 0)->toDateTimeString(),
                'utj_confirmed_by_user_id' => $this->approverFinanceId,
            ]);
        }
    }

    /**
     * Upsert ProspectCustomer by NIK. Kalau NIK kosong, fallback by nama.
     */
    protected function upsertProspect(array $row, int $salesId): ProspectCustomer
    {
        $nik = $this->cleanNik($row['nik']);
        $nama = $this->pickFirstName($row['nama']);

        if ($nik && isset($this->prospectByNik[$nik])) {
            return ProspectCustomer::find($this->prospectByNik[$nik]);
        }

        // Cari existing
        $prospect = null;
        if ($nik) {
            $prospect = ProspectCustomer::where('nik', $nik)->first();
        }
        if (! $prospect) {
            $prospect = ProspectCustomer::where('nama_lengkap', $nama)
                ->where('sales_id', $salesId)->first();
        }

        $data = [
            'sales_id' => $salesId,
            'proyek_id' => $this->proyekId,
            'nama_lengkap' => $nama,
            'nik' => $nik ?: null,
            'hp' => $row['telepon'] ?: null,
            'npwp' => $row['npwp'] ?: null,
            'alamat' => $row['alamat'] ?: null,
            'sumber' => 'Walk-in',
            'status' => 'finish',
        ];

        if ($prospect && $this->option('skip-existing')) {
            // Prod menang: prospect yang sudah ada dipakai apa adanya, tidak ditimpa Excel.
            $this->stats['prod_wins_prospect']++;
        } elseif ($prospect) {
            $prospect->update(array_filter($data, fn ($v) => $v !== null && $v !== ''));
            $this->stats['prospect_updated']++;
        } else {
            $prospect = ProspectCustomer::create($data);
            $this->stats['prospect_created']++;
        }

        if ($nik) {
            $this->prospectByNik[$nik] = $prospect->id;
        }

        return $prospect;
    }

    /**
     * Upsert Rumah by proyek_id + blok + nomor_unit.
     */
    protected function upsertRumah(array $row): Rumah
    {
        $blok = $row['blok'];
        $unit = $this->parser->parseNomorUnit($row['unit']);
        $key = "$blok|$unit";

        if (isset($this->rumahByBlokUnit[$key])) {
            return Rumah::find($this->rumahByBlokUnit[$key]);
        }

        $rumah = Rumah::where('proyek_id', $this->proyekId)
            ->where('blok', $blok)
            ->where('nomor_unit', $unit)
            ->first();

        // Cari tipe_rumah_id sesuai LB (default: LB=30 → tipe 1, LB=36+ → tipe 2)
        $tipeId = $this->tipeByLb[(int) $row['lb']] ?? array_values($this->tipeByLb)[0] ?? null;
        if (! $tipeId) {
            throw new \RuntimeException('Tidak ada tipe_rumah untuk LB '.$row['lb']);
        }

        $data = [
            'proyek_id' => $this->proyekId,
            'tipe_rumah_id' => $tipeId,
            'blok' => $blok,
            'nomor_unit' => $unit,
            'status' => 'available',
        ];
        // LOT dari Excel SOP kolom D (kalau ada)
        if (! empty($row['lot']) && $row['lot'] > 0) {
            $data['lot'] = $row['lot'];
        }

        if ($rumah && $this->option('skip-existing')) {
            // Prod menang: tipe & status unit prod tidak ditimpa. LOT hanya diisi kalau masih kosong
            // (kolomnya baru ada di prod, jadi ini mengisi blank — bukan menimpa).
            if (! empty($row['lot']) && $row['lot'] > 0 && ! $rumah->lot) {
                $rumah->update(['lot' => $row['lot']]);
            }
            $this->stats['prod_wins_rumah']++;
        } elseif ($rumah) {
            $updates = ['tipe_rumah_id' => $tipeId];
            if (! empty($row['lot']) && $row['lot'] > 0 && ! $rumah->lot) {
                $updates['lot'] = $row['lot'];
            }
            $rumah->update($updates);
            $this->stats['rumah_updated']++;
        } else {
            $rumah = Rumah::create($data);
            $this->stats['rumah_created']++;
        }

        $this->rumahByBlokUnit[$key] = $rumah->id;

        return $rumah;
    }

    /**
     * Tentukan nomor kwitansi yang dipakai kalau nomornya sudah ada di DB.
     *
     * Buku kwitansi manual sempat memakai satu nomor untuk dua pembayaran. Aturannya:
     * pembayaran yang tanggalnya paling baru yang diberi tanda ("00004-2"), yang lebih
     * lama tetap memegang nomor aslinya. Kalau yang sudah tercatat ternyata lebih baru,
     * justru record itu yang ditandai supaya urutan buku tetap terjaga.
     *
     * @return string Nomor yang harus dipakai pembayaran yang sedang diproses
     */
    protected function selesaikanKwitansiKembar(string $kwt, string $tglBaru, string $sel, string $jenis, float $nominal): string
    {
        $existing = SprRealisasiPembayaran::where('nomor_kwitansi', $kwt)->first();
        if (! $existing) {
            return $kwt;
        }

        $tglExisting = $existing->tanggal_bayar?->toDateString() ?? '';
        $existingLebihBaru = $tglExisting !== '' && $tglExisting > $tglBaru;

        if ($existingLebihBaru) {
            $bertanda = $this->nomorBertanda($kwt);
            $existing->update(['nomor_kwitansi' => $bertanda]);
            $dipakai = $kwt;
            $ditandai = "record lama (#{$existing->id}, $tglExisting) → $bertanda";
        } else {
            $dipakai = $this->nomorBertanda($kwt);
            $ditandai = "pembayaran ini → $dipakai";
        }

        $this->kwitansiDitandai[] = [
            'sel' => $sel,
            'nomor' => $kwt,
            'jenis' => $jenis,
            'nominal' => $nominal,
            'tanggal' => $tglBaru,
            'aksi' => $ditandai,
        ];

        return $dipakai;
    }

    /** Cari varian bertanda pertama yang belum dipakai: "00004-2", "00004-3", … */
    protected function nomorBertanda(string $kwt): string
    {
        for ($n = 2; $n < 100; $n++) {
            $kandidat = "$kwt-$n";
            if (! SprRealisasiPembayaran::where('nomor_kwitansi', $kandidat)->exists()) {
                return $kandidat;
            }
        }

        throw new \RuntimeException("Tidak bisa memberi tanda pada nomor kwitansi \"$kwt\" — sudah lebih dari 98 varian");
    }

    /**
     * Create realisasi pembayaran dari kolom AC..CD (BF/UTJ + UM1..UM17).
     * Return info BF pertama untuk backfill utj_* di SPR, atau null.
     *
     * @return array{tgl: string, nominal: float}|null
     */
    protected function createRealisasi(Worksheet $sh, int $r, int $sprId): ?array
    {
        $bfInfo = null;
        // Slot: [kwt_col, nominal_col, tgl_col, jenis]
        $slots = [
            ['AC', 'AD', 'AE', 'bf'],
            ['AF', 'AG', 'AH', 'um'],
            ['AI', 'AJ', 'AK', 'um'],
            ['AL', 'AM', 'AN', 'um'],
            ['AO', 'AP', 'AQ', 'um'],
            ['AR', 'AS', 'AT', 'um'],
            ['AU', 'AV', 'AW', 'um'],
            ['AX', 'AY', 'AZ', 'um'],
            // UM8..UM17 kosong semua di Excel — skip
        ];

        foreach ($slots as [$kwtCol, $nomCol, $tglCol, $jenis]) {
            $kwt = trim((string) $sh->getCell($kwtCol.$r)->getValue());
            $nominal = $this->parser->parseNominal($sh->getCell($nomCol.$r)->getValue());
            $tgl = $this->parser->parseTglSetor($sh->getCell($tglCol.$r)->getValue());

            if ($nominal <= 0 || ! $tgl) {
                continue;
            }

            // Nomor kwitansi kembar: pembayaran TETAP dicatat, yang tanggalnya paling baru
            // diberi tanda. Yang lebih lama memegang nomor asli sesuai urutan buku kwitansi.
            $kwtDipakai = $kwt;
            if ($kwt !== '') {
                $kwtDipakai = $this->selesaikanKwitansiKembar($kwt, $tgl->toDateString(), $kwtCol.$r, $jenis, $nominal);
            }

            SprRealisasiPembayaran::create([
                'spr_id' => $sprId,
                'jenis' => $jenis,
                'tanggal_bayar' => $tgl->toDateString(),
                'jumlah' => $nominal,
                'nomor_kwitansi' => $kwtDipakai ?: null,
                'metode' => 'transfer',
                'input_by_user_id' => $this->approverFinanceId,
            ]);
            $this->stats['realisasi_created']++;

            // Capture BF pertama untuk backfill utj_*
            if ($jenis === 'bf' && $bfInfo === null) {
                $bfInfo = ['tgl' => $tgl->toDateString(), 'nominal' => $nominal];
            }
        }

        return $bfInfo;
    }

    /**
     * Generate jadwal termin default untuk SPR historis:
     *   - 1 BF (urutan=0)
     *   - 4 UM (urutan=1..4, jumlah = (um_net - utj_nominal) / 4)
     *   - 1 SBUM (urutan=0, hanya jika sbum > 0)
     *
     * Aturan tanggal (SprJadwalTermin): anchor = tgl_spr, termin 1 = anchor + 15 hari, sisanya +1 bulan.
     */
    protected function generateTermin(Spr $spr, CarbonImmutable $tglSpr): void
    {
        // BF (UTJ)
        SprTerminPembayaran::create([
            'spr_id' => $spr->id,
            'jenis' => 'bf',
            'urutan' => 0,
            'tanggal_jadwal' => null,
            'jumlah_jadwal' => (float) $spr->utj_nominal,
            'input_by_user_id' => $this->approverFinanceId,
        ]);
        $this->stats['termin_created']++;

        // 4 UM (kalau ada UM yg perlu dijadwalkan)
        $sisaCicil = max(0, (float) $spr->um_net - (float) $spr->utj_nominal);
        if ($sisaCicil > 0 && $spr->jenis_pembayaran !== 'cash') {
            $jumlahTermin = 4;
            $perTermin = round($sisaCicil / $jumlahTermin, 0);
            $anchor = Carbon::instance($tglSpr->toDateTime());
            $jadwal = SprJadwalTermin::generate($anchor, $jumlahTermin, $perTermin);
            foreach ($jadwal as $row) {
                SprTerminPembayaran::create([
                    'spr_id' => $spr->id,
                    'jenis' => 'um',
                    'urutan' => $row['urutan'],
                    'tanggal_jadwal' => $row['tanggal']->toDateString(),
                    'jumlah_jadwal' => $row['jumlah'],
                    'input_by_user_id' => $this->approverFinanceId,
                ]);
                $this->stats['termin_created']++;
            }
        }

        // SBUM
        if ((float) $spr->sbum > 0) {
            SprTerminPembayaran::create([
                'spr_id' => $spr->id,
                'jenis' => 'sbum',
                'urutan' => 0,
                'tanggal_jadwal' => null,
                'jumlah_jadwal' => (float) $spr->sbum,
                'input_by_user_id' => $this->approverFinanceId,
            ]);
            $this->stats['termin_created']++;
        }
    }

    /** Bersihkan NIK — delegasi ke parser supaya koreksi NIK dipakai importer & preview. */
    protected function cleanNik(string $val): string
    {
        return $this->parser->parseNik($val);
    }

    /** Ambil nama pertama (suami) kalau format "SUAMI/ISTRI". */
    protected function pickFirstName(string $val): string
    {
        $s = trim($val);
        if (str_contains($s, '/')) {
            $s = trim(explode('/', $s)[0]);
        }

        return $s;
    }
}
