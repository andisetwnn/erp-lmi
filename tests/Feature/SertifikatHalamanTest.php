<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sertifikat;
use App\Models\Master\SertifikatSerahTerima;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\SertifikatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Empat halaman Perizinan: satu untuk melihat, tiga untuk mengisi.
 *
 * Dipisah supaya layar arsip tidak berpindah-pindah keadaan antara membaca dan
 * mengisi — dan supaya tiap tahap bisa dikunci izinnya sendiri, kalau nanti
 * serah terima ke bank ternyata dikerjakan peran lain.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->legal = User::factory()->create();
    $this->legal->assignRole('admin-legal');
    $this->actingAs($this->legal);

    $this->svc = app(SertifikatService::class);
});

function unitHal(string $blok, string $nomor): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'available',
    ]);
}

/** Sertifikat yang sudah terbit, siap diserahkan. */
function terbitHal(string $blok, string $nomor, string $no): Sertifikat
{
    $unit = unitHal($blok, $nomor);
    test()->svc->ajukan(test()->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0], '', '', test()->legal->id);

    $s = Sertifikat::where('rumah_id', $unit->id)->first();
    $s->update(['nomor_sertifikat' => $no, 'nib' => '6'.$unit->id, 'tanggal_terbit' => '2026-10-05']);

    return $s->fresh();
}

// ─────────────────── Halaman arsip ───────────────────

it('membuka halaman arsip untuk yang hanya boleh melihat', function () {
    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');

    $this->actingAs($direktur)->get(route('perizinan.sertifikat.index'))->assertOk();
});

it('menolak peran yang tidak berkepentingan', function () {
    $teknik = User::factory()->create();
    $teknik->assignRole('admin-teknik');

    $this->actingAs($teknik)->get(route('perizinan.sertifikat.index'))->assertForbidden();
});

it('menampilkan saringan walau proyek belum dipilih', function () {
    // Saringan muncul lebih dulu; datanya baru menyusul setelah proyek dipilih
    // dari picker di sidebar.
    Livewire::test('pages::perizinan.sertifikat')
        ->set('proyekId', null)
        ->assertSee('Semua notaris')
        ->assertSee('Pilih proyek dulu');
});

it('menunggu tombol Tampilkan, tidak menyaring sambil diketik', function () {
    // Tabelnya lebar dan datanya banyak — menyaring tiap ketukan memberatkan.
    $a = terbitHal('AA', '01', 'SHGB 100');
    unitHal('AB', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [Rumah::where('blok', 'AB')->first()->id => 70.0]);

    $komponen = Livewire::test('pages::perizinan.sertifikat')
        ->set('proyekId', $this->proyek->id)
        ->set('status', 'terbit');

    // Belum ditekan — kedua baris masih tampil.
    expect($komponen->viewData('baris')->total())->toBe(2);

    $komponen->call('tampilkan');

    expect($komponen->viewData('baris')->pluck('id')->all())->toBe([$a->id]);
});

it('menghitung ringkasan per keadaan datanya', function () {
    unitHal('BA', '01');                               // belum diajukan
    $unit = unitHal('BA', '02');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 50.0]);  // proses
    $terbit = terbitHal('BA', '03', 'SHGB 200');       // terbit, belum ke bank
    $diBank = terbitHal('BA', '04', 'SHGB 201');
    $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$diBank->id]);

    $r = Livewire::test('pages::perizinan.sertifikat')
        ->set('proyekId', $this->proyek->id)
        ->viewData('ringkasan');

    expect($r['belum_diajukan'])->toBe(1)
        ->and($r['proses'])->toBe(1)
        ->and($r['belum_ke_bank'])->toBe(1)
        ->and($r['di_bank'])->toBe(1)
        // 50 diajukan + 70 + 70 dari dua yang terbit
        ->and($r['luas'])->toBe(190.0);
});

// ─────────────────── Halaman pengajuan ───────────────────

