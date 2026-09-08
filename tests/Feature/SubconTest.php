<?php

use App\Models\Master\Bank;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Subcon;
use App\Models\Master\SubconDokumen;
use App\Models\Master\SubconRekening;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\KtpOcrService;
use App\Services\SubconTarifPph;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Master subcon — orangnya dicatat, badan usaha menyusul kalau punya.
 *
 * Tarif PPh disarankan sistem dari jenis jasa, kualifikasi, dan ada-tidaknya
 * sertifikat yang masih berlaku. Sarannya boleh ditimpa: pajak bukan urusan yang
 * boleh diputuskan diam-diam oleh aplikasi.
 */
beforeEach(function () {
    $this->seed();
    $this->tarif = app(SubconTarifPph::class);
});

function subcon(array $atribut = []): Subcon
{
    return Subcon::create(array_merge([
        'nama' => 'Andrew Mashandro',
        'jenis_jasa' => 'pelaksana',
        'kualifikasi' => 'menengah',
    ], $atribut));
}

function dokumen(Subcon $s, string $jenis, ?string $berlakuSampai = null): SubconDokumen
{
    return SubconDokumen::create([
        'subcon_id' => $s->id,
        'jenis' => $jenis,
        'nomor' => strtoupper($jenis).'-001',
        'berlaku_sampai' => $berlakuSampai,
    ]);
}

function pengelolaSubcon(): User
{
    $u = User::factory()->create();
    $u->assignRole('super-admin');

    return $u;
}

it('membedakan perorangan dari badan usaha', function () {
    expect(subcon(['nama' => 'Agus Sunarno'])->perorangan())->toBeTrue()
        ->and(subcon(['nama' => 'Ahmad Nanang', 'badan_usaha' => 'CV Daha Bore Pile'])->perorangan())->toBeFalse();
});

it('menyarankan 4% untuk pelaksana tanpa sertifikat', function () {
    // Pola Andrew di sistem lama: tanpa SBU, tanpa SKA.
    $s = subcon(['kualifikasi' => 'pribadi']);

    expect($this->tarif->saran($s->fresh()->load('dokumen')))->toBe(4.00);
});

it('menyarankan 1,75% untuk kualifikasi kecil yang bersertifikat', function () {
    $s = subcon(['kualifikasi' => 'kecil']);
    dokumen($s, 'sbu', now()->addYear()->toDateString());

    expect($this->tarif->saran($s->fresh()->load('dokumen')))->toBe(1.75);
});

it('menyarankan 2,65% untuk kualifikasi menengah yang bersertifikat', function () {
    // Pola Ahmad Nanang: menengah, SBU berlaku.
    $s = subcon(['kualifikasi' => 'menengah']);
    dokumen($s, 'sbu', now()->addYear()->toDateString());

    expect($this->tarif->saran($s->fresh()->load('dokumen')))->toBe(2.65);
});

it('menganggap SKA setara SBU untuk penentuan tarif', function () {
    // Affrel: pribadi, tanpa SBU tapi punya SKA — di sistem lama tarifnya 1,75%.
    $s = subcon(['kualifikasi' => 'pribadi']);
    dokumen($s, 'ska', now()->addYears(4)->toDateString());

    expect($this->tarif->saran($s->fresh()->load('dokumen')))->toBe(1.75);
});

it('tidak menghitung sertifikat yang sudah kadaluarsa', function () {
    // SBU lewat tanggal sama saja dengan tidak punya.
    $s = subcon(['kualifikasi' => 'kecil']);
    dokumen($s, 'sbu', now()->subDay()->toDateString());

    expect($this->tarif->saran($s->fresh()->load('dokumen')))->toBe(4.00);
});

it('tidak menyarankan apa pun untuk jasa dan notaris', function () {
    // Keduanya bukan jasa konstruksi — tarifnya diisi manual.
    foreach (['jasa', 'notaris'] as $jenis) {
        $s = subcon(['jenis_jasa' => $jenis, 'kualifikasi' => 'pribadi']);
        expect($this->tarif->saran($s->fresh()->load('dokumen')))->toBeNull();
    }
});

it('tidak menyarankan kalau kualifikasinya belum diisi', function () {
    $s = subcon(['kualifikasi' => null]);

    expect($this->tarif->saran($s->fresh()->load('dokumen')))->toBeNull();
});

it('menandai tarif yang menyimpang dari saran', function () {
    $s = subcon(['kualifikasi' => 'menengah', 'pph_persen' => 2.65]);
    dokumen($s, 'sbu', now()->addYear()->toDateString());

    expect($this->tarif->menyimpang($s->fresh()->load('dokumen')))->toBeFalse();

    $s->update(['pph_persen' => 4.00]);
    expect($this->tarif->menyimpang($s->fresh()->load('dokumen')))->toBeTrue();
});

