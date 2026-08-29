<?php

use App\Models\Master\Booking;
use App\Models\Master\JenisSales;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Realisasi pembayaran kadang salah input dan harus dihapus. Karena ini catatan uang,
 * penghapusannya wajib meninggalkan jejak: setelah barisnya hilang, log aktivitas jadi
 * satu-satunya sumber untuk menjawab berapa nominalnya dan siapa yang menghapus.
 */
beforeEach(function () {
    $this->seed();

    $proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $proyek->id)->first();

    $sales = Sales::create([
        'kode' => 'SLS-T', 'nama' => 'Sales Test',
        'jenis_sales_id' => JenisSales::first()?->id, 'is_aktif' => true,
        'dbos_username' => 'sales-t', 'dbos_password' => 'rahasia123',
    ]);

    $rumah = Rumah::create([
        'proyek_id' => $proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'ZZ', 'nomor_unit' => '01', 'status' => 'available',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'nama_lengkap' => 'Konsumen Uji', 'nik' => '3200000000000001',
        'hp' => '628111111111', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    $this->spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => 'SPR/UJI/00001',
        'tanggal_spr' => now()->subMonth(), 'harga_jual' => 200_000_000,
        'total_harga' => 200_000_000, 'um_net' => 15_000_000,
        'jenis_pembayaran' => 'kpr', 'status' => 'approved',
    ]);

    $this->realisasi = SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDays(3), 'jumlah' => 1_000_000,
        'nomor_kwitansi' => '90001', 'metode' => 'transfer',
    ]);

    $this->finance = User::factory()->create();
    $this->finance->assignRole('finance');
});

function komponenSpr(int $sprId)
{
    return Livewire::test('pages::marketing.spr-show', ['id' => $sprId]);
}

it('menghapus realisasi yang salah input', function () {
    $this->actingAs($this->finance);

    komponenSpr($this->spr->id)
        ->call('openHapusRealisasi', $this->realisasi->id)
        ->set('hapusRealisasiAlasan', 'salah input nominal')
        ->call('hapusRealisasi')
        ->assertHasNoErrors();

    expect(SprRealisasiPembayaran::find($this->realisasi->id))->toBeNull();
});

it('menolak penghapusan tanpa alasan', function () {
    $this->actingAs($this->finance);

    komponenSpr($this->spr->id)
        ->call('openHapusRealisasi', $this->realisasi->id)
        ->set('hapusRealisasiAlasan', '')
        ->call('hapusRealisasi')
        ->assertHasErrors('hapusRealisasiAlasan');

    expect(SprRealisasiPembayaran::find($this->realisasi->id))->not->toBeNull();
});

it('menyimpan nominal dan alasannya di log sebelum barisnya hilang', function () {
    $this->actingAs($this->finance);

    komponenSpr($this->spr->id)
        ->call('openHapusRealisasi', $this->realisasi->id)
        ->set('hapusRealisasiAlasan', 'dobel dengan kwitansi 90002')
        ->call('hapusRealisasi');

    $log = Activity::where('event', 'realisasi.deleted')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and((float) $log->properties['dihapus']['jumlah'])->toBe(1000000.0)
        ->and($log->properties['nomor_kwitansi'])->toBe('90001')
        ->and($log->properties['alasan'])->toBe('dobel dengan kwitansi 90002')
        ->and($log->causer_id)->toBe($this->finance->id)
        ->and($log->description)->toContain('Hapus realisasi UM');
});

it('menolak user tanpa izin kelola pembayaran', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    komponenSpr($this->spr->id)
        ->call('openHapusRealisasi', $this->realisasi->id)
        ->assertForbidden();

    expect(SprRealisasiPembayaran::find($this->realisasi->id))->not->toBeNull();
});

it('tidak bisa menghapus baris BF lewat jalur ini', function () {
    // BF/UTJ tertaut ke kolom utj_* di SPR — menghapusnya lewat sini akan membuat
    // data booking fee tidak sinkron. Pembatalan SPR jalurnya sendiri.
    $bf = SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'bf',
        'tanggal_bayar' => now()->subMonth(), 'jumlah' => 500_000,
        'nomor_kwitansi' => '90000', 'metode' => 'transfer',
    ]);

    $this->actingAs($this->finance);

    expect(fn () => komponenSpr($this->spr->id)->call('openHapusRealisasi', $bf->id))
        ->toThrow(ModelNotFoundException::class);

    expect(SprRealisasiPembayaran::find($bf->id))->not->toBeNull();
});

it('menghapus hanya baris yang dipilih', function () {
    $lain = SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDay(), 'jumlah' => 2_000_000,
        'nomor_kwitansi' => '90002', 'metode' => 'transfer',
    ]);

    $this->actingAs($this->finance);

    komponenSpr($this->spr->id)
        ->call('openHapusRealisasi', $this->realisasi->id)
        ->set('hapusRealisasiAlasan', 'salah input')
        ->call('hapusRealisasi');

    expect(SprRealisasiPembayaran::find($this->realisasi->id))->toBeNull()
        ->and(SprRealisasiPembayaran::find($lain->id))->not->toBeNull()
        ->and((float) SprRealisasiPembayaran::where('spr_id', $this->spr->id)->where('jenis', 'um')->sum('jumlah'))->toBe(2000000.0);
});
