<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \Laravolt\Indonesia\Seeds\DatabaseSeeder::class,

            // Sistem: role + permission + admin user
            RolePermissionSeeder::class,
            PerusahaanAdminSeeder::class,

            // Master 6 yang wajib ada
            BankSeeder::class,
            CoaSeeder::class,
            ProyekSeeder::class,
            TipeRumahSeeder::class,

            // Master reference statik (pilihan dropdown standar)
            JenisSalesSeeder::class,
            AlasanPembatalanSeeder::class,

            // Data operasional (sales, notaris, tempat_kerja, rumah, customer, dst)
            // TIDAK auto-seed — di-input manual via UI atau via import:konsumen-on-progress.
        ]);
    }
}
