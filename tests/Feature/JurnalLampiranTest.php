<?php

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalLampiran;
use App\Models\Master\Perusahaan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Berkas pendukung jurnal — invoice, bukti transfer, kwitansi.
 *
 * Disimpan di disk private, bukan public: bukti bayar tidak boleh bisa diambil
 * siapa pun yang menebak URL-nya.
 */
beforeEach(function () {
    Storage::fake('private');
    $this->seed();

    $this->finance = User::factory()->create();
    $this->finance->assignRole('finance');

    $this->direktur = User::factory()->create();
    $this->direktur->assignRole('direktur');

    $this->jurnal = Jurnal::create([
        'perusahaan_id' => Perusahaan::value('id'),
        'tanggal' => now()->toDateString(),
        'no_bukti' => 'KAS/08/26/9001',
        'tipe' => 'umum',
        'kategori_bukti' => 'KAS',
        'status' => 'draft',
        'created_by_user_id' => $this->finance->id,
    ]);
});

function halamanJurnal()
{
    return Livewire::test('pages::akunting.jurnal-umum');
}

it('mengunggah berkas pendukung ke disk private', function () {
    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')])
        ->set('lampiranKeterangan', 'Invoice #123')
        ->call('simpanLampiran')
        ->assertHasNoErrors();

    $lampiran = JurnalLampiran::where('jurnal_id', $this->jurnal->id)->first();

    expect($lampiran)->not->toBeNull()
        ->and($lampiran->file_original_name)->toBe('invoice.pdf')
        ->and($lampiran->keterangan)->toBe('Invoice #123')
        ->and($lampiran->uploaded_by_user_id)->toBe($this->finance->id);

    Storage::disk('private')->assertExists($lampiran->file_path);
});

it('mengunggah beberapa berkas sekaligus', function () {
    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [
            UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->image('bukti-transfer.jpg'),
        ])
        ->call('simpanLampiran')
        ->assertHasNoErrors();

    expect(JurnalLampiran::where('jurnal_id', $this->jurnal->id)->count())->toBe(2);
});

it('menolak berkas yang jenisnya tidak diizinkan', function () {
    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->create('skrip.exe', 10)])
        ->call('simpanLampiran')
        ->assertHasErrors('lampiranBaru.*');

    expect(JurnalLampiran::count())->toBe(0);
});

it('menolak berkas yang lebih besar dari 5 MB', function () {
    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->create('besar.pdf', 6000, 'application/pdf')])
        ->call('simpanLampiran')
        ->assertHasErrors('lampiranBaru.*');

    expect(JurnalLampiran::count())->toBe(0);
});

it('masih menerima berkas susulan setelah jurnal diposting', function () {
    // Posting mengunci angkanya, bukan dokumennya — bukti bayar sering menyusul.
    $this->jurnal->update(['status' => 'posted', 'posted_at' => now()]);
    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->create('bukti-bayar.pdf', 40, 'application/pdf')])
        ->call('simpanLampiran')
        ->assertHasNoErrors();

    expect(JurnalLampiran::where('jurnal_id', $this->jurnal->id)->count())->toBe(1);
});

it('menghapus berkas beserta filenya di penyimpanan', function () {
    $this->actingAs($this->finance);

    $komponen = halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->create('salah.pdf', 20, 'application/pdf')])
        ->call('simpanLampiran');

    $lampiran = JurnalLampiran::firstOrFail();
    $path = $lampiran->file_path;

    $komponen->call('konfirmasiHapusLampiran', $lampiran->id)
        ->assertSet('hapusLampiranId', $lampiran->id)
        ->call('hapusLampiran')
        ->assertSet('hapusLampiranId', null);

    expect(JurnalLampiran::find($lampiran->id))->toBeNull();
    Storage::disk('private')->assertMissing($path);
});

it('ikut terhapus kalau jurnalnya dihapus', function () {
    $lampiran = JurnalLampiran::create([
        'jurnal_id' => $this->jurnal->id,
        'file_path' => 'jurnal/1/x.pdf',
        'file_original_name' => 'x.pdf',
        'ukuran' => 100,
    ]);

    $this->jurnal->delete();

    expect(JurnalLampiran::find($lampiran->id))->toBeNull();
});

it('direktur boleh melihat berkas tapi tidak boleh menambah', function () {
    JurnalLampiran::create([
        'jurnal_id' => $this->jurnal->id,
        'file_path' => 'jurnal/1/invoice.pdf',
        'file_original_name' => 'invoice.pdf',
        'ukuran' => 100,
    ]);

    $this->actingAs($this->direktur);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->assertViewHas('bolehKelolaLampiran', false)
        ->assertSeeText('invoice.pdf');
});

it('menolak unggahan dari user tanpa izin kelola jurnal', function () {
    $this->actingAs($this->direktur);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->create('nekat.pdf', 20, 'application/pdf')])
        ->call('simpanLampiran')
        ->assertForbidden();

    expect(JurnalLampiran::count())->toBe(0);
});

