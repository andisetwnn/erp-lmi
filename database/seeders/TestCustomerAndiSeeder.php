<?php

namespace Database\Seeders;

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use Illuminate\Database\Seeder;

/**
 * Seeder khusus untuk data test.
 * TIDAK dipanggil dari DatabaseSeeder default. Jalankan manual:
 *   php artisan db:seed --class=TestCustomerAndiSeeder
 */
class TestCustomerAndiSeeder extends Seeder
{
    public function run(): void
    {
        $andi = Sales::where('nama', 'like', '%Andi%')->first();
        if (! $andi) {
            $this->command->error('Sales Andi tidak ditemukan. Buat dulu via UI Master Sales.');

            return;
        }

        $proyek = Proyek::first();
        if (! $proyek) {
            $this->command->error('Master proyek kosong. Jalankan ProyekSeeder dulu.');

            return;
        }

        ProspectCustomer::updateOrCreate(
            ['nik' => '3671010101900001'],
            [
                'sales_id' => $andi->id,
                'proyek_id' => $proyek->id,
                'nama_lengkap' => 'Budi Santoso',
                'hp' => '081234567890',
                'sumber' => 'Walk-in',
                'tempat_lahir' => 'Depok',
                'tanggal_lahir' => '1990-01-01',
                'jenis_kelamin' => 'L',
                'agama' => 'Islam',
                'status_perkawinan' => 'Kawin',
                'pekerjaan_ktp' => 'Karyawan Swasta',
                'penghasilan_bulanan' => 8000000,
                'foto_ktp' => 'test/dummy-ktp.jpg',
                'alamat' => 'Jl. Test No. 1 RT 001/002',
                'bi_kol' => '1',
                'bi_dbr' => 30.00,
                'bi_keterangan' => 'BI check bersih, layak',
                'status' => 'finish',
                'catatan' => 'Test customer untuk QA flow SPR + realisasi.',
            ],
        );

        $this->command->info('✓ Prospect "Budi Santoso" (NIK 3671010101900001) sudah di-seed untuk Sales Andi (ID '.$andi->id.') dengan status finish.');
    }
}
