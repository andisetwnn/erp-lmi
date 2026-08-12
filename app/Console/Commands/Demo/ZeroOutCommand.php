<?php

namespace App\Console\Commands\Demo;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Zero-out semua angka nominal untuk keperluan screenshot demo/presentasi.
 * Semua angka jadi 0 tapi struktur data tetap ada — tampilan sistem tetap
 * "berjalan" dengan tabel & baris utuh, cuma nominalnya 0.
 *
 * Ada guard APP_ENV=local — TIDAK BISA jalan di production.
 * Auto-backup DB ke storage/backups/pre-demo-{timestamp}.sql sebelum zero-out.
 * Pakai --restore untuk balikin dari backup terakhir.
 */
class ZeroOutCommand extends Command
{
    protected $signature = 'demo:zero-out {--restore : Restore dari backup terakhir}';

    protected $description = 'Zero-out semua nominal (jurnal, aktiva tetap) untuk demo/presentasi. Auto-backup.';

    protected string $backupDir = '';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('❌ Command ini DILARANG jalan di production.');

            return self::FAILURE;
        }

        $this->backupDir = storage_path('backups');
        File::ensureDirectoryExists($this->backupDir);

        return $this->option('restore') ? $this->doRestore() : $this->doZeroOut();
    }

    protected function doZeroOut(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║  ZERO-OUT ANGKA NOMINAL — untuk screenshot demo             ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Step 1: Backup DB
        $timestamp = date('Ymd-His');
        $backupFile = $this->backupDir.'/pre-demo-'.$timestamp.'.sql';

        $this->comment('[1/2] Backup DB dulu ke storage/backups/...');
        if (! $this->backupDatabase($backupFile)) {
            $this->error('Backup gagal. Aborting.');

            return self::FAILURE;
        }
        $this->info('  ✓ Backup tersimpan: '.basename($backupFile).' ('.$this->formatSize(filesize($backupFile)).')');
        $this->newLine();

        // Step 2: Zero out
        $this->comment('[2/2] Zero-out nominal...');
        DB::transaction(function () {
            $jurnal = DB::table('jurnal_detail')->update(['debet' => 0, 'kredit' => 0]);
            $this->line('  ✓ jurnal_detail : '.number_format($jurnal).' row → nominal = 0');

            $aktiva = DB::table('aktiva_tetap')->update([
                'harga_perolehan' => 0,
                'akumulasi_penyusutan' => 0,
                'nilai_residu' => 0,
            ]);
            $this->line('  ✓ aktiva_tetap  : '.number_format($aktiva).' row → nominal = 0');
        });

        $this->newLine();
        $this->info('✓ SELESAI. Sistem sekarang tampil dengan angka 0 semua.');
        $this->newLine();
        $this->warn('Setelah selesai screenshot, jalankan untuk balikin:');
        $this->line('  php artisan demo:zero-out --restore');

        return self::SUCCESS;
    }

    protected function doRestore(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║  RESTORE DB dari backup terakhir                             ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $backups = collect(File::files($this->backupDir))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'pre-demo-'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        if ($backups->isEmpty()) {
            $this->error('Tidak ada backup pre-demo-*.sql di storage/backups/');

            return self::FAILURE;
        }

        $latest = $backups->first();
        $this->comment('Restore dari: '.$latest->getFilename().' ('.$this->formatSize($latest->getSize()).')');

        if (! $this->confirm('Yakin? Data current akan di-overwrite.', true)) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        if (! $this->restoreDatabase($latest->getPathname())) {
            $this->error('Restore gagal.');

            return self::FAILURE;
        }

        $this->info('✓ SELESAI. Data sudah kembali normal.');

        return self::SUCCESS;
    }

    protected function backupDatabase(string $outFile): bool
    {
        $conn = config('database.connections.'.config('database.default'));

        $process = new Process([
            $this->findBinary('mysqldump'),
            '--host='.$conn['host'],
            '--port='.$conn['port'],
            '--user='.$conn['username'],
            '--password='.$conn['password'],
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            $conn['database'],
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('mysqldump error: '.$process->getErrorOutput());

            return false;
        }

        File::put($outFile, $process->getOutput());

        return true;
    }

    protected function restoreDatabase(string $sqlFile): bool
    {
        $conn = config('database.connections.'.config('database.default'));

        $process = Process::fromShellCommandline(sprintf(
            '"%s" --host=%s --port=%s --user=%s --password=%s %s < "%s"',
            $this->findBinary('mysql'),
            $conn['host'],
            $conn['port'],
            $conn['username'],
            $conn['password'],
            $conn['database'],
            $sqlFile,
        ));
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('mysql error: '.$process->getErrorOutput());

            return false;
        }

        return true;
    }

    /** Cari binary mysqldump/mysql — coba PATH, lalu Laragon common path. */
    protected function findBinary(string $name): string
    {
        // Try PATH first (works if mysqldump.exe/mysql.exe in system PATH)
        $binary = PHP_OS_FAMILY === 'Windows' ? $name.'.exe' : $name;

        // Laragon common paths (glob semua versi mysql-*/bin)
        if (PHP_OS_FAMILY === 'Windows') {
            $glob = glob('C:\\laragon\\bin\\mysql\\*\\bin\\'.$binary);
            if (! empty($glob)) {
                return $glob[0];
            }
        }

        // Fallback ke PATH
        return $binary;
    }

    protected function formatSize(int $bytes): string
    {
        if ($bytes > 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).' MB';
        }
        if ($bytes > 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
