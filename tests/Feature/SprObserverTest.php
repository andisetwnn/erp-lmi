<?php

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Seed seluruh master data supaya FK constraint terpenuhi.
    $this->seed();

    $proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $proyek->id)->first();
    $sales = Sales::first();
    $prospect = ProspectCustomer::first()
        ?? ProspectCustomer::create([
            'sales_id' => $sales->id,
            'nama_lengkap' => 'Test Customer',
            'nik' => '0000000000000001',
            'hp' => '0800000001',
            'sumber' => 'Walk-in',
            'status' => 'finish',
            'foto_ktp' => 'x.jpg',
            'bi_kol' => '1',
            'bi_dbr' => 25,
        ]);

    $this->rumah = Rumah::create([
        'proyek_id' => $proyek->id,
        'tipe_rumah_id' => $tipe->id,
        'blok' => 'ZZ',
        'nomor_unit' => '99',
        'status' => 'booking',
    ]);

    $this->booking = DB::table('booking')->insertGetId([
        'sales_id' => $sales->id,
        'proyek_id' => $proyek->id,
        'prospect_customer_id' => $prospect->id,
        'rumah_id' => $this->rumah->id,
        'tanggal_booking' => now(),
        'tanggal_expired' => now()->addDay(),
        'status' => 'sukses',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->salesId = $sales->id;
    $this->prospectId = $prospect->id;
});

function makeSpr(string $status = 'submitted'): Spr
{
    /** @var \Tests\TestCase $t */
    $t = test();

    return Spr::create([
        'booking_id' => $t->booking,
        'sales_id' => $t->salesId,
        'prospect_customer_id' => $t->prospectId,
        'rumah_id' => $t->rumah->id,
        'kategori' => 'subsidi',
        'nomor_spr' => 'SPR/TEST/'.uniqid(),
        'tanggal_spr' => now(),
        'harga_jual' => 185_000_000,
        'biaya_tambahan' => 18_000_000,
        'diskon' => 10_000_000,
        'total_harga' => 193_000_000,
        'jenis_pembayaran' => 'kpr',
        'dp_persen' => 5,
        'dp_nominal' => 10_000_000,
        'sbum' => 4_000_000,
        'um_net' => 6_000_000,
        'nilai_kpr' => 179_000_000,
        'utj_nominal' => 500_000,
        'utj_tanggal_bayar' => now(),
        'utj_metode' => 'transfer',
        'status' => $status,
    ]);
}

test('SPR yang langsung dibuat dengan status approved langsung lock rumah ke terjual', function () {
    expect($this->rumah->status)->toBe('booking');

    makeSpr('approved');

    expect($this->rumah->fresh()->status)->toBe('terjual');
});

test('SPR yang baru dibuat sebagai submitted tidak mengubah rumah', function () {
    makeSpr('submitted');

    expect($this->rumah->fresh()->status)->toBe('booking');
});

test('SPR berubah dari submitted ke approved akan lock rumah jadi terjual', function () {
    $spr = makeSpr('submitted');
    expect($this->rumah->fresh()->status)->toBe('booking');

    $spr->update(['status' => 'approved']);

    expect($this->rumah->fresh()->status)->toBe('terjual');
});

test('SPR yang di-revert dari approved ke rejected akan unlock rumah ke booking', function () {
    $spr = makeSpr('approved');
    expect($this->rumah->fresh()->status)->toBe('terjual');

    $spr->update(['status' => 'rejected', 'alasan_reject' => 'test']);

    expect($this->rumah->fresh()->status)->toBe('booking');
});

test('update field SPR selain status tidak mengubah rumah', function () {
    $spr = makeSpr('approved');
    Rumah::where('id', $this->rumah->id)->update(['status' => 'terjual']);

    $spr->update(['catatan' => 'edit catatan saja']);

    expect($this->rumah->fresh()->status)->toBe('terjual');
});

test('SPR approved → cancelled akan release rumah ke available (bukan booking)', function () {
    $spr = makeSpr('approved');
    expect($this->rumah->fresh()->status)->toBe('terjual');

    $spr->update(['status' => 'cancelled']);

    expect($this->rumah->fresh()->status)->toBe('available');
});
