<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Jadwal cicilan dibuat sekali saat SPR terbentuk. Kalau nilai UM-nya belakangan
 * diperbaiki, jadwalnya tetap memakai angka lama — konsumen melihat cicilan yang
 * jauh dari kewajibannya.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-TR', 'nama' => 'Sales Termin', 'is_aktif' => true,
        'dbos_username' => 'sales-tr', 'dbos_password' => 'rahasia123',
    ]);
});

function buatSprTermin(array $atribut = [], array $jadwalUm = []): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'TR', 'nomor_unit' => '01', 'status' => 'terjual',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'KONSUMEN TERMIN', 'nik' => '3200000000005555',
        'hp' => '628100005555', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    $spr = Spr::create(array_merge([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => 'SPR/2026/09/00300',
        'tanggal_spr' => '2026-04-23', 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'harga_jual' => 185_000_000, 'total_harga' => 198_000_000,
        'nilai_kpr' => 179_000_000, 'sbum' => 4_000_000,
        'um_net' => 15_000_000, 'utj_nominal' => 500_000,
        'utj_tanggal_transaksi' => '2026-04-23',
    ], $atribut));

    foreach ($jadwalUm as $i => $jumlah) {
        $spr->terminPembayaran()->create([
            'jenis' => 'um', 'urutan' => $i + 1,
            'tanggal_jadwal' => sprintf('2026-%02d-23', 5 + $i),
            'jumlah_jadwal' => $jumlah,
        ]);
    }

    return $spr->refresh();
}

/** Jadwal bolong: dibuat dari UM 198 juta, bukan 15 juta. */
function jadwalSalah(): array
{
    return [49_375_000, 49_375_000, 49_375_000, 49_375_000];
}

it('hanya melapor kalau --commit tidak diberikan', function () {
    $spr = buatSprTermin([], jadwalSalah());

    $this->artisan('spr:perbaiki-jadwal-termin')->assertExitCode(0);

    expect((float) $spr->refresh()->terminPembayaran->where('jenis', 'um')->sum('jumlah_jadwal'))
        ->toBe(197500000.0);
});

it('menyusun ulang cicilan dari UM bersih dikurangi UTJ', function () {
    $spr = buatSprTermin([], jadwalSalah());

    $this->artisan('spr:perbaiki-jadwal-termin', ['--commit' => true])->assertExitCode(0);

    $um = $spr->refresh()->terminPembayaran->where('jenis', 'um')->sortBy('urutan')->values();

    // (15.000.000 − 500.000 UTJ) / 4 = 3.625.000
    expect($um)->toHaveCount(4)
        ->and($um->pluck('jumlah_jadwal')->map(fn ($v) => (float) $v)->all())
        ->toBe([3625000.0, 3625000.0, 3625000.0, 3625000.0]);
});

it('tidak menyentuh jadwal yang sudah sesuai', function () {
    $spr = buatSprTermin([], [3_625_000, 3_625_000, 3_625_000, 3_625_000]);
    $sebelum = $spr->terminPembayaran->where('jenis', 'um')->pluck('id')->sort()->values()->all();

    $this->artisan('spr:perbaiki-jadwal-termin', ['--commit' => true])->assertExitCode(0);

    $sesudah = $spr->refresh()->terminPembayaran->where('jenis', 'um')->pluck('id')->sort()->values()->all();

    expect($sesudah)->toBe($sebelum);
});

it('melewati SPR yang terminnya sudah ada realisasinya', function () {
    // Baris termin menyimpan jadwal sekaligus pembayaran. Menyusun ulang akan
    // menghapus catatan uang yang benar-benar sudah masuk.
    $spr = buatSprTermin([], jadwalSalah());

    $spr->terminPembayaran()->where('jenis', 'um')->where('urutan', 1)->update([
        'tanggal_realisasi' => '2026-05-23', 'jumlah' => 49_375_000,
        'nomor_kwitansi' => 'KW-0001',
    ]);

    $this->artisan('spr:perbaiki-jadwal-termin', ['--commit' => true])->assertExitCode(0);

    expect((float) $spr->refresh()->terminPembayaran->where('jenis', 'um')->sum('jumlah_jadwal'))
        ->toBe(197500000.0);
});

it('tidak menyentuh unit komersial', function () {
    // Komersial tidak memakai skema cicilan uang muka.
    $spr = buatSprTermin(['kategori' => 'komersial'], jadwalSalah());

    $this->artisan('spr:perbaiki-jadwal-termin', ['--commit' => true])->assertExitCode(0);

    expect((float) $spr->refresh()->terminPembayaran->where('jenis', 'um')->sum('jumlah_jadwal'))
        ->toBe(197500000.0);
});

it('bisa dibatasi ke nomor SPR tertentu', function () {
    $spr = buatSprTermin([], jadwalSalah());

    $this->artisan('spr:perbaiki-jadwal-termin', [
        '--spr' => ['SPR/2026/09/99999'],
        '--commit' => true,
    ])->assertExitCode(0);

    expect((float) $spr->refresh()->terminPembayaran->where('jenis', 'um')->sum('jumlah_jadwal'))
        ->toBe(197500000.0);
});
