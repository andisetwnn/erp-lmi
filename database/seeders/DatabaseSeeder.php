<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \Laravolt\Indonesia\Seeds\DatabaseSeeder::class,
            RolePermissionSeeder::class,
            BankSeeder::class,
            JenisSalesSeeder::class,
            SalesSeeder::class,
            NotarisSeeder::class,
            AlasanPembatalanSeeder::class,
            PerusahaanAdminSeeder::class,
            CoaSeeder::class,
            ProyekSeeder::class,
            TipeRumahSeeder::class,
            // RumahSeeder dihapus — data rumah di-generate oleh import:konsumen-on-progress dari MASTER DATA xlsx.
            TempatKerjaSeeder::class,
            // CustomerSeeder + ProspectCustomerSeeder di-skip: data real (172 customer + prospect)
            // masuk via import:konsumen-on-progress dari MASTER DATA.xlsx.
            // BookingSeeder, SprSeeder, KonsumenOnProgressSeeder juga dihapus alasan yg sama.
        ]);
    }
}
