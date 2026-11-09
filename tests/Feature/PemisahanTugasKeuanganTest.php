<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Staf Keuangan menyiapkan transaksi (draft jurnal, cicilan, materai), FAT yang
 * mengesahkan: posting jurnal dan verifikasi UTJ. Selebihnya izin keduanya sama.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->fat = User::factory()->create();
    $this->fat->assignRole('finance');
    $this->staf = User::factory()->create();
    $this->staf->assignRole('staf-keuangan');
});

it('memberi staf keuangan semua izin finance kecuali posting jurnal dan verifikasi UTJ', function () {
    $finance = RolePermissionSeeder::matriksRole()['finance'];
    $staf = RolePermissionSeeder::matriksRole()['staf-keuangan'];

    expect(array_values(array_diff($finance, $staf)))->toBe(['utj.verifikasi', 'jurnal.post'])
        ->and(array_diff($staf, $finance))->toBe([])
        ->and($this->staf->can('jurnal.umum.kelola'))->toBeTrue()
        ->and($this->staf->can('pembayaran.kelola'))->toBeTrue()
        ->and($this->staf->can('jurnal.post'))->toBeFalse()
        ->and($this->staf->can('utj.verifikasi'))->toBeFalse()
        ->and($this->fat->can('jurnal.post'))->toBeTrue()
        ->and($this->fat->can('utj.verifikasi'))->toBeTrue();
});

it('hanya FAT yang bisa memverifikasi UTJ', function () {
    Livewire::actingAs($this->staf)
        ->test('pages::finance.penerimaan-konsumen')
        ->call('openKonfirmasi', 1)
        ->assertForbidden();

    Livewire::actingAs($this->staf)
        ->test('pages::finance.penerimaan-konsumen')
        ->call('submitKonfirmasi')
        ->assertForbidden();
});

it('membawa staf keuangan ke dashboard Keuangan', function () {
    $this->actingAs($this->staf)->get(route('dashboard'))->assertRedirect(route('dashboard.finance'));
    $this->actingAs($this->staf)->get(route('dashboard.finance'))->assertOk();
});
