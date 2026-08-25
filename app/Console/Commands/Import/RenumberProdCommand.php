<?php

namespace App\Console\Commands\Import;

use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Services\Import\SopRowParser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Selaraskan penomoran SPR & kwitansi di DB tujuan dengan buku historis (sheet SOP).
 *
 * Sistem berjalan mulai penomoran dari 00001 saat cutoff Agustus, padahal buku lama
 * sudah berjalan sejak Februari. Akibatnya nomor yang sama dipakai dua transaksi berbeda,
 * dan import historis tidak bisa masuk tanpa menabrak unique constraint.
 *
 * Aturan:
 *   - Record yang ada di dua-duanya  → pakai nomor dari buku historis.
 *   - Record yang hanya ada di sini  → lanjutkan setelah nomor historis tertinggi.
 *
 * WAJIB dijalankan SEBELUM `import:sop`.
 */
class RenumberProdCommand extends Command
{
    protected $signature = 'import:renumber-prod
        {--file=DATA MASTER GRHA ARYANA.xlsx}
        {--sheet=SOP}
        {--start-row=8}
        {--dry-run : Tampilkan rencana perubahan tanpa menyimpan}';

    protected $description = 'Samakan nomor SPR & kwitansi existing dengan buku historis sebelum import';

    protected SopRowParser $parser;

    /** Slot pembayaran di sheet SOP: [kolom kwitansi, kolom nominal, kolom tanggal, jenis]. */
    protected const SLOT_BAYAR = [
        ['AC', 'AD', 'AE', 'bf'],
        ['AF', 'AG', 'AH', 'um'],
        ['AI', 'AJ', 'AK', 'um'],
        ['AL', 'AM', 'AN', 'um'],
        ['AO', 'AP', 'AQ', 'um'],
        ['AR', 'AS', 'AT', 'um'],
        ['AU', 'AV', 'AW', 'um'],
        ['AX', 'AY', 'AZ', 'um'],
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        $this->parser = new SopRowParser;

        $file = base_path((string) $this->option('file'));
        if (! file_exists($file)) {
            $this->error("File tidak ditemukan: $file");

            return self::FAILURE;
        }

        $this->info('RENUMBER — samakan penomoran existing dengan buku historis');
        $this->line("File: $file · Sheet: ".$this->option('sheet'));
        $this->newLine();

        $sheet = IOFactory::load($file)->getSheetByName((string) $this->option('sheet'));
        $histori = $this->bacaHistori($sheet);
        $this->line('Baris historis terbaca: '.count($histori['perUnit']));
        $this->line('Nomor SPR historis tertinggi      : '.$histori['maxSpr']);
        $this->line('Nomor kwitansi historis tertinggi : '.$histori['maxKwitansi']);
        $this->newLine();

        $rencanaSpr = $this->rencanaSpr($histori);
        $rencanaKwitansi = $this->rencanaKwitansi($histori, $rencanaSpr);

        $this->tampilkanRencana($rencanaSpr, $rencanaKwitansi);

        if (! $rencanaSpr && ! $rencanaKwitansi) {
            $this->info('Tidak ada yang perlu dinomori ulang.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY-RUN: tidak ada perubahan disimpan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rencanaSpr, $rencanaKwitansi) {
            $this->terapkan(Spr::class, 'nomor_spr', $rencanaSpr);
            $this->terapkan(SprRealisasiPembayaran::class, 'nomor_kwitansi', $rencanaKwitansi);
        });

        $this->newLine();
        $this->info('✓ Penomoran diselaraskan. Import historis siap dijalankan.');

        return self::SUCCESS;
    }

