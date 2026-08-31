<?php

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalDetail;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use App\Models\User;
use App\Services\LaporanAkuntingService;
use App\Support\RekananPilihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Buku Besar menampilkan rekanan tiap mutasi dan bisa disaring ke satu pihak saja —
 * di situlah kolom rekanan jadi berguna: menjawab "piutang si A berapa".
 *
 * Saldo awal wajib ikut tersaring. Kalau tidak, saldo berjalannya campur aduk:
 * awalnya dari semua rekanan, mutasinya cuma satu rekanan.
 */
beforeEach(function () {
    $this->seed();

    // Akun khusus uji — seeder sudah mengisi akun bawaan dengan ratusan mutasi,
    // jadi hitungan di sini harus berdiri di atas akun yang benar-benar kosong.
    $perusahaanId = Perusahaan::value('id');

    $this->piutang = Coa::create([
        'perusahaan_id' => $perusahaanId, 'kode' => '9901', 'nama' => 'Piutang Uji',
        'tipe' => 'aset', 'saldo_normal' => 'debit', 'is_header' => false, 'is_aktif' => true,
    ]);

    $this->lawan = Coa::create([
        'perusahaan_id' => $perusahaanId, 'kode' => '9902', 'nama' => 'Lawan Uji',
        'tipe' => 'pendapatan', 'saldo_normal' => 'kredit', 'is_header' => false, 'is_aktif' => true,
    ]);

    $proyek = Proyek::first();

    $this->sales = Sales::create([
        'kode' => 'SLS-B', 'nama' => 'Sales Buku', 'is_aktif' => true,
        'dbos_username' => 'sales-b', 'dbos_password' => 'rahasia123',
    ]);

    $this->budi = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $proyek->id,
        'nama_lengkap' => 'BUDI SANTOSO', 'nik' => '3200000000000021',
        'hp' => '628133333333', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $this->sinta = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $proyek->id,
        'nama_lengkap' => 'SINTA DEWI', 'nik' => '3200000000000022',
        'hp' => '628144444444', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $this->user = User::factory()->create();
    $this->user->assignRole('finance');
    $this->actingAs($this->user);
});

/** Bikin jurnal posted satu baris debet ke akun piutang atas nama rekanan tertentu. */
function jurnalPiutang(string $tanggal, float $jumlah, ?object $rekanan = null): Jurnal
{
    $jurnal = Jurnal::create([
        'perusahaan_id' => Perusahaan::value('id'),
        'tanggal' => $tanggal,
        'no_bukti' => 'UJI/'.str_replace('-', '', $tanggal).'/'.random_int(1000, 9999),
        'tipe' => 'umum',
        'kategori_bukti' => 'KAS',
        'status' => 'posted',
        'created_by_user_id' => test()->user->id,
    ]);

    JurnalDetail::create([
        'jurnal_id' => $jurnal->id,
        'coa_id' => test()->piutang->id,
        'debet' => $jumlah,
        'kredit' => 0,
        'rekanan_type' => $rekanan ? $rekanan::class : null,
        'rekanan_id' => $rekanan?->id,
    ]);

    JurnalDetail::create([
        'jurnal_id' => $jurnal->id,
        'coa_id' => test()->lawan->id,
        'debet' => 0,
        'kredit' => $jumlah,
    ]);

    return $jurnal;
}

function bukuBesar()
{
    return Livewire::test('pages::akunting.buku-besar')
        ->set('coaId', test()->piutang->id)
        ->set('from', '2026-01-01')
        ->set('to', '2026-12-31');
}

it('menampilkan semua mutasi kalau rekanan tidak disaring', function () {
    jurnalPiutang('2026-03-01', 1_000_000, $this->budi);
    jurnalPiutang('2026-03-02', 2_000_000, $this->sinta);
    jurnalPiutang('2026-03-03', 500_000);

    bukuBesar()->assertViewHas('coaSelected', fn ($c) => $c->id === $this->piutang->id)
        ->assertSet('rekananFilter', '')
        ->assertSeeText('BUDI SANTOSO')
        ->assertSeeText('SINTA DEWI');
});

it('menyaring mutasi ke satu rekanan saja', function () {
    jurnalPiutang('2026-03-01', 1_000_000, $this->budi);
    jurnalPiutang('2026-03-02', 2_000_000, $this->sinta);
    jurnalPiutang('2026-03-03', 500_000);

    $komponen = bukuBesar()
        ->call('bukaRekanan')
        ->call('pilihRekanan', RekananPilihan::nilai(ProspectCustomer::class, $this->budi->id));

    expect($komponen->instance()->mutasi)->toHaveCount(1)
        ->and((float) $komponen->instance()->totalDebet)->toBe(1000000.0);

    $komponen->assertSeeText('BUDI SANTOSO')
        ->assertDontSeeText('SINTA DEWI');
});

