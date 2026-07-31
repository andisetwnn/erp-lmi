<?php

namespace Database\Seeders;

use App\Models\Master\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        $banks = [
            'Bank Central Asia (BCA)',
            'Bank Mandiri',
            'Bank Negara Indonesia (BNI)',
            'Bank Rakyat Indonesia (BRI)',
            'Bank Tabungan Negara (BTN)',
            'Bank CIMB Niaga',
            'Bank Danamon',
            'Bank Permata',
            'Bank Syariah Indonesia (BSI)',
            'Bank Mega',
            'Bank OCBC NISP',
            'Bank Maybank Indonesia',
        ];

        foreach ($banks as $nama) {
            Bank::firstOrCreate(['nama' => $nama]);
        }
    }
}
