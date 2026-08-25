<?php

namespace App\Console\Commands\Import;

use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\User;
use App\Services\Import\SopRowParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Append realisasi pembayaran BARU dari sheet SOP ke SPR yang SUDAH ADA di DB.
 *
 * Beda dgn `import:sop --skip-existing`:
 *   - import:sop skip SPR existing total (tidak tambah realisasi baru sekalipun ada di Excel)
 *   - import:sop-realisasi HANYA sync realisasi — SPR existing tetap, tapi cek per slot BF/UM1-UM7,
 *     kalau ada nomor kwitansi baru → tambah record realisasi
 *
 * Dedup: cek nomor_kwitansi. Kalau kwitansi sudah ada di DB (per SPR ini atau SPR lain), skip.
 *
 * Usage:
 *   php artisan import:sop-realisasi --dry-run   # preview
 *   php artisan import:sop-realisasi --force     # execute
 */
class ImportSopRealisasiCommand extends Command
{
    protected $signature = 'import:sop-realisasi
        {--file=DATA MASTER GRHA ARYANA.xlsx : Path xlsx sumber}
        {--sheet=SOP : Nama sheet}
        {--start-row=8 : Baris awal data}
        {--dry-run : Preview tanpa write ke DB}
        {--force : Skip konfirmasi}';

    protected $description = 'Sync realisasi pembayaran (BF + UM1..UM7) dari sheet SOP ke SPR existing di DB';

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        $parser = new SopRowParser;

        $file = base_path((string) $this->option('file'));
        if (! file_exists($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $uliId = User::where('username', 'uli')->value('id');

        $this->info('╔══════════════════════════════════════════════════════╗');
        $this->info('║  SYNC REALISASI — append record baru ke SPR existing ║');
        $this->info('╚══════════════════════════════════════════════════════╝');
        $this->line("File : $file · Sheet: {$this->option('sheet')}");
        $this->line('Mode : '.($dryRun ? 'DRY-RUN' : 'WRITE'));
        $this->newLine();

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Lanjut sync realisasi?', true)) {
            return self::FAILURE;
        }

        $ss = IOFactory::load($file);
        $sh = $ss->getSheetByName((string) $this->option('sheet'));
        if (! $sh) {
            $this->error('Sheet tidak ditemukan.');

            return self::FAILURE;
        }

        // Prewarm: index all existing kwitansi supaya dedup fast
        $existingKwitansi = SprRealisasiPembayaran::whereNotNull('nomor_kwitansi')
            ->pluck('nomor_kwitansi')->flip()->toArray();
        $this->line('Existing kwitansi di DB: '.count($existingKwitansi));

        // Slot per row: [kwt_col, nominal_col, tgl_col, jenis]
        $slots = [
            ['AC', 'AD', 'AE', 'bf'],
            ['AF', 'AG', 'AH', 'um'],
            ['AI', 'AJ', 'AK', 'um'],
            ['AL', 'AM', 'AN', 'um'],
            ['AO', 'AP', 'AQ', 'um'],
            ['AR', 'AS', 'AT', 'um'],
            ['AU', 'AV', 'AW', 'um'],
            ['AX', 'AY', 'AZ', 'um'],
        ];

        $stats = ['spr_scanned' => 0, 'spr_not_found' => 0, 'realisasi_created' => 0, 'skip_duplicate' => 0];
        $warnings = [];

        DB::beginTransaction();
        try {
            $startRow = (int) $this->option('start-row');
            $endRow = $sh->getHighestRow();
            $bar = $this->output->createProgressBar($endRow - $startRow + 1);
            $bar->start();

            for ($r = $startRow; $r <= $endRow; $r++) {
                $bar->advance();

                $noSprRaw = trim((string) $sh->getCell("E$r")->getValue());
                if ($noSprRaw === '') {
                    continue;
                }

                $sprNums = $parser->parseSprNumbers($parser->parseText($noSprRaw));
                if (! $sprNums['active']) {
                    continue;
                }

                $spr = Spr::where('nomor_spr', 'like', '%/'.$sprNums['active'])->first();
                if (! $spr) {
                    $stats['spr_not_found']++;
                    $warnings[] = "R$r: SPR {$sprNums['active']} tidak ditemukan di DB";

                    continue;
                }
                $stats['spr_scanned']++;

                foreach ($slots as [$kwtCol, $nomCol, $tglCol, $jenis]) {
                    $kwt = trim((string) $sh->getCell($kwtCol.$r)->getValue());
                    $nominal = $parser->parseNominal($sh->getCell($nomCol.$r)->getValue());
                    $tgl = $parser->parseTglSetor($sh->getCell($tglCol.$r)->getValue());

                    if ($nominal <= 0 || ! $tgl) {
                        continue;
                    }

                    // Dedup: kwitansi sudah ada?
                    if ($kwt !== '' && isset($existingKwitansi[$kwt])) {
                        $stats['skip_duplicate']++;

                        continue;
                    }

                    SprRealisasiPembayaran::create([
                        'spr_id' => $spr->id,
                        'jenis' => $jenis,
                        'tanggal_bayar' => $tgl->toDateString(),
                        'jumlah' => $nominal,
                        'nomor_kwitansi' => $kwt ?: null,
                        'metode' => 'transfer',
                        'input_by_user_id' => $uliId,
                    ]);
                    $stats['realisasi_created']++;

                    // Update index supaya intra-row juga dedup
                    if ($kwt !== '') {
                        $existingKwitansi[$kwt] = true;
                    }
                }
            }
            $bar->finish();
            $this->newLine(2);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: rollback.');
            } else {
                DB::commit();
                $this->info('✓ COMMIT sukses.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('FATAL: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== STATS ===');
        foreach ($stats as $k => $v) {
            $this->line("  $k: $v");
        }
        if ($warnings) {
            $this->newLine();
            $this->warn('WARNINGS ('.count($warnings).'):');
            foreach (array_slice($warnings, 0, 10) as $w) {
                $this->line("  $w");
            }
        }

        return self::SUCCESS;
    }
}
