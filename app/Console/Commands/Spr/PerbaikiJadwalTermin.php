<?php

namespace App\Console\Commands\Spr;

use App\Models\Master\Spr;
use App\Support\SprJadwalTermin;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Susun ulang jadwal cicilan uang muka yang tidak lagi sesuai nilai UM-nya.
 *
 * Jadwal dibuat sekali saat SPR terbentuk. Kalau nilai UM-nya belakangan
 * diperbaiki — misalnya blok KPR yang tadinya bolong, atau harga pindahan yang
 * dikembalikan ke harga kesepakatan — jadwalnya tetap memakai angka lama.
 * Akibatnya konsumen melihat cicilan yang jauh dari kewajibannya.
 *
 * Perhitungannya sama dengan saat SPR dibuat: (UM bersih − UTJ) dibagi empat,
 * jatuh tempo bulanan dari tanggal transfer UTJ.
 *
 * Tanpa --commit hanya melaporkan.
 */
class PerbaikiJadwalTermin extends Command
{
    protected $signature = 'spr:perbaiki-jadwal-termin
        {--spr=* : Nomor SPR tertentu. Kosongkan untuk memeriksa semua}
        {--commit : Simpan. Tanpa ini hanya melaporkan}';

    protected $description = 'Susun ulang jadwal cicilan UM yang tidak sesuai nilai UM-nya';

    private const JUMLAH_TERMIN = 4;

    public function handle(): int
    {
        $query = Spr::query()
            ->with('terminPembayaran')
            ->where('kategori', '!=', 'komersial')
            ->where('um_net', '>', 0)
            ->whereNotIn('status', ['draft', 'rejected']);

        if ($nomor = array_filter((array) $this->option('spr'))) {
            $query->whereIn('nomor_spr', $nomor);
        }

        $perbaikan = [];

        foreach ($query->orderBy('nomor_spr')->get() as $spr) {
            $terminUm = $spr->terminPembayaran->where('jenis', 'um');

            if ($terminUm->isEmpty()) {
                continue;
            }

            $seharusnya = max(0, (float) $spr->um_net - (float) $spr->utj_nominal);
            $sekarang = (float) $terminUm->sum('jumlah_jadwal');

            if ((int) round($sekarang * 100) === (int) round($seharusnya * 100)) {
                continue;
            }

            // Termin yang sudah ada realisasinya jangan disentuh: baris yang sama
            // menyimpan jadwal sekaligus pembayaran, jadi menyusun ulang akan
            // menghapus catatan uang yang benar-benar sudah masuk.
            $sudahTerbayar = $terminUm->filter(
                fn ($t) => $t->tanggal_realisasi !== null || (float) $t->jumlah > 0,
            );

            if ($sudahTerbayar->isNotEmpty()) {
                $this->warn(sprintf(
                    '  %s: %d termin UM sudah ada realisasinya — dilewati, sesuaikan manual.',
                    $spr->nomor_spr,
                    $sudahTerbayar->count(),
                ));

                continue;
            }

            $perbaikan[] = ['spr' => $spr, 'sekarang' => $sekarang, 'seharusnya' => $seharusnya];
        }

        $this->laporkan($perbaikan);

        if ($perbaikan === []) {
            return self::SUCCESS;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('Belum ada yang disimpan. Tambahkan --commit kalau laporan di atas sudah sesuai.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($perbaikan) {
            foreach ($perbaikan as $item) {
                $this->susunUlang($item['spr'], $item['seharusnya']);
            }
        });

        $this->newLine();
        $this->info(count($perbaikan).' SPR disusun ulang jadwal cicilannya.');

        return self::SUCCESS;
    }

    private function susunUlang(Spr $spr, float $sisaCicil): void
    {
        $spr->terminPembayaran()->where('jenis', 'um')->delete();

        $perTermin = round($sisaCicil / self::JUMLAH_TERMIN, 0);

        $anchor = SprJadwalTermin::toAnchor($spr->utj_tanggal_transaksi)
            ?? SprJadwalTermin::toAnchor($spr->tanggal_spr)
            ?? Carbon::now();

        foreach (SprJadwalTermin::generate($anchor, self::JUMLAH_TERMIN, $perTermin) as $row) {
            $spr->terminPembayaran()->create([
                'jenis' => 'um',
                'urutan' => $row['urutan'],
                'tanggal_jadwal' => $row['tanggal'],
                'jumlah_jadwal' => $row['jumlah'],
                'input_by_user_id' => null,
            ]);
        }
    }

    /**
     * @param  list<array{spr: Spr, sekarang: float, seharusnya: float}>  $perbaikan
     */
    private function laporkan(array $perbaikan): void
    {
        $rp = fn (float $v) => number_format($v, 0, ',', '.');

        $this->newLine();
        $this->line('  Jadwal perlu disusun ulang : '.count($perbaikan));

        if ($perbaikan === []) {
            $this->newLine();
            $this->info('Semua jadwal cicilan sudah sesuai nilai UM-nya.');

            return;
        }

        foreach ($perbaikan as $item) {
            $spr = $item['spr'];
            $perTermin = round($item['seharusnya'] / self::JUMLAH_TERMIN, 0);

            $this->newLine();
            $this->line(sprintf('  %s  unit %s', $spr->nomor_spr, $spr->rumah?->kode_unit ?? '-'));
            $this->line(sprintf('     %-20s %18s', 'um_net', $rp((float) $spr->um_net)));
            $this->line(sprintf('     %-20s %18s', 'utj', $rp((float) $spr->utj_nominal)));
            $this->line(sprintf(
                '     %-20s %18s  ->  %18s',
                'total jadwal UM',
                $rp($item['sekarang']),
                $rp($item['seharusnya']),
            ));
            $this->line(sprintf(
                '     %-20s %18s  ->  %18s',
                'per termin (x'.self::JUMLAH_TERMIN.')',
                $rp(round($item['sekarang'] / self::JUMLAH_TERMIN, 0)),
                $rp($perTermin),
            ));
        }
    }
}