it('mengajukan unit yang dicentang beserta luasnya', function () {
    $a = unitHal('DA', '01');
    $b = unitHal('DA', '02');

    Livewire::test('pages::perizinan.sertifikat-pengajuan')
        ->set('proyekId', $this->proyek->id)
        ->set('pilih', [$a->id => true, $b->id => true])
        ->set('luas', [$a->id => '72.5', $b->id => '80'])
        ->call('simpan');

    expect(Sertifikat::count())->toBe(2)
        ->and((float) Sertifikat::where('rumah_id', $a->id)->value('luas_diajukan'))->toBe(72.5);
});

it('mengisi luas usulan saat mencentang semua', function () {
    unitHal('DB', '01');

    $luas = Livewire::test('pages::perizinan.sertifikat-pengajuan')
        ->set('proyekId', $this->proyek->id)
        ->call('pilihSemua')
        ->get('luas');

    expect(array_values($luas)[0])->toBe((string) (float) $this->tipe->luas_tanah);
});

it('menolak halaman pengajuan tanpa izinnya', function () {
    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');   // hanya legal.lihat

    $this->actingAs($direktur)->get(route('perizinan.sertifikat.pengajuan'))->assertForbidden();
});

// ─────────────────── Halaman input data ───────────────────

it('menyimpan data sertifikat yang terbit', function () {
    $unit = unitHal('EA', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0]);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    Livewire::test('pages::perizinan.sertifikat-input')
        ->set('proyekId', $this->proyek->id)
        ->call('openIsi', $s->id)
        ->set('nomorSertifikat', 'SHGB 01234')
        ->set('nib', '64885')
        ->set('hgbInduk', 'HGB 9/Sukamaju')
        ->set('luasSertifikat', '68.75')
        ->call('simpan')
        ->assertHasNoErrors();

    $s = $s->fresh();

    expect($s->nomor_sertifikat)->toBe('SHGB 01234')
        ->and($s->nib)->toBe('64885')
        ->and($s->hgb_induk)->toBe('HGB 9/Sukamaju')
        ->and($s->status())->toBe('terbit')
        ->and($s->luasBerlaku())->toBe(68.75);
});

it('mewajibkan nomor sertifikat', function () {
    $unit = unitHal('EB', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0]);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    Livewire::test('pages::perizinan.sertifikat-input')
        ->set('proyekId', $this->proyek->id)
        ->call('openIsi', $s->id)
        ->set('nomorSertifikat', '')
        ->call('simpan')
        ->assertHasErrors(['nomorSertifikat']);
});

// ─────────────────── Halaman serah terima ───────────────────

it('mencatat serah terima beberapa sertifikat sekaligus', function () {
    $a = terbitHal('FA', '01', 'SHGB 400');
    $b = terbitHal('FA', '02', 'SHGB 401');

    Livewire::test('pages::perizinan.sertifikat-serah-bank')
        ->set('proyekId', $this->proyek->id)
        ->set('tanggal', '2026-11-02')
        ->set('pilih', [$a->id => true, $b->id => true])
        ->call('simpan');

    expect(SertifikatSerahTerima::count())->toBe(1)
        ->and($a->fresh()->status())->toBe('di_bank')
        ->and($b->fresh()->status())->toBe('di_bank');
});

it('membatalkan penyerahan dan mengembalikan statusnya', function () {
    $a = terbitHal('FB', '01', 'SHGB 500');
    $serah = $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$a->id]);

    Livewire::test('pages::perizinan.sertifikat-serah-bank')
        ->set('proyekId', $this->proyek->id)
        ->call('konfirmasiHapus', $serah->id)
        ->call('hapus');

    expect(SertifikatSerahTerima::count())->toBe(0)
        ->and($a->fresh()->status())->toBe('terbit');
});

it('mengunci tiap halaman input dengan izinnya sendiri', function () {
    // Inti pemisahan: serah terima bisa dipindah ke peran lain tanpa ikut
    // memberi akses mengajukan atau mencatat sertifikat.
    $hanyaSerah = User::factory()->create();
    $hanyaSerah->givePermissionTo('legal.serahterima.kelola');

    $this->actingAs($hanyaSerah);

    $this->get(route('perizinan.sertifikat.serah-bank'))->assertOk();
    $this->get(route('perizinan.sertifikat.pengajuan'))->assertForbidden();
    $this->get(route('perizinan.sertifikat.input'))->assertForbidden();
});

