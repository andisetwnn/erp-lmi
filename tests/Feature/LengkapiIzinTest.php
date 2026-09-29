<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * `izin:lengkapi` — menambah izin yang belum ada tanpa menyentuh yang sudah.
 *
 * RolePermissionSeeder menegakkan matriks apa adanya lewat syncPermissions,
 * sehingga izin yang diberikan manual di lingkungan berjalan ikut tercabut.
 * Perintah ini untuk produksi yang isinya sudah disesuaikan tangan: apa yang
 * sudah ada di sana harus tetap seperti sekarang.
 */
beforeEach(function () {
    $this->seed();
});

it('tidak mengubah apa pun tanpa --commit', function () {
    $admin = Role::where('name', 'admin-kpr')->first();
    $admin->revokePermissionTo('rencanaakad.kelola');

    $this->artisan('izin:lengkapi')->assertExitCode(0);

    expect($admin->fresh()->hasPermissionTo('rencanaakad.kelola'))->toBeFalse();
});

it('mengembalikan izin yang kurang pada sebuah role', function () {
    $admin = Role::where('name', 'admin-kpr')->first();
    $admin->revokePermissionTo('rencanaakad.kelola');

    $this->artisan('izin:lengkapi', ['--commit' => true])->assertExitCode(0);

    expect($admin->fresh()->hasPermissionTo('rencanaakad.kelola'))->toBeTrue();
});

it('tidak mencabut izin yang diberikan di luar matriks', function () {
    // Inti perintah ini. Penyesuaian tangan di produksi harus selamat —
    // itu sebabnya seeder tidak dipakai di sana.
    Permission::findOrCreate('izin.khusus.lapangan', 'web');

    $admin = Role::where('name', 'admin-kpr')->first();
    $admin->givePermissionTo('izin.khusus.lapangan');

    $this->artisan('izin:lengkapi', ['--commit' => true])->assertExitCode(0);

    expect($admin->fresh()->hasPermissionTo('izin.khusus.lapangan'))->toBeTrue();
});

it('membuat izin yang belum pernah terdaftar', function () {
    Permission::where('name', 'rencanaakad.approve')->delete();

    $this->artisan('izin:lengkapi', ['--commit' => true])->assertExitCode(0);

    expect(Permission::where('name', 'rencanaakad.approve')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('tidak menghapus role lama yang masih dipakai', function () {
    // Seeder menghapus role warisan; perintah ini tidak boleh ikut-ikutan.
    Role::findOrCreate('fat', 'web');

    $this->artisan('izin:lengkapi', ['--commit' => true])->assertExitCode(0);

    expect(Role::where('name', 'fat')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('bisa dibatasi ke satu role saja', function () {
    $kpr = Role::where('name', 'admin-kpr')->first();
    $sales = Role::where('name', 'admin-sales')->first();

    $kpr->revokePermissionTo('rencanaakad.kelola');
    $sales->revokePermissionTo('spr.akad');

    $this->artisan('izin:lengkapi', ['--role' => 'admin-kpr', '--commit' => true])->assertExitCode(0);

    expect($kpr->fresh()->hasPermissionTo('rencanaakad.kelola'))->toBeTrue()
        ->and($sales->fresh()->hasPermissionTo('spr.akad'))->toBeFalse();
});

it('tidak berbuat apa-apa kalau semuanya sudah lengkap', function () {
    $this->artisan('izin:lengkapi', ['--commit' => true])
        ->expectsOutputToContain('Semua izin sudah lengkap')
        ->assertExitCode(0);
});

it('memakai daftar izin yang sama dengan seedernya', function () {
    // Dua salinan daftar izin akan menyimpang cepat atau lambat.
    expect(RolePermissionSeeder::daftarIzin())->toContain('rencanaakad.kelola')
        ->and(RolePermissionSeeder::matriksRole())->toHaveKey('admin-kpr')
        ->and(RolePermissionSeeder::matriksRole()['super-admin'])->toBe(RolePermissionSeeder::daftarIzin());
});