it('menghitung saldo awal khusus rekanan yang disaring', function () {
    // Sebelum periode: Budi 1jt, Sinta 9jt. Saldo awal Budi harus 1jt, bukan 10jt.
    jurnalPiutang('2025-12-01', 1_000_000, $this->budi);
    jurnalPiutang('2025-12-02', 9_000_000, $this->sinta);
    jurnalPiutang('2026-03-01', 2_000_000, $this->budi);

    $semua = bukuBesar();
    expect((float) $semua->instance()->saldoAwal)->toBe(10000000.0);

    $budiSaja = bukuBesar()
        ->set('rekananFilter', RekananPilihan::nilai(ProspectCustomer::class, $this->budi->id));

    expect((float) $budiSaja->instance()->saldoAwal)->toBe(1000000.0)
        ->and((float) $budiSaja->instance()->saldoAkhir)->toBe(3000000.0);
});

it('mengabaikan saringan rekanan yang nilainya tidak sah', function () {
    jurnalPiutang('2026-03-01', 1_000_000, $this->budi);
    jurnalPiutang('2026-03-02', 2_000_000, $this->sinta);

    $komponen = bukuBesar()->set('rekananFilter', 'App\Models\Master\Rumah:1');

    expect($komponen->instance()->mutasi)->toHaveCount(2);
});

it('mengosongkan saringan mengembalikan semua mutasi', function () {
    jurnalPiutang('2026-03-01', 1_000_000, $this->budi);
    jurnalPiutang('2026-03-02', 2_000_000, $this->sinta);

    $komponen = bukuBesar()
        ->set('rekananFilter', RekananPilihan::nilai(ProspectCustomer::class, $this->budi->id));
    expect($komponen->instance()->mutasi)->toHaveCount(1);

    $komponen->call('kosongkanRekananFilter')->assertSet('rekananFilter', '');
    expect($komponen->instance()->mutasi)->toHaveCount(2);
});

it('menandai mutasi tanpa rekanan dengan strip, bukan dikosongkan', function () {
    jurnalPiutang('2026-03-03', 500_000);

    bukuBesar()->assertSeeText('SALDO AWAL');
    expect(bukuBesar()->instance()->mutasi->first()->rekanan)->toBeNull();
});

it('cetak PDF mengikuti saringan rekanan yang sama dengan layar', function () {
    jurnalPiutang('2026-03-01', 1_000_000, $this->budi);
    jurnalPiutang('2026-03-02', 2_000_000, $this->sinta);

    $url = route('akunting.buku-besar.print', [
        'coa' => $this->piutang->id,
        'from' => '2026-01-01',
        'to' => '2026-12-31',
        'rekanan' => RekananPilihan::nilai(ProspectCustomer::class, $this->budi->id),
    ]);

    $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('cetak PDF tetap jalan tanpa parameter rekanan', function () {
    jurnalPiutang('2026-03-01', 1_000_000, $this->budi);

    $this->get(route('akunting.buku-besar.print', [
        'coa' => $this->piutang->id,
        'from' => '2026-01-01',
        'to' => '2026-12-31',
    ]))->assertOk();
});

it('saldo akun di service bisa dibatasi per rekanan', function () {
    jurnalPiutang('2026-03-01', 1_000_000, $this->budi);
    jurnalPiutang('2026-03-02', 9_000_000, $this->sinta);

    $svc = app(LaporanAkuntingService::class);

    expect($svc->saldoAkun($this->piutang, null, '2026-12-31'))->toBe(10000000.0)
        ->and($svc->saldoAkun($this->piutang, null, '2026-12-31',
            [ProspectCustomer::class, $this->budi->id]))->toBe(1000000.0);
});

it('memuat relasi rekanan sekaligus, bukan satu query per baris', function () {
    foreach (range(1, 6) as $i) {
        jurnalPiutang('2026-03-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 100_000, $this->budi);
    }

    $mutasi = bukuBesar()->instance()->mutasi;

    // Relasi sudah termuat sejak query pertama; kalau eager load-nya hilang, tiap baris
    // di tabel jadi satu query sendiri.
    expect($mutasi)->toHaveCount(6)
        ->and($mutasi->every(fn ($m) => $m->relationLoaded('rekanan')))->toBeTrue();
});
