<?php

namespace App\Console\Commands\Import;

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Services\Import\ExcelSourceLoader;
use App\Services\Import\NameMatcher;
use App\Services\Import\NikParser;
use App\Services\Import\SalesResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Import prospect_customer dari DATA_PENJUALAN JUAL (216 rows) + merge dgn:
 * - DATA_KONSUMEN (pekerjaan, gaji, BI kol, kontak darurat)
 * - KTP.zip (attach file KTP kalau match)
 *
 * Rules (per konfirmasi user):
 * - Semua customer status = 'finish' (tidak peduli SPR-nya batal atau tidak)
 * - NIK di-parse → provinsi_code, provinsi_nama, kota_code, tanggal_lahir, jenis_kelamin
 * - 5 KTP orphan (CHRISTIANA SUGONDO, FANI, MAHARINI, NOVA, RAIHAN ADETYO) di-skip
 *
 * Usage:
 *   php artisan import:customer --dry-run
 *   php artisan import:customer
 */
class ImportCustomerCommand extends Command
{
    protected $signature = 'import:customer
        {--dry-run : Preview tanpa write ke DB}
        {--force : Skip konfirmasi}';

    protected $description = 'Import prospect_customer dari Excel legacy Grha Aryana + attach KTP file';

    protected ExcelSourceLoader $loader;

    protected NikParser $nikParser;

    protected NameMatcher $matcher;

    protected SalesResolver $salesResolver;

    protected bool $dryRun = false;

    protected array $ktpMap = []; // normalized name → ['folder' => X, 'file' => Y, 'ext' => Z]

