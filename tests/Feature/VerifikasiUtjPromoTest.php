<?php

use App\Models\Master\Booking;
use App\Models\Master\PromoPenjualan;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\PromoPenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * SPR yang dimasukkan setelah masa promo berakhir tidak mendapat promo otomatis, padahal
 * konsumennya mentransfer booking fee promo. Saat FAT memverifikasi UTJ dengan tanggal
 * transfer di masa promo, layar mengingatkan — pemasangannya tetap lewat promo susulan.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->sales = Sales::create([
        'kode' => 'SLS-V', 'nama' => 'Sales Verifikasi', 'is_aktif' => true,
        'dbos_username' => 'sales-verifikasi', 'dbos_password' => 'rahasia123',
    ]);
    $this->fat = User::factory()->create();
    $this->fat->assignRole('finance');

    app(PromoPenjualanService::class)->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo KEJORA 10.10', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => '2026-10-10', 'berlaku_sampai' => '2026-10-11',
        'booking_fee_tetap' => 101_000, 'semua_unit' => true,
    ], [], [], $this->fat->id);
});

function sprTanpaPromo(): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => TipeRumah::where('proyek_id', test()->proyek->id)->value('id'),
        'blok' => 'VK', 'nomor_unit' => '19', 'status' => 'booking',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id, 'nama_lengkap' => 'Konsumen Kejora',
        'nik' => '3200000000001019', 'hp' => '628100001019', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => '2026-11-04', 'tanggal_expired' => '2026-11-05', 'status' => 'sukses',
    ]);

    return Spr::create([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'kategori' => 'subsidi', 'nomor_spr' => 'SPR/2026/11/00999', 'tanggal_spr' => '2026-11-04',
        'harga_jual' => 185_000_000, 'biaya_tambahan' => 8_000_000, 'total_harga' => 193_000_000,
        'um_net' => 10_000_000, 'utj_nominal' => 1_000_000, 'jenis_pembayaran' => 'kpr', 'status' => 'submitted',
    ]);
}

it('mengingatkan promo yang berlaku pada tanggal transfer tapi belum terpasang', function () {
    $spr = sprTanpaPromo();

    Livewire::actingAs($this->fat)
        ->test('pages::finance.penerimaan-konsumen')
        ->call('openKonfirmasi', $spr->id)
        ->assertDontSee('masuk masa Promo KEJORA')
        ->set('confirmTanggalTransaksi', '2026-10-10')
        ->assertSee('Tanggal transfer ini masuk masa Promo KEJORA 10.10, tapi SPR ini belum berpromo.')
        ->assertSee('Booking fee promo Rp 101.000.')
        ->set('confirmTanggalTransaksi', '2026-10-15')
        ->assertDontSee('masuk masa Promo KEJORA');
});

it('tidak mengingatkan SPR yang sudah berpromo', function () {
    $spr = sprTanpaPromo();
    $spr->update(['tanggal_spr' => '2026-10-10']);
    app(PromoPenjualanService::class)->lampirkan($spr->fresh(), PromoPenjualan::first(), 'susulan uji', $this->fat->id);

    Livewire::actingAs($this->fat)
        ->test('pages::finance.penerimaan-konsumen')
        ->call('openKonfirmasi', $spr->id)
        ->set('confirmTanggalTransaksi', '2026-10-10')
        ->assertDontSee('tapi SPR ini belum berpromo');
});
