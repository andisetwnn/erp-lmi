<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;

uses(RefreshDatabase::class);

/**
 * Sebagian kavling di siteplan belum pernah masuk master, sehingga Total Rencana
 * Kavling di dashboard direksi lebih kecil dari kenyataan. Perintah ini
 * melengkapinya — dan karena menambah puluhan unit sekaligus mengubah semua
 * persentase di dashboard, ia harus melapor dulu sebelum menyimpan.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();

    $this->berkas = tempnam(sys_get_temp_dir(), 'stock').'.csv';
});

afterEach(function () {
    if (isset($this->berkas) && is_file($this->berkas)) {
        @unlink($this->berkas);
    }
});

function tulisDaftar(array $baris): void
{
    $isi = "blok,nomor,tipe,catatan\n";

    foreach ($baris as $b) {
        $isi .= implode(',', $b)."\n";
    }

    file_put_contents(test()->berkas, $isi);
}

function jalankanImport(array $opsi = []): PendingCommand
{
    return test()->artisan('master:import-stock-kavling', array_merge([
        '--file' => test()->berkas,
    ], $opsi));
}

it('hanya melapor kalau --commit tidak diberikan', function () {
    tulisDaftar([
        ['ZZ', '01', '30/60', ''],
        ['ZZ', '02', '30/60', ''],
    ]);

    $sebelum = Rumah::count();

    jalankanImport()->assertExitCode(0);

    expect(Rumah::count())->toBe($sebelum);
});

it('menambahkan unit yang belum ada dengan status draft', function () {
    tulisDaftar([
        ['ZZ', '01', '30/60', ''],
        ['ZZ', '02', '30/60', 'RUMAH CONTOH'],
    ]);

    jalankanImport(['--commit' => true])->assertExitCode(0);

    $unit = Rumah::where('blok', 'ZZ')->orderBy('nomor_unit')->get();

    expect($unit)->toHaveCount(2)
        ->and($unit->pluck('status')->unique()->all())->toBe(['draft'])
        // Nomor diseragamkan dua digit mengikuti mayoritas isi master.
        ->and($unit->pluck('nomor_unit')->all())->toBe(['01', '02']);
});

it('melewati unit yang sudah ada, apa pun statusnya', function () {
    $tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    Rumah::create([
        'proyek_id' => $this->proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'ZZ', 'nomor_unit' => '01', 'status' => 'terjual',
    ]);

    tulisDaftar([
        ['ZZ', '01', '30/60', ''],
        ['ZZ', '02', '30/60', ''],
    ]);

    jalankanImport(['--commit' => true])->assertExitCode(0);

    expect(Rumah::where('blok', 'ZZ')->count())->toBe(2)
        // Yang sudah ada tidak disentuh — statusnya tetap terjual, bukan direset draft.
        ->and(Rumah::where('blok', 'ZZ')->where('nomor_unit', '01')->value('status'))->toBe('terjual');
});

it('menyamakan nomor berawalan nol dengan yang tanpa nol', function () {
    // Isi master tidak seragam: sebagian "1", sebagian "01". Tanpa penyamaan,
    // unit yang sudah ada akan terbaca sebagai kurang lalu digandakan.
    $tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    Rumah::create([
        'proyek_id' => $this->proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'ZZ', 'nomor_unit' => '7', 'status' => 'available',
    ]);

    tulisDaftar([['ZZ', '07', '30/60', '']]);

    jalankanImport(['--commit' => true])->assertExitCode(0);

    expect(Rumah::where('blok', 'ZZ')->count())->toBe(1);
});

it('berhenti tanpa menyimpan apa pun kalau ada tipe yang tidak dikenal', function () {
    // Lebih baik gagal terang-terangan daripada menebak tipe terdekat: salah tipe
    // berarti salah harga, salah KPR, dan salah uang muka di semua SPR berikutnya.
    tulisDaftar([
        ['ZZ', '01', '30/60', ''],
        ['ZZ', '02', '99/99', ''],
    ]);

    $sebelum = Rumah::count();

    jalankanImport(['--commit' => true])->assertExitCode(1);

    expect(Rumah::count())->toBe($sebelum);
});

it('mencocokkan label tipe ke nama tipe di master', function () {
    TipeRumah::create([
        'proyek_id' => $this->proyek->id, 'tipe' => 'Nakula 21/78',
        'nama_tipe' => 'Nakula', 'kategori' => 'subsidi',
        'luas_bangunan' => 21, 'luas_tanah' => 78, 'harga_jual' => 168_000_000,
    ]);

    tulisDaftar([
        ['ZZ', '01', '30/60', ''],
        ['ZZ', '02', '21/78', ''],
    ]);

    jalankanImport(['--commit' => true])->assertExitCode(0);

    $nakula = TipeRumah::where('tipe', 'Nakula 21/78')->first();
    $arjuna = TipeRumah::where('tipe', 'like', 'Arjuna%')->first();

    expect(Rumah::where('blok', 'ZZ')->where('nomor_unit', '02')->value('tipe_rumah_id'))->toBe($nakula->id)
        ->and(Rumah::where('blok', 'ZZ')->where('nomor_unit', '01')->value('tipe_rumah_id'))->toBe($arjuna->id);
});

it('menolak berkas yang judul kolomnya tidak sesuai', function () {
    file_put_contents($this->berkas, "kode,unit\nZZ,01\n");

    $sebelum = Rumah::count();

    jalankanImport(['--commit' => true])->assertExitCode(1);

    expect(Rumah::count())->toBe($sebelum);
});
