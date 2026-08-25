<?php

namespace App\Console\Commands\Import;

use App\Models\Master\Customer;
use App\Models\Master\CustomerKontakDarurat;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerKontakDarurat;
use App\Services\Import\NameMatcher;
use App\Services\Import\SopRowParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Backfill detail customer dari sheet "Data Detail" di DATA MASTER GRHA ARYANA.xlsx.
 *
 * Header R3 (data mulai R4):
 *   A NO | B NAMA | C SALES | D SUMBER LEADS | E NO HP | F ALAMAT PEKERJAAN
 *   G PEKERJAAN | H NAMA KONDAR | I KONTAK DARURAT | J GAJI/PENGHASILAN | K BI CHECK
 *
 * Target update:
 *   prospect_customer: sumber, pekerjaan_ktp, penghasilan_bulanan, hp (kalau kosong)
 *   customer         : sumber, jenis_pekerjaan, penghasilan_bulanan, bi_kol, alamat_perusahaan, hp (kalau kosong)
 *   prospect_customer_kontak_darurat + customer_kontak_darurat: 1 record per row (kalau kondar terisi)
 *
 * Match by nama_lengkap (fuzzy via NameMatcher — handle gelar, pasangan, typo).
 */
class ImportSopDetailCommand extends Command
{
    protected $signature = 'import:sop-detail
        {--file=DATA MASTER GRHA ARYANA.xlsx : Path xlsx sumber}
        {--sheet=Data Detail : Nama sheet}
        {--dry-run : Preview tanpa write ke DB}
        {--force : Skip konfirmasi}';

    protected $description = 'Backfill detail customer (sumber, pekerjaan, penghasilan, kontak darurat, BI) dari sheet Data Detail';

    public function handle(): int
    {
        $parser = new SopRowParser;
        $matcher = new NameMatcher;

        $file = base_path((string) $this->option('file'));
        if (! file_exists($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $this->info('╔════════════════════════════════════════════════╗');
        $this->info('║  IMPORT SOP DETAIL — backfill customer         ║');
        $this->info('╚════════════════════════════════════════════════╝');
        $this->line('Mode: '.($dryRun ? 'DRY-RUN' : 'WRITE'));
        $this->newLine();

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Lanjut update?', true)) {
            return self::FAILURE;
        }

        $ss = IOFactory::load($file);
        $sh = $ss->getSheetByName((string) $this->option('sheet'));
        if (! $sh) {
            $this->error('Sheet tidak ditemukan.');

            return self::FAILURE;
        }

        // Prewarm lookup: prospect index by normalized nama
        $prospectIndex = [];
        foreach (ProspectCustomer::whereNotNull('nama_lengkap')->get(['id', 'nama_lengkap']) as $p) {
            $prospectIndex[$matcher->normalize($p->nama_lengkap)] = $p->id;
        }
        $this->line('Prospect index: '.count($prospectIndex).' entries');

        $stats = [
            'row_processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'prospect_updated' => 0,
            'customer_updated' => 0,
            'kontak_darurat_created' => 0,
        ];
        $unmatched = [];

        DB::beginTransaction();
        try {
            $bar = $this->output->createProgressBar($sh->getHighestRow() - 3);
            $bar->start();

            for ($r = 4; $r <= $sh->getHighestRow(); $r++) {
                $bar->advance();
                $stats['row_processed']++;

                $nama = trim((string) $sh->getCell("B$r")->getValue());
                if ($nama === '') {
                    continue;
                }

                $key = $matcher->normalize($nama);
                $prospectId = $prospectIndex[$key] ?? null;
                if (! $prospectId) {
                    // Coba lookup "nama pertama" saja
                    $namaPertama = trim(explode('/', $nama)[0]);
                    $key2 = $matcher->normalize($namaPertama);
                    $prospectId = $prospectIndex[$key2] ?? null;
                }
                if (! $prospectId) {
                    $stats['unmatched']++;
                    $unmatched[] = "R$r: $nama";

                    continue;
                }
                $stats['matched']++;

                $prospect = ProspectCustomer::find($prospectId);
                $customer = Customer::where('nik', $prospect->nik)->first();

                // Parse fields
                $sumberLeads = trim((string) $sh->getCell("D$r")->getValue()) ?: null;
                $hpRaw = trim((string) $sh->getCell("E$r")->getValue());
                $hpNorm = $hpRaw ? $parser->parsePhone($hpRaw) : null;
                $alamatPekerjaan = trim((string) $sh->getCell("F$r")->getValue()) ?: null;
                $pekerjaan = trim((string) $sh->getCell("G$r")->getValue()) ?: null;
                $namaKondar = trim((string) $sh->getCell("H$r")->getValue()) ?: null;
                $noKondar = trim((string) $sh->getCell("I$r")->getValue());
                $noKondarNorm = $noKondar ? $parser->parsePhone($noKondar) : null;
                $gaji = $parser->parseNominal($sh->getCell("J$r")->getValue());
                $biCheckRaw = strtoupper(trim((string) $sh->getCell("K$r")->getValue()));

                // Map BI CHECK "KOL 1" → "1"
                $biKol = null;
                if (preg_match('/(\d)/', $biCheckRaw, $m)) {
                    $biKol = $m[1];
                }

                // Update prospect
                $pUpdate = array_filter([
                    'sumber' => $sumberLeads,
                    'pekerjaan_ktp' => $pekerjaan,
                    'penghasilan_bulanan' => $gaji > 0 ? $gaji : null,
                    'hp' => $prospect->hp ?: $hpNorm,
                    'bi_kol' => $biKol,
                ], fn ($v) => $v !== null && $v !== '');
                if ($pUpdate) {
                    $prospect->update($pUpdate);
                    $stats['prospect_updated']++;
                }

                // Update customer
                if ($customer) {
                    $cUpdate = array_filter([
                        'sumber' => $sumberLeads,
                        'jenis_pekerjaan' => $pekerjaan,
                        'penghasilan_bulanan' => $gaji > 0 ? $gaji : null,
                        'alamat_perusahaan' => $alamatPekerjaan,
                        'hp' => $customer->hp ?: $hpNorm,
                        'bi_kol' => $biKol,
                    ], fn ($v) => $v !== null && $v !== '');
                    if ($cUpdate) {
                        $customer->updateQuietly($cUpdate);
                        $stats['customer_updated']++;
                    }
                }

                // Kontak darurat
                if ($namaKondar && $noKondarNorm) {
                    ProspectCustomerKontakDarurat::firstOrCreate([
                        'prospect_customer_id' => $prospect->id,
                        'nama' => $namaKondar,
                    ], [
                        'nomor_telepon' => $noKondarNorm,
                        'hubungan' => 'lainnya',
                    ]);
                    if ($customer) {
                        CustomerKontakDarurat::firstOrCreate([
                            'customer_id' => $customer->id,
                            'nama' => $namaKondar,
                        ], [
                            'nomor_telepon' => $noKondarNorm,
                            'hubungan' => 'lainnya',
                        ]);
                    }
                    $stats['kontak_darurat_created']++;
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
        if ($unmatched) {
            $this->newLine();
            $this->warn('UNMATCHED ('.count($unmatched).'):');
            foreach (array_slice($unmatched, 0, 15) as $u) {
                $this->line("  $u");
            }
            if (count($unmatched) > 15) {
                $this->line('  ... ('.(count($unmatched) - 15).' more)');
            }
        }

        return self::SUCCESS;
    }
}