it('mengajukan seluruh unit blok dengan satu luas', function () {
    // Satu blok biasanya berisi puluhan unit berluas sama. Mengetiknya satu per
    // satu bukan ketelitian, melainkan pekerjaan sia-sia.
    unitHal('GA', '01');
    unitHal('GA', '02');
    unitHal('GA', '03');
    unitHal('GB', '01');   // blok lain, tidak boleh ikut

    Livewire::test('pages::perizinan.sertifikat-pengajuan')
        ->set('proyekId', $this->proyek->id)
        ->set('metode', 'blok')
        ->set('blok', 'GA')
        ->set('luasBlok', '60')
        ->call('simpan')
        ->assertHasNoErrors();

    $dibuat = Sertifikat::with('rumah')->get();

    expect($dibuat)->toHaveCount(3)
        ->and($dibuat->pluck('rumah.blok')->unique()->all())->toBe(['GA'])
        ->and($dibuat->pluck('luas_diajukan')->map(fn ($l) => (float) $l)->all())->toBe([60.0, 60.0, 60.0]);
});

it('mewajibkan luas diisi pada metode kolektif', function () {
    unitHal('GC', '01');

    Livewire::test('pages::perizinan.sertifikat-pengajuan')
        ->set('proyekId', $this->proyek->id)
        ->set('metode', 'blok')
        ->set('blok', 'GC')
        ->call('simpan')
        ->assertHasErrors(['luasBlok']);

    expect(Sertifikat::count())->toBe(0);
});

it('tidak mengajukan apa pun kalau bloknya belum dipilih', function () {
    unitHal('GD', '01');

    Livewire::test('pages::perizinan.sertifikat-pengajuan')
        ->set('proyekId', $this->proyek->id)
        ->set('metode', 'blok')
        ->set('luasBlok', '60')
        ->call('simpan');

    expect(Sertifikat::count())->toBe(0);
});

it('melewati unit blok yang sudah pernah diajukan', function () {
    $lama = unitHal('GE', '01');
    unitHal('GE', '02');

    test()->svc->ajukan($this->proyek, '2026-08-01', 'kavling', [$lama->id => 55.0]);

    Livewire::test('pages::perizinan.sertifikat-pengajuan')
        ->set('proyekId', $this->proyek->id)
        ->set('metode', 'blok')
        ->set('blok', 'GE')
        ->set('luasBlok', '60')
        ->call('simpan');

    // Yang lama tetap 55, tidak ditimpa; yang baru satu saja.
    expect(Sertifikat::count())->toBe(2)
        ->and((float) Sertifikat::where('rumah_id', $lama->id)->value('luas_diajukan'))->toBe(55.0);
});

// ─────────────────── Riwayat per unit ───────────────────

it('menyusun riwayat tiga tahap beserta yang belum tercapai', function () {
    $unit = unitHal('HA', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0], '', '', $this->legal->id);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    $riwayat = $this->svc->riwayat($s);
    $tahap = collect($riwayat['tahap'])->keyBy('kunci');

    expect($tahap)->toHaveCount(3)
        ->and($tahap['diajukan']['tercapai'])->toBeTrue()
        ->and($tahap['diajukan']['tanggal'])->toBe('20/09/2026')
        ->and($tahap['diajukan']['oleh'])->toBe($this->legal->name)
        ->and($tahap['diajukan']['rincian']['Luas diajukan'])->toBe('70 m²')
        // Tahap yang belum jalan tetap dikembalikan, supaya layarnya bisa
        // menunjukkan prosesnya berhenti di mana.
        ->and($tahap['terbit']['tercapai'])->toBeFalse()
        ->and($tahap['di_bank']['tercapai'])->toBeFalse();
});

