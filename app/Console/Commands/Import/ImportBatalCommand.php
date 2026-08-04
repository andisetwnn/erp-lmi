<?php

namespace App\Console\Commands\Import;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Proyek;
use App\Models\Master\Spr;
use App\Models\User;
use App\Services\Import\ExcelSourceLoader;
use App\Services\Import\NameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Import data batal dari DATA_PUSAT sheet BATAL (39 rows).
 *
 * Update existing SPR jadi cancelled + set refund fields + alasan.
 *
 * Rules:
 * - Lookup SPR by nama + BLOK+NO
 * - Set status='cancelled', refund_amount=kolom KEMBALI, cancelled_at=NOW() (tidak ada di file)
 * - alasan_pembatalan_id: lookup master by KET (case-insensitive)
 * - Prospect customer tetap status='finish' (rules user)
 *
 * Usage:
 *   php artisan import:batal --dry-run
 *   php artisan import:batal
 */
class ImportBatalCommand extends Command
{
    protected $signature = 'import:batal
        {--dry-run}
        {--force}';

    protected $description = 'Import data batal SPR dari Excel legacy';

    protected ExcelSourceLoader $loader;

    protected NameMatcher $matcher;

    protected bool $dryRun = false;

    public function handle(ExcelSourceLoader $loader, NameMatcher $matcher): int
    {
        $this->loader = $loader;
        $this->matcher = $matcher;
        $this->dryRun = (bool) $this->option('dry-run');

        $this->info('== IMPORT BATAL — Grha Aryana ==');
        if ($this->dryRun) {
            $this->warn('MODE: DRY-RUN');
        }

        if (! $this->dryRun && ! $this->option('force') && ! $this->confirm('Lanjut import batal?', true)) {
            return self::FAILURE;
        }

        $proyek = Proyek::where('nama_proyek', 'Grha Aryana')->first() ?? Proyek::first();
        if (Spr::count() === 0) {
            $this->error('SPR belum ada. Jalankan import:spr dulu.');

            return self::FAILURE;
        }

        // Preload alasan pembatalan
        $alasanMap = AlasanPembatalan::pluck('id', 'nama')->mapWithKeys(fn ($id, $nama) => [strtoupper($nama) => $id])->toArray();

        DB::beginTransaction();
        try {
            $stats = $this->importBatal($proyek, $alasanMap);

            if ($this->dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('[DRY-RUN] Rollback.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('✓ Import batal selesai (committed).');
            }
            $this->printStats($stats);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function importBatal(Proyek $proyek, array $alasanMap): array
    {
        $userFinanceId = User::whereHas('roles', fn ($q) => $q->where('name', 'finance'))
            ->orderBy('id')->value('id') ?? 1;

        // Pre-load SPR mapping
        $sprMap = [];
        Spr::with(['prospectCustomer:id,nama_lengkap', 'rumah:id,blok,nomor_unit'])
            ->get(['id', 'prospect_customer_id', 'rumah_id'])
            ->each(function ($s) use (&$sprMap) {
                if (! $s->prospectCustomer || ! $s->rumah) {
                    return;
                }
                $key = $this->matcher->normalize($s->prospectCustomer->nama_lengkap).'|'.strtoupper($s->rumah->blok).'-'.$s->rumah->nomor_unit;
                $sprMap[$key] = $s->id;
            });

        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'BATAL');
        $rows = $this->loader->rows($sheet, headerRow: 6, columns: [
            'nama' => 'C',
            'sales' => 'D',
            'tgl_jual' => 'E',
            'type' => 'F',
            'blok' => 'G',
            'no_unit' => 'H',
            'total_harga' => 'I',
            'total_um' => 'J',
            'akumulasi' => 'K',
            'penalti' => 'L',
            'kembali' => 'M',
            'ket' => 'N',
        ]);

        $stats = [
            'total_baris' => 0,
            'updated' => 0,
            'skipped_spr_not_found' => 0,
            'alasan_matched' => 0,
            'alasan_default' => 0,
            'total_kembali' => 0,
        ];
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
                $unmatched[] = "{$d['nama']} | $unit";

                continue;
            }

            $kembali = $this->loader->parseNumber($d['kembali']);
            $akumulasi = $this->loader->parseNumber($d['akumulasi']);

            // Refund status derived
            $refundStatus = 'tidak_ada_refund';
            if ($kembali > 0 && $kembali >= $akumulasi) {
                $refundStatus = 'full';
            } elseif ($kembali > 0) {
                $refundStatus = 'partial';
            }

            // Alasan lookup
            $ketUpper = strtoupper(trim($d['ket']));
            $alasanId = $alasanMap[$ketUpper] ?? null;
            if ($alasanId) {
                $stats['alasan_matched']++;
            } else {
                $stats['alasan_default']++;
            }

            $stats['total_kembali'] += $kembali;

            if ($this->dryRun) {
                $stats['updated']++;

                continue;
            }

            Spr::where('id', $sprId)->update([
                'status' => 'cancelled',
                'alasan_pembatalan_id' => $alasanId,
                'cancel_keterangan' => $d['ket'],
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $userFinanceId,
                'refund_status' => $refundStatus,
                'refund_amount' => $kembali,
                'refund_at' => $kembali > 0 ? now()->toDateString() : null,
                'refund_keterangan' => 'Import legacy: penalti Rp '.number_format($this->loader->parseNumber($d['penalti']), 0, ',', '.'),
            ]);

            $stats['updated']++;
        }

        if (! $this->dryRun && count($unmatched) > 0) {
            Storage::disk('local')->makeDirectory('import-log');
            $ts = now()->format('Ymd_His');
            Storage::disk('local')->put("import-log/batal-{$ts}-not-found.csv", "NAMA_UNIT\n".implode("\n", $unmatched));
        }

        return $stats;
    }

    protected function printStats(array $stats): void
    {
        $this->newLine();
        $this->info('== STATISTIK IMPORT BATAL ==');
        foreach ($stats as $key => $val) {
            if ($key === 'total_kembali') {
                $this->line(sprintf('  %-30s Rp %s', $key, number_format($val, 0, ',', '.')));
            } else {
                $this->line(sprintf('  %-30s %d', $key, $val));
            }
        }
    }
}