    /**
     * Baca sheet SOP jadi index per unit + nomor tertinggi yang dipakai buku historis.
     *
     * @return array{perUnit: array<string, array{nomor_spr: string, bayar: array<int, array{nomor: string, jenis: string, nominal: float, tanggal: string}>}>, maxSpr: string, maxKwitansi: string}
     */
    protected function bacaHistori(Worksheet $sheet): array
    {
        $perUnit = [];
        $maxSpr = '';
        $maxKwitansi = '';
        $start = (int) $this->option('start-row');

        for ($r = $start; $r <= $sheet->getHighestRow(); $r++) {
            $noSprRaw = trim((string) $sheet->getCell("E$r")->getValue());
            if ($noSprRaw === '') {
                continue;
            }

            $tglSpr = $this->parser->parseDate($sheet->getCell("B$r")->getValue());
            $nomorRaw = $this->parser->parseSprNumbers($this->parser->parseText($noSprRaw));
            if (! $tglSpr || ! $nomorRaw['active']) {
                continue;
            }
            $nomorSpr = $this->parser->formatNomorSpr($nomorRaw['active'], $tglSpr);
            $maxSpr = max($maxSpr, $nomorSpr);

            $blok = $this->parser->parseText($sheet->getCell("G$r")->getValue());
            $unit = $this->parser->parseNomorUnit($this->parser->parseText($sheet->getCell("H$r")->getValue()));

            $bayar = [];
            foreach (self::SLOT_BAYAR as [$kwtCol, $nomCol, $tglCol, $jenis]) {
                $kwt = trim((string) $sheet->getCell($kwtCol.$r)->getValue());
                $nominal = $this->parser->parseNominal($sheet->getCell($nomCol.$r)->getValue());
                $tgl = $this->parser->parseTglSetor($sheet->getCell($tglCol.$r)->getValue());
                if ($kwt === '' || $nominal <= 0 || ! $tgl) {
                    continue;
                }
                $bayar[] = [
                    'nomor' => $kwt,
                    'jenis' => $jenis,
                    'nominal' => $nominal,
                    'tanggal' => $tgl->toDateString(),
                ];
                // Sebagian sel kwitansi berisi formula atau gabungan beberapa nomor
                // ("00022/00162", "=E43"). Nilai seperti itu tidak boleh jadi acuan
                // nomor tertinggi — bandingkan hanya yang murni angka.
                if (ctype_digit($kwt) && ($maxKwitansi === '' || (int) $kwt > (int) $maxKwitansi)) {
                    $maxKwitansi = $kwt;
                }
            }

            $perUnit["$blok|$unit"] = ['nomor_spr' => $nomorSpr, 'bayar' => $bayar];
        }

        return ['perUnit' => $perUnit, 'maxSpr' => $maxSpr, 'maxKwitansi' => $maxKwitansi];
    }

    /**
     * Tentukan nomor SPR baru untuk tiap SPR yang sudah ada di DB.
     *
     * @return array<int, array{lama: string, baru: string, label: string}>
     */
    protected function rencanaSpr(array $histori): array
    {
        $lanjut = $this->pencacah($histori['maxSpr']);
        $rencana = [];

        $sprList = Spr::with(['rumah', 'prospectCustomer'])->orderBy('id')->get();
        foreach ($sprList as $spr) {
            if (! $spr->rumah) {
                continue;
            }
            $key = $spr->rumah->blok.'|'.$this->parser->parseNomorUnit($spr->rumah->nomor_unit);
            $cocok = $histori['perUnit'][$key] ?? null;

            $baru = $cocok ? $cocok['nomor_spr'] : $lanjut();
            if ($baru === $spr->nomor_spr) {
                continue;
            }

            $rencana[$spr->id] = [
                'lama' => $spr->nomor_spr,
                'baru' => $baru,
                'label' => str_replace('|', '-', $key).'  '.($spr->prospectCustomer->nama_lengkap ?? '?')
                    .($cocok ? '' : '  [tidak ada di historis — lanjut nomor]'),
            ];
        }

        return $rencana;
    }

    /**
     * Tentukan nomor kwitansi baru untuk tiap realisasi yang sudah ada di DB.
     *
     * @return array<int, array{lama: string, baru: string, label: string}>
     */
    protected function rencanaKwitansi(array $histori, array $rencanaSpr): array
    {
        $lanjut = $this->pencacah($histori['maxKwitansi']);
        $rencana = [];
        $terpakai = [];

        $realisasi = SprRealisasiPembayaran::with(['spr.rumah'])->orderBy('id')->get();
        foreach ($realisasi as $rp) {
            if (! $rp->spr || ! $rp->spr->rumah) {
                continue;
            }
            $key = $rp->spr->rumah->blok.'|'.$this->parser->parseNomorUnit($rp->spr->rumah->nomor_unit);
            $cocok = $histori['perUnit'][$key] ?? null;

            $pasangan = $cocok ? $this->cariPasanganBayar($cocok['bayar'], $rp, $terpakai) : null;
            $baru = $pasangan['nomor'] ?? $lanjut();
            $terpakai[$baru] = true;

            if ($baru === $rp->nomor_kwitansi) {
                continue;
            }

            $rencana[$rp->id] = [
                'lama' => (string) $rp->nomor_kwitansi,
                'baru' => $baru,
                'label' => sprintf('%-3s Rp %14s  %s  %s',
                    strtoupper($rp->jenis),
                    number_format((float) $rp->jumlah, 0, ',', '.'),
                    $rp->tanggal_bayar?->format('d/m/Y') ?? '-',
                    str_replace('|', '-', $key).($pasangan ? '' : '  [tidak ada di historis — lanjut nomor]')),
            ];
        }

        return $rencana;
    }