it('mencatat kapan dan oleh siapa nomor sertifikat diisi', function () {
    $unit = unitHal('HB', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0]);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    Livewire::test('pages::perizinan.sertifikat-input')
        ->set('proyekId', $this->proyek->id)
        ->call('openIsi', $s->id)
        ->set('nomorSertifikat', 'SHGB 777')
        ->set('tanggalTerbit', '2026-10-02')
        ->call('simpan');

    $tahap = collect($this->svc->riwayat($s->fresh())['tahap'])->keyBy('kunci');

    expect($tahap['terbit']['tercapai'])->toBeTrue()
        ->and($tahap['terbit']['tanggal'])->toBe('02/10/2026')
        ->and($tahap['terbit']['oleh'])->toBe($this->legal->name)
        // Tanggal terbit datang dari BPN; kapan datanya diketik adalah hal lain,
        // dan keduanya harus bisa dibedakan saat menelusuri keterlambatan.
        ->and($tahap['terbit']['dicatat'])->not->toBeNull();
});

it('mencatat koreksi sebagai perubahan, bukan sebagai terbit ulang', function () {
    $unit = unitHal('HC', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0]);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    $isi = fn (string $nomor) => Livewire::test('pages::perizinan.sertifikat-input')
        ->set('proyekId', $this->proyek->id)
        ->call('openIsi', $s->id)
        ->set('nomorSertifikat', $nomor)
        ->call('simpan');

    $isi('SHGB 100');
    $isi('SHGB 101');

    $riwayat = $this->svc->riwayat($s->fresh());

    expect($riwayat['catatan'])->toHaveCount(1)
        ->and($riwayat['catatan'][0]['judul'])->toBe('Data Sertifikat Diperbarui')
        ->and($riwayat['catatan'][0]['perubahan']['No. Sertifikat'])->toBe(['SHGB 100', 'SHGB 101']);
});

it('tidak mencatat perubahan kalau isiannya disimpan apa adanya', function () {
    // Menyimpan tanpa mengubah apa pun sering terjadi — riwayatnya jangan
    // ikut terisi, karena yang dicari orang di sana justru yang berubah.
    $unit = unitHal('HD', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0]);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    $simpan = fn () => Livewire::test('pages::perizinan.sertifikat-input')
        ->set('proyekId', $this->proyek->id)
        ->call('openIsi', $s->id)
        ->set('nomorSertifikat', 'SHGB 200')
        ->call('simpan');

    $simpan();
    $simpan();

    expect($this->svc->riwayat($s->fresh())['catatan'])->toBeEmpty();
});

it('menyimpan jejak pembatalan serah terima di riwayat unitnya', function () {
    // Pembatalan menghapus berkas serah terimanya, jadi tanpa catatan ini tidak
    // ada apa pun di baris sertifikat yang menunjukkan ia pernah ke bank.
    $a = terbitHal('HE', '01', 'SHGB 900');
    $serah = $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$a->id]);

    $this->svc->batalkanSerahTerima($serah);

    $riwayat = $this->svc->riwayat($a->fresh());
    $tahap = collect($riwayat['tahap'])->keyBy('kunci');

    expect($tahap['di_bank']['tercapai'])->toBeFalse()
        ->and($riwayat['catatan'])->toHaveCount(1)
        ->and($riwayat['catatan'][0]['judul'])->toBe('Serah Terima Bank Dibatalkan');
});

it('tetap menampilkan tahap data lama yang tidak punya jejak pencatatnya', function () {
    // Data yang masuk sebelum riwayat ini ada — tanggalnya tetap harus terbaca,
    // hanya pencatatnya yang kosong.
    $a = terbitHal('HF', '01', 'SHGB 950');

    $tahap = collect($this->svc->riwayat($a)['tahap'])->keyBy('kunci');

    expect($tahap['terbit']['tercapai'])->toBeTrue()
        ->and($tahap['terbit']['tanggal'])->toBe('05/10/2026')
        ->and($tahap['terbit']['oleh'])->toBeNull()
        ->and($tahap['terbit']['dicatat'])->toBeNull();
});

