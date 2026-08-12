<?php

namespace App\Console\Commands\Spr;

use App\Models\Master\Spr;
use App\Support\SprJadwalTermin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill data SPR historis ke aturan baru (Aug 2026):
 * - tanggal_spr = utj_tanggal_transaksi (tgl transfer UTJ aktual dari Finance)
 * - jadwal cicilan UM diregenerasi: termin ke-1 = anchor + 15 hari,
 *   termin ke-N = termin sebelumnya + 1 bulan
 *
 * Jalankan --dry-run dulu untuk preview, baru --force untuk commit.
 * Aman diulang berkali-kali (idempotent — hanya update yg berbeda).
 */
class BackfillJadwalTerminCommand extends Command
{
    protected $signature = 'spr:backfill-jadwal
        {--dry-run : Preview saja, tidak commit ke DB}
        {--force : Skip konfirmasi}';

    protected $description = 'Backfill tgl_spr & jadwal termin UM SPR historis ke aturan baru (UTJ+15h)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║  BACKFILL JADWAL TERMIN SPR — aturan baru (UTJ+15h)          ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        if ($dryRun) {
            $this->warn('MODE: DRY-RUN (tidak commit apapun)');
        } else {
            $this->warn('MODE: LIVE — perubahan akan di-commit ke database');
            if (! $this->option('force') && ! $this->confirm('Lanjutkan?', false)) {
                $this->info('Dibatalkan.');

                return self::SUCCESS;
            }
        }
        $this->newLine();

        // Ambil semua SPR yang sudah punya utj_tanggal_transaksi
        // (Finance sudah konfirmasi UTJ). Skip cancelled.
        $sprList = Spr::query()
            ->whereNotNull('utj_tanggal_transaksi')
            ->where('status', '!=', 'cancelled')
            ->with(['terminPembayaran' => fn ($q) => $q->where('jenis', 'um')->orderBy('urutan')])
            ->orderBy('id')
            ->get();

        $this->info("Ditemukan {$sprList->count()} SPR eligible (approved + UTJ terkonfirmasi).");
        $this->newLine();

        $sprUpdated = 0;
        $sprSkipped = 0;
        $terminUpdated = 0;
        $terminSkipped = 0;
        $sampleChanges = [];

        $callback = function () use ($sprList, &$sprUpdated, &$sprSkipped, &$terminUpdated, &$terminSkipped, &$sampleChanges, $dryRun) {
            foreach ($sprList as $spr) {
                $newTglSpr = optional($spr->utj_tanggal_transaksi)->toDateString();
                $oldTglSpr = optional($spr->tanggal_spr)->toDateString();

                $sprChanged = false;
                if ($oldTglSpr !== $newTglSpr) {
                    $sprChanged = true;
                    if (! $dryRun) {
                        $spr->update(['tanggal_spr' => $newTglSpr]);
                    }
                    $sprUpdated++;
                } else {
                    $sprSkipped++;
                }

                $anchor = SprJadwalTermin::toAnchor($newTglSpr);
                if (! $anchor) {
                    continue;
                }

                $terminChangesForThisSpr = [];
                foreach ($spr->terminPembayaran as $t) {
                    $newTgl = SprJadwalTermin::tanggalTermin($anchor, (int) $t->urutan);
                    $oldTgl = optional($t->tanggal_jadwal)->toDateString();
                    $newTglStr = $newTgl->toDateString();

                    if ($oldTgl !== $newTglStr) {
                        if (! $dryRun) {
                            $t->update(['tanggal_jadwal' => $newTglStr]);
                        }
                        $terminUpdated++;
                        $terminChangesForThisSpr[] = "T{$t->urutan}: {$oldTgl} → {$newTglStr}";
                    } else {
                        $terminSkipped++;
                    }
                }

                // Simpan sample 5 perubahan pertama untuk display
                if (($sprChanged || ! empty($terminChangesForThisSpr)) && count($sampleChanges) < 5) {
                    $sampleChanges[] = [
                        'spr' => $spr->nomor_display ?? "#{$spr->id}",
                        'tgl_spr' => "{$oldTglSpr} → {$newTglSpr}",
                        'termin' => $terminChangesForThisSpr,
                    ];
                }
            }
        };

        if ($dryRun) {
            $callback();
        } else {
            DB::transaction($callback);
        }

        $this->newLine();
        $this->info('═══════════════════════ RINGKASAN ═══════════════════════');
        $this->line('  tanggal_spr diubah      : '.number_format($sprUpdated).' SPR');
        $this->line('  tanggal_spr sudah pas   : '.number_format($sprSkipped).' SPR');
        $this->line('  tanggal_jadwal diubah   : '.number_format($terminUpdated).' termin');
        $this->line('  tanggal_jadwal sudah pas: '.number_format($terminSkipped).' termin');
        $this->newLine();

        if (! empty($sampleChanges)) {
            $this->line('Contoh perubahan (5 SPR pertama):');
            foreach ($sampleChanges as $s) {
                $this->line("  ▸ SPR {$s['spr']}");
                $this->line("     tgl_spr: {$s['tgl_spr']}");
                foreach ($s['termin'] as $t) {
                    $this->line("     {$t}");
                }
            }
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('Ini DRY-RUN — tidak ada yg berubah. Jalankan tanpa --dry-run untuk commit.');
        } else {
            $this->info('✓ Selesai. Semua SPR historis sudah pakai aturan jadwal baru.');
        }

        return self::SUCCESS;
    }
}
