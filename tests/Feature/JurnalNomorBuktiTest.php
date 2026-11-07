<?php

use App\Models\Akunting\Jurnal;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\User;
use App\Services\JurnalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * No bukti mengikuti kategori dan bulan jurnal (KAS/10/26/0164). Draft yang kategori atau
 * bulannya diganti harus ikut dinomori ulang — kalau tidak, muncul KAS/... berkategori AKM —
 * dan kategorinya mengikuti akun kas/bank/akumulasi yang dipakai.
 */
beforeEach(function () {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00'));
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $this->actingAs($admin);
});

function akunJurnal(string $kode): int
{
    return Coa::where('kode', $kode)->value('id');
}

function draftJurnal(string $kategori, string $tanggal, string $akunDebet, string $akunKredit): Jurnal
{
    $svc = app(JurnalService::class);
    $perusahaanId = Perusahaan::value('id');

    return $svc->create([
        'perusahaan_id' => $perusahaanId, 'tanggal' => $tanggal, 'kategori_bukti' => $kategori,
        'no_bukti' => $svc->generateNoBukti($perusahaanId, $kategori, $tanggal), 'keterangan' => 'Akumulasi penyusutan kendaraan bermotor',
    ], [
        ['coa_id' => akunJurnal($akunDebet), 'debet' => 3_570_479],
        ['coa_id' => akunJurnal($akunKredit), 'kredit' => 3_570_479],
    ]);
}

it('menomori ulang draft yang kategorinya diganti dan mencatat nomor lamanya', function () {
    $jurnal = draftJurnal('KAS', '2026-10-31', '6004.005', '1501.001');
    expect($jurnal->no_bukti)->toBe('KAS/10/26/0001');

    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openEdit', $jurnal->id)
        ->assertSet('no_bukti', 'KAS/10/26/0001')
        ->set('kategori_bukti', 'AKM')
        ->assertSet('no_bukti', 'AKM/10/26/0001')
        ->assertSee('Menggantikan KAS/10/26/0001')
        ->call('simpan')
        ->assertHasNoErrors();

    $jurnal->refresh();
    $log = Activity::where('event', 'jurnal.diubah')->latest('id')->first();

    expect($jurnal->no_bukti)->toBe('AKM/10/26/0001')
        ->and($jurnal->kategori_bukti)->toBe('AKM')
        ->and($log->properties['catatan'])->toBe('Nomor bukti KAS/10/26/0001 → AKM/10/26/0001');
});

it('mempertahankan nomor selama kategori dan bulannya sama', function () {
    $jurnal = draftJurnal('KAS', '2026-10-20', '6002.019', '1001.001');

    $layar = Livewire::test('pages::akunting.jurnal-umum')
        ->call('openEdit', $jurnal->id)
        ->set('tanggal', '2026-10-05')
        ->assertSet('no_bukti', 'KAS/10/26/0001')
        ->set('tanggal', '2026-11-02')
        ->assertSet('no_bukti', 'KAS/11/26/0001')
        ->set('tanggal', '2026-10-25')
        ->assertSet('no_bukti', 'KAS/10/26/0001')
        ->set('keterangan', 'Biaya konsumsi rapat')
        ->call('simpan')
        ->assertHasNoErrors();

    expect($jurnal->fresh()->no_bukti)->toBe('KAS/10/26/0001')
        ->and(Activity::where('event', 'jurnal.diubah')->latest('id')->first()->properties['catatan'])->toBeNull();
});

it('memilih kategori dari akun kas, bank, atau akumulasi saat membuat jurnal', function () {
    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->assertSet('kategori_bukti', 'KAS')
        ->set('detail.0.coa_id', akunJurnal('2012.001'))
        ->set('detail.1.coa_id', akunJurnal('1002.001'))
        ->assertSet('kategori_bukti', 'BANK')
        ->assertSet('no_bukti', 'BANK/11/26/0001')
        ->set('detail.0.coa_id', akunJurnal('6004.005'))
        ->set('detail.1.coa_id', akunJurnal('1501.001'))
        ->assertSet('kategori_bukti', 'AKM')
        ->assertSet('no_bukti', 'AKM/11/26/0001');
});

it('tidak menggeser kategori yang dipilih sendiri', function () {
    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->set('kategori_bukti', 'RJE')
        ->set('detail.0.coa_id', akunJurnal('1002.001'))
        ->assertSet('kategori_bukti', 'RJE');

    $reklas = draftJurnal('RJE', '2026-10-31', '1001.002', '6002.019');

    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openEdit', $reklas->id)
        ->set('detail.1.coa_id', akunJurnal('1001.001'))
        ->assertSet('kategori_bukti', 'RJE')
        ->assertSet('no_bukti', 'RJE/10/26/0001');
});

it('menentukan kategori dari induk akun dengan urutan akumulasi, bank, lalu kas', function () {
    expect(Jurnal::kategoriDariAkun(['6004.005', '1501.001']))->toBe('AKM')
        ->and(Jurnal::kategoriDariAkun(['1001.002', '1002.001']))->toBe('BANK')
        ->and(Jurnal::kategoriDariAkun(['6002.019', '1001.001']))->toBe('KAS')
        ->and(Jurnal::kategoriDariAkun(['4001.003', '2012.001']))->toBeNull();
});