it('membuka riwayat dari layar arsip', function () {
    $a = terbitHal('HG', '01', 'SHGB 960');

    Livewire::test('pages::perizinan.sertifikat')
        ->set('proyekId', $this->proyek->id)
        ->call('openRiwayat', $a->id)
        ->assertSet('riwayatJudul', 'HG-01')
        ->assertSee('SHGB 960');
});

it('menolak unduhan berkas dari peran tanpa kepentingan legal', function () {
    $a = terbitHal('HH', '01', 'SHGB 970');

    $teknik = User::factory()->create();
    $teknik->assignRole('admin-teknik');

    Livewire::actingAs($teknik)
        ->test('pages::perizinan.sertifikat')
        ->call('unduhBerkas', $a->id)
        ->assertForbidden();
});

it('tidak menyodorkan berkas yang tidak ada', function () {
    $a = terbitHal('HI', '01', 'SHGB 980');

    Livewire::test('pages::perizinan.sertifikat')
        ->call('unduhBast', $a->id)
        ->assertNotFound();
});

it('memakai label resmi untuk riwayat, bukan kode teknisnya', function () {
    // Nama teknis event tidak boleh sampai ke layar Legal.
    $a = terbitHal('HJ', '01', 'SHGB 990');
    $serah = $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$a->id]);
    $this->svc->batalkanSerahTerima($serah);

    $judul = collect($this->svc->riwayat($a->fresh())['catatan'])->pluck('judul');

    expect($judul)->not->toContain('sertifikat.bast_dibatalkan')
        ->and($judul->first())->toBe('Serah Terima Bank Dibatalkan');
});

it('memisahkan catatan dari rincian pada riwayat', function () {
    // Catatan berupa kalimat bebas yang panjangnya tak tentu. Kalau ikut masuk
    // kisi rincian, ia menghimpit kolom di sebelahnya sampai tumpang tindih.
    $unit = unitHal('HK', '01');
    $this->svc->ajukan(
        $this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0],
        '', 'Menunggu berkas ukur dari juru ukur BPN wilayah.', $this->legal->id,
    );
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    $tahap = collect($this->svc->riwayat($s)['tahap'])->keyBy('kunci');

    expect($tahap['diajukan']['catatan'])->toBe('Menunggu berkas ukur dari juru ukur BPN wilayah.')
        ->and($tahap['diajukan']['rincian'])->not->toHaveKey('Catatan');
});

it('mencari unit persis seperti yang terbaca di layar', function () {
    // Kolom Blok menampilkan "AD-18", gabungan dua kolom yang tidak ada
    // bentuknya di basis data. Mengetik apa yang terlihat harus ketemu.
    $a = terbitHal('JA', '18', 'SHGB 700');
    terbitHal('JA', '19', 'SHGB 701');
    terbitHal('JB', '18', 'SHGB 702');

    $hasil = fn (string $cari) => Livewire::test('pages::perizinan.sertifikat')
        ->set('proyekId', $this->proyek->id)
        ->set('cari', $cari)
        ->call('tampilkan')
        ->viewData('baris')
        ->pluck('id');

    expect($hasil('JA-18')->all())->toBe([$a->id])
        // Huruf kecil pun harus ketemu — orang mengetik seadanya.
        ->and($hasil('ja-18')->all())->toBe([$a->id])
        // Nomor unit saja, tanpa bloknya.
        ->and($hasil('18'))->toHaveCount(2)
        // Blok saja tetap seperti sebelumnya.
        ->and($hasil('JA'))->toHaveCount(2);
});

it('tetap mencari lewat nomor sertifikat, NIB, dan nama konsumen', function () {
    $a = terbitHal('JC', '01', 'SHGB 800');

    $hasil = fn (string $cari) => Livewire::test('pages::perizinan.sertifikat')
        ->set('proyekId', $this->proyek->id)
        ->set('cari', $cari)
        ->call('tampilkan')
        ->viewData('baris')
        ->pluck('id');

    expect($hasil('SHGB 800')->all())->toBe([$a->id])
        ->and($hasil($a->nib)->all())->toBe([$a->id])
        ->and($hasil('SHGB 9999')->all())->toBe([]);
});
