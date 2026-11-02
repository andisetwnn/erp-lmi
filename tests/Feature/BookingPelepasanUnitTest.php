<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Booking yang lewat 24 jam melepas unitnya dan tidak bisa lagi dibuatkan SPR.
 * Booking itu tetap berstatus 'aktif', jadi cek status saja tidak cukup: tanpa
 * cek batas waktu, satu unit bisa berakhir dengan dua SPR.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->where('kategori', 'subsidi')->first()
        ?? TipeRumah::where('proyek_id', $this->proyek->id)->first();
    $this->sales = Sales::create([
        'kode' => 'SLS-LP', 'nama' => 'Sales Pelepasan', 'is_aktif' => true,
        'dbos_username' => 'sales-lepas', 'dbos_password' => 'rahasia123',
    ]);
    $this->sales->forceFill(['tanda_tangan_path' => 'ttd/sales-lepas.png'])->save();
});

function bookingPelepasan(array $ubah = [], string $statusRumah = 'booking'): Booking
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'LP', 'nomor_unit' => (string) (Rumah::where('blok', 'LP')->count() + 1), 'status' => $statusRumah,
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id, 'nama_lengkap' => 'Konsumen Pelepasan',
        'nik' => '3201000000003333', 'hp' => '628120000003', 'sumber' => 'Walk-in', 'status' => 'finish',
        'tanggal_lahir' => '1995-05-17',
    ]);

    return Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'aktif',
        ...$ubah,
    ]);
}

function formSpr(Booking $booking)
{
    return Livewire::actingAs(test()->sales->fresh(), 'sales')
        ->test('pages::dbos.spr.form', ['bookingId' => $booking->id]);
}

function isiFormSpr($form)
{
    return $form->set('jenisPembayaran', 'kpr')
        ->set('jumlahTerminUm', 1)
        ->set('utjBukti', UploadedFile::fake()->image('bukti-utj.jpg'));
}

it('melepas unit dari booking yang lewat 24 jam', function () {
    $booking = bookingPelepasan(['tanggal_expired' => now()->subHour()]);

    $this->artisan('bookings:expire')->assertSuccessful();

    expect($booking->rumah->fresh()->status)->toBe('available')
        ->and($booking->fresh()->status)->toBe('aktif')
        ->and($booking->fresh()->unit_dilepas_at)->not->toBeNull();
});

it('tidak menyentuh booking yang masih dalam 24 jam', function () {
    $booking = bookingPelepasan();

    $this->artisan('bookings:expire')->assertSuccessful();

    expect($booking->rumah->fresh()->status)->toBe('booking')
        ->and($booking->fresh()->unit_dilepas_at)->toBeNull();
});

it('tidak menyentuh booking yang sudah jadi SPR', function () {
    $booking = bookingPelepasan(['tanggal_expired' => now()->subHour(), 'status' => 'sukses']);

    $this->artisan('bookings:expire')->assertSuccessful();

    expect($booking->rumah->fresh()->status)->toBe('booking');
});

function unitTersedia(): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'LQ', 'nomor_unit' => (string) (Rumah::where('blok', 'LQ')->count() + 1), 'status' => 'available',
    ]);
}

function bookingLewatForm(Rumah $rumah, ProspectCustomer $prospect, ?Sales $sales = null): void
{
    Livewire::actingAs(($sales ?? test()->sales)->fresh(), 'sales')
        ->test('pages::dbos.booking.form', ['id' => $rumah->id])
        ->call('pickProspect', $prospect->id)
        ->call('save');
}

function bookingKedaluwarsa(): Booking
{
    $booking = bookingPelepasan(['tanggal_expired' => now()->subHour()]);
    $booking->prospectCustomer->update(['bi_kol' => '1', 'bi_dbr' => 30]);

    test()->artisan('bookings:expire')->assertSuccessful();

    return $booking->fresh();
}

it('mencatat waktu pelepasan dari batas waktu booking', function () {
    $booking = bookingKedaluwarsa();

    expect($booking->unit_dilepas_at->equalTo($booking->tanggal_expired))->toBeTrue();
});

