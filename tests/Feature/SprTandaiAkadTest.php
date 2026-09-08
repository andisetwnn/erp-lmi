<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Penandaan akad.
 *
 * Sebelum ini tidak ada satu pun layar yang bisa mengisi tgl_akad — 47 SPR berstatus
 * akad semuanya masuk lewat import. Akad yang terjadi setelahnya tidak pernah tercatat,
 * jadi tidak terhitung sebagai realisasi di dashboard.
 *
 * Sementara ditaruh di detail SPR (Admin Sales); pindah ke Rencana Akad nanti.
 */
beforeEach(function () {
    $this->seed();

    $proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $proyek->id)->first();

    $sales = Sales::create([
        'kode' => 'SLS-K', 'nama' => 'Sales Akad', 'is_aktif' => true,
        'dbos_username' => 'sales-k', 'dbos_password' => 'rahasia123',
    ]);

    $rumah = Rumah::create([
        'proyek_id' => $proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'KK', 'nomor_unit' => '01', 'status' => 'available',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'nama_lengkap' => 'BUDI AKAD', 'nik' => '3200000000000077',
        'hp' => '628100000000', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    $this->spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => $tipe->kategori, 'nomor_spr' => 'SPR/2026/09/00900',
        'tanggal_spr' => now()->subMonth(), 'harga_jual' => 198_000_000,
        'total_harga' => 198_000_000, 'um_net' => 15_000_000,
        'jenis_pembayaran' => 'kpr', 'status' => 'approved',
    ]);

    $this->adminSales = User::factory()->create();
    $this->adminSales->assignRole('admin-sales');
});

function halamanSpr(int $id)
{
    return Livewire::test('pages::marketing.spr-show', ['id' => $id]);
}

it('menandai SPR sudah akad beserta tanggalnya', function () {
    $this->actingAs($this->adminSales);

    halamanSpr($this->spr->id)
        ->call('openTandaiAkad')
        ->set('tglAkadInput', '2026-09-05')
        ->call('tandaiAkad')
        ->assertHasNoErrors();

    $segar = $this->spr->fresh();

    expect($segar->status)->toBe('akad')
        ->and($segar->tgl_akad->toDateString())->toBe('2026-09-05');
});

it('mencatat jejaknya supaya muncul di log dan monitoring', function () {
    // sprAkad() sudah lama ada tapi tidak pernah dipanggil dari mana pun.
    $this->actingAs($this->adminSales);

    halamanSpr($this->spr->id)
        ->call('openTandaiAkad')
        ->set('tglAkadInput', '2026-09-05')
        ->call('tandaiAkad');

    $log = Activity::where('event', 'spr.akad')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->adminSales->id)
        ->and($log->properties['nomor_spr'])->toBe('SPR/2026/09/00900');
});

it('menolak tanggal akad di masa depan', function () {
    $this->actingAs($this->adminSales);

    halamanSpr($this->spr->id)
        ->call('openTandaiAkad')
        ->set('tglAkadInput', now()->addWeek()->toDateString())
        ->call('tandaiAkad')
        ->assertHasErrors('tglAkadInput');

    expect($this->spr->fresh()->status)->toBe('approved');
});

it('menolak SPR yang belum disetujui', function () {
    $this->spr->update(['status' => 'submitted']);
    $this->actingAs($this->adminSales);

    halamanSpr($this->spr->id)
        ->call('openTandaiAkad')
        ->set('tglAkadInput', '2026-09-05')
        ->call('tandaiAkad');

    expect($this->spr->fresh()->status)->toBe('submitted')
        ->and($this->spr->fresh()->tgl_akad)->toBeNull();
});

it('bisa membatalkan penandaan untuk membetulkan salah input', function () {
    $this->actingAs($this->adminSales);

    halamanSpr($this->spr->id)
        ->call('openTandaiAkad')
        ->set('tglAkadInput', '2026-09-05')
        ->call('tandaiAkad')
        ->call('batalkanTandaAkad');

    $segar = $this->spr->fresh();

    expect($segar->status)->toBe('approved')
        ->and($segar->tgl_akad)->toBeNull();
});

it('tidak melepas unit saat penandaan dibatalkan', function () {
    // approved dan akad sama-sama mengunci unit — membatalkan penandaan tidak boleh
    // membuat unitnya kembali tersedia dijual.
    $this->actingAs($this->adminSales);

    halamanSpr($this->spr->id)
        ->call('openTandaiAkad')
        ->set('tglAkadInput', '2026-09-05')
        ->call('tandaiAkad')
        ->call('batalkanTandaAkad');

    expect($this->spr->rumah->fresh()->status)->toBe('terjual');
});

it('menutup penandaan dari user tanpa izin spr.akad', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-kpr');
    $this->actingAs($tamu);

    halamanSpr($this->spr->id)->call('openTandaiAkad')->assertForbidden();

    expect($this->spr->fresh()->status)->toBe('approved');
});
