<?php

namespace App\Console\Commands\Import;

use App\Models\Master\Proyek;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\User;
use App\Services\Import\ExcelSourceLoader;
use App\Services\Import\NameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Import kwitansi (realisasi pembayaran) dari DATA_PUSAT KWITANSI NEW.
 *
 * Rules:
 * - Skip semua KET yg diawali "NUP" (NUP BATAL, NUP KOMERSIL)
 * - Nomor kwitansi as-is (00001..00537)
 * - Mapping KET → jenis:
 *   - BF KPR / BF CASH / BF KOMERSIL → 'bf'
 *   - UM 1..6 / PELUNASAN DP / PELUNASAN UM → 'um'
 *   - BF KPR + UM 1 → split 2 row (bf + um)
 *   - Empty → skip (log)
 * - VIA → metode: kalau prefix bank → 'transfer', kalau TUNAI/CASH → 'tunai'
 * - Lookup SPR by nama (via prospect_customer) + BLOK+KAV
 *
 * Usage:
 *   php artisan import:kwitansi --dry-run
 *   php artisan import:kwitansi
 */
class ImportKwitansiCommand extends Command
{
    protected $signature = 'import:kwitansi
        {--dry-run : Preview tanpa write ke DB}
        {--force : Skip konfirmasi}';

    protected $description = 'Import kwitansi (realisasi pembayaran) dari Excel legacy Grha Aryana';

    protected ExcelSourceLoader $loader;

    protected NameMatcher $matcher;

    protected bool $dryRun = false;

