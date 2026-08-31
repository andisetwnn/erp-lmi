<?php

namespace App\Console\Commands\Master;

use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Selaraskan rumah.status dengan status SPR yang memegang unitnya.
 *
 * Dipakai untuk membetulkan data yang terlanjur melenceng — mis. unit yang SPR-nya sudah
 * akad tapi masih tampil 'available' karena observer versi lama hanya mengenali status
 * 'approved'. Aman dijalankan berulang; unit tanpa SPR aktif tidak disentuh.
 */
class SyncRumahStatusCommand extends Command
{
    protected $signature = 'rumah:sync-status
        {--dry-run : Tampilkan yang akan diperbaiki tanpa menyimpan}';

    protected $description = 'Betulkan rumah.status agar cocok dengan status SPR yang memegang unit';

    /** Status SPR yang berarti unitnya tidak lagi tersedia dijual. */
    private const STATUS_MENGUNCI = ['approved', 'akad'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Unit yang dipegang SPR approved/akad tapi statusnya belum 'terjual'
        $melenceng = Rumah::query()
            ->where('status', '!=', 'terjual')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('spr')
                ->whereColumn('spr.rumah_id', 'rumah.id')
                ->whereIn('spr.status', self::STATUS_MENGUNCI))
            ->orderBy('blok')->orderBy('nomor_unit')
            ->get(['id', 'blok', 'nomor_unit', 'status']);

        $this->info('SYNC STATUS RUMAH');
        $this->line('Unit yang statusnya tidak cocok dengan SPR-nya: '.$melenceng->count());
        $this->newLine();

        if ($melenceng->isEmpty()) {
            $this->info('Semua sudah selaras, tidak ada yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        foreach ($melenceng as $r) {
            $spr = Spr::where('rumah_id', $r->id)
                ->whereIn('status', self::STATUS_MENGUNCI)
                ->orderByRaw(Spr::urutanJenisSql(['akad', 'approved'], 'status'))
                ->first();

            $this->line(sprintf('  %-9s %-10s → terjual   (%s · %s)',
                $r->blok.'-'.$r->nomor_unit, $r->status, $spr?->nomor_spr ?? '?', $spr?->status ?? '?'));
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY-RUN: tidak ada perubahan disimpan.');

            return self::SUCCESS;
        }

        // updateQuietly: status sudah dihitung di sini, jangan picu observer lagi.
        $jml = Rumah::whereIn('id', $melenceng->pluck('id'))->update(['status' => 'terjual']);

        $this->newLine();
        $this->info("✓ $jml unit diperbarui jadi 'terjual'.");

        return self::SUCCESS;
    }
}