    public function handle(
        ExcelSourceLoader $loader,
        NikParser $nikParser,
        NameMatcher $matcher,
        SalesResolver $salesResolver,
    ): int {
        $this->loader = $loader;
        $this->nikParser = $nikParser;
        $this->matcher = $matcher;
        $this->salesResolver = $salesResolver;
        $this->dryRun = (bool) $this->option('dry-run');

        $this->info('== IMPORT CUSTOMER — Grha Aryana ==');
        if ($this->dryRun) {
            $this->warn('MODE: DRY-RUN — tidak akan write ke DB atau extract KTP');
        }

        if (! $this->dryRun && ! $this->option('force') && ! $this->confirm('Lanjut import customer ke DB?', true)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $proyek = Proyek::where('nama_proyek', 'Grha Aryana')->first() ?? Proyek::first();
        if (! $proyek) {
            $this->error('Master proyek kosong. Jalankan ProyekSeeder dulu.');

            return self::FAILURE;
        }

        // 1. Load KTP mapping dari zip
        $this->loadKtpMap();

        // 2. Load data tambahan dari DATA_KONSUMEN (pekerjaan, gaji, BI)
        $konsumenExtras = $this->loadKonsumenExtras();

        // 3. Import customer dari DATA_PENJUALAN JUAL
        DB::beginTransaction();
        try {
            $stats = $this->importCustomers($proyek, $konsumenExtras);

            if ($this->dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('[DRY-RUN] Semua perubahan di-rollback.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('✓ Import customer selesai (committed).');
            }

            $this->printStats($stats);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Import gagal: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Load KTP.zip → mapping normalized nama → file info. */
    protected function loadKtpMap(): void
    {
        $zipPath = storage_path('app/KTP.zip');
        if (! file_exists($zipPath)) {
            $this->warn('KTP.zip tidak ditemukan di storage/app/. Skip attach KTP.');

            return;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Gagal buka KTP.zip');

            return;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));
            if (str_ends_with($name, '/') || ! preg_match('/\.(pdf|jpg|jpeg|png)$/i', $name)) {
                continue;
            }
            $folder = trim(dirname($name), '/');
            // Strip "LEGAL " prefix
            $folderClean = preg_replace('/^LEGAL\s+/i', '', $folder);
            $normalized = $this->matcher->normalize($folderClean);
            $this->ktpMap[$normalized] = [
                'folder' => $folder,
                'file_in_zip' => $name,
                'basename' => basename($name),
                'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            ];
        }
        $zip->close();

        $this->comment('KTP.zip loaded: '.count($this->ktpMap).' folder unique.');
    }

    /** Load pekerjaan, gaji, BI kol, kontak darurat dari DATA_KONSUMEN. */
    protected function loadKonsumenExtras(): array
    {
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'Data Detail');
        $extras = [];
        $rows = $this->loader->rows($sheet, headerRow: 3, columns: [
            'nama' => 'B',
            'pekerjaan' => 'G',
            'kondar_nama' => 'H',
            'kondar_hp' => 'I',
            'gaji' => 'J',
            'bi_kol' => 'K',
        ]);
        foreach ($rows as $r => $d) {
            if ($d['nama'] === '') {
                continue;
            }
            $key = $this->matcher->normalize($d['nama']);
            $extras[$key] = [
                'pekerjaan' => $d['pekerjaan'] ?: null,
                'kondar_nama' => $d['kondar_nama'] ?: null,
                'kondar_hp' => $d['kondar_hp'] ?: null,
                'gaji' => $this->loader->parseNumber($d['gaji']),
                'bi_kol' => $this->parseBiKol($d['bi_kol']),
            ];
        }

        return $extras;
    }

    protected function parseBiKol(?string $val): ?string
    {
        if (! $val) {
            return null;
        }
        // "KOL 1", "KOL 2" → "1", "2"
        if (preg_match('/(\d+)/', $val, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function importCustomers(Proyek $proyek, array $konsumenExtras): array
    {
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'JUAL');
        $rows = $this->loader->rows($sheet, headerRow: 3, columns: [
            'tgl_jual' => 'B',
            'nama' => 'G',
            'alamat' => 'I',
            'hp' => 'J',
            'sales' => 'K',
            'npwp' => 'L',
            'nik' => 'M',
            'sumber' => 'O',
        ]);

        $stats = [
            'total_baris' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_no_nama' => 0,
            'nik_valid' => 0,
            'nik_invalid' => 0,
            'sales_matched' => 0,
            'sales_unmatched' => 0,
            'ktp_matched' => 0,
            'ktp_missing' => 0,
        ];

        $unmatchedSales = [];
        $ktpMissing = [];

        foreach ($rows as $r => $d) {
            $stats['total_baris']++;
            if ($d['nama'] === '') {
                $stats['skipped_no_nama']++;

                continue;
            }

            // Parse NIK
            $parsed = $this->nikParser->parse($d['nik']);
            if ($parsed['valid']) {
                $stats['nik_valid']++;
            } else {
                $stats['nik_invalid']++;
            }

            // Resolve sales
            $sales = $this->salesResolver->resolve($d['sales']);
            if ($sales) {
                $stats['sales_matched']++;
            } else {
                $stats['sales_unmatched']++;
                $unmatchedSales[$d['sales']] = ($unmatchedSales[$d['sales']] ?? 0) + 1;
            }

            // Cari data tambahan dari DATA_KONSUMEN
            $normKey = $this->matcher->normalize($d['nama']);
            $extra = $konsumenExtras[$normKey] ?? [];

            // KTP file
            $ktpInfo = $this->ktpMap[$normKey] ?? null;
            $fotoKtpPath = null;
            if ($ktpInfo) {
                $stats['ktp_matched']++;
                $fotoKtpPath = 'customer/ktp/'.$ktpInfo['basename'];
                if (! $this->dryRun) {
                    $this->extractKtp($ktpInfo, $fotoKtpPath);
                }
            } else {
                $stats['ktp_missing']++;
                $ktpMissing[] = $d['nama'];
            }

            $payload = [
                'sales_id' => $sales?->id,
                'proyek_id' => $proyek->id,
                'nama_lengkap' => $d['nama'],
                'hp' => $d['hp'] ?: '-', // NOT NULL — legacy kadang kosong
                'hp_2' => $extra['kondar_hp'] ?? null,
                'sumber' => $d['sumber'] ?: 'Legacy Excel',
                'nik' => $d['nik'] ?: null,
                'tempat_lahir' => null, // tidak ada di legacy
                'tanggal_lahir' => $parsed['tanggal_lahir'],
                'jenis_kelamin' => $parsed['jenis_kelamin'],
                'agama' => null, // tidak ada
                'status_perkawinan' => str_contains($d['nama'], '/') ? 'Kawin' : null,
                'pekerjaan_ktp' => $extra['pekerjaan'] ?? null,
                'penghasilan_bulanan' => $extra['gaji'] ?? 0,
                'npwp' => $d['npwp'] ?: null,
                'foto_ktp' => $fotoKtpPath,
                'bi_kol' => $extra['bi_kol'] ?? null,
                'alamat' => $d['alamat'] ?: null,
                'provinsi_code' => $parsed['provinsi_code'],
                'provinsi_nama' => $parsed['provinsi_nama'],
                'kota_code' => $parsed['kota_code'],
                'status' => 'finish', // per rules, semua di-import = finish
                'catatan' => 'Import legacy Grha Aryana 31 Juli 2026',
            ];

            // Upsert by NIK kalau ada, else by nama
            $lookup = $d['nik'] ? ['nik' => $d['nik']] : ['nama_lengkap' => $d['nama'], 'proyek_id' => $proyek->id];
            $existing = ProspectCustomer::where($lookup)->first();

            if ($this->dryRun) {
                $existing ? $stats['updated']++ : $stats['created']++;

                continue;
            }

            if ($existing) {
                $existing->update($payload);
                $stats['updated']++;
            } else {
                ProspectCustomer::create($payload);
                $stats['created']++;
            }
        }

        // Save reports unmatched
        // Post-process: customer di UANG MUKA yg belum ada di JUAL → bikin customer minimal
        $stats['created_from_uang_muka'] = $this->importCustomersFromUangMuka($proyek);

        $this->saveUnmatchedReport($unmatchedSales, $ktpMissing);

        return $stats;
    }

    /**
     * Iterate sheet UANG MUKA. Kalau NAMA customer belum ada di DB (tidak muncul di JUAL),
     * bikin prospect_customer minimal dari data UANG MUKA (nama, sales, unit, tgl).
     * Field NIK/NPWP/alamat/HP tidak tersedia di UANG MUKA — set default null/dash.
     */
    protected function importCustomersFromUangMuka(Proyek $proyek): int
    {
        // Build alias map dari GANTI NAMA sheet: OLD_NAME → NEW_NAME (normalized).
        // Kasus: RAIHAN ADETYO/ADE IRMAWATI (UANG MUKA) sebenarnya = ADE IRMAWATI
        // (JUAL) setelah ganti nama primary. Tanpa alias, jadi 2 customer duplikat.
        $aliasOldToNew = [];
        $gantiSheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'GANTI NAMA & PINDAH KAV');
        $gantiRows = $this->loader->rows($gantiSheet, headerRow: 3, columns: [
            'nama_baru' => 'C',
            'nama_lama' => 'D',
        ]);
        foreach ($gantiRows as $r => $g) {
            if ($g['nama_baru'] === '' || $g['nama_lama'] === '') {
                continue;
            }
            if (strtoupper($g['nama_baru']) === 'NAMA' || strtoupper($g['nama_lama']) === 'LAMA') {
                continue;
            }
            $normLama = $this->matcher->normalize($g['nama_lama']);
            $normBaru = $this->matcher->normalize($g['nama_baru']);
            if ($normLama && $normBaru && $normLama !== $normBaru) {
                $aliasOldToNew[$normLama] = $normBaru;
            }
        }

        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'UANG MUKA');
        $rows = $this->loader->rows($sheet, headerRow: 2, columns: [
            'nama' => 'B',
            'blok' => 'C',
            'no_unit' => 'D',
            'sales' => 'E',
            'tanggal' => 'F',
            'nomor_spr' => 'AA',
        ]);

        $created = 0;
        foreach ($rows as $r => $d) {
            if ($d['nama'] === '' || $d['nomor_spr'] === '') {
                continue;
            }
            // Skip header row / junk row
            if (strtoupper($d['nama']) === 'NAMA' || strlen($d['nama']) < 3) {
                continue;
            }
            // Skip kalau customer sudah ada di DB (dari JUAL) — compare NORMALIZED.
            // Juga cek alias OLD→NEW dari GANTI NAMA: kalau nama UANG MUKA (normalized)
            // == old name yg sudah pindah, gunakan NEW name utk cek existence.
            $normUm = $this->matcher->normalize($d['nama']);
            $lookupNorm = $aliasOldToNew[$normUm] ?? $normUm;
            $exists = ProspectCustomer::where('proyek_id', $proyek->id)
                ->get(['id', 'nama_lengkap'])
                ->contains(fn ($c) => $this->matcher->normalize($c->nama_lengkap) === $lookupNorm);
            if ($exists) {
                continue;
            }

            if ($this->dryRun) {
                $created++;

                continue;
            }

            $sales = $this->salesResolver->resolve($d['sales']);

            // Cari data extras dari sheet lain (KWITANSI untuk bank/via, kalau ada)
            ProspectCustomer::create([
                'sales_id' => $sales?->id ?? 1, // fallback ke sales 1 kalau tidak ketemu
                'proyek_id' => $proyek->id,
                'nama_lengkap' => $d['nama'],
                'hp' => '-',
                'sumber' => 'Legacy Excel (UANG MUKA)',
                'status' => 'finish',
                'catatan' => 'Import legacy Grha Aryana 31 Juli 2026 — data minimal dari UANG MUKA (belum ada NIK/HP/alamat)',
            ]);
            $created++;
        }
        if ($created > 0) {
            $this->comment("Customer supplementary dari UANG MUKA: $created (minimal — perlu update NIK/HP/alamat manual)");
        }

        return $created;
    }

    protected function extractKtp(array $ktpInfo, string $targetPath): void
    {
        $zipPath = storage_path('app/KTP.zip');
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return;
        }

        $content = $zip->getFromName($ktpInfo['file_in_zip']);
        $zip->close();
        if ($content === false) {
            return;
        }

        Storage::disk('public')->put($targetPath, $content);
    }

    protected function saveUnmatchedReport(array $unmatchedSales, array $ktpMissing): void
    {
        if ($this->dryRun) {
            return;
        }

        Storage::disk('local')->makeDirectory('import-log');
        $ts = now()->format('Ymd_His');

        // Sales unmatched
        if (count($unmatchedSales) > 0) {
            $csv = "SALES_NAME,COUNT\n";
            foreach ($unmatchedSales as $s => $c) {
                $csv .= "\"$s\",$c\n";
            }
            Storage::disk('local')->put("import-log/customer-{$ts}-unmatched-sales.csv", $csv);
        }

        // KTP missing
        if (count($ktpMissing) > 0) {
            $csv = "NAMA_CUSTOMER\n";
            foreach ($ktpMissing as $n) {
                $csv .= "\"$n\"\n";
            }
            Storage::disk('local')->put("import-log/customer-{$ts}-ktp-missing.csv", $csv);
        }
    }

    protected function printStats(array $stats): void
    {
        $this->newLine();
        $this->info('== STATISTIK IMPORT CUSTOMER ==');
        foreach ($stats as $key => $val) {
            $this->line(sprintf('  %-25s %d', $key, $val));
        }
    }
}
