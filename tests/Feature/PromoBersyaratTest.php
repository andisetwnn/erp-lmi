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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Promo bersyarat, misalnya KAPUR yang hanya untuk pengajar. Sales ditanya di form SPR
 * apakah konsumennya memenuhi syarat; promo hanya terpasang kalau dijawab "Ya", konsumen
 * lain membayar booking fee normal. Tidak ada unggahan bukti.
 */
beforeEach(function () {
    $this->seed();
    Storage::fake('public');
    Storage::fake('local');

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->where('kategori', 'subsidi')->first();
    if (! $this->tipe) {
        $this->markTestSkipped('Seeder tidak punya tipe subsidi.');
    }
    $this->sales = Sales::create([
        'kode' => 'SLS-K', 'nama' => 'Sales Kapur', 'is_aktif' => true,
        'dbos_username' => 'sales-kapur', 'dbos_password' => 'rahasia123', 'tanda_tangan_path' => 'ttd/sales-kapur.png',
    ]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-sales');
    $this->svc = app(PromoPenjualanService::class);

    $this->kapur = $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'KAPUR: Kesempatan Pengajar Punya Rumah', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => now()->toDateString(), 'berlaku_sampai' => now()->addDays(4)->toDateString(),
        'booking_fee_tetap' => 110_000, 'semua_unit' => true,
        'syarat' => 'Pengajar: guru PNS, guru honorer, dosen, guru bimbel, dan sejenisnya',
    ], [], [], $this->admin->id);
});

function bookingKapur(string $blok): Booking
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => '1', 'status' => 'booking',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id, 'nama_lengkap' => 'Konsumen '.$blok,
        'nik' => '32'.str_pad((string) crc32('kapur'.$blok), 14, '0'), 'hp' => '628115',
        'sumber' => 'Walk-in', 'status' => 'booking', 'tanggal_lahir' => '1994-03-02',
    ]);

    return Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'aktif',
    ]);
}

function formSprKapur(Booking $booking)
{
    return Livewire::actingAs(test()->sales->fresh(), 'sales')
        ->test('pages::dbos.spr.form', ['bookingId' => $booking->id])
        ->set('jenisPembayaran', 'kpr')
        ->set('jumlahTerminUm', 1)
        ->set('utjBukti', UploadedFile::fake()->image('bukti-utj.jpg'));
}

it('menyimpan syarat promo dan tidak memberi potongan tanpa jawaban Ya', function () {
    $unit = Rumah::create(['proyek_id' => $this->proyek->id, 'tipe_rumah_id' => $this->tipe->id, 'blok' => 'KP', 'nomor_unit' => '1', 'status' => 'available']);
    $utj = (float) $this->tipe->utj ?: 1_000_000;

    expect($this->kapur->bersyarat())->toBeTrue()
        ->and($this->svc->berlaku($unit, now())?->id)->toBe($this->kapur->id)
        ->and($this->svc->untuk($unit, now(), null, $utj, 10_000_000))->toBeNull()
        ->and($this->svc->untuk($unit, now(), null, $utj, 10_000_000, false))->toBeNull()
        ->and($this->svc->untuk($unit, now(), null, $utj, 10_000_000, true)['potongan_utj'])->toBe($utj - 110_000);
});

it('mewajibkan sales menjawab syarat promo sebelum lanjut', function () {
    formSprKapur(bookingKapur('KQ'))
        ->assertDontSee('Apakah konsumen memenuhi syarat promo ini?')
        ->call('nextStep')
        ->assertSee('Apakah konsumen memenuhi syarat promo ini?')
        ->assertSee('Pengajar: guru PNS')
        ->call('nextStep')
        ->assertHasErrors(['promoSyarat' => 'required']);
});

it('memasang promo kalau sales menjawab konsumen memenuhi syarat', function () {
    $booking = bookingKapur('KR');

    formSprKapur($booking)->set('promoSyarat', 'ya')->call('submit')->assertHasNoErrors();

    $spr = Spr::where('booking_id', $booking->id)->sole();
    expect($spr->promo_penjualan_id)->toBe($this->kapur->id)
        ->and($spr->promo_syarat_terpenuhi)->toBeTrue()
        ->and($spr->utjKonsumen())->toBe(110_000.0);
});

it('membiarkan booking fee normal kalau konsumen tidak memenuhi syarat, tanpa pengingat di verifikasi UTJ', function () {
    $booking = bookingKapur('KS');

    formSprKapur($booking)->set('promoSyarat', 'tidak')->call('submit')->assertHasNoErrors();

    $spr = Spr::where('booking_id', $booking->id)->sole();
    expect($spr->promo_penjualan_id)->toBeNull()
        ->and($spr->promo_syarat_terpenuhi)->toBeFalse()
        ->and($spr->utjKonsumen())->toBe((float) $spr->utj_nominal);

    $fat = User::factory()->create();
    $fat->assignRole('finance');

    Livewire::actingAs($fat)
        ->test('pages::finance.penerimaan-konsumen')
        ->call('openKonfirmasi', $spr->id)
        ->set('confirmTanggalTransaksi', now()->toDateString())
        ->assertDontSee('tapi SPR ini belum berpromo');
});

it('tidak menanyakan apa pun kalau promonya tanpa syarat', function () {
    $this->kapur->update(['syarat' => null]);
    $booking = bookingKapur('KT');

    formSprKapur($booking)->call('nextStep')->assertDontSee('Apakah konsumen memenuhi syarat promo ini?')->call('submit')->assertHasNoErrors();

    $spr = Spr::where('booking_id', $booking->id)->sole();
    expect($spr->promo_penjualan_id)->toBe($this->kapur->id)
        ->and($spr->promo_syarat_terpenuhi)->toBeNull();
});

it('mencatat konsumen memenuhi syarat saat Admin Sales memasang promo susulan', function () {
    $booking = bookingKapur('KU');
    formSprKapur($booking)->set('promoSyarat', 'tidak')->call('submit');
    $spr = Spr::where('booking_id', $booking->id)->sole();

    $this->svc->lampirkan($spr, PromoPenjualan::find($this->kapur->id), 'Ternyata guru honorer, sales salah jawab', $this->admin->id);

    expect($spr->fresh()->promo_syarat_terpenuhi)->toBeTrue()
        ->and($spr->fresh()->promo_penjualan_id)->toBe($this->kapur->id);
});

it('mengisi syarat promo lewat layar Admin Sales', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::marketing.promo-penjualan')
        ->set('proyekId', $this->proyek->id)
        ->call('ubah', $this->kapur->id)
        ->assertSet('syarat', 'Pengajar: guru PNS, guru honorer, dosen, guru bimbel, dan sejenisnya')
        ->set('syarat', 'Guru dan dosen')
        ->call('simpan')
        ->assertHasNoErrors();

    expect($this->kapur->fresh()->syarat)->toBe('Guru dan dosen');
});
