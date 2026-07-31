<?php

namespace Database\Seeders;

use App\Models\Master\Proyek;
use App\Models\Master\TipeRumah;
use Illuminate\Database\Seeder;

class TipeRumahSeeder extends Seeder
{
    public function run(): void
    {
        $grhaAryana = Proyek::where('kode_surat', 'GA')->first();
        if (! $grhaAryana) {
            return;
        }

        // ARJUNA 30/60 — subsidi mainstream Grha Aryana (sumber: MASTER DATA xlsx).
        // Harga jual 185jt, all in 198jt (after 13jt biaya admin).
        // Plafon KPR 179jt → Total UM = 19jt (198-179). SBUM 4jt → UM Sendiri 15jt.
        TipeRumah::firstOrCreate(
            [
                'proyek_id' => $grhaAryana->id,
                'tipe' => 'Arjuna 30/60',
            ],
            [
                'nama_tipe' => 'Arjuna',
                'kategori' => 'subsidi',
                'luas_tanah' => 60,
                'luas_bangunan' => 30,
                'harga_jual' => 185_000_000,
                'harga_all_in' => 198_000_000,
                'plafon_kpr' => 179_000_000,
                'biaya_administrasi' => 13_000_000,
                'utj' => 500_000,
                'sbum' => 4_000_000,
                'spesifikasi' => "2 Kamar Tidur\n1 Kamar Mandi\n1 Ruang Tamu\n1 Dapur\nCarport\nKM dalam kering\nLantai keramik 40x40\nDaya listrik 1.300 VA",
                'is_aktif' => true,
            ],
        );

        // BIMA 36/78 — komersil. Harga jual 348jt, all in 350jt.
        // Plafon KPR default 0 (bank ditentukan per SPR / negosiasi).
        TipeRumah::firstOrCreate(
            [
                'proyek_id' => $grhaAryana->id,
                'tipe' => 'Bima 36/78',
            ],
            [
                'nama_tipe' => 'Bima',
                'kategori' => 'komersial',
                'luas_tanah' => 78,
                'luas_bangunan' => 36,
                'harga_jual' => 348_000_000,
                'harga_all_in' => 350_000_000,
                'plafon_kpr' => 0,
                'biaya_administrasi' => 2_000_000,
                'utj' => 2_000_000,
                'sbum' => 0,
                'spesifikasi' => "3 Kamar Tidur\n2 Kamar Mandi\n1 Ruang Keluarga\n1 Ruang Tamu\n1 Dapur\nCarport 2 mobil\nLantai granit\nDaya listrik 2.200 VA",
                'is_aktif' => true,
            ],
        );
    }
}
