<?php

namespace App\Console\Commands\Import;

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Services\Import\SopRowParser;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Preview konflik antara Excel Grha Aryana (sheet SOP) vs current DB.
 *
 * Output report per-tabel: baris Excel yang bentrok dgn record existing di DB (via NIK / nomor SPR / blok+unit).
 * Read-only — tidak ada write ke DB.
 *
 * Usage sebelum deploy production:
 *   1. Backup production DB → restore ke lokal
 *   2. Run: php artisan check:sop-vs-db
 *   3. Review report untuk decide merge strategy
 *   4. Deploy dgn: php artisan import:sop --skip-existing --force
 */
class CheckSopVsDbCommand extends Command
{
    protected $signature = 'check:sop-vs-db
        {--file=DATA MASTER GRHA ARYANA.xlsx : Path xlsx sumber}
        {--sheet=SOP : Nama sheet}
        {--start-row=8 : Baris awal data}
        {--limit=0 : Batasi jumlah row diproses (0 = semua)}
        {--output= : Simpan report ke file txt (opsional)}';

    protected $description = 'Preview konflik row Excel SOP vs DB current (read-only, tanpa write)';

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        $parser = new SopRowParser;

        $file = base_path((string) $this->option('file'));
        if (! file_exists($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }

        $proyek = Proyek::first();
        if (! $proyek) {
            $this->error('Belum ada Proyek di sistem.');

            return self::FAILURE;
        }

        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  CHECK KONFLIK — Excel SOP vs DB current                  ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->line("File   : $file · Sheet: {$this->option('sheet')} · Proyek: {$proyek->nama_proyek}");
        $this->newLine();

        $ss = IOFactory::load($file);
        $sh = $ss->getSheetByName((string) $this->option('sheet'));
        $hi = $sh->getHighestRow();
        $start = (int) $this->option('start-row');
        $limit = (int) $this->option('limit');
        $end = $limit > 0 ? min($hi, $start + $limit - 1) : $hi;

        // Prewarm lookup dari DB — hindari N+1 query
        $existingNiks = ProspectCustomer::whereNotNull('nik')->pluck('nama_lengkap', 'nik')->toArray();
        $existingSprs = Spr::pluck('id', 'nomor_spr')->toArray();
        $existingRumah = Rumah::where('proyek_id', $proyek->id)
            ->get(['id', 'blok', 'nomor_unit', 'status'])
            ->keyBy(fn ($r) => $r->blok.'|'.$parser->parseNomorUnit($r->nomor_unit))
            ->toArray();

        /** @var array<int, string> rumah_id => nomor SPR aktif yang memegang unit itu */
        $unitDipegangSpr = Spr::whereNotIn('status', ['batal', 'reject', 'draft'])
            ->pluck('nomor_spr', 'rumah_id')
            ->toArray();

        $this->line('DB current: '.count($existingNiks).' NIK · '.count($existingSprs).' SPR · '.count($existingRumah).' Rumah');
        $this->newLine();

        $skipSpr = [];      // nomor SPR sudah ada → baris dilewati
        $skipUnit = [];     // unit sudah dipegang SPR aktif → baris dilewati
        $reuseNik = [];     // prospect sudah ada → dipakai ulang, SPR tetap masuk
        $reuseRumah = [];   // rumah sudah ada (belum ada SPR) → dipakai ulang, SPR tetap masuk
        $safe = 0;
        $total = 0;

        $bar = $this->output->createProgressBar($end - $start + 1);
        $bar->start();

        for ($r = $start; $r <= $end; $r++) {
            $noSprRaw = trim((string) $sh->getCell("E$r")->getValue());
            if ($noSprRaw === '') {
                $bar->advance();

                continue;
            }
            $total++;

            $tglSpr = $parser->parseDate($sh->getCell("B$r")->getValue());
            $nomorRaw = $parser->parseSprNumbers($parser->parseText($noSprRaw));
            $nomorSystem = $tglSpr && $nomorRaw['active']
                ? $parser->formatNomorSpr($nomorRaw['active'], $tglSpr) : null;
            $nik = $parser->parseNik($sh->getCell("N$r")->getValue()) ?: null;
            $blok = $parser->parseText($sh->getCell("G$r")->getValue());
            $unit = $parser->parseNomorUnit($parser->parseText($sh->getCell("H$r")->getValue()));
            $nama = $parser->parseText($sh->getCell("C$r")->getValue());
            $blokUnit = "$blok|$unit";

            // Klasifikasi mengikuti perilaku `import:sop --skip-existing`:
            // baris dilewati HANYA kalau transaksinya sudah tercatat di DB tujuan.
            if ($nomorSystem && isset($existingSprs[$nomorSystem])) {
                $skipSpr[] = "R$r  $nomorSystem  ($nama)";
                $bar->advance();

                continue;
            }

            $rumahDb = $existingRumah[$blokUnit] ?? null;
            if ($rumahDb && isset($unitDipegangSpr[$rumahDb['id']])) {
                $skipUnit[] = "R$r  $blokUnit  dipegang {$unitDipegangSpr[$rumahDb['id']]}  (Excel: '$nama')";
                $bar->advance();

                continue;
            }

            // Baris tetap masuk. Catat entitas mana yang dipakai ulang dari DB.
            if ($nik && isset($existingNiks[$nik])) {
                $reuseNik[] = "R$r  NIK $nik  Excel: '$nama'  →  pakai prospect DB: '{$existingNiks[$nik]}'";
            }
            if ($rumahDb) {
                $reuseRumah[] = "R$r  $blokUnit  (DB status: {$rumahDb['status']})  →  pakai rumah DB, SPR '$nama' masuk";
            }
            if (! ($nik && isset($existingNiks[$nik])) && ! $rumahDb) {
                $safe++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Report
        $lines = [];
        $lines[] = '═══════════════════════════════════════════════════════════';
        $lines[] = 'SUMMARY';
        $lines[] = '═══════════════════════════════════════════════════════════';
        $masuk = $total - count($skipSpr) - count($skipUnit);
        $lines[] = "Total row Excel diproses: $total";
        $lines[] = "SPR yang akan MASUK: $masuk";
        $lines[] = '  · murni baru (prospect & unit belum ada): '.$safe;
        $lines[] = '  · pakai ulang prospect existing: '.count($reuseNik);
        $lines[] = '  · pakai ulang rumah existing: '.count($reuseRumah);
        $lines[] = 'Baris DILEWATI (data tujuan menang): '.(count($skipSpr) + count($skipUnit));
        $lines[] = '  · nomor SPR sudah ada: '.count($skipSpr);
        $lines[] = '  · unit sudah dipegang SPR aktif: '.count($skipUnit);
        $lines[] = '';

        if ($skipSpr) {
            $lines[] = '─── DILEWATI: nomor SPR sudah ada ('.count($skipSpr).') ───';
            $lines = array_merge($lines, $skipSpr);
            $lines[] = '';
        }
        if ($skipUnit) {
            $lines[] = '─── DILEWATI: unit sudah dipegang SPR aktif ('.count($skipUnit).') ───';
            $lines = array_merge($lines, $skipUnit);
            $lines[] = '';
        }
        if ($reuseNik) {
            $lines[] = '─── PAKAI ULANG prospect existing ('.count($reuseNik).') ───';
            $lines = array_merge($lines, $reuseNik);
            $lines[] = '';
        }
        if ($reuseRumah) {
            $lines[] = '─── PAKAI ULANG rumah existing ('.count($reuseRumah).') ───';
            $lines = array_merge($lines, $reuseRumah);
            $lines[] = '';
        }

        foreach ($lines as $l) {
            $this->line($l);
        }

        if ($output = $this->option('output')) {
            file_put_contents(base_path($output), implode("\n", $lines));
            $this->info("\nReport tersimpan: $output");
        }

        $this->newLine();
        if ($safe === $total) {
            $this->info('✓ DB tujuan masih kosong dari data ini. Import bisa dijalankan tanpa --skip-existing.');
        } else {
            $this->warn('⚠ Ada data yang beririsan. Deploy dgn: php artisan import:sop --skip-existing --force');
        }

        return self::SUCCESS;
    }
}
