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
 * Ada SPR bertanda KPR tapi nilai KPR-nya nol, sehingga seluruh harga jatuh jadi
 * uang muka. Itu isian yang terlewat, bukan pembelian tunai. Angkanya dihitung
 * ulang dari master tipe rumah.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();

    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();
    $this->tipe->update(['plafon_kpr' => 179_000_000, 'sbum' => 4_000_000]);

    $this->sales = Sales::create([
        'kode' => 'SLS-KPR', 'nama' => 'Sales KPR', 'is_aktif' => true,
        'dbos_username' => 'sales-kpr', 'dbos_password' => 'rahasia123',
    ]);
});

function buatSprBlokKpr(array $atribut, string $nomor = 'SPR/2026/05/00103'): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'KP', 'nomor_unit' => substr($nomor, -2), 'status' => 'terjual',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'KONSUMEN KPR', 'nik' => '32000000000'.substr($nomor, -5),
        'hp' => '628100002222', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    return Spr::create(array_merge([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => test()->tipe->kategori, 'nomor_spr' => $nomor,
        'tanggal_spr' => now(), 'jenis_pembayaran' => 'kpr', 'status' => 'cancelled',
    ], $atribut));
}

/** Bentuk bolong yang ada di produksi: seluruh harga jatuh jadi uang muka. */
function blokKprBolong(): array
{
    return [
        'harga_jual' => 185_000_000, 'biaya_tambahan' => 13_000_000,
        'total_harga' => 198_000_000,
        'nilai_kpr' => 0, 'dp_nominal' => 198_000_000,
        'sbum' => 0, 'um_net' => 198_000_000,
    ];
}

it('hanya melapor kalau --commit tidak diberikan', function () {
    $spr = buatSprBlokKpr(blokKprBolong());

    $this->artisan('spr:perbaiki-blok-kpr')->assertExitCode(0);

    expect((float) $spr->refresh()->nilai_kpr)->toBe(0.0);
});

it('menghitung ulang blok KPR dari plafon tipe rumah', function () {
    $spr = buatSprBlokKpr(blokKprBolong());

    $this->artisan('spr:perbaiki-blok-kpr', ['--commit' => true])->assertExitCode(0);

    $spr->refresh();

    // 198 juta − KPR 179 juta = UM 19 juta, dikurangi SBUM 4 juta = 15 juta.
    expect((float) $spr->nilai_kpr)->toBe(179000000.0)
        ->and((float) $spr->dp_nominal)->toBe(19000000.0)
        ->and((float) $spr->sbum)->toBe(4000000.0)
        ->and((float) $spr->um_net)->toBe(15000000.0)
        // Harga totalnya tidak disentuh — yang bolong cuma blok pembayarannya.
        ->and((float) $spr->total_harga)->toBe(198000000.0);
});

it('memperbaiki SPR yang sudah dibatalkan juga', function () {
    // SPR asal pindah kavling berstatus cancelled, tapi angkanya tetap jadi acuan
    // SPR penggantinya — jadi tetap harus benar.
    $spr = buatSprBlokKpr(blokKprBolong());

    expect($spr->status)->toBe('cancelled');

    $this->artisan('spr:perbaiki-blok-kpr', ['--commit' => true])->assertExitCode(0);

    expect((float) $spr->refresh()->nilai_kpr)->toBe(179000000.0);
});

it('tidak menyentuh SPR yang blok KPR-nya sudah terisi', function () {
    $spr = buatSprBlokKpr(array_merge(blokKprBolong(), [
        'nilai_kpr' => 179_000_000, 'dp_nominal' => 19_000_000,
        'sbum' => 4_000_000, 'um_net' => 15_000_000,
    ]));
    $sebelum = $spr->updated_at;

    $this->artisan('spr:perbaiki-blok-kpr', ['--commit' => true])->assertExitCode(0);

    expect($spr->refresh()->updated_at->eq($sebelum))->toBeTrue();
});

it('tidak menyentuh pembelian tunai', function () {
    // Tunai memang nilai KPR-nya nol — itu benar, bukan isian yang terlewat.
    $spr = buatSprBlokKpr(array_merge(blokKprBolong(), ['jenis_pembayaran' => 'cash']));

    $this->artisan('spr:perbaiki-blok-kpr', ['--commit' => true])->assertExitCode(0);

    expect((float) $spr->refresh()->nilai_kpr)->toBe(0.0);
});

it('melewati SPR yang tipe rumahnya belum punya plafon KPR', function () {
    // Menebak plafon dari harga akan melahirkan angka karangan.
    $this->tipe->update(['plafon_kpr' => 0]);
    $spr = buatSprBlokKpr(blokKprBolong());

    $this->artisan('spr:perbaiki-blok-kpr', ['--commit' => true])->assertExitCode(0);

    expect((float) $spr->refresh()->nilai_kpr)->toBe(0.0);
});

it('bisa dibatasi ke nomor SPR tertentu', function () {
    $sasaran = buatSprBlokKpr(blokKprBolong(), 'SPR/2026/05/00103');
    $lainnya = buatSprBlokKpr(blokKprBolong(), 'SPR/2026/05/00104');

    $this->artisan('spr:perbaiki-blok-kpr', [
        '--spr' => ['SPR/2026/05/00103'],
        '--commit' => true,
    ])->assertExitCode(0);

    expect((float) $sasaran->refresh()->nilai_kpr)->toBe(179000000.0)
        ->and((float) $lainnya->refresh()->nilai_kpr)->toBe(0.0);
});