    public function handle(ExcelSourceLoader $loader, NameMatcher $matcher): int
    {
        $this->loader = $loader;
        $this->matcher = $matcher;
        $this->dryRun = (bool) $this->option('dry-run');

        $this->info('== IMPORT KWITANSI — Grha Aryana ==');
        if ($this->dryRun) {
            $this->warn('MODE: DRY-RUN');
        }

        if (! $this->dryRun && ! $this->option('force') && ! $this->confirm('Lanjut import kwitansi ke DB?', true)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $proyek = Proyek::where('nama_proyek', 'Grha Aryana')->first() ?? Proyek::first();
        if (! $proyek) {
            $this->error('Master proyek kosong.');

            return self::FAILURE;
        }

        if (Spr::count() === 0) {
            $this->error('SPR belum ada di DB. Jalankan import:spr dulu.');

            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            $stats = $this->importKwitansi($proyek);

            if ($this->dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('[DRY-RUN] Rollback.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('✓ Import kwitansi selesai (committed).');
            }

            $this->printStats($stats);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Import gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function importKwitansi(Proyek $proyek): array
    {
        $userFinanceId = User::whereHas('roles', fn ($q) => $q->where('name', 'finance'))
            ->orderBy('id')->value('id') ?? 1;

        // Pre-load semua SPR (approved/akad/cancelled) → mapping.
        // Simpan status supaya bisa prefer active saat matching (kwitansi harus
        // route ke SPR aktif, bukan cancelled).
        $sprMap = []; // "NORMNAMA|BLOK-UNIT" → [entry, ...]
        $sprByName = []; // "NORMNAMA" → [entry, ...]
        $sprByUnit = []; // "BLOK-UNIT" → [entry, ...]
        Spr::whereIn('status', ['approved', 'akad', 'cancelled'])
            ->with(['prospectCustomer:id,nama_lengkap', 'rumah:id,blok,nomor_unit'])
            ->get(['id', 'prospect_customer_id', 'rumah_id', 'status', 'tanggal_spr'])
            ->each(function ($s) use (&$sprMap, &$sprByName, &$sprByUnit) {
                if (! $s->prospectCustomer || ! $s->rumah) {
                    return;
                }
                $normNama = $this->matcher->normalize($s->prospectCustomer->nama_lengkap);
                $unit = strtoupper($s->rumah->blok).'-'.$s->rumah->nomor_unit;
                $entry = [
                    'id' => $s->id,
                    'status' => $s->status,
                    'tgl' => $s->tanggal_spr?->format('Y-m-d') ?? '9999-12-31',
                    'unit' => $unit,
                ];
                $sprMap[$normNama.'|'.$unit][] = $entry;
                $sprByName[$normNama][] = $entry;
                $sprByUnit[$unit][] = $entry;
            });

        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'KWITANSI');
        $rows = $this->loader->rows($sheet, headerRow: 6, columns: [
            'nama' => 'B',
            'tgl_kwitansi' => 'C',
            'no_kwt' => 'D',
            'type' => 'E',
            'blok' => 'F',
            'kav' => 'G',
            'nominal' => 'H',
            'ket' => 'I',
            'via' => 'J',
        ]);

        $stats = [
            'total_baris' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_nup' => 0,
            'skipped_no_nama' => 0,
            'skipped_no_ket' => 0,
            'skipped_spr_not_found' => 0,
            'jenis_bf' => 0,
            'jenis_um' => 0,
            'split_bf_um' => 0,
            'metode_transfer' => 0,
            'metode_tunai' => 0,
        ];

        $unmatched = [];

        foreach ($rows as $r => $d) {
            $stats['total_baris']++;
            if ($d['nama'] === '' || $d['no_kwt'] === '') {
                $stats['skipped_no_nama']++;

                continue;
            }

            $ket = strtoupper(trim($d['ket']));
            if (str_starts_with($ket, 'NUP')) {
                $stats['skipped_nup']++;

                continue;
            }
            if ($ket === '') {
                $stats['skipped_no_ket']++;

                continue;
            }

            // Cari SPR — cascade:
            //   1. exact NAMA (prefer active). Ini menangani pindah kavling: kwitansi
            //      di unit LAMA harus route ke SPR CURRENT (di unit baru, active),
            //      bukan SPR di unit lama (cancelled).
            //   2. fallback: exact NAMA+UNIT (prefer active) — kalau nama match >1
            //      customer di DB, unit disambiguasi.
            //   3. fallback: UNIT + tgl proximity (prefer active, tgl_spr <= tgl_bayar)
            //   4. fallback: fuzzy substring nama (prefer active)
            $normNama = $this->matcher->normalize($d['nama']);
            // Format unit standar: {2 huruf blok}-{digit unit}. Data entry legacy kadang
            // salah isi BLOK="DD-03" (padahal harusnya "DD" saja) → strip suffix "-N".
            // Sama untuk KAV kalau ada suffix aneh.
            $blokRaw = trim((string) $d['blok']);
            $blokClean = strtoupper(explode('-', $blokRaw)[0]);
            $kavClean = preg_replace('/^(\d+)-\d+$/', '$1', trim((string) $d['kav']));
            $unit = $blokClean.'-'.$kavClean;
            $tglBayar = $this->loader->parseDate($d['tgl_kwitansi']) ?: '9999-12-31';

            $sprId = null;
            if (! empty($sprByName[$normNama])) {
                $sprId = $this->pickBest($sprByName[$normNama], $tglBayar);
            }
            if (! $sprId && ! empty($sprMap[$normNama.'|'.$unit])) {
                $sprId = $this->pickBest($sprMap[$normNama.'|'.$unit], $tglBayar);
            }
            if (! $sprId && ! empty($sprByUnit[$unit])) {
                $sprId = $this->pickBest($sprByUnit[$unit], $tglBayar);
            }
            if (! $sprId) {
                // Fuzzy substring: cari SPR nama yg contains KWT nama (atau sebaliknya)
                foreach ($sprByName as $sprNorm => $entries) {
                    if ($sprNorm === '') {
                        continue;
                    }
                    if (str_contains($sprNorm, $normNama) || str_contains($normNama, $sprNorm)) {
                        $sprId = $this->pickBest($entries, $tglBayar);
                        if ($sprId) {
                            break;
                        }
                    }
                }
            }
            if (! $sprId) {
                $stats['skipped_spr_not_found']++;
                $unmatched[] = ['no_kwt' => $d['no_kwt'], 'nama' => $d['nama'], 'unit' => $unit, 'ket' => $ket];

                continue;
            }

            $tglBayar = $this->loader->parseDate($d['tgl_kwitansi']);
            $jumlah = $this->loader->parseNumber($d['nominal']);

            // Determine metode
            $viaUpper = strtoupper($d['via']);
            $metode = in_array($viaUpper, ['TUNAI', 'CASH']) || str_contains($viaUpper, 'TUNAI') ? 'tunai' : 'transfer';
            $metode === 'transfer' ? $stats['metode_transfer']++ : $stats['metode_tunai']++;

            // Determine jenis
            $jenisRows = $this->mapJenis($ket, $jumlah);
            if (count($jenisRows) === 2) {
                $stats['split_bf_um']++;
            }

            foreach ($jenisRows as $idx => $jr) {
                $nomorKwt = str_pad($d['no_kwt'], 5, '0', STR_PAD_LEFT);
                if ($idx > 0) {
                    // Split → suffix "b" biar nomor tetap unique
                    $nomorKwt .= '-b';
                }

                $existing = SprRealisasiPembayaran::where('nomor_kwitansi', $nomorKwt)->first();

                if ($this->dryRun) {
                    $existing ? $stats['updated']++ : $stats['created']++;
                    if ($jr['jenis'] === 'bf') {
                        $stats['jenis_bf']++;
                    }
                    if ($jr['jenis'] === 'um') {
                        $stats['jenis_um']++;
                    }

                    continue;
                }

                $data = [
                    'spr_id' => $sprId,
                    'jenis' => $jr['jenis'],
                    'tanggal_bayar' => $tglBayar,
                    'jumlah' => $jr['jumlah'],
                    'nomor_kwitansi' => $nomorKwt,
                    'metode' => $metode,
                    'keterangan' => 'Legacy: '.$ket.' via '.$d['via'],
                    'input_by_user_id' => $userFinanceId,
                ];

                if ($existing) {
                    $existing->update($data);
                    $stats['updated']++;
                } else {
                    SprRealisasiPembayaran::create($data);
                    $stats['created']++;
                }
                if ($jr['jenis'] === 'bf') {
                    $stats['jenis_bf']++;
                }
                if ($jr['jenis'] === 'um') {
                    $stats['jenis_um']++;
                }
            }
        }

        if (! $this->dryRun && count($unmatched) > 0) {
            Storage::disk('local')->makeDirectory('import-log');
            $ts = now()->format('Ymd_His');
            $csv = "NO_KWT,NAMA,UNIT,KET\n".implode("\n", array_map(fn ($u) => "\"{$u['no_kwt']}\",\"{$u['nama']}\",\"{$u['unit']}\",\"{$u['ket']}\"", $unmatched));
            Storage::disk('local')->put("import-log/kwitansi-{$ts}-spr-not-found.csv", $csv);
        }

        return $stats;
    }

    /**
     * Pilih SPR terbaik dari kandidat: prefer status active (approved/akad),
     * lalu prefer tanggal_spr <= tgl_bayar dan paling recent.
     *
     * @param  array<int, array{id:int,status:string,tgl:string,unit:string}>  $candidates
     */
    protected function pickBest(array $candidates, string $tglBayar): ?int
    {
        if (empty($candidates)) {
            return null;
        }
        $active = array_filter($candidates, fn ($c) => in_array($c['status'], ['approved', 'akad']));
        $pool = ! empty($active) ? $active : $candidates;

        $before = array_filter($pool, fn ($c) => $c['tgl'] <= $tglBayar);
        if (! empty($before)) {
            $pool = $before;
        }

        usort($pool, fn ($a, $b) => strcmp($b['tgl'], $a['tgl']));

        return $pool[array_key_first($pool)]['id'];
    }

    /**
     * Map KET → jenis (bf/um). Return array of ['jenis' => X, 'jumlah' => Y].
     * BF+UM combined → split 2 row 50/50 (assumsi 500rb BF + sisa UM utk subsidi).
     */
    protected function mapJenis(string $ket, float $jumlah): array
    {
        // BF combined dgn UM (BF KPR + UM 1)
        if (preg_match('/^BF.*\+.*UM/i', $ket)) {
            // Asumsi 500rb BF (subsidi standard), sisa UM
            $bf = 500_000;
            $um = max(0, $jumlah - $bf);

            return [
                ['jenis' => 'bf', 'jumlah' => $bf],
                ['jenis' => 'um', 'jumlah' => $um],
            ];
        }

        // BF (Booking Fee / UTJ)
        if (str_starts_with($ket, 'BF')) {
            return [['jenis' => 'bf', 'jumlah' => $jumlah]];
        }

        // UM, PELUNASAN DP, PELUNASAN UM
        if (str_starts_with($ket, 'UM') || str_contains($ket, 'PELUNASAN')) {
            return [['jenis' => 'um', 'jumlah' => $jumlah]];
        }

        // BY PINDAH BLOK → treat as UM
        if (str_contains($ket, 'PINDAH')) {
            return [['jenis' => 'um', 'jumlah' => $jumlah]];
        }

        // Default: UM
        return [['jenis' => 'um', 'jumlah' => $jumlah]];
    }

    protected function printStats(array $stats): void
    {
        $this->newLine();
        $this->info('== STATISTIK IMPORT KWITANSI ==');
        foreach ($stats as $key => $val) {
            $this->line(sprintf('  %-30s %d', $key, $val));
        }
    }
}
