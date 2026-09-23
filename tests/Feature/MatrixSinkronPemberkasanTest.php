<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use App\Models\Master\TipeRumah;
use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use App\Services\MatrixSinkronPemberkasan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tanggal tahapan berkas KPR di Matrix bisa disalurkan ke modul Pemberkasan.
 * Bedanya dengan progres fisik: modul ini sudah dipakai Admin KPR sehari-hari,
 * jadi isian mereka tidak boleh tergeser oleh unggahan.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-PB', 'nama' => 'Sales Berkas', 'is_aktif' => true,
        'dbos_username' => 'sales-pb', 'dbos_password' => 'rahasia123',
    ]);

    $this->import = MatrixImport::create([
        'nama_file' => 'MATRIX.xlsx', 'proyek' => $this->proyek->nama_proyek,
        'minggu_ke' => 'MINGGU KE-24', 'periode' => 'Per September 2026', 'jumlah_baris' => 0,
    ]);
});

function buatSprBerkas(string $blok, string $nomor, array $atribut = []): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'terjual',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => "KONSUMEN $blok$nomor", 'nik' => '32000000000'.str_pad($nomor, 5, '0', STR_PAD_LEFT),
        'hp' => '628100007777', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    return Spr::create(array_merge([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => "SPR/2026/09/004$nomor",
        'tanggal_spr' => now(), 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'harga_jual' => 198_000_000, 'total_harga' => 198_000_000, 'um_net' => 15_000_000,
    ], $atribut));
}

function buatBarisBerkas(string $blok, string $nomor, array $tahapan = []): MatrixUnit
{
    return MatrixUnit::create(array_merge([
        'matrix_import_id' => test()->import->id, 'kategori' => 'mikro',
        'seksi' => 'ACC SP3K', 'urutan' => 1, 'nama' => 'KONSUMEN',
        'blok' => $blok, 'nomor_unit' => $nomor,
    ], $tahapan));
}

function sinkronBerkas(?int $userId = null): array
{
    return app(MatrixSinkronPemberkasan::class)->jalankan(test()->import, $userId);
}

it('membuat baris pemberkasan baru dari tanggal di berkas', function () {
    $spr = buatSprBerkas('PB', '01');
    buatBarisBerkas('PB', '01', [
        'bm' => '2026-03-14', 'wcr' => '2026-03-14',
        'sp3k' => '2026-03-17', 'bank_ko' => 'CBN',
    ]);

    $hasil = sinkronBerkas();

    $berkas = SprPemberkasan::where('spr_id', $spr->id)->first();

    expect($berkas)->not->toBeNull()
        ->and($berkas->bm_tanggal->toDateString())->toBe('2026-03-14')
        ->and($berkas->sp3k_tanggal->toDateString())->toBe('2026-03-17')
        ->and($berkas->bank_kode)->toBe('CBN')
        ->and($hasil['baru'])->toBe(1);
});

it('menghitung sendiri masa berlaku SP3K, tidak mengambil dari berkas', function () {
    // Matrix kadang mencatat +91 atau +92 hari. Satu aturan saja yang berlaku.
    $spr = buatSprBerkas('PB', '02');
    buatBarisBerkas('PB', '02', ['sp3k' => '2026-03-01', 'exp_sp3k' => '2026-06-15']);

    sinkronBerkas();

    expect(SprPemberkasan::where('spr_id', $spr->id)->value('sp3k_expired')->toDateString())
        ->toBe('2026-05-30');  // 1 Maret + 90 hari
});

it('tidak menggeser tanggal yang sudah diinput Admin KPR', function () {
    $spr = buatSprBerkas('PB', '03');
    SprPemberkasan::create(['spr_id' => $spr->id, 'bm_tanggal' => '2026-01-05']);

    buatBarisBerkas('PB', '03', ['bm' => '2026-03-14', 'wcr' => '2026-03-20']);

    sinkronBerkas();

    $berkas = SprPemberkasan::where('spr_id', $spr->id)->first();

    expect($berkas->bm_tanggal->toDateString())->toBe('2026-01-05')
        // Yang kosong tetap diisi.
        ->and($berkas->wcr_tanggal->toDateString())->toBe('2026-03-20');
});

it('ikut mengisi SPR yang sudah akad', function () {
    // Daftar pemberkasan memuat yang sudah akad juga — LPA dan sertifikat
    // sering baru turun sesudahnya.
    $spr = buatSprBerkas('PB', '04', ['status' => 'akad', 'tgl_akad' => '2026-05-20']);
    buatBarisBerkas('PB', '04', ['lpa' => '2026-06-01']);

    sinkronBerkas();

    expect(SprPemberkasan::where('spr_id', $spr->id)->value('lpa_tanggal')->toDateString())->toBe('2026-06-01');
});

it('melewati pembelian tunai', function () {
    // Tunai tidak punya berkas bank untuk diurus.
    $spr = buatSprBerkas('PB', '05', ['jenis_pembayaran' => 'cash']);
    buatBarisBerkas('PB', '05', ['bm' => '2026-03-14']);

    $hasil = sinkronBerkas();

    expect(SprPemberkasan::where('spr_id', $spr->id)->exists())->toBeFalse()
        ->and($hasil['tanpa_spr'])->toBe(1);
});

it('mengabaikan kode bank yang tidak dikenali', function () {
    $spr = buatSprBerkas('PB', '06');
    buatBarisBerkas('PB', '06', ['bm' => '2026-03-14', 'bank_ko' => 'XYZ']);

    sinkronBerkas();

    expect(SprPemberkasan::where('spr_id', $spr->id)->value('bank_kode'))->toBeNull();
});

it('tidak berbuat apa-apa kalau dijalankan dua kali', function () {
    buatSprBerkas('PB', '07');
    buatBarisBerkas('PB', '07', ['bm' => '2026-03-14', 'sp3k' => '2026-03-20']);

    expect(sinkronBerkas()['diperbarui'])->toBe(1)
        ->and(sinkronBerkas()['diperbarui'])->toBe(0);
});

it('melewati baris yang tidak punya tanggal tahapan sama sekali', function () {
    $spr = buatSprBerkas('PB', '08');
    buatBarisBerkas('PB', '08');

    $hasil = sinkronBerkas();

    expect(SprPemberkasan::where('spr_id', $spr->id)->exists())->toBeFalse()
        ->and($hasil['diperbarui'])->toBe(0)
        ->and($hasil['tanpa_spr'])->toBe(0);
});
