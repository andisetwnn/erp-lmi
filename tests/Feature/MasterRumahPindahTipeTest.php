<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Pindah tipe beberapa unit sekaligus di Master Rumah.
 *
 * Dibatasi ke unit yang masih available. Unit yang sudah booking atau terjual
 * punya SPR yang harga, uang muka, dan plafon KPR-nya diturunkan dari tipe ini —
 * memindahkannya berarti menggeser dasar perjanjian yang sudah ditandatangani.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipeLama = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->tipeBaru = TipeRumah::create([
        'proyek_id' => $this->proyek->id, 'tipe' => 'Sadewa 36/72',
        'nama_tipe' => 'Sadewa', 'kategori' => 'subsidi',
        'luas_bangunan' => 36, 'luas_tanah' => 72, 'harga_jual' => 245_000_000,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->actingAs($this->admin);
});

function unitPindah(string $nomor, string $status = 'available'): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipeLama->id,
        'blok' => 'PT', 'nomor_unit' => $nomor, 'status' => $status,
    ]);
}

function proyekLainnya(string $nama, string $kode): Proyek
{
    return Proyek::create([
        'nama_proyek' => $nama, 'nama_perumahan' => $nama,
        'desa' => 'Sukamaju', 'kelurahan' => 'Sukamaju',
        'kecamatan' => 'Cibinong', 'kota_kabupaten' => 'Bogor',
        'kode_surat' => $kode, 'kode_akuntansi' => $kode, 'kode_virtual_account' => $kode,
    ]);
}

function halamanRumah()
{
    return Livewire::test('pages::master.rumah')
        ->set('selectedProyekId', test()->proyek->id);
}

it('memindahkan unit available ke tipe baru', function () {
    $satu = unitPindah('01');
    $dua = unitPindah('02');

    halamanRumah()
        ->set('terpilih', [$satu->id, $dua->id])
        ->set('pindah_tipe_id', $this->tipeBaru->id)
        ->call('simpanPindahTipe')
        ->assertHasNoErrors();

    expect($satu->fresh()->tipe_rumah_id)->toBe($this->tipeBaru->id)
        ->and($dua->fresh()->tipe_rumah_id)->toBe($this->tipeBaru->id);
});

it('menolak memindahkan unit yang sudah booking atau terjual', function () {
    // Penjagaannya di sisi server, bukan sekadar centangnya disembunyikan —
    // daftar pilihan hidup di sisi pengguna dan bisa dikirim apa adanya.
    $terjual = unitPindah('03', 'terjual');
    $booking = unitPindah('04', 'booking');

    halamanRumah()
        ->set('terpilih', [$terjual->id, $booking->id])
        ->set('pindah_tipe_id', $this->tipeBaru->id)
        ->call('simpanPindahTipe');

    expect($terjual->fresh()->tipe_rumah_id)->toBe($this->tipeLama->id)
        ->and($booking->fresh()->tipe_rumah_id)->toBe($this->tipeLama->id);
});

it('tetap memindahkan yang available walau pilihannya tercampur', function () {
    $available = unitPindah('05');
    $terjual = unitPindah('06', 'terjual');

    halamanRumah()
        ->set('terpilih', [$available->id, $terjual->id])
        ->set('pindah_tipe_id', $this->tipeBaru->id)
        ->call('simpanPindahTipe');

    expect($available->fresh()->tipe_rumah_id)->toBe($this->tipeBaru->id)
        ->and($terjual->fresh()->tipe_rumah_id)->toBe($this->tipeLama->id);
});

it('tidak menyentuh unit draft', function () {
    // Draft belum diluncurkan; tipenya diatur lewat form unit, bukan lewat
    // pemindahan massal yang ditujukan untuk stok yang sudah dipasarkan.
    $draft = unitPindah('07', 'draft');

    halamanRumah()
        ->set('terpilih', [$draft->id])
        ->set('pindah_tipe_id', $this->tipeBaru->id)
        ->call('simpanPindahTipe');

    expect($draft->fresh()->tipe_rumah_id)->toBe($this->tipeLama->id);
});

it('menolak tipe tujuan dari proyek lain', function () {
    $proyekLain = proyekLainnya('Proyek Lain', 'LAIN');

    $tipeAsing = TipeRumah::create([
        'proyek_id' => $proyekLain->id, 'tipe' => 'Bima 45/90',
        'nama_tipe' => 'Bima', 'kategori' => 'komersial',
        'luas_bangunan' => 45, 'luas_tanah' => 90, 'harga_jual' => 450_000_000,
    ]);

    $unit = unitPindah('08');

    halamanRumah()
        ->set('terpilih', [$unit->id])
        ->set('pindah_tipe_id', $tipeAsing->id)
        ->call('simpanPindahTipe')
        ->assertHasErrors(['pindah_tipe_id']);

    expect($unit->fresh()->tipe_rumah_id)->toBe($this->tipeLama->id);
});

it('mewajibkan tipe tujuan dipilih', function () {
    $unit = unitPindah('09');

    halamanRumah()
        ->set('terpilih', [$unit->id])
        ->call('simpanPindahTipe')
        ->assertHasErrors(['pindah_tipe_id']);

    expect($unit->fresh()->tipe_rumah_id)->toBe($this->tipeLama->id);
});

it('menampilkan harga tiap tipe tujuan', function () {
    // Harga menentukan pilihan. Kalau kolomnya tidak ikut diambil, yang tampil
    // Rp 0 — dan nol terbaca sebagai harga sungguhan, bukan sebagai data hilang.
    unitPindah('16');

    halamanRumah()->assertSee('Rp '.number_format($this->tipeBaru->harga_jual, 0, ',', '.'));
});

it('mencatat siapa yang memindahkan', function () {
    $unit = unitPindah('10');

    halamanRumah()
        ->set('terpilih', [$unit->id])
        ->set('pindah_tipe_id', $this->tipeBaru->id)
        ->call('simpanPindahTipe');

    expect($unit->fresh()->updated_by_user_id)->toBe($this->admin->id);
});

it('mengosongkan pilihan setelah dipindah', function () {
    $unit = unitPindah('11');

    halamanRumah()
        ->set('terpilih', [$unit->id])
        ->set('pindah_tipe_id', $this->tipeBaru->id)
        ->call('simpanPindahTipe')
        ->assertSet('terpilih', []);
});

it('melepas pilihan saat proyek aktif berganti', function () {
    // Id unit milik proyek sebelumnya tidak ada artinya di proyek lain.
    $unit = unitPindah('12');

    $proyekLain = proyekLainnya('Proyek Ganti', 'GANTI');

    halamanRumah()
        ->set('terpilih', [$unit->id])
        ->call('syncFromGlobalPicker', $proyekLain->id)
        ->assertSet('terpilih', []);
});
