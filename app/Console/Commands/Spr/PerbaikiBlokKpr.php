<?php

namespace App\Console\Commands\Spr;

use App\Models\Master\Spr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lengkapi blok KPR SPR yang tidak pernah diisi.
 *
 * Ada SPR bertanda KPR tapi nilai KPR-nya nol, sehingga seluruh harga jatuh
 * jadi uang muka. Itu isian yang terlewat, bukan pembelian tunai — konsumennya
 * tetap memakai KPR.
 *
 * Akibatnya tidak berhenti di SPR itu sendiri: kalau konsumennya pindah
 * kavling, angka bolong tadi ikut tersalin ke SPR barunya.
 *
 * Nilainya dihitung ulang dari master tipe rumah, persis seperti saat SPR baru
 * dibuat: KPR dari plafon tipe, sisanya jadi uang muka, lalu dikurangi SBUM.
 *
 * Tanpa --commit hanya melaporkan.
 */
class PerbaikiBlokKpr extends Command
{
    protected $signature = 'spr:perbaiki-blok-kpr
        {--spr=* : Nomor SPR tertentu. Kosongkan untuk memeriksa semua}
        {--commit : Simpan. Tanpa ini hanya melaporkan}';

    protected $description = 'Lengkapi blok KPR SPR yang nilai KPR-nya tidak pernah diisi';

    public function handle(): int
    {
        $query = Spr::query()
            ->with('rumah.tipeRumah')
            ->where('jenis_pembayaran', 'kpr')
            ->where('nilai_kpr', '<=', 0)
            ->where('total_harga', '>', 0);

        if ($nomor = array_filter((array) $this->option('spr'))) {
            $query->whereIn('nomor_spr', $nomor);
        }

        $daftar = $query->orderBy('nomor_spr')->get();

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada SPR dengan blok KPR yang kosong.');

            return self::SUCCESS;
        }

        $perbaikan = [];

        foreach ($daftar as $spr) {
            $tipe = $spr->rumah?->tipeRumah;
            $plafon = (float) ($tipe?->plafon_kpr ?? 0);

            if ($plafon <= 0) {
                $this->warn("  {$spr->nomor_spr}: tipe rumahnya belum punya plafon KPR — dilewati.");

                continue;
            }

            $total = (float) $spr->total_harga;
            $sbum = (float) ($tipe->sbum ?? 0);
            $dpNominal = max(0, $total - $plafon);

            $perbaikan[] = [
                'spr' => $spr,
                'tipe' => $tipe->tipe,
                'nilai' => [
                    'nilai_kpr' => $plafon,
                    'dp_nominal' => $dpNominal,
                    'sbum' => $sbum,
                    'um_net' => max(0, $dpNominal - $sbum),
                    'dp_persen' => $total > 0 ? round(($dpNominal / $total) * 100, 2) : 0,
                ],
            ];
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
                $item['spr']->update($item['nilai']);
            }
        });

        $this->newLine();
        $this->info(count($perbaikan).' SPR dilengkapi blok KPR-nya.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{spr: Spr, tipe: string, nilai: array<string, float>}>  $perbaikan
     */
    private function laporkan(array $perbaikan): void
    {
        $rp = fn (mixed $v) => number_format((float) $v, 0, ',', '.');

        $this->newLine();
        $this->line('  Perlu dilengkapi : '.count($perbaikan));

        foreach ($perbaikan as $item) {
            $spr = $item['spr'];

            $this->newLine();
            $this->line(sprintf(
                '  %s  unit %s  (%s)  status %s',
                $spr->nomor_spr,
                $spr->rumah?->kode_unit ?? '-',
                $item['tipe'],
                $spr->status,
            ));
            $this->line(sprintf('     %-20s %18s', 'total_harga', $rp($spr->total_harga)));

            foreach ($item['nilai'] as $kolom => $baru) {
                $this->line(sprintf(
                    '     %-20s %18s  ->  %18s',
                    $kolom,
                    $rp($spr->{$kolom}),
                    $rp($baru),
                ));
            }
        }
    }
}
