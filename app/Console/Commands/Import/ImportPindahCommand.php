<?php

namespace App\Console\Commands\Import;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Spr;
use App\Models\User;
use App\Services\Import\ExcelSourceLoader;
use App\Services\Import\NameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Import data pindah kavling dari DATA_PUSAT sheet GANTI NAMA & PINDAH KAV (51 rows).
 *
 * MVP simplification: mark SPR asal sbg cancelled dgn alasan "Pindah Kavling".
 * TIDAK bikin SprSwitching + SPR baru — karena info unit tujuan tidak selalu
 * jelas di file. Kalau user butuh, bisa manual re-input SPR baru lewat UI
 * pakai fitur Pindah Kavling.
 *
 * Rules:
 * - Lookup SPR by nama + BLOK+NO
 * - Set status='cancelled', alasan_pembatalan_id="Pindah Kavling", refund fields
 * - Prospect customer tetap 'finish'
 *
 * Usage:
 *   php artisan import:pindah --dry-run
 *   php artisan import:pindah
 */
class ImportPindahCommand extends Command
{
    protected $signature = 'import:pindah
        {--dry-run}
        {--force}';

    protected $description = 'Import data pindah kavling dari Excel legacy (mark SPR asal sbg cancelled)';

    protected ExcelSourceLoader $loader;

    protected NameMatcher $matcher;

    protected bool $dryRun = false;

    public function handle(ExcelSourceLoader $loader, NameMatcher $matcher): int
    {
        $this->loader = $loader;
        $this->matcher = $matcher;
        $this->dryRun = (bool) $this->option('dry-run');

        $this->info('== IMPORT PINDAH KAVLING — Grha Aryana ==');
        if ($this->dryRun) {
            $this->warn('MODE: DRY-RUN');
        }

        if (! $this->dryRun && ! $this->option('force') && ! $this->confirm('Lanjut import pindah?', true)) {
            return self::FAILURE;
        }

        // Pastikan alasan "Pindah Kavling" ada
        $alasanPindah = AlasanPembatalan::firstOrCreate(
            ['nama' => 'Pindah Kavling'],
            ['dapat_meneruskan_angsuran' => true, 'is_aktif' => true],
        );

        DB::beginTransaction();
        try {
            $stats = $this->importPindah($alasanPindah->id);

            if ($this->dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('[DRY-RUN] Rollback.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('✓ Import pindah selesai (committed).');
                $this->comment('CATATAN: SPR baru untuk unit tujuan tidak auto-generate.');
                $this->comment('User perlu re-input SPR baru lewat UI atau pakai fitur Pindah Kavling.');
            }
            $this->printStats($stats);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function importPindah(int $alasanPindahId): array
    {
        $userFinanceId = User::whereHas('roles', fn ($q) => $q->where('name', 'finance'))
            ->orderBy('id')->value('id') ?? 1;

        // Pre-load SPR
        $sprMap = [];
        Spr::with(['prospectCustomer:id,nama_lengkap', 'rumah:id,blok,nomor_unit'])
            ->whereIn('status', ['approved', 'akad'])
            ->get(['id', 'prospect_customer_id', 'rumah_id'])
            ->each(function ($s) use (&$sprMap) {
                if (! $s->prospectCustomer || ! $s->rumah) {
                    return;
                }
                $key = $this->matcher->normalize($s->prospectCustomer->nama_lengkap).'|'.strtoupper($s->rumah->blok).'-'.$s->rumah->nomor_unit;
                $sprMap[$key] = $s->id;
            });

        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'GANTI NAMA & PINDAH KAV');
        $rows = $this->loader->rows($sheet, headerRow: 3, columns: [
            'nama' => 'C',
            'sales' => 'D',
            'tgl_jual' => 'E',
            'type' => 'F',
            'blok' => 'G',
            'no_unit' => 'H',
            'total_harga' => 'I',
            'akumulasi' => 'K',
            'kembali' => 'M',
            'ket' => 'N',
        ]);

        $stats = ['total_baris' => 0, 'updated' => 0, 'skipped_spr_not_found' => 0];
        $unmatched = [];

        foreach ($rows as $r => $d) {
            $stats['total_baris']++;
            if ($d['nama'] === '' || $d['blok'] === '') {
                continue;
            }

            $normNama = $this->matcher->normalize($d['nama']);
            $unit = strtoupper($d['blok']).'-'.$d['no_unit'];
            $sprId = $sprMap[$normNama.'|'.$unit] ?? null;

            if (! $sprId) {
                $stats['skipped_spr_not_found']++;
                $unmatched[] = "{$d['nama']} | $unit → {$d['ket']}";

                continue;
            }

            // Safety: jangan cancel kalau ini satu-satunya SPR aktif customer.
            // PINDAH sheet kadang punya entry unit lama yg sebenarnya = unit
            // sekarang (data noise). Cancel akan bikin customer tanpa SPR aktif.
            $spr = Spr::find($sprId);
            if ($spr) {
                $otherActive = Spr::where('prospect_customer_id', $spr->prospect_customer_id)
                    ->where('id', '!=', $sprId)
                    ->whereIn('status', ['approved', 'akad'])
                    ->exists();
                if (! $otherActive) {
                    $stats['skipped_spr_not_found']++;
                    $unmatched[] = "{$d['nama']} | $unit → SKIP (only active SPR)";

                    continue;
                }
            }

            $kembali = $this->loader->parseNumber($d['kembali']);

            if ($this->dryRun) {
                $stats['updated']++;

                continue;
            }

            Spr::where('id', $sprId)->update([
                'status' => 'cancelled',
                'alasan_pembatalan_id' => $alasanPindahId,
                'cancel_keterangan' => 'Pindah Kavling: '.$d['ket'],
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $userFinanceId,
                'refund_status' => $kembali > 0 ? 'partial' : 'tidak_ada_refund',
                'refund_amount' => $kembali,
                'refund_at' => $kembali > 0 ? now()->toDateString() : null,
                'refund_keterangan' => 'Import legacy: pindah kavling',
            ]);

            $stats['updated']++;
        }

        if (! $this->dryRun && count($unmatched) > 0) {
            Storage::disk('local')->makeDirectory('import-log');
            $ts = now()->format('Ymd_His');
            Storage::disk('local')->put("import-log/pindah-{$ts}-not-found.csv", "NAMA_UNIT_KET\n".implode("\n", $unmatched));
        }

        return $stats;
    }

    protected function printStats(array $stats): void
    {
        $this->newLine();
        $this->info('== STATISTIK IMPORT PINDAH ==');
        foreach ($stats as $key => $val) {
            $this->line(sprintf('  %-30s %d', $key, $val));
        }
    }
}
