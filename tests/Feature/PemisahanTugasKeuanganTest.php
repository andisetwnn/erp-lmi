<?php

use App\Models\User;
use App\Services\PembelianService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Staf Keuangan menyiapkan transaksi (draft jurnal, cicilan, materai, PO), FAT yang
 * mengesahkan: posting jurnal, verifikasi UTJ, dan persetujuan PR. Selebihnya izin keduanya sama.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->fat = User::factory()->create();
    $this->fat->assignRole('finance');
    $this->staf = User::factory()->create();
    $this->staf->assignRole('staf-keuangan');
});

it('memberi staf keuangan semua izin finance kecuali posting jurnal, verifikasi UTJ, dan persetujuan PR', function () {
    $finance = RolePermissionSeeder::matriksRole()['finance'];
    $staf = RolePermissionSeeder::matriksRole()['staf-keuangan'];

    expect(array_values(array_diff($finance, $staf)))->toEqualCanonicalizing(['utj.verifikasi', 'jurnal.post', 'pembelian.review'])
        ->and(array_diff($staf, $finance))->toBe([])
        ->and($this->staf->can('jurnal.umum.kelola'))->toBeTrue()
        ->and($this->staf->can('pembayaran.kelola'))->toBeTrue()
        ->and($this->staf->can('jurnal.post'))->toBeFalse()
        ->and($this->staf->can('utj.verifikasi'))->toBeFalse()
        ->and($this->fat->can('jurnal.post'))->toBeTrue()
        ->and($this->fat->can('utj.verifikasi'))->toBeTrue()
        ->and($this->staf->can('pembelian.review'))->toBeFalse()
        ->and($this->staf->can('pembelian.po'))->toBeTrue()
        ->and($this->staf->can('pembelian.bayar'))->toBeTrue()
        ->and($this->fat->can('pembelian.review'))->toBeTrue();
});

it('hanya FAT yang menyetujui PR, staf keuangan tidak', function () {
    $layanan = app(PembelianService::class);
    $pr = $layanan->buatPermintaan([
        'tanggal' => '2026-11-10', 'proyek_id' => null, 'departemen' => 'Umum',
        'keperluan' => 'ATK kantor pusat', 'tanggal_dibutuhkan' => null,
    ], [['nama_barang' => 'Kertas HVS', 'spesifikasi' => 'A4', 'jumlah' => 5, 'satuan' => 'rim', 'harga_perkiraan' => 52000]], $this->staf->id);
    $layanan->ajukan($pr, $this->staf->id);

    Livewire::actingAs($this->staf)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->assertDontSeeHtml("wire:click=\"openAksi('setujui-fat')\"")
        ->call('openAksi', 'setujui-fat')
        ->assertForbidden();

    Livewire::actingAs($this->fat)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->call('openAksi', 'setujui-fat')
        ->call('jalankanAksi');
    expect($pr->fresh()->status)->toBe('disetujui');
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
