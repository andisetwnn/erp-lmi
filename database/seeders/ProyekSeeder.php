<?php

namespace Database\Seeders;

use App\Models\Master\Proyek;
use Illuminate\Database\Seeder;

class ProyekSeeder extends Seeder
{
    public function run(): void
    {
        Proyek::firstOrCreate(
            ['kode_surat' => 'GA'],
            [
                'nama_proyek' => 'Grha Aryana',
                'nama_perumahan' => 'Grha Aryana Residence',
                'desa' => 'Sukamaju',
                'kelurahan' => 'Sukamaju',
                'kecamatan' => 'Cilodong',
                'kota_kabupaten' => 'Depok',
                'kode_akuntansi' => '100.110.01',
                'kode_virtual_account' => '08124001100380',
                'keterangan' => 'Perumahan subsidi & komersial.',
            ],
        );
    }
}
