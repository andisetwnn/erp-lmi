<?php

namespace Database\Seeders;

use App\Models\Master\JenisSales;
use Illuminate\Database\Seeder;

class JenisSalesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['In House', 'Freelance'] as $nama) {
            JenisSales::firstOrCreate(['nama' => $nama]);
        }
    }
}
