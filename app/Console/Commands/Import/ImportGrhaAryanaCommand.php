<?php

namespace App\Console\Commands\Import;

use Illuminate\Console\Command;

/**
 * Orchestrator — jalankan semua fase import Grha Aryana berurutan.
 *
 * Urutan wajib:
 *   1. import:master     (bank, alasan, rumah)
 *   2. import:customer   (prospect_customer + KTP)
 *   3. import:spr        (SPR + booking)
 *   4. import:kwitansi   (realisasi pembayaran)
 *   5. import:batal      (update SPR cancelled)
 *   6. import:pindah     (mark SPR asal sbg cancelled dgn alasan Pindah Kavling)
 *
 * Kalau ada fase yg gagal, orchestrator berhenti (fase berikutnya tidak dijalankan).
 *
 * Usage:
 *   php artisan import:grha-aryana --dry-run
 *   php artisan import:grha-aryana
 *   php artisan import:grha-aryana --skip=pindah,batal
 */
class ImportGrhaAryanaCommand extends Command
{
    protected $signature = 'import:grha-aryana
        {--dry-run : Preview semua fase tanpa write ke DB}
        {--force : Skip semua konfirmasi}
        {--skip= : Fase yg di-skip, comma-separated (mis. --skip=pindah,batal)}';

    protected $description = 'Orchestrator: jalankan semua fase import Grha Aryana berurutan';

    public function handle(): int
    {
        // Import legacy load banyak Excel (~5 file total dgn 1000+ rows) → butuh memory besar
        ini_set('memory_limit', '2G');

        $phases = [
            'master' => 'import:master',
            'customer' => 'import:customer',
            'spr' => 'import:spr',
            'kwitansi' => 'import:kwitansi',
            'batal' => 'import:batal',
            'pindah' => 'import:pindah',
        ];

        $skip = array_filter(array_map('trim', explode(',', (string) $this->option('skip'))));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  IMPORT LEGACY GRHA ARYANA — 6 FASE                       ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        if ($dryRun) {
            $this->warn('MODE: DRY-RUN — semua fase preview tanpa write');
        }
        if ($skip) {
            $this->warn('Skip: '.implode(', ', $skip));
        }

        if (! $dryRun && ! $force && ! $this->confirm('Lanjut import semua fase ke DB?', true)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $totalPhases = count($phases) - count(array_intersect(array_keys($phases), $skip));
        $current = 0;

        foreach ($phases as $key => $command) {
            if (in_array($key, $skip)) {
                $this->line("[SKIP] $command");

                continue;
            }

            $current++;
            $this->newLine();
            $this->line('────────────────────────────────────────');
            $this->info("FASE $current/$totalPhases: $command");
            $this->line('────────────────────────────────────────');

            $options = ['--force' => true];
            if ($dryRun) {
                $options['--dry-run'] = true;
            }

            $exitCode = $this->call($command, $options);

            if ($exitCode !== self::SUCCESS) {
                $this->error("❌ Fase $command GAGAL. Orchestrator berhenti.");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  ✓ SEMUA FASE IMPORT SELESAI                              ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->comment('Log unmatched: storage/app/private/import-log/');

        if (! $dryRun) {
            $this->newLine();
            $this->comment('Langkah berikutnya:');
            $this->comment('  1. Verify di UI: buka /marketing/spr/data, cek jumlah SPR = ~213');
            $this->comment('  2. Reset LEGACY_MAX_NOMOR_* di .env ke 0 (sekarang pakai DB MAX)');
            $this->comment('  3. php artisan config:clear');
        }

        return self::SUCCESS;
    }
}
