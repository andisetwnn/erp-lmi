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

            // Sales team default (2 tim: Tim Agus + Tim Dellon)
            SalesSeeder::class,

            // Data operasional lain (notaris, tempat_kerja, rumah, customer, prospect, spr)
            // TIDAK auto-seed — di-input manual via UI atau via import:konsumen-on-progress.
        ]);
    }
}
