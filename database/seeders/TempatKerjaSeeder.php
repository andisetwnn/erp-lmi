<?php

namespace Database\Seeders;

use App\Models\Master\TempatKerja;
use Illuminate\Database\Seeder;

class TempatKerjaSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['nama' => 'PT Sinar Mas Tbk',         'bidang_usaha' => 'Multi-Industri',          'alamat' => 'Jakarta'],
            ['nama' => 'Dinas Pendidikan Kota Depok', 'bidang_usaha' => 'Pemerintahan',          'alamat' => 'Depok'],
            ['nama' => 'PT Telkom Indonesia',      'bidang_usaha' => 'Telekomunikasi',          'alamat' => 'Jakarta'],
            ['nama' => 'PT Astra Honda Motor',     'bidang_usaha' => 'Otomotif',                'alamat' => 'Jakarta'],
            ['nama' => 'PT Bank Mandiri Tbk',      'bidang_usaha' => 'Perbankan',               'alamat' => 'Jakarta'],
            ['nama' => 'PT Pertamina',             'bidang_usaha' => 'Energi',                  'alamat' => 'Jakarta'],
        ];

        foreach ($items as $row) {
            TempatKerja::firstOrCreate(['nama' => $row['nama']], $row);
        }
    }
}
