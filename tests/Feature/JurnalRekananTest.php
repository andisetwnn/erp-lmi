<?php

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalDetail;
use App\Models\Master\Bank;
use App\Models\Master\Coa;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Support\RekananPilihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Baris jurnal boleh menunjuk pihak yang bertransaksi — konsumen, bank, sales, notaris.
 * Kolomnya polymorphic, jadi yang ditunjuk adalah master yang sama dengan yang dipakai
 * modul lain, bukan salinannya. Sifatnya opsional: 2.155 jurnal lama tidak punya rekanan
 * dan harus tetap bisa disimpan maupun diedit.
 */
beforeEach(function () {
    $this->seed();

    $this->kas = Coa::where('is_header', false)->orderBy('kode')->first();
    $this->lawan = Coa::where('is_header', false)->where('id', '!=', $this->kas->id)->orderBy('kode')->first();

    $proyek = Proyek::first();

    $this->sales = Sales::create([
        'kode' => 'SLS-R', 'nama' => 'Sales Rekanan', 'is_aktif' => true,
        'dbos_username' => 'sales-r', 'dbos_password' => 'rahasia123',
    ]);

    $this->konsumen = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $proyek->id,
        'nama_lengkap' => 'BUDI SANTOSO', 'nik' => '3200000000000009',
        'hp' => '628122222222', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $this->finance = User::factory()->create();
    $this->finance->assignRole('finance');
    $this->actingAs($this->finance);
});

function isiJurnal($komponen, array $barisPertama = [], array $barisKedua = [])
{
    return $komponen
        ->call('openCreate')
        ->set('tanggal', now()->toDateString())
        ->set('keterangan', 'uji rekanan')
        ->set('detail.0', array_merge(
            ['coa_id' => test()->kas->id, 'debet' => '1.000.000', 'kredit' => '', 'rekanan' => ''],
            $barisPertama
        ))
        ->set('detail.1', array_merge(
            ['coa_id' => test()->lawan->id, 'debet' => '', 'kredit' => '1.000.000', 'rekanan' => ''],
            $barisKedua
        ));
}

it('menyimpan rekanan yang dipilih di baris jurnal', function () {
    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'), [
        'rekanan' => RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id),
    ])->call('simpan')->assertHasNoErrors();

    $detail = Jurnal::latest('id')->first()->detail;

    expect($detail[0]->rekanan_type)->toBe(ProspectCustomer::class)
        ->and($detail[0]->rekanan_id)->toBe($this->konsumen->id)
        ->and($detail[0]->rekanan->nama_lengkap)->toBe('BUDI SANTOSO')
        ->and($detail[1]->rekanan_type)->toBeNull();
});

it('tetap bisa disimpan tanpa rekanan sama sekali', function () {
    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'))
        ->call('simpan')
        ->assertHasNoErrors();

    $detail = Jurnal::latest('id')->first()->detail;

    expect($detail)->toHaveCount(2)
        ->and($detail->whereNotNull('rekanan_type'))->toBeEmpty();
});

it('menolak rekanan dari model yang tidak ada di daftar izin', function () {
    // Rumah bukan pihak yang bertransaksi. Kalau nilainya diselundupkan lewat payload,
    // baris tetap tersimpan tapi rekanannya diabaikan — bukan dipercaya mentah-mentah.
    $rumah = Rumah::create([
        'proyek_id' => Proyek::first()->id,
        'tipe_rumah_id' => TipeRumah::first()->id,
        'blok' => 'ZZ', 'nomor_unit' => '09', 'status' => 'available',
    ]);

    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'), [
        'rekanan' => Rumah::class.':'.$rumah->id,
    ])->call('simpan')->assertHasNoErrors();

    expect(Jurnal::latest('id')->first()->detail[0]->rekanan_type)->toBeNull();
});

it('mengabaikan rekanan yang barisnya sudah tidak ada', function () {
    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'), [
        'rekanan' => RekananPilihan::nilai(Sales::class, 999999),
    ])->call('simpan')->assertHasNoErrors();

    expect(Jurnal::latest('id')->first()->detail[0]->rekanan_type)->toBeNull();
});

it('memuat kembali rekanan saat jurnal draft dibuka untuk diedit', function () {
    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'), [
        'rekanan' => RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id),
    ])->call('simpan');

    $jurnal = Jurnal::latest('id')->first();

    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openEdit', $jurnal->id)
        ->assertSet('detail.0.rekanan', RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id))
        ->assertSet('detail.1.rekanan', '');
});

it('mempertahankan rekanan saat jurnal draft diedit ulang', function () {
    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'), [
        'rekanan' => RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id),
    ])->call('simpan');

    $jurnal = Jurnal::latest('id')->first();

    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openEdit', $jurnal->id)
        ->set('keterangan', 'diubah')
        ->call('simpan')
        ->assertHasNoErrors();

    $detail = $jurnal->fresh()->detail;

    expect($detail[0]->rekanan_type)->toBe(ProspectCustomer::class)
        ->and($detail[0]->rekanan_id)->toBe($this->konsumen->id);
});

it('membawa rekanan ikut ke jurnal reversal', function () {
    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'), [
        'rekanan' => RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id),
    ])->call('simpan', true);

    $asli = Jurnal::latest('id')->first();

    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openConfirm', 'reverse', $asli->id, $asli->no_bukti)
        ->call('executeConfirm');

    $reversal = Jurnal::where('reversed_from_jurnal_id', $asli->id)->firstOrFail();

    expect($reversal->detail->firstWhere('rekanan_id', $this->konsumen->id))->not->toBeNull();
});