it('menjelaskan dasar sarannya', function () {
    $s = subcon(['kualifikasi' => 'menengah']);
    dokumen($s, 'sbu', now()->addYear()->toDateString());

    expect($this->tarif->alasan($s->fresh()->load('dokumen')))
        ->toContain('Pelaksana konstruksi')
        ->toContain('menengah')
        ->toContain('masih berlaku');
});

it('menandai dokumen yang lewat masa berlakunya', function () {
    // Di daftar sistem lama, SIUP Andrew ditandai merah "Exp 08/02/2025".
    $s = subcon();
    $lewat = dokumen($s, 'siup', now()->subMonth()->toDateString());
    $aman = dokumen($s, 'sbu', now()->addYear()->toDateString());

    expect($lewat->kadaluarsa())->toBeTrue()
        ->and($aman->kadaluarsa())->toBeFalse()
        ->and($s->fresh()->load('dokumen')->dokumenKadaluarsa())->toHaveCount(1);
});

it('memperingatkan dokumen yang akan habis dalam dua bulan', function () {
    $s = subcon();
    $segera = dokumen($s, 'siup', now()->addDays(30)->toDateString());
    $lama = dokumen($s, 'sbu', now()->addYear()->toDateString());

    expect($segera->segeraKadaluarsa())->toBeTrue()
        ->and($lama->segeraKadaluarsa())->toBeFalse();
});

it('menganggap NPWP dan PKP tidak punya masa berlaku', function () {
    $s = subcon();
    $npwp = dokumen($s, 'npwp');

    expect($npwp->perluMasaBerlaku())->toBeFalse()
        ->and($npwp->kadaluarsa())->toBeFalse()
        ->and($npwp->masihBerlaku())->toBeTrue();
});

it('menolak dokumen jenis sama dua kali untuk satu subcon', function () {
    $s = subcon();
    dokumen($s, 'siup');

    expect(fn () => dokumen($s, 'siup'))->toThrow(QueryException::class);
});

it('menyimpan beberapa rekening dengan satu ditandai utama', function () {
    $s = subcon();
    $bank = Bank::first() ?? Bank::create(['nama' => 'BTN']);

    SubconRekening::create([
        'subcon_id' => $s->id, 'bank_id' => $bank->id,
        'nomor_rekening' => '111', 'atas_nama' => 'Andrew', 'is_utama' => false,
    ]);
    $utama = SubconRekening::create([
        'subcon_id' => $s->id, 'bank_id' => $bank->id,
        'nomor_rekening' => '222', 'atas_nama' => 'Andrew', 'is_utama' => true,
    ]);

    $s = $s->fresh()->load('rekening', 'rekeningUtama');

    // Yang utama tampil di urutan pertama.
    expect($s->rekening)->toHaveCount(2)
        ->and($s->rekening->first()->id)->toBe($utama->id)
        ->and($s->rekeningUtama->nomor_rekening)->toBe('222')
        ->and($utama->namaBank())->toBe($bank->nama);
});

it('memakai nama bank ketikan kalau tidak ada di master', function () {
    $s = subcon();
    $r = SubconRekening::create([
        'subcon_id' => $s->id, 'bank_nama' => 'BPR Sejahtera',
        'nomor_rekening' => '333', 'atas_nama' => 'Andrew',
    ]);

    expect($r->namaBank())->toBe('BPR Sejahtera');
});

it('ikut terhapus kalau subconnya dihapus', function () {
    $s = subcon();
    $d = dokumen($s, 'siup');
    $r = SubconRekening::create([
        'subcon_id' => $s->id, 'nomor_rekening' => '444', 'atas_nama' => 'Andrew',
    ]);

    $s->delete();

    expect(SubconDokumen::find($d->id))->toBeNull()
        ->and(SubconRekening::find($r->id))->toBeNull();
});