it('mencatat jam saat sales membatalkan booking', function () {
    $booking = bookingPelepasan();

    Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.booking.index')
        ->call('openCancel', $booking->id)
        ->set('cancelKeterangan', 'Konsumen mundur')
        ->call('confirmCancel')
        ->assertHasNoErrors();

    expect($booking->fresh()->unit_dilepas_at->diffInMinutes(now(), true))->toBeLessThan(1.0);
});

it('menahan booking ulang unit yang sama sampai tepat 48 jam', function () {
    $booking = bookingKedaluwarsa();
    $dilepas = $booking->unit_dilepas_at;

    $this->travelTo($dilepas->copy()->addHours(48)->subMinute());
    bookingLewatForm($booking->rumah, $booking->prospectCustomer);
    expect(Booking::where('rumah_id', $booking->rumah_id)->count())->toBe(1);

    $this->travelTo($dilepas->copy()->addHours(48));
    bookingLewatForm($booking->rumah, $booking->prospectCustomer);
    expect(Booking::where('rumah_id', $booking->rumah_id)->count())->toBe(2);
});

it('menampilkan jam berakhirnya cooldown', function () {
    $booking = bookingKedaluwarsa();

    Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.booking.unit', ['id' => $this->proyek->id, 'blok' => 'LP'])
        ->assertSee('Bisa booking ulang:')
        ->assertSee($booking->rebookingAllowedAt()->translatedFormat('d M Y H:i'));
});

it('tidak menahan sales lain saat cooldown berjalan', function () {
    $booking = bookingKedaluwarsa();

    $salesLain = Sales::create([
        'kode' => 'SLS-LN', 'nama' => 'Sales Lain', 'is_aktif' => true,
        'dbos_username' => 'sales-lain', 'dbos_password' => 'rahasia123',
    ]);
    $prospectLain = ProspectCustomer::create([
        'sales_id' => $salesLain->id, 'proyek_id' => $this->proyek->id, 'nama_lengkap' => 'Konsumen Lain',
        'nik' => '3201000000004444', 'hp' => '628120000004', 'sumber' => 'Walk-in', 'status' => 'finish',
        'bi_kol' => '1', 'bi_dbr' => 30,
    ]);

    bookingLewatForm($booking->rumah, $prospectLain, $salesLain);

    expect(Booking::where('rumah_id', $booking->rumah_id)->where('sales_id', $salesLain->id)->count())->toBe(1);
});

it('membolehkan konsumen dibooking lagi di unit lain setelah bookingnya kedaluwarsa', function () {
    $booking = bookingKedaluwarsa();
    $unitLain = unitTersedia();

    bookingLewatForm($unitLain, $booking->prospectCustomer);

    expect(Booking::where('rumah_id', $unitLain->id)->count())->toBe(1);
});

it('tetap menolak konsumen yang bookingnya masih berlaku', function () {
    $booking = bookingPelepasan();
    $booking->prospectCustomer->update(['bi_kol' => '1', 'bi_dbr' => 30]);
    $unitLain = unitTersedia();

    bookingLewatForm($unitLain, $booking->prospectCustomer);

    expect(Booking::where('rumah_id', $unitLain->id)->count())->toBe(0);
});

it('menjalankan pelepasan tiap 10 menit', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('*/10 * * * *');
});

it('menolak membuka form SPR dari booking yang lewat 24 jam', function () {
    $booking = bookingPelepasan(['tanggal_expired' => now()->subMinute()]);

    formSpr($booking)->assertRedirect(route('dbos.booking.index'));
});

it('menyimpan SPR kalau booking masih dalam 24 jam', function () {
    Storage::fake('public');
    Storage::fake('local');

    $booking = bookingPelepasan();

    isiFormSpr(formSpr($booking))->call('submit')->assertHasNoErrors();

    expect(Spr::where('booking_id', $booking->id)->count())->toBe(1)
        ->and($booking->fresh()->status)->toBe('sukses');
});

it('tidak menyimpan SPR kalau batas 24 jam lewat saat form masih terbuka', function () {
    Storage::fake('public');
    Storage::fake('local');

    $booking = bookingPelepasan();
    $form = isiFormSpr(formSpr($booking));

    $booking->update(['tanggal_expired' => now()->subMinute()]);

    $form->call('submit')->assertRedirect(route('dbos.booking.index'));

    expect(Spr::where('booking_id', $booking->id)->count())->toBe(0)
        ->and($booking->fresh()->status)->toBe('aktif');
});
