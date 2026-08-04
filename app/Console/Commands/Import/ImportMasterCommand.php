<?php

namespace App\Console\Commands\Import;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Bank;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use App\Services\Import\ExcelSourceLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import master data prerequisites dari file Excel legacy Grha Aryana.
 *
 * Fase yg dipersiapkan:
 * - Bank (dari kolom VIA di KWITANSI NEW)
 * - Rumah (dari BLOK+NO di semua sheet — kalau ada BLOK-NO baru yg belum ada di master)
 * - AlasanPembatalan (dari kolom KET di BATAL — kalau ada alasan baru)
 *
 * Design: idempotent (updateOrCreate), aman di-rerun. Semua di dalam DB::transaction.
 *
 * Usage:
 *   php artisan import:master --dry-run
 *   php artisan import:master
 */
class ImportMasterCommand extends Command
{
    protected $signature = 'import:master
        {--dry-run : Preview tanpa write ke DB}
        {--force : Skip konfirmasi}';

    protected $description = 'Import master data prerequisites dari Excel legacy Grha Aryana (Bank, Rumah, Alasan Pembatalan)';

    protected ExcelSourceLoader $loader;

    protected bool $dryRun = false;

    public function handle(ExcelSourceLoader $loader): int
    {
        $this->loader = $loader;
        $this->dryRun = (bool) $this->option('dry-run');

        $this->info('== IMPORT MASTER DATA — Grha Aryana ==');
        if ($this->dryRun) {
            $this->warn('MODE: DRY-RUN — tidak akan write ke DB');
        }

        if (! $this->dryRun && ! $this->option('force') && ! $this->confirm('Lanjut import master ke DB?', true)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $proyek = Proyek::where('nama_proyek', 'Grha Aryana')->first()
            ?? Proyek::first();
        if (! $proyek) {
            $this->error('Master proyek kosong. Jalankan ProyekSeeder dulu.');

            return self::FAILURE;
        }
        $this->info("Proyek target: {$proyek->nama_proyek} (id={$proyek->id})");

        DB::beginTransaction();
        try {
            $this->importBank();
            $this->importAlasanPembatalan();
            $this->importRumah($proyek);

            if ($this->dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('[DRY-RUN] Semua perubahan di-rollback. Tidak ada yang tersimpan.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('✓ Import master selesai (committed).');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Import gagal, semua perubahan di-rollback:');
            $this->error('   '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Ambil unique bank code dari kolom VIA di KWITANSI NEW. */
    protected function importBank(): void
    {
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'KWITANSI');
        $banks = [];
        $rows = $this->loader->rows($sheet, headerRow: 6, columns: ['via' => 'J']);
        foreach ($rows as $r => $data) {
            if ($data['via'] === '') {
                continue;
            }
            $prefix = strtoupper(explode(' ', $data['via'])[0]);
            // Skip non-bank (angka, DASH, dll)
            if (! preg_match('/^[A-Z]{2,}$/', $prefix)) {
                continue;
            }
            $banks[$prefix] = ($banks[$prefix] ?? 0) + 1;
        }

        // Filter yg jelas bank (skip TUNAI/CASH/DANA/OVO — atau tambahkan sebagai bank juga)
        $skip = ['TUNAI', 'CASH'];
        $banks = array_diff_key($banks, array_flip($skip));

        $this->newLine();
        $this->comment('Bank kandidat dari kolom VIA (skip TUNAI/CASH): '.count($banks));

        $added = 0;
        $existed = 0;
        foreach ($banks as $kode => $count) {
            // Bank table hanya punya kolom `nama`, cek exact atau LIKE prefix
            $existing = Bank::whereRaw('UPPER(nama) = ?', [$kode])
                ->orWhere('nama', 'like', "$kode%")
                ->first();
            if ($existing) {
                $existed++;

                continue;
            }
            if (! $this->dryRun) {
                Bank::create(['nama' => $kode]);
            }
            $added++;
            $this->line("  + Bank baru: $kode (dipakai $count kali)");
        }
        $this->info("Bank: $added baru, $existed sudah ada");
    }

    /** Ambil unique alasan pembatalan dari kolom KET sheet BATAL. */
    protected function importAlasanPembatalan(): void
    {
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'BATAL');
        $alasans = [];
        $rows = $this->loader->rows($sheet, headerRow: 6, columns: ['ket' => 'N']);
        foreach ($rows as $r => $data) {
            $ket = strtoupper(trim($data['ket']));
            if ($ket === '' || strlen($ket) < 5) {
                continue;
            }
            $alasans[$ket] = ($alasans[$ket] ?? 0) + 1;
        }

        $this->newLine();
        $this->comment('Alasan pembatalan unique di sheet BATAL: '.count($alasans));

        $added = 0;
        $existed = 0;
        foreach ($alasans as $nama => $count) {
            // Normalize case (Title Case untuk display)
            $namaFmt = ucwords(strtolower($nama));
            $existing = AlasanPembatalan::whereRaw('UPPER(nama) = ?', [$nama])->first();
            if ($existing) {
                $existed++;

                continue;
            }
            if (! $this->dryRun) {
                AlasanPembatalan::create([
                    'nama' => $namaFmt,
                    'dapat_meneruskan_angsuran' => false,
                    'is_aktif' => true,
                ]);
            }
            $added++;
            $this->line("  + Alasan baru: $namaFmt ($count kali)");
        }
        $this->info("Alasan Pembatalan: $added baru, $existed sudah ada");
    }

    /** Sync master rumah — cek BLOK+NO di semua sheet legacy vs master, tambahkan yg belum ada. */
    protected function importRumah(Proyek $proyek): void
    {
        $this->newLine();
        $this->comment('Sync master rumah (BLOK + NOMOR_UNIT) dari legacy...');

        $tipeSubsidi = TipeRumah::where('proyek_id', $proyek->id)->where('kategori', 'subsidi')->first();
        $tipeKomersial = TipeRumah::where('proyek_id', $proyek->id)->where('kategori', 'komersial')->first();

        if (! $tipeSubsidi || ! $tipeKomersial) {
            $this->error('Tipe rumah subsidi/komersial belum ada. Jalankan TipeRumahSeeder dulu.');

            return;
        }

        $units = [];

        // Kumpulin BLOK+NO dari beberapa sheet biar coverage komplit:
        // 1. JUAL (utama)
        $sheet = $this->loader->sheet('DATA_PUSAT.xlsx', 'JUAL');
        $rows = $this->loader->rows($sheet, headerRow: 3, columns: ['blok' => 'E', 'no' => 'F', 'type' => 'D']);
        foreach ($rows as $r => $d) {
            if ($d['blok'] === '' || $d['no'] === '') {
                continue;
            }
            $key = strtoupper($d['blok']).'-'.$d['no'];
            $units[$key] = [
                'blok' => strtoupper($d['blok']),
                'nomor_unit' => $d['no'],
                'type' => $d['type'],
            ];
        }

        // 2. UANG MUKA (kalau ada unit yg belum di JUAL — mis. PHILIP AB-23, INDRA AB-20)
        $sheetUm = $this->loader->sheet('DATA_PUSAT.xlsx', 'UANG MUKA');
        $rowsUm = $this->loader->rows($sheetUm, headerRow: 2, columns: ['blok' => 'C', 'no' => 'D', 'type' => 'G']);
        foreach ($rowsUm as $r => $d) {
            if ($d['blok'] === '' || $d['no'] === '' || strtoupper($d['blok']) === 'BLOK') {
                continue;
            }
            $key = strtoupper($d['blok']).'-'.$d['no'];
            if (isset($units[$key])) {
                continue;
            }
            // Kolom G di UANG MUKA = LB (30), bukan type "30/60" full. Assume subsidi kalau LB=30.
            $units[$key] = [
                'blok' => strtoupper($d['blok']),
                'nomor_unit' => $d['no'],
                'type' => $d['type'] === '30' ? '30/60' : ($d['type'] ?: '30/60'),
            ];
        }

        // 3. KWITANSI (fallback — kalau ada unit di kwitansi yg belum di JUAL/UANG MUKA)
        $sheetKwt = $this->loader->sheet('DATA_PUSAT.xlsx', 'KWITANSI');
        $rowsKwt = $this->loader->rows($sheetKwt, headerRow: 6, columns: ['blok' => 'F', 'kav' => 'G', 'type' => 'E']);
        foreach ($rowsKwt as $r => $d) {
            if ($d['blok'] === '' || $d['kav'] === '') {
                continue;
            }
            // Handle format BLOK "DD-03" (bug data entry) → strip suffix
            $blok = strtoupper(explode('-', $d['blok'])[0]);
            $kav = preg_replace('/^(\d+)-\d+$/', '$1', trim((string) $d['kav']));
            $key = $blok.'-'.$kav;
            if (isset($units[$key])) {
                continue;
            }
            $units[$key] = [
                'blok' => $blok,
                'nomor_unit' => $kav,
                'type' => str_contains($d['type'], '30/60') ? '30/60' : $d['type'],
            ];
        }

        $added = 0;
        $existed = 0;
        foreach ($units as $key => $u) {
            $tipe = str_contains($u['type'], '30/60') ? $tipeSubsidi : $tipeKomersial;

            $existing = Rumah::where('proyek_id', $proyek->id)
                ->where('blok', $u['blok'])
                ->where('nomor_unit', $u['nomor_unit'])
                ->first();

            if ($existing) {
                $existed++;

                continue;
            }
            if (! $this->dryRun) {
                Rumah::create([
                    'proyek_id' => $proyek->id,
                    'tipe_rumah_id' => $tipe->id,
                    'blok' => $u['blok'],
                    'nomor_unit' => $u['nomor_unit'],
                    'biaya_tambahan' => 0,
                    'discount' => 0,
                    'ppn' => 0,
                    'status' => 'available',
                ]);
            }
            $added++;
            if ($added <= 20) {
                $this->line("  + Rumah baru: {$u['blok']}-{$u['nomor_unit']} ({$tipe->kategori})");
            }
        }
        if ($added > 20) {
            $this->line('  ... dan '.($added - 20).' lainnya');
        }
        $this->info("Rumah: $added baru, $existed sudah ada (total ".count($units).' unit di legacy)');
    }
}