it('mengisi nama, nomor KTP, dan alamat dari hasil baca KTP', function () {
    // OCR-nya sudah ada dan dipakai DBOS; di sini hanya disambungkan ke tiga kolom
    // yang memang dimiliki form subcon. Sisa hasil bacaan diabaikan.
    $s = subcon(['nama' => 'Sementara']);
    $s->update(['nama' => '', 'nik' => null, 'alamat' => null]);

    app()->bind(KtpOcrService::class, fn () => new class
    {
        public function read(string $path): array
        {
            return [
                'ok' => true, 'error' => null, 'provider' => 'palsu', 'raw' => null,
                'nama' => 'ANDREW MASHANDRO TANTO PUTRA',
                'nik' => '3374062508010004',
                'alamat' => 'JL. PARANG BARIS I/26',
                'tempat_lahir' => 'SEMARANG', 'tanggal_lahir' => '25-08-2001',
                'jenis_kelamin' => 'LAKI-LAKI', 'rt_rw' => '004/015',
                'kelurahan' => 'TLOGOSARI KULON', 'kecamatan' => 'PEDURUNGAN',
                'kota_kabupaten' => 'SEMARANG', 'provinsi' => 'JAWA TENGAH',
                'agama' => 'ISLAM', 'status_perkawinan' => 'BELUM KAWIN', 'pekerjaan' => 'WIRASWASTA',
            ];
        }
    });

    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-show', ['id' => $s->id])
        ->call('openIdentitas')
        ->set('i_foto_ktp', UploadedFile::fake()->image('ktp.jpg'))
        ->assertSet('i_nama', 'ANDREW MASHANDRO TANTO PUTRA')
        ->assertSet('i_nik', '3374062508010004')
        ->assertSet('i_alamat', 'JL. PARANG BARIS I/26')
        ->assertSet('ocrJenis', 'success');
});

it('tidak menimpa kolom yang sudah terisi', function () {
    app()->bind(KtpOcrService::class, fn () => new class
    {
        public function read(string $path): array
        {
            return [
                'ok' => true, 'error' => null, 'provider' => 'palsu', 'raw' => null,
                'nama' => 'NAMA DARI KTP', 'nik' => '3374062508010004',
                'alamat' => 'ALAMAT DARI KTP',
                'tempat_lahir' => null, 'tanggal_lahir' => null, 'jenis_kelamin' => null,
                'rt_rw' => null, 'kelurahan' => null, 'kecamatan' => null,
                'kota_kabupaten' => null, 'provinsi' => null, 'agama' => null,
                'status_perkawinan' => null, 'pekerjaan' => null,
            ];
        }
    });

    $s = subcon(['nama' => 'Andrew Mashandro', 'nik' => '3374062508010004', 'alamat' => 'Alamat lama']);

    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-show', ['id' => $s->id])
        ->call('openIdentitas')
        ->set('i_foto_ktp', UploadedFile::fake()->image('ktp.jpg'))
        ->assertSet('i_nama', 'Andrew Mashandro')
        ->assertSet('i_alamat', 'Alamat lama')
        ->assertSet('ocrJenis', 'warning');
});

it('memberi tahu kalau KTP tidak terbaca, bukan diam', function () {
    app()->bind(KtpOcrService::class, fn () => new class
    {
        public function read(string $path): array
        {
            return ['ok' => false, 'error' => 'Tesseract error: gambar buram', 'provider' => 'tesseract', 'raw' => null];
        }
    });

    $s = subcon();

    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-show', ['id' => $s->id])
        ->call('openIdentitas')
        ->set('i_foto_ktp', UploadedFile::fake()->image('ktp.jpg'))
        ->assertSet('ocrJenis', 'error')
        ->assertSet('ocrPesan', 'Tesseract error: gambar buram');
});

it('mendaftarkan subcon perorangan lewat tiga langkah', function () {
    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-create')
        ->assertSet('langkah', 1)
        ->set('nama', 'Agus Sunarno')
        ->set('nik', '3374062508010004')
        ->set('telepon', '0813-2918-2079')
        ->call('keLangkah', 2)
        ->assertSet('langkah', 2)
        ->set('jenisJasa', 'pelaksana')
        ->set('kualifikasi', 'pribadi')
        ->call('pakaiSaran')
        ->assertSet('pph', 4.0)
        ->call('keLangkah', 3)
        ->assertSet('langkah', 3)
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertRedirect();

    $s = Subcon::latest('id')->first();

    expect($s->nama)->toBe('Agus Sunarno')
        ->and($s->perorangan())->toBeTrue()
        ->and($s->badan_usaha)->toBeNull()
        ->and((float) $s->pph_persen)->toBe(4.0);
});

it('menahan lanjut ke langkah dua kalau nama kosong', function () {
    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-create')
        ->set('nama', '')
        ->call('keLangkah', 2)
        ->assertHasErrors('nama')
        ->assertSet('langkah', 1);
});

it('menolak nomor KTP yang bukan 16 digit', function () {
    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-create')
        ->set('nama', 'Andrew')
        ->set('nik', '123')
        ->call('keLangkah', 2)
        ->assertHasErrors('nik');
});

it('mewajibkan nama badan usaha kalau kotaknya dicentang', function () {
    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-create')
        ->set('nama', 'Ahmad Nanang')
        ->set('punyaBadanUsaha', true)
        ->set('badanUsaha', '')
        ->call('keLangkah', 2)
        ->assertHasErrors('badanUsaha');
});