it('memilih rekanan lewat modal untuk baris yang diklik', function () {
    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->call('openRekanan', 1)
        ->assertSet('rekananTujuan', '1')
        ->call('pilihRekanan', RekananPilihan::nilai(Sales::class, $this->sales->id))
        ->assertSet('detail.1.rekanan', RekananPilihan::nilai(Sales::class, $this->sales->id))
        ->assertSet('detail.0.rekanan', '')
        ->assertSet('rekananTujuan', null);
});

it('mengosongkan rekanan satu baris tanpa mengganggu baris lain', function () {
    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->call('openRekanan', 0)
        ->call('pilihRekanan', RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id))
        ->call('openRekanan', 1)
        ->call('pilihRekanan', RekananPilihan::nilai(Sales::class, $this->sales->id))
        ->call('kosongkanRekanan', 0)
        ->assertSet('detail.0.rekanan', '')
        ->assertSet('detail.1.rekanan', RekananPilihan::nilai(Sales::class, $this->sales->id));
});

it('menyaring daftar rekanan lewat pencarian dan kategori', function () {
    Bank::create(['nama' => 'BTN Syariah']);

    $komponen = Livewire::test('pages::akunting.jurnal-umum')->call('openCreate');

    $komponen->set('rekananCari', 'BUDI')
        ->assertViewHas('rekananJumlah', 1)
        ->assertViewHas('rekananHalamanIni', fn ($h) => $h->first()['nama'] === 'BUDI SANTOSO');

    $komponen->set('rekananCari', '')
        ->set('rekananKategori', 'Bank')
        ->assertViewHas('rekananHalamanIni', fn ($h) => $h->every(fn ($r) => $r['kategori'] === 'Bank'));
});

it('mencari juga lewat kode, bukan cuma nama', function () {
    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->set('rekananCari', '3200000000000009')
        ->assertViewHas('rekananHalamanIni', fn ($h) => $h->count() === 1
            && $h->first()['nama'] === 'BUDI SANTOSO');
});

it('mengembalikan ke halaman satu tiap kali saringan berubah', function () {
    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->call('gantiHalamanRekanan', 3)
        ->assertSet('rekananHalaman', 3)
        ->set('rekananCari', 'BUDI')
        ->assertSet('rekananHalaman', 1);
});

it('tidak melewati halaman terakhir saat saringan menyusut', function () {
    // Halaman 99 tidak ada isinya; yang tampil harus halaman terakhir yang valid,
    // bukan tabel kosong yang bikin orang mengira datanya hilang.
    $komponen = Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->call('gantiHalamanRekanan', 99);

    $komponen->assertViewHas('rekananHalamanAktif', $komponen->viewData('rekananTotalHalaman'))
        ->assertViewHas('rekananHalamanIni', fn ($h) => $h->isNotEmpty());
});

it('mengabaikan permintaan buka rekanan untuk baris yang tidak ada', function () {
    Livewire::test('pages::akunting.jurnal-umum')
        ->call('openCreate')
        ->call('openRekanan', 99)
        ->assertSet('rekananTujuan', null);
});

it('menyusun daftar pilihan dari master yang sudah ada', function () {
    Bank::create(['nama' => 'BTN Syariah']);

    $daftar = RekananPilihan::daftar();
    $kategori = $daftar->pluck('kategori')->unique()->values()->all();

    expect($kategori)->toContain('Konsumen', 'Bank', 'Sales')
        ->and($daftar->firstWhere('nama', 'BUDI SANTOSO'))
        ->toMatchArray([
            'kategori' => 'Konsumen',
            'kode' => '3200000000000009',
            'nilai' => RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id),
        ]);
});

it('mengurutkan daftar per nama, bukan dikelompokkan per kategori', function () {
    // Kalau diurut per kategori, halaman pertama habis dipakai Bank saja — 42 baris
    // tanpa kode, dan konsumen yang jumlahnya mayoritas malah tidak kelihatan.
    Bank::create(['nama' => 'ZZZ Bank Terakhir']);

    $nama = RekananPilihan::daftar()->pluck('nama')->all();
    $urut = $nama;
    usort($urut, fn ($a, $b) => strcasecmp($a, $b));

    expect($nama)->toBe($urut)
        ->and(RekananPilihan::daftar()->last()['nama'])->toBe('ZZZ Bank Terakhir');
});

it('memberi label berkategori untuk ditampilkan', function () {
    expect(RekananPilihan::label(ProspectCustomer::class, $this->konsumen->id))
        ->toBe('Konsumen · BUDI SANTOSO')
        ->and(RekananPilihan::label(Sales::class, $this->sales->id))
        ->toBe('Sales · Sales Rekanan')
        ->and(RekananPilihan::label(null, null))->toBeNull()
        ->and(RekananPilihan::label(Rumah::class, 1))->toBeNull();
});

it('menyimpan rekanan berbeda di tiap baris', function () {
    isiJurnal(
        Livewire::test('pages::akunting.jurnal-umum'),
        ['rekanan' => RekananPilihan::nilai(ProspectCustomer::class, $this->konsumen->id)],
        ['rekanan' => RekananPilihan::nilai(Sales::class, $this->sales->id)],
    )->call('simpan')->assertHasNoErrors();

    $detail = Jurnal::latest('id')->first()->detail;

    expect($detail[0]->rekanan_type)->toBe(ProspectCustomer::class)
        ->and($detail[1]->rekanan_type)->toBe(Sales::class)
        ->and($detail[1]->rekanan_id)->toBe($this->sales->id);
});

it('tidak mengubah jurnal lama yang rekanannya kosong', function () {
    $sebelum = JurnalDetail::whereNotNull('rekanan_type')->count();

    isiJurnal(Livewire::test('pages::akunting.jurnal-umum'))->call('simpan');

    expect(JurnalDetail::whereNotNull('rekanan_type')->count())->toBe($sebelum);
});
