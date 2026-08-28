<?php

namespace Database\Seeders;

use App\Models\Master\Proyek;
use App\Models\Master\Spr;
use App\Models\Master\TargetMarketing;
use Illuminate\Database\Seeder;

/**
 * Isi target marketing tahun berjalan supaya matriks di dashboard direksi tidak kosong
 * selagi menunggu angka resmi dari manajemen.
 *
 * Angkanya diturunkan dari realisasi tahun berjalan — rata-rata per bulan, dibulatkan ke
 * atas ke kelipatan lima — bukan angka karangan. Dengan begitu perbandingan
 * target vs realisasi di dashboard masih masuk akal untuk dibaca.
 *
 * Aman diulang: bulan yang targetnya sudah pernah diisi TIDAK ditimpa, jadi angka resmi
 * yang sudah dimasukkan direktur tidak akan tergeser.
 *
 * Sengaja tidak dipanggil dari DatabaseSeeder — ini pengisian sementara, dijalankan
 * manual: php artisan db:seed --class=TargetMarketingSeeder
 */
class TargetMarketingSeeder extends Seeder
{
    private const CATATAN = 'Angka sementara, menunggu target resmi';

    public function run(): void
    {
        $tahun = (int) now()->year;

        foreach (Proyek::all() as $proyek) {
            $realisasiPenjualan = Spr::whereHas('rumah', fn ($r) => $r->where('proyek_id', $proyek->id))
                ->whereYear('tanggal_spr', $tahun)->count();

            $realisasiAkad = Spr::whereHas('rumah', fn ($r) => $r->where('proyek_id', $proyek->id))
                ->whereYear('tgl_akad', $tahun)->count();

            $targetPenjualan = $this->perBulan($realisasiPenjualan);
            $targetAkad = $this->perBulan($realisasiAkad);

            $diisi = 0;
            for ($bulan = 1; $bulan <= 12; $bulan++) {
                $sudahAda = TargetMarketing::where('proyek_id', $proyek->id)
                    ->where('tahun', $tahun)->where('bulan', $bulan)->exists();

                if ($sudahAda) {
                    continue;
                }

                TargetMarketing::create([
                    'proyek_id' => $proyek->id,
                    'tahun' => $tahun,
                    'bulan' => $bulan,
                    'target_akad' => $targetAkad,
                    'target_penjualan' => $targetPenjualan,
                    'catatan' => self::CATATAN,
                ]);
                $diisi++;
            }

            $this->command?->line(sprintf(
                '  %-22s %2d bulan diisi · target/bulan: %d penjualan, %d akad  (realisasi %d tahun ini: %d penjualan, %d akad)',
                $proyek->nama_proyek, $diisi, $targetPenjualan, $targetAkad, $tahun, $realisasiPenjualan, $realisasiAkad
            ));
        }
    }

    /** Rata-rata per bulan, dibulatkan ke atas ke kelipatan lima. Minimal 5. */
    private function perBulan(int $realisasiSetahun): int
    {
        $rata = (int) ceil($realisasiSetahun / 12);

        return max(5, (int) (ceil($rata / 5) * 5));
    }
}