it('menyimpan SIUP jadi dokumen, bukan kolom lepas', function () {
    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-create')
        ->set('nama', 'Ahmad Nanang Zuaini')
        ->set('punyaBadanUsaha', true)
        ->set('badanUsaha', 'CV Daha Bore Pile')
        ->set('pemilik', 'Ahmad Nanang')
        ->set('nomorSiup', '1406220067299')
        ->set('berlakuSiup', '2027-06-14')
        ->call('keLangkah', 2)
        ->set('jenisJasa', 'pelaksana')
        ->set('kualifikasi', 'menengah')
        ->set('pph', 2.65)
        ->set('pphCatatan', 'SBU menyusul')
        ->call('simpan')
        ->assertHasNoErrors();

    $s = Subcon::latest('id')->first();
    $siup = $s->dokumenJenis('siup');

    expect($s->badan_usaha)->toBe('CV Daha Bore Pile')
        ->and($s->pemilik)->toBe('Ahmad Nanang')
        ->and($siup)->not->toBeNull()
        ->and($siup->nomor)->toBe('1406220067299')
        ->and($siup->berlaku_sampai->toDateString())->toBe('2027-06-14');
});

it('tidak menyimpan data perusahaan kalau kotaknya tidak dicentang', function () {
    // Kotak sempat dicentang lalu dilepas — sisanya tidak boleh ikut tersimpan.
    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-create')
        ->set('nama', 'Alisma')
        ->set('punyaBadanUsaha', true)
        ->set('badanUsaha', 'CV Salah Isi')
        ->set('pemilik', 'Bukan Pemilik')
        ->set('nomorSiup', '999')
        ->set('punyaBadanUsaha', false)
        ->call('simpan')
        ->assertHasNoErrors();

    $s = Subcon::latest('id')->first();

    expect($s->badan_usaha)->toBeNull()
        ->and($s->pemilik)->toBeNull()
        ->and($s->dokumenJenis('siup'))->toBeNull();
});

it('mewajibkan alasan kalau tarif menyimpang dari saran', function () {
    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-create')
        ->set('nama', 'Andrew')
        ->call('keLangkah', 2)
        ->set('jenisJasa', 'pelaksana')
        ->set('kualifikasi', 'pribadi')
        ->set('pph', 1.75)
        ->set('pphCatatan', '')
        ->call('keLangkah', 3)
        ->assertHasErrors('pphCatatan')
        ->assertSet('langkah', 2);
});

it('menutup halaman registrasi dari user tanpa izin', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    $this->get(route('master.subcon.create'))->assertForbidden();
});

it('menghapus subcon yang belum dipakai unit mana pun', function () {
    Storage::fake('private');

    $s = subcon();
    $d = dokumen($s, 'siup');
    $d->update(['file_path' => 'subcon/1/siup.pdf']);
    Storage::disk('private')->put('subcon/1/siup.pdf', 'isi');

    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-show', ['id' => $s->id])
        ->call('konfirmasiHapusSubcon')
        ->assertSet('konfirmasiHapus', true)
        ->call('hapusSubcon')
        ->assertRedirect();

    expect(Subcon::find($s->id))->toBeNull()
        ->and(SubconDokumen::find($d->id))->toBeNull();

    // Berkasnya ikut dibuang, tidak jadi sampah.
    Storage::disk('private')->assertMissing('subcon/1/siup.pdf');
});

it('menolak menghapus subcon yang masih dipakai unit', function () {
    // rumah.subcon_id dipasang nullOnDelete — tanpa penjagaan ini, menghapus subcon
    // akan mengosongkan unitnya tanpa suara.
    $s = subcon();
    $proyek = Proyek::first();

    $rumah = Rumah::create([
        'proyek_id' => $proyek->id,
        'tipe_rumah_id' => TipeRumah::where('proyek_id', $proyek->id)->first()->id,
        'blok' => 'ZZ', 'nomor_unit' => '01', 'status' => 'available',
        'subcon_id' => $s->id,
    ]);

    Livewire::actingAs(pengelolaSubcon())
        ->test('pages::master.subcon-show', ['id' => $s->id])
        ->call('konfirmasiHapusSubcon')
        ->call('hapusSubcon')
        ->assertNoRedirect()
        ->assertSet('konfirmasiHapus', false);

    expect(Subcon::find($s->id))->not->toBeNull()
        ->and($rumah->fresh()->subcon_id)->toBe($s->id);
});

it('menutup penghapusan subcon dari user tanpa izin', function () {
    $s = subcon();

    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');

    Livewire::actingAs($tamu)
        ->test('pages::master.subcon-show', ['id' => $s->id])
        ->call('konfirmasiHapusSubcon')
        ->assertForbidden();

    expect(Subcon::find($s->id))->not->toBeNull();
});
