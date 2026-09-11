<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SPR pindahan yang dibuat sebelum perbaikan mengambil harga dari tipe unit
 * tujuan, padahal konsumen sudah sepakat di harga unit lamanya. Perintah ini
 * mengembalikannya — perbaikannya tidak bisa otomatis karena SPR-nya sudah
 * terlanjur ada di produksi.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-PH', 'nama' => 'Sales Pindah', 'is_aktif' => true,
        'dbos_username' => 'sales-ph', 'dbos_password' => 'rahasia123',
    ]);
});

function buatSprPindah(array $hargaAsal, array $hargaBaru): array
{
    $bikinRumah = function (string $blok, string $nomor) {
        return Rumah::create([
            'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
            'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'terjual',
        ]);
    };

    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'KONSUMEN PINDAH', 'nik' => '3200000000001234',
        'hp' => '628100001234', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $bikinSpr = function (Rumah $rumah, string $nomorSpr, array $harga, string $status) use ($prospect) {
        $booking = Booking::create([
            'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
            'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
            'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
        ]);

        return Spr::create(array_merge([
            'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
            'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
            'kategori' => test()->tipe->kategori, 'nomor_spr' => $nomorSpr,
            'tanggal_spr' => now(), 'jenis_pembayaran' => 'kpr', 'status' => $status,
        ], $harga));
    };

    $asal = $bikinSpr($bikinRumah('PA', '01'), 'SPR/2026/09/00100', $hargaAsal, 'cancelled');
    $baru = $bikinSpr($bikinRumah('PB', '01'), 'SPR/2026/09/00101', $hargaBaru, 'approved');
    $baru->update(['switched_from_spr_id' => $asal->id]);

    return [$asal, $baru->refresh()];
}

/** Harga kesepakatan konsumen: 198 juta. */
function hargaLama(): array
{
    return [
        'harga_jual' => 198_000_000, 'total_harga' => 198_000_000,
        'nilai_kpr' => 179_000_000, 'dp_nominal' => 19_000_000,
        'sbum' => 4_000_000, 'um_net' => 15_000_000,
    ];
}

/** Harga daftar unit tujuan: 185 juta — yang keliru dipakai sistem lama. */
function hargaUnitTujuan(): array
{
    return [
        'harga_jual' => 185_000_000, 'total_harga' => 185_000_000,
        'nilai_kpr' => 179_000_000, 'dp_nominal' => 6_000_000,
        'sbum' => 4_000_000, 'um_net' => 2_000_000,
    ];
}

it('hanya melapor kalau --commit tidak diberikan', function () {
    [, $baru] = buatSprPindah(hargaLama(), hargaUnitTujuan());

    $this->artisan('spr:perbaiki-harga-pindah')->assertExitCode(0);

    expect((float) $baru->refresh()->total_harga)->toBe(185000000.0);
});

it('mengembalikan seluruh blok harga dari SPR asalnya', function () {
    [, $baru] = buatSprPindah(hargaLama(), hargaUnitTujuan());

    $this->artisan('spr:perbaiki-harga-pindah', ['--commit' => true])->assertExitCode(0);

    $baru->refresh();

    expect((float) $baru->total_harga)->toBe(198000000.0)
        ->and((float) $baru->harga_jual)->toBe(198000000.0)
        ->and((float) $baru->dp_nominal)->toBe(19000000.0)
        ->and((float) $baru->um_net)->toBe(15000000.0);
});

it('menghapus refund selisih harga yang belum cair', function () {
    // Refund ini lahir dari selisih harga yang sebenarnya tidak pernah ada.
    [, $baru] = buatSprPindah(hargaLama(), hargaUnitTujuan());

    SprRealisasiPembayaran::create([
        'spr_id' => $baru->id, 'jenis' => 'refund_pindah',
        'tanggal_bayar' => now(), 'jumlah' => 13_000_000,
        'nomor_kwitansi' => null, 'metode' => 'transfer',
    ]);

    $this->artisan('spr:perbaiki-harga-pindah', ['--commit' => true])->assertExitCode(0);

    expect(SprRealisasiPembayaran::where('spr_id', $baru->id)->where('jenis', 'refund_pindah')->count())->toBe(0);
});

it('tidak menghapus refund yang sudah terlanjur dibayarkan', function () {
    // Kwitansi terbit saat keuangan mencairkan. Uangnya sudah berpindah, jadi
    // menghapus catatannya akan menyembunyikan pengeluaran yang benar-benar terjadi.
    [, $baru] = buatSprPindah(hargaLama(), hargaUnitTujuan());

    SprRealisasiPembayaran::create([
        'spr_id' => $baru->id, 'jenis' => 'refund_pindah',
        'tanggal_bayar' => now(), 'jumlah' => 13_000_000,
        'nomor_kwitansi' => 'RF-0001', 'metode' => 'transfer',
    ]);

    $this->artisan('spr:perbaiki-harga-pindah', ['--commit' => true])->assertExitCode(0);

    expect(SprRealisasiPembayaran::where('spr_id', $baru->id)->where('jenis', 'refund_pindah')->count())->toBe(1);
});

it('melewati SPR pindahan yang harganya sudah benar', function () {
    [, $baru] = buatSprPindah(hargaLama(), hargaLama());
    $sebelum = $baru->updated_at;

    $this->artisan('spr:perbaiki-harga-pindah', ['--commit' => true])->assertExitCode(0);

    expect($baru->refresh()->updated_at->eq($sebelum))->toBeTrue();
});

it('bisa dibatasi ke nomor SPR tertentu', function () {
    [, $baru] = buatSprPindah(hargaLama(), hargaUnitTujuan());

    $this->artisan('spr:perbaiki-harga-pindah', [
        '--spr' => ['SPR/2026/09/99999'],
        '--commit' => true,
    ])->assertExitCode(0);

    // Nomor yang disebut tidak ada, jadi SPR bermasalah itu tidak ikut tersentuh.
    expect((float) $baru->refresh()->total_harga)->toBe(185000000.0);
});

it('tidak menyentuh SPR biasa yang bukan hasil pindah', function () {
    [$asal, $baru] = buatSprPindah(hargaLama(), hargaUnitTujuan());
    $baru->update(['switched_from_spr_id' => null]);

    $this->artisan('spr:perbaiki-harga-pindah', ['--commit' => true])->assertExitCode(0);

    expect((float) $baru->refresh()->total_harga)->toBe(185000000.0)
        ->and((float) $asal->refresh()->total_harga)->toBe(198000000.0);
});

it('ikut mengembalikan skema pembayaran, bukan cuma angkanya', function () {
    // Konsumen yang aslinya membeli tunai pernah jadi KPR di SPR pindahannya.
    // Kalau cuma nominalnya yang disalin, hasilnya janggal: tertulis KPR tapi
    // nilai KPR-nya nol.
    [, $baru] = buatSprPindah(
        array_merge(hargaLama(), ['jenis_pembayaran' => 'cash', 'nilai_kpr' => 0, 'sbum' => 0, 'um_net' => 198_000_000]),
        array_merge(hargaUnitTujuan(), ['jenis_pembayaran' => 'kpr']),
    );

    $this->artisan('spr:perbaiki-harga-pindah', ['--commit' => true])->assertExitCode(0);

    $baru->refresh();

    expect($baru->jenis_pembayaran)->toBe('cash')
        ->and((float) $baru->nilai_kpr)->toBe(0.0)
        ->and((float) $baru->um_net)->toBe(198000000.0);
});
