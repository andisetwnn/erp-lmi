<?php

namespace Database\Seeders;

use App\Models\Master\AlasanPembatalan;
use Illuminate\Database\Seeder;

class AlasanPembatalanSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['nama' => 'Tolak Bank',                                                       'dapat_meneruskan_angsuran' => true],
            ['nama' => 'Mengundurkan diri',                                                'dapat_meneruskan_angsuran' => false],
            ['nama' => 'Ganti nama bukan suami/istri (tidak satu KK) atau Pindah kavling', 'dapat_meneruskan_angsuran' => true],
            ['nama' => '>10 hari setelah UTJ, tidak membayar UM1',                         'dapat_meneruskan_angsuran' => false],
            ['nama' => '>10 hari setelah pembayaran UTJ, tidak melengkapi pemberkasan KPR', 'dapat_meneruskan_angsuran' => false],
            ['nama' => 'Selama 2 bulan berturut-turut tidak ada pembayaran angsuran',      'dapat_meneruskan_angsuran' => false],
            ['nama' => 'Ganti nama suami/istri',                                           'dapat_meneruskan_angsuran' => true],
            ['nama' => 'Perubahan dari KPR ke Cash atau sebaliknya',                       'dapat_meneruskan_angsuran' => true],
            ['nama' => 'Lain-lain',                                                        'dapat_meneruskan_angsuran' => true],
            ['nama' => 'Salah input',                                                      'dapat_meneruskan_angsuran' => true],
            ['nama' => 'Sales Batal Booking',                                              'dapat_meneruskan_angsuran' => false],
            ['nama' => 'Ganti nama bukan suami/istri (dalam satu KK) atau Pindah kavling', 'dapat_meneruskan_angsuran' => true],
            ['nama' => 'Konsumen Tidak Kooperatif',                                       'dapat_meneruskan_angsuran' => false],
            ['nama' => 'Pindah Kavling',                                                  'dapat_meneruskan_angsuran' => true],
        ];

        foreach ($items as $item) {
            AlasanPembatalan::firstOrCreate(['nama' => $item['nama']], $item);
        }
    }
}
