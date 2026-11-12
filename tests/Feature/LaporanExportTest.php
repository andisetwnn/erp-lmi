<?php

use App\Exports\LaporanExport;
use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\SprSwitchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Export Excel di menu Laporan harus berisi baris yang sama dengan tab yang sedang
 * dibuka. Sebelumnya tab Pindah Kavling menghasilkan berkas kosong, dan saringan
 * Kategori (Subsidi/Komersial) tidak ikut terbawa ke berkas.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->sales = Sales::create([
        'kode' => 'SLS-X', 'nama' => 'Sales Export', 'is_aktif' => true,
        'dbos_username' => 'sales-x', 'dbos_password' => 'rahasia123',
    ]);
    $this->pm = User::factory()->create();
    $this->pm->assignRole('project-manager');
});

function tipeExport(string $kategori): TipeRumah
{
    return TipeRumah::create([
        'proyek_id' => test()->proyek->id, 'tipe' => 'EXP '.$kategori, 'nama_tipe' => 'Export '.$kategori,
        'kategori' => $kategori, 'harga_jual' => 185_000_000,
    ]);
}

function sprExport(string $blok, TipeRumah $tipe, string $kode): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => $blok, 'nomor_unit' => '1', 'status' => 'terjual',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'Konsumen '.$kode, 'nik' => '32'.str_pad((string) crc32($kode), 14, '0'),
        'hp' => '628100000000', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    return Spr::create([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => $tipe->kategori, 'nomor_spr' => 'SPR/EXPORT/'.$kode,
        'tanggal_spr' => now()->subMonth(), 'harga_jual' => 185_000_000,
        'total_harga' => 185_000_000, 'um_net' => 10_000_000, 'utj_nominal' => 500_000,
        'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'approved_by_user_id' => test()->pm->id, 'approved_at' => now()->subDays(20),
        'pm_approved_by_user_id' => test()->pm->id, 'pm_approved_at' => now()->subDays(18),
        'materai_file_path' => 'spr/export-materai.pdf', 'konsumen_signed_at' => now()->subDays(15),
        'spr_finalized_at' => now()->subDays(14),
    ]);
}

it('mengisi export Pindah Kavling dengan transaksi pindah', function () {
    $tipe = tipeExport('subsidi');
    $lama = sprExport('XA', $tipe, 'PINDAH');
    $tujuan = Rumah::create([
        'proyek_id' => $this->proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'XB', 'nomor_unit' => '2', 'status' => 'available',
    ]);
    app(SprSwitchingService::class)->pindahUnit($lama, $tujuan->id, 'dekat jalan utama', $this->pm->id);

    $export = new LaporanExport(tab: 'pindah', proyekId: $this->proyek->id, from: now()->subYear(), to: now()->addDay());
    $baris = $export->array();

    expect($export->headings())->toContain('Nomor Transaksi', 'Unit Lama', 'Unit Baru')
        ->and($export->title())->toBe('Pindah Kavling')
        ->and($baris)->toHaveCount(1)
        ->and($baris[0][2])->toBe('Pindah')
        ->and($baris[0][3])->toBe('Konsumen PINDAH')
        ->and($baris[0][4])->toBe($lama->rumah->kode_unit)
        ->and($baris[0][5])->toBe($tujuan->kode_unit)
        ->and($baris[0][11])->toBe('dekat jalan utama');
});

it('membawa saringan kategori ke export', function () {
    sprExport('XC', tipeExport('subsidi'), 'SUBSIDI');
    sprExport('XD', tipeExport('komersial'), 'KOMERSIAL');

    $nomor = fn (?string $kategori) => collect((new LaporanExport(tab: 'penjualan', proyekId: $this->proyek->id, kategori: $kategori))->array())
        ->pluck(0)->filter(fn ($n) => str_starts_with((string) $n, 'SPR/EXPORT/'))->values()->all();

    expect($nomor('subsidi'))->toBe(['SPR/EXPORT/SUBSIDI'])
        ->and($nomor('komersial'))->toBe(['SPR/EXPORT/KOMERSIAL'])
        ->and($nomor(null))->toHaveCount(2);
});

it('membawa saringan kategori ke export stok unit', function () {
    sprExport('XE', tipeExport('komersial'), 'STOK');

    $unit = collect((new LaporanExport(tab: 'stock', proyekId: $this->proyek->id, kategori: 'subsidi'))->array())->pluck(0);

    expect($unit)->not->toContain('XE-01');
});