it('menolak penghapusan berkas dari user tanpa izin kelola jurnal', function () {
    $lampiran = JurnalLampiran::create([
        'jurnal_id' => $this->jurnal->id,
        'file_path' => 'jurnal/1/invoice.pdf',
        'file_original_name' => 'invoice.pdf',
        'ukuran' => 100,
    ]);

    $this->actingAs($this->direktur);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->call('konfirmasiHapusLampiran', $lampiran->id)
        ->assertForbidden();

    expect(JurnalLampiran::find($lampiran->id))->not->toBeNull();
});

it('mengunduh berkas lewat disk private', function () {
    $this->actingAs($this->finance);

    $komponen = halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->create('invoice.pdf', 30, 'application/pdf')])
        ->call('simpanLampiran');

    $lampiran = JurnalLampiran::firstOrFail();

    $komponen->call('unduhLampiran', $lampiran->id)
        ->assertFileDownloaded('invoice.pdf');
});

it('memberi tahu kalau file fisiknya hilang, bukan error', function () {
    $lampiran = JurnalLampiran::create([
        'jurnal_id' => $this->jurnal->id,
        'file_path' => 'jurnal/1/sudah-hilang.pdf',
        'file_original_name' => 'sudah-hilang.pdf',
        'ukuran' => 100,
    ]);

    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->call('unduhLampiran', $lampiran->id)
        ->assertNoFileDownloaded();
});

it('langsung menampilkan berkas terbaru saat modal dibuka', function () {
    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->image('bukti.jpg')])
        ->call('simpanLampiran');

    $lampiran = JurnalLampiran::firstOrFail();

    // Buka ulang: panel pratinjau tidak boleh kosong kalau berkasnya ada.
    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->assertSet('lampiranPreviewId', $lampiran->id)
        ->assertViewHas('lampiranPreview', fn ($p) => $p?->id === $lampiran->id);
});

it('memindah pratinjau ke berkas lain setelah yang ditampilkan dihapus', function () {
    $this->actingAs($this->finance);

    $komponen = halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [
            UploadedFile::fake()->create('satu.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->create('dua.pdf', 20, 'application/pdf'),
        ])
        ->call('simpanLampiran');

    $ditampilkan = JurnalLampiran::findOrFail($komponen->get('lampiranPreviewId'));

    $komponen->call('konfirmasiHapusLampiran', $ditampilkan->id)
        ->call('hapusLampiran');

    // Tidak boleh menunjuk berkas yang sudah tiada.
    $sisa = JurnalLampiran::where('jurnal_id', $this->jurnal->id)->firstOrFail();
    $komponen->assertSet('lampiranPreviewId', $sisa->id);
});

it('menyajikan berkas inline untuk dipratinjau, bukan sebagai unduhan', function () {
    $this->actingAs($this->finance);

    halamanJurnal()
        ->call('bukaLampiran', $this->jurnal->id)
        ->set('lampiranBaru', [UploadedFile::fake()->image('bukti.jpg')])
        ->call('simpanLampiran');

    $lampiran = JurnalLampiran::firstOrFail();

    $respons = $this->get(route('akunting.jurnal-lampiran.pratinjau', $lampiran));

    $respons->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($respons->headers->get('content-disposition'))->toStartWith('inline');
});

it('menolak pratinjau dari user tanpa izin akunting', function () {
    $lampiran = JurnalLampiran::create([
        'jurnal_id' => $this->jurnal->id,
        'file_path' => 'jurnal/1/invoice.pdf',
        'file_original_name' => 'invoice.pdf',
        'ukuran' => 100,
    ]);

    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    $this->get(route('akunting.jurnal-lampiran.pratinjau', $lampiran))->assertForbidden();
});

it('membalas 404 kalau file fisiknya sudah tidak ada', function () {
    $lampiran = JurnalLampiran::create([
        'jurnal_id' => $this->jurnal->id,
        'file_path' => 'jurnal/1/hilang.pdf',
        'file_original_name' => 'hilang.pdf',
        'ukuran' => 100,
    ]);

    $this->actingAs($this->finance);

    $this->get(route('akunting.jurnal-lampiran.pratinjau', $lampiran))->assertNotFound();
});

it('menandai di tabel jurnal mana yang sudah punya berkas', function () {
    $this->actingAs($this->finance);

    // Belum ada berkas — hitungannya nol, penanda tampil redup.
    halamanJurnal()->assertViewHas('jurnalList', fn ($list) => $list
        ->firstWhere('id', $this->jurnal->id)->lampiran_count === 0);

    JurnalLampiran::create([
        'jurnal_id' => $this->jurnal->id,
        'file_path' => 'jurnal/1/invoice.pdf',
        'file_original_name' => 'invoice.pdf',
        'ukuran' => 100,
    ]);

    halamanJurnal()->assertViewHas('jurnalList', fn ($list) => $list
        ->firstWhere('id', $this->jurnal->id)->lampiran_count === 1);
});

it('menampilkan ukuran berkas dalam bentuk terbaca', function () {
    $lampiran = new JurnalLampiran(['ukuran' => 900]);
    expect($lampiran->ukuranTerbaca())->toBe('900 B');

    $lampiran = new JurnalLampiran(['ukuran' => 150_000]);
    expect($lampiran->ukuranTerbaca())->toBe('146 KB');

    $lampiran = new JurnalLampiran(['ukuran' => 1_500_000]);
    expect($lampiran->ukuranTerbaca())->toBe('1,4 MB');
});
