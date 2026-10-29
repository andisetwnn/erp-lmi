<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Input realisasi pembayaran di detail SPR khusus Keuangan (pembayaran.kelola).
 * Sebelumnya tombol "Tambah Transaksi" hanya melihat status SPR, sehingga
 * Direktur — yang membuka SPR lewat spr.lihat untuk melihat saja — bisa
 * mencatat pembayaran.
 */
beforeEach(function () {
    $this->seed();

    $proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $proyek->id)->first();
    $sales = Sales::create([
        'kode' => 'SLS-HA', 'nama' => 'Sales Hak Akses', 'is_aktif' => true,
        'dbos_username' => 'sales-ha', 'dbos_password' => 'rahasia123',
    ]);
    $rumah = Rumah::create([
        'proyek_id' => $proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'HA', 'nomor_unit' => '01', 'status' => 'available',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id, 'nama_lengkap' => 'KONSUMEN AKSES',
        'nik' => '3200000000000077', 'hp' => '628100000077', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);
    $this->spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'kategori' => $tipe->kategori, 'nomor_spr' => 'SPR/2026/09/00810',
        'tanggal_spr' => now()->subMonth(), 'harga_jual' => 198_000_000, 'total_harga' => 198_000_000,
        'um_net' => 15_000_000, 'utj_nominal' => 0, 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
    ]);
});

function penggunaRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('tidak menampilkan tombol Tambah Transaksi untuk Direktur', function () {
    Livewire::actingAs(penggunaRole('direktur'))
        ->test('pages::marketing.spr-show', ['id' => $this->spr->id])
        ->call('setTab', 'rincian')
        ->assertDontSeeHtml('wire:click="openTambahTransaksi"');
});

it('menolak Direktur yang mencoba mencatat pembayaran langsung', function (string $aksi) {
    Livewire::actingAs(penggunaRole('direktur'))
        ->test('pages::marketing.spr-show', ['id' => $this->spr->id])
        ->set('trxTanggal', now()->toDateString())
        ->set('trxJumlah', '5000000')
        ->set('trxMetode', 'transfer')
        ->call($aksi)
        ->assertForbidden();

    expect(SprRealisasiPembayaran::where('spr_id', $this->spr->id)->count())->toBe(0);
})->with(['openTambahTransaksi', 'saveTransaksi', 'prefillLunas', 'hapusDokumenSigned']);

it('menyembunyikan dan menolak cetak SPR bagi yang tidak punya spr.cetak', function () {
    // Di produksi role disusun lewat layar; pemegang spr.lihat tanpa spr.cetak
    // harus benar-benar tidak bisa mencetak, bukan cuma tidak melihat tombolnya.
    $this->spr->update(['pm_approved_at' => now(), 'materai_stamped_at' => now(), 'materai_file_path' => 'spr-materai/contoh.pdf']);
    $direktur = penggunaRole('direktur');
    $direktur->roles->first()->revokePermissionTo('spr.cetak');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $direktur->refresh();

    Livewire::actingAs($direktur)
        ->test('pages::marketing.spr-show', ['id' => $this->spr->id])
        ->assertDontSeeHtml(route('marketing.spr.print', $this->spr->id))
        ->assertDontSeeHtml(route('marketing.spr.materai-pdf', $this->spr->id));

    $this->actingAs($direktur)->get(route('marketing.spr.print', $this->spr->id))->assertForbidden();
    $this->actingAs($direktur)->get(route('marketing.spr.materai-pdf', $this->spr->id))->assertForbidden();
});

it('tetap membolehkan pemegang spr.cetak mencetak SPR', function () {
    $this->spr->update(['pm_approved_at' => now()]);
    $adminKpr = penggunaRole('admin-kpr');

    Livewire::actingAs($adminKpr)
        ->test('pages::marketing.spr-show', ['id' => $this->spr->id])
        ->assertSeeHtml(route('marketing.spr.print', $this->spr->id));

    $this->actingAs($adminKpr)->get(route('marketing.spr.print', $this->spr->id))->assertOk();
});

it('tetap membolehkan Keuangan mencatat pembayaran', function () {
    Livewire::actingAs(penggunaRole('finance'))
        ->test('pages::marketing.spr-show', ['id' => $this->spr->id])
        ->call('setTab', 'rincian')
        ->assertSeeHtml('wire:click="openTambahTransaksi"')
        ->call('openTambahTransaksi')
        ->set('trxTanggal', now()->toDateString())
        ->set('trxJumlah', '5000000')
        ->set('trxMetode', 'transfer')
        ->call('saveTransaksi')
        ->assertHasNoErrors();

    expect((float) SprRealisasiPembayaran::where('spr_id', $this->spr->id)->sum('jumlah'))->toBe(5000000.0);
});
