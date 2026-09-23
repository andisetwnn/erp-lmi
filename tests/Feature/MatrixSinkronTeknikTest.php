<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Subcon;
use App\Models\Master\TipeRumah;
use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use App\Models\User;
use App\Services\MatrixSinkronTeknik;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Matrix disusun mingguan dan sering lebih mutakhir daripada isi menu Teknik.
 * Progres bangunan & LOT-nya bisa disalurkan ke master rumah saat berkasnya
 * diunggah — tapi hanya kolom yang benar-benar terisi.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->import = MatrixImport::create([
        'nama_file' => 'MATRIX.xlsx',
        'proyek' => $this->proyek->nama_proyek,
        'minggu_ke' => 'MINGGU KE-24',
        'periode' => 'Per September 2026',
        'jumlah_baris' => 0,
    ]);
});

function buatRumahTeknik(string $blok, string $nomor, array $atribut = []): Rumah
{
    return Rumah::create(array_merge([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'terjual',
        'progres_fisik' => 0, 'lot' => null,
    ], $atribut));
}

function buatBarisMatrix(string $blok, string $nomor, ?float $progres, ?string $lot = null): MatrixUnit
{
    return MatrixUnit::create([
        'matrix_import_id' => test()->import->id, 'kategori' => 'mikro',
        'seksi' => 'ACC SP3K', 'urutan' => 1, 'nama' => 'KONSUMEN',
        'blok' => $blok, 'nomor_unit' => $nomor, 'progres' => $progres, 'lot' => $lot,
    ]);
}

function sinkron(?int $userId = null): array
{
    return app(MatrixSinkronTeknik::class)->jalankan(test()->import, $userId);
}

it('memperbarui progres fisik dan LOT dari berkas', function () {
    $rumah = buatRumahTeknik('AA', '01');
    buatBarisMatrix('AA', '01', 94.41, '3');

    $hasil = sinkron();

    $rumah->refresh();

    // 94,41 dibulatkan ke satuan persen.
    expect($rumah->progres_fisik)->toBe(94)
        ->and($rumah->lot)->toBe(3)
        ->and($hasil['diperbarui'])->toBe(1)
        ->and($hasil['progres'])->toBe(1)
        ->and($hasil['lot'])->toBe(1);
});

it('tidak mengosongkan progres yang sudah ada kalau berkasnya kosong', function () {
    // Kolom kosong berarti belum diisi, bukan nol persen.
    $rumah = buatRumahTeknik('AA', '02', ['progres_fisik' => 75]);
    buatBarisMatrix('AA', '02', null);

    sinkron();

    expect($rumah->refresh()->progres_fisik)->toBe(75);
});

it('mencatat siapa dan kapan yang memperbaruinya', function () {
    // Supaya Admin Teknik tahu angka itu datang dari unggahan, bukan dari orang.
    $user = User::factory()->create();
    $rumah = buatRumahTeknik('AA', '03');
    buatBarisMatrix('AA', '03', 50);

    sinkron($user->id);

    $rumah->refresh();

    expect($rumah->progres_updated_by_user_id)->toBe($user->id)
        ->and($rumah->progres_updated_at)->not->toBeNull();
});

it('menyamakan nomor berawalan nol dengan yang tanpa nol', function () {
    // Isi master tidak seragam: sebagian "7", sebagian "07".
    $rumah = buatRumahTeknik('AA', '7');
    buatBarisMatrix('AA', '07', 60);

    sinkron();

    expect($rumah->refresh()->progres_fisik)->toBe(60);
});

it('menghitung unit berkas yang belum ada di master rumah', function () {
    buatBarisMatrix('ZZ', '99', 40);

    $hasil = sinkron();

    expect($hasil['tanpa_unit'])->toBe(1)
        ->and($hasil['diperbarui'])->toBe(0);
});

it('tidak menyentuh unit yang nilainya sudah sama', function () {
    $rumah = buatRumahTeknik('AA', '04', ['progres_fisik' => 80, 'lot' => 2]);
    buatBarisMatrix('AA', '04', 80, '2');

    $hasil = sinkron();

    expect($hasil['dilewati'])->toBe(1)
        ->and($hasil['diperbarui'])->toBe(0)
        // Jejak pembaruan tidak ikut tergeser padahal tidak ada yang berubah.
        ->and($rumah->refresh()->progres_updated_at)->toBeNull();
});

it('membatasi progres pada rentang nol sampai seratus', function () {
    $rumah = buatRumahTeknik('AA', '05');
    buatBarisMatrix('AA', '05', 142.7);

    sinkron();

    expect($rumah->refresh()->progres_fisik)->toBe(100);
});

it('memakai nama subcon, dan melengkapi catatan lama yang masih berupa kode', function () {
    // Master lahir dari kolom teks bebas, jadi isinya kode ("WIN"). Begitu nama
    // panjangnya diketahui dari legenda berkas, catatan itu dilengkapi — bukan
    // dibiarkan berdampingan sebagai dua pemborong berbeda untuk orang yang sama.
    $lama = Subcon::create(['nama' => 'WIN', 'is_aktif' => true]);

    $rumah = buatRumahTeknik('AA', '06');
    buatBarisMatrix('AA', '06', 70)->update(['subcon' => 'WINARTO']);

    $hasil = sinkron();

    expect($lama->refresh()->nama)->toBe('WINARTO')
        ->and($rumah->refresh()->subcon_id)->toBe($lama->id)
        ->and(Subcon::count())->toBe(1)
        ->and($hasil['subcon_baru'])->toBe(0);
});

it('mendaftarkan subcon yang belum ada sama sekali', function () {
    $rumah = buatRumahTeknik('AA', '07');
    buatBarisMatrix('AA', '07', 30)->update(['subcon' => 'FATIMAH AZZAHRA']);

    $hasil = sinkron();

    $baru = Subcon::where('nama', 'FATIMAH AZZAHRA')->first();

    expect($baru)->not->toBeNull()
        ->and($rumah->refresh()->subcon_id)->toBe($baru->id)
        ->and($hasil['subcon_baru'])->toBe(1);
});

it('tidak membuat subcon kembar untuk nama yang sudah terdaftar', function () {
    $ada = Subcon::create(['nama' => 'EGA K', 'is_aktif' => true]);

    buatRumahTeknik('AA', '08');
    buatBarisMatrix('AA', '08', 20)->update(['subcon' => 'EGA K']);

    $hasil = sinkron();

    expect(Subcon::where('nama', 'EGA K')->count())->toBe(1)
        ->and($hasil['subcon_baru'])->toBe(0)
        ->and(Subcon::find($ada->id))->not->toBeNull();
});

it('tidak mengulang perubahan subcon kalau dijalankan dua kali', function () {
    // subcon_id harus ikut dibaca saat membandingkan; kalau tidak, tiap unggahan
    // akan selalu terhitung berubah walau isinya sama.
    buatRumahTeknik('AA', '09');
    buatBarisMatrix('AA', '09', 40)->update(['subcon' => 'WINARTO']);

    expect(sinkron()['subcon'])->toBe(1)
        ->and(sinkron()['subcon'])->toBe(0);
});