    /**
     * Cari slot pembayaran historis yang paling mungkin sama dengan realisasi ini:
     * jenis harus sama, nominal persis diutamakan, lalu tanggal terdekat.
     *
     * @param  array<int, array{nomor: string, jenis: string, nominal: float, tanggal: string}>  $bayar
     * @param  array<string, bool>  $terpakai
     * @return array{nomor: string, jenis: string, nominal: float, tanggal: string}|null
     */
    protected function cariPasanganBayar(array $bayar, SprRealisasiPembayaran $rp, array $terpakai): ?array
    {
        $kandidat = array_filter(
            $bayar,
            fn ($b) => $b['jenis'] === $rp->jenis && ! isset($terpakai[$b['nomor']])
        );
        if (! $kandidat) {
            return null;
        }

        $tglRp = $rp->tanggal_bayar?->toDateString() ?? '';
        $nominalRp = (float) $rp->jumlah;

        usort($kandidat, function ($a, $b) use ($nominalRp, $tglRp) {
            $samaNominalA = abs($a['nominal'] - $nominalRp) < 0.01 ? 0 : 1;
            $samaNominalB = abs($b['nominal'] - $nominalRp) < 0.01 ? 0 : 1;
            if ($samaNominalA !== $samaNominalB) {
                return $samaNominalA <=> $samaNominalB;
            }

            return abs(strtotime($a['tanggal']) - strtotime($tglRp))
                <=> abs(strtotime($b['tanggal']) - strtotime($tglRp));
        });

        return $kandidat[0];
    }

    /**
     * Pencacah nomor lanjutan setelah nomor tertinggi buku historis.
     * "00616" → "00617", "00618", … · "SPR/2026/08/00254" → "SPR/2026/08/00255", …
     */
    protected function pencacah(string $tertinggi): callable
    {
        $prefix = '';
        $angka = $tertinggi;
        if (($pos = strrpos($tertinggi, '/')) !== false) {
            $prefix = substr($tertinggi, 0, $pos + 1);
            $angka = substr($tertinggi, $pos + 1);
        }
        $lebar = strlen($angka);
        $n = (int) $angka;

        return function () use ($prefix, $lebar, &$n): string {
            $n++;

            return $prefix.str_pad((string) $n, $lebar, '0', STR_PAD_LEFT);
        };
    }

    /**
     * Terapkan penomoran dua tahap: semua diberi nilai sementara dulu supaya tidak
     * bentrok dengan unique constraint saat nomor lama & baru saling bertukar.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, array{lama: string, baru: string, label: string}>  $rencana
     */
    protected function terapkan(string $model, string $kolom, array $rencana): void
    {
        foreach (array_keys($rencana) as $id) {
            $model::whereKey($id)->update([$kolom => "TMP-$id"]);
        }
        foreach ($rencana as $id => $r) {
            $model::whereKey($id)->update([$kolom => $r['baru']]);
        }
    }

    /**
     * @param  array<int, array{lama: string, baru: string, label: string}>  $rencanaSpr
     * @param  array<int, array{lama: string, baru: string, label: string}>  $rencanaKwitansi
     */
    protected function tampilkanRencana(array $rencanaSpr, array $rencanaKwitansi): void
    {
        $this->info('═══ SPR ('.count($rencanaSpr).') ═══');
        foreach ($rencanaSpr as $r) {
            $this->line(sprintf('  %-18s →  %-18s %s', $r['lama'], $r['baru'], $r['label']));
        }

        $this->newLine();
        $this->info('═══ KWITANSI ('.count($rencanaKwitansi).') ═══');
        foreach ($rencanaKwitansi as $r) {
            $this->line(sprintf('  %-8s →  %-8s %s', $r['lama'], $r['baru'], $r['label']));
        }
        $this->newLine();
    }
}
