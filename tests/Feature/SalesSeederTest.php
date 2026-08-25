<?php

use App\Models\Master\Sales;
use Database\Seeders\SalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SalesSeeder juga dijalankan di server yang sudah hidup untuk menambah sales baru.
 * Kalau ia menimpa dbos_password, sales yang sudah mengganti passwordnya sendiri akan
 * terkunci keluar dari DBOS — ini pernah nyaris terjadi saat menyiapkan import historis.
 */
it('tidak mengubah password DBOS sales yang sudah ada saat di-seed ulang', function () {
    $this->seed(SalesSeeder::class);

    $sales = Sales::where('dbos_username', 'agus.solehudin')->firstOrFail();

    // Sales mengganti passwordnya sendiri lewat DBOS
    $sales->update(['dbos_password' => 'password-pilihan-sales']);
    $hashPilihanSales = $sales->fresh()->dbos_password;

    $this->seed(SalesSeeder::class);

    expect($sales->fresh()->dbos_password)->toBe($hashPilihanSales);
});

it('tetap mengisi password saat sales pertama kali dibuat', function () {
    $this->seed(SalesSeeder::class);

    expect(Sales::where('dbos_username', 'agus.solehudin')->value('dbos_password'))->not->toBeEmpty();
});

it('menambah sales baru tanpa menyentuh sales lain yang sudah ada', function () {
    // Simulasikan kondisi production: satu sales dibuat manual di luar seeder
    Sales::create([
        'kode' => 'SLS-009',
        'nama' => 'Andi IT Test',
        'dbos_username' => 'andi',
        'dbos_password' => 'rahasia-andi',
        'is_aktif' => true,
    ]);

    $this->seed(SalesSeeder::class);

    $sebelum = Sales::orderBy('kode')->pluck('dbos_password', 'kode')->all();

    $this->seed(SalesSeeder::class);

    $sesudah = Sales::orderBy('kode')->pluck('dbos_password', 'kode')->all();

    foreach ($sebelum as $kode => $hash) {
        expect($sesudah[$kode] ?? null)->toBe($hash, "password $kode berubah");
    }
});

it('tidak menggeser kode sales solo saat di-seed berulang', function () {
    $this->seed(SalesSeeder::class);
    $kodeAwal = Sales::where('dbos_username', 'dkh')->value('kode');

    $this->seed(SalesSeeder::class);
    $this->seed(SalesSeeder::class);

    expect(Sales::where('dbos_username', 'dkh')->value('kode'))->toBe($kodeAwal)
        ->and(Sales::where('dbos_username', 'dkh')->count())->toBe(1);
});

it('memberi kode lanjutan pada sales solo, tidak menabrak kode yang sudah dipakai', function () {
    Sales::create([
        'kode' => 'SLS-009',
        'nama' => 'Andi IT Test',
        'dbos_username' => 'andi',
        'dbos_password' => 'rahasia-andi',
        'is_aktif' => true,
    ]);

    $this->seed(SalesSeeder::class);

    expect(Sales::where('dbos_username', 'dkh')->value('kode'))->toBe('SLS-010')
        ->and(Sales::where('dbos_username', 'andi')->value('kode'))->toBe('SLS-009');
});
