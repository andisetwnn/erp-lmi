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
            // ─── SISTEM ───
            'user.kelola',        // Kelola user & role
            'ttd.kelola',         // Register / update tanda tangan sendiri

            // ─── MASTER (breakdown per entitas) ───
            'master.kelola',          // Umbrella: kelola SEMUA master (backward compat + super-admin)
            'master.perusahaan.kelola',
            'master.proyek.kelola',
            'master.tipe.kelola',     // PM: buka blok baru, atur harga tipe
            'master.rumah.kelola',    // PM: kelola unit rumah (blok+nomor)
            'master.customer.kelola',
            'master.sales.kelola',
            'master.notaris.kelola',
            'master.va.kelola',       // Virtual account
            'master.coa.kelola',      // Chart of Accounts

            // ─── SPR (breakdown per aksi) ───
            'spr.lihat',          // Lihat list & detail SPR + halaman pembatalan
            'spr.approve',        // Approve SPR sebagai Project Manager
            'spr.batal',          // Proses pembatalan SPR + refund
            'spr.pindah-unit',    // Pindah kavling / swap SPR
            'spr.cetak',          // Cetak PDF SPR final

            // ─── FINANCE ───
            'pembayaran.kelola',  // Konfirmasi UTJ + realisasi cicilan + tempel materai
            'pembayaran.approve', // Approve refund / reversal (biasanya finance-manager)

            // ─── AKUNTING ───
            'jurnal.umum.kelola',      // Input/edit jurnal umum (draft)
            'jurnal.bank.kelola',      // Input/edit jurnal bank
            'jurnal.kaskecil.kelola',  // Input/edit jurnal kas kecil
            'jurnal.post',             // Posting jurnal (draft → posted), biasanya manager
            'jurnal.delete',           // Hapus jurnal, biasanya manager
            'bukubesar.lihat',         // Lihat buku besar
            'kasbank.lihat',           // Lihat dashboard Kas & Bank
            'labarugi.lihat',          // Lihat laporan laba rugi
            'neraca.lihat',            // Lihat neraca
            'neracasaldo.lihat',       // Lihat neraca saldo (trial balance)
            'neracalajur.lihat',       // Lihat neraca lajur (worksheet 10 kolom)
            'aruskas.lihat',           // Lihat arus kas (cash flow statement)
            'aktivatetap.kelola',      // CRUD aktiva tetap
            'aktivatetap.lihat',       // Lihat aktiva tetap

            // ─── LAPORAN ───
            'laporan.lihat',

            // ─── LOG & MONITORING ───
            'log.lihat',
            'monitoring.lihat',    // Feed monitoring realtime + notifikasi (PM, Direktur)
            'notifikasi.keuangan', // Bell notif khusus event Keuangan
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $roleMatrix = [
            // Super admin: SEMUA permission
            'super-admin' => $permissions,

            // Direktur: view-only (SPR, akunting, laporan, log, monitor)
            'direktur' => [
                'spr.lihat',
                'spr.cetak',
                'bukubesar.lihat',
                'kasbank.lihat',
                'labarugi.lihat',
                'neraca.lihat',
                'neracasaldo.lihat',
                'neracalajur.lihat',
                'aruskas.lihat',
                'aktivatetap.lihat',
                'laporan.lihat',
                'log.lihat',
                'monitoring.lihat',
                'ttd.kelola',
            ],

            // Project Manager: approve SPR + kelola master proyek/tipe/rumah (untuk buka blok)
            'project-manager' => [
                'master.proyek.kelola',
                'master.tipe.kelola',
                'master.rumah.kelola',
                'spr.lihat',
                'spr.approve',
                'spr.pindah-unit',
                'spr.cetak',
                'laporan.lihat',
                'log.lihat',
                'monitoring.lihat',
                'ttd.kelola',
            ],

            // Finance: full akunting + pembayaran + master keuangan (notaris/VA/COA)
            'finance' => [
                'master.notaris.kelola',
                'master.va.kelola',
                'master.coa.kelola',
                'spr.lihat',
                'spr.cetak',
                'pembayaran.kelola',
                'pembayaran.approve',
                'jurnal.umum.kelola',
                'jurnal.bank.kelola',
                'jurnal.kaskecil.kelola',
                'jurnal.post',
                'jurnal.delete',
                'bukubesar.lihat',
                'kasbank.lihat',
                'labarugi.lihat',
                'neraca.lihat',
                'neracasaldo.lihat',
                'neracalajur.lihat',
                'aruskas.lihat',
                'aktivatetap.kelola',
                'aktivatetap.lihat',
                'laporan.lihat',
                'notifikasi.keuangan',
                'ttd.kelola',
            ],

            // Admin KPR: kelola customer + proses pembatalan SPR + laporan
            'admin-kpr' => [
                'master.customer.kelola',
                'spr.lihat',
                'spr.batal',
                'spr.cetak',
                'laporan.lihat',
                'ttd.kelola',
            ],
        ];

        // Hapus role lama yang sudah tidak dipakai di web (sales-* di DBOS guard sendiri).
        Role::whereIn('name', ['sales-lapangan', 'sales-admin', 'fat'])
            ->where('guard_name', 'web')
            ->delete();

        // Hapus permission yang tidak dipakai lagi (legacy dari seeder awal).
        Permission::whereIn('name', [
            'customer.kelola', 'dbos.kelola', 'spr.kelola', 'approval.proses',
            'jurnal.kelola', 'kpr.kelola', 'sp3k.kelola', 'akad.kelola',
            'utj.konfirmasi',
            'bukubank.lihat', // halaman Buku Bank sudah dihapus (dilebur ke Buku Besar)
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
