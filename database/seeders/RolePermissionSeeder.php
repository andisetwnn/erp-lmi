<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Role & Permission matrix untuk sistem pusat (guard `web`).
     * DBOS (guard `sales`) tidak pakai Spatie — pakai middleware sendiri.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Sistem
            'master.kelola',      // CRUD semua master data
            'user.kelola',        // Kelola user & role
            'ttd.kelola',         // Register / update tanda tangan sendiri

            // SPR (cetak/batal ter-cover spr.lihat, tidak perlu permission terpisah)
            'spr.lihat',          // Lihat list & detail SPR + cetak PDF + halaman pembatalan
            'spr.approve',        // Approve SPR sebagai Project Manager
            'spr.pindah-unit',    // Pindah kavling / swap SPR

            // Finance (utj.konfirmasi ter-cover pembayaran.kelola)
            'pembayaran.kelola',  // Konfirmasi UTJ + realisasi cicilan + tempel materai + refund

            // Laporan
            'laporan.lihat',

            // Log & Audit
            'log.lihat',

            // Monitoring
            'monitoring.lihat',   // Feed monitoring realtime + notifikasi (PM, Direktur)
            'notifikasi.keuangan', // Bell notif khusus event Keuangan
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $roleMatrix = [
            'super-admin' => $permissions,

            'direktur' => [
                'spr.lihat',
                'laporan.lihat',
                'log.lihat',
                'monitoring.lihat',
                'ttd.kelola',
            ],

            'project-manager' => [
                'spr.lihat',
                'spr.approve',
                'laporan.lihat',
                'log.lihat',
                'monitoring.lihat',
                'ttd.kelola',
            ],

            'finance' => [
                'spr.lihat',
                'pembayaran.kelola',
                'laporan.lihat',
                'notifikasi.keuangan',
                'ttd.kelola',
            ],

            'admin-kpr' => [
                'spr.lihat',
                'laporan.lihat',
                'ttd.kelola',
            ],
        ];

        // Hapus role lama yang sudah tidak dipakai di web (sales-* di DBOS guard sendiri).
        Role::whereIn('name', ['sales-lapangan', 'sales-admin', 'fat'])
            ->where('guard_name', 'web')
            ->delete();

        // Hapus permission yang tidak dipakai lagi:
        // - Modul mendatang (jurnal/kpr/sp3k/akad) → belum ada
        // - Permission redundan (spr.cetak/spr.batal/utj.konfirmasi) → sudah ter-cover spr.lihat & pembayaran.kelola
        // - Legacy dari seeder awal
        Permission::whereIn('name', [
            'customer.kelola', 'dbos.kelola', 'spr.kelola', 'approval.proses',
            'jurnal.kelola', 'kpr.kelola', 'sp3k.kelola', 'akad.kelola',
            'spr.cetak', 'spr.batal', 'utj.konfirmasi',
        ])
            ->where('guard_name', 'web')
            ->delete();

        foreach ($roleMatrix as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
