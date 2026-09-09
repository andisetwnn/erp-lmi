<?php

use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use App\Models\User;
use App\Services\MatrixImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as PenulisXlsx;

uses(RefreshDatabase::class);

/**
 * Matrix masih disusun manual di luar sistem, jadi berkasnya yang jadi acuan.
 * Tesnya memakai tiruan berkas itu — bukan berkas aslinya — supaya tidak
 * bergantung pada berkas yang kebetulan ada di folder proyek.
 */
function berkasMatrixTiruan(): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    $judulKolom = [
        'A' => 'NO', 'B' => 'NAMA', 'C' => 'BLOK', 'D' => 'NO', 'E' => 'SALES',
        'F' => 'TANGGAL', 'G' => 'TYPE', 'I' => 'TYPE', 'J' => 'LOT',
    ];

    // Satu baris data lengkap: tanggal sebagai angka seri, BM sebagai teks.
    $isiBerlot = [
        'A' => 1, 'B' => 'BUDI MATRIX', 'C' => 'AE', 'D' => '11', 'E' => 'HENDRA',
        'F' => 46071, 'G' => 30, 'H' => 60, 'I' => 'ARJUNA', 'J' => '2', 'K' => 'MSM',
        'L' => 0.9441, 'M' => 185_000_000, 'N' => 198_000_000, 'O' => 179_000_000,
        'P' => 4_000_000, 'Q' => 15_000_000, 'R' => 46205, 'S' => 11_000_000,
        'T' => 0.7333, 'U' => 4_000_000, 'V' => '14/03/2026', 'AB' => 'CBN',
    ];

    foreach (['MIKRO', 'MAKRO A', 'MAKRO B'] as $nama) {
        $sheet = $spreadsheet->createSheet()->setTitle($nama);
        $sheet->setCellValue('A2', 'GRAHA ARYANA');
        $sheet->setCellValue('A3', 'MINGGU KE-24');
        $sheet->setCellValue('A5', 'Per September 2026');

        $sheet->setCellValue('B7', 'ACC SP3K');
        foreach ($judulKolom as $kolom => $teks) {
            $sheet->setCellValue($kolom.'8', $teks);
        }
        $sheet->setCellValue('G9', 'LB');
        $sheet->setCellValue('H9', 'LT');
        foreach ($isiBerlot as $kolom => $nilai) {
            $sheet->setCellValue($kolom.'10', $nilai);
        }

        // Tahap kedua, untuk memastikan label tahap benar-benar berpindah.
        $sheet->setCellValue('B12', 'CASH');
        foreach ($judulKolom as $kolom => $teks) {
            $sheet->setCellValue($kolom.'13', $teks);
        }
        $sheet->setCellValue('A15', 2);
        $sheet->setCellValue('B15', 'SITI TUNAI');
        $sheet->setCellValue('C15', 'AF');
        $sheet->setCellValue('D15', '3');
        $sheet->setCellValue('L15', 1);
        $sheet->setCellValue('N15', 200_000_000);
    }

    // Tahap STOCK lalu catatan "NOTED :" di atas datanya — persis seperti berkas asli.
    $mikro = $spreadsheet->getSheetByName('MIKRO');
    $mikro->setCellValue('B18', 'STOCK');
    $mikro->setCellValue('A19', 'NO');
    $mikro->setCellValue('B19', 'NAMA');
    $mikro->setCellValue('B20', 'NOTED :');
    $mikro->setCellValue('A21', 'NO');
    $mikro->setCellValue('B21', 'NAMA');
    $mikro->setCellValue('A22', 3);
    $mikro->setCellValue('B22', 'RUMAH CONTOH');
    $mikro->setCellValue('C22', 'AA');
    $mikro->setCellValue('D22', '1');
    $mikro->setCellValue('L22', 1);

    // Satu unit dengan progres rendah, supaya rentang bawaan Makro (0–70%) ada isinya.
    $makroB = $spreadsheet->getSheetByName('MAKRO B');
    $makroB->setCellValue('A16', 3);
    $makroB->setCellValue('B16', 'ANDI LAMBAT');
    $makroB->setCellValue('C16', 'AG');
    $makroB->setCellValue('D16', '7');
    $makroB->setCellValue('L16', 0.45);
    $makroB->setCellValue('N16', 190_000_000);

    // Non Lot: judul kolom di baris 7, tanpa subcon, ada BBA di kolom O.
    $sheet = $spreadsheet->createSheet()->setTitle('NON LOT');
    $sheet->setCellValue('B6', 'BERKAS BELUM');
    $sheet->setCellValue('A7', 'NO');
    $sheet->setCellValue('B7', 'NAMA KONSUMEN');
    $sheet->setCellValue('A9', 1);
    $sheet->setCellValue('B9', 'RUDI NONLOT');
    $sheet->setCellValue('C9', 'AD');
    $sheet->setCellValue('D9', '3');
    $sheet->setCellValue('K9', 0.5);
    $sheet->setCellValue('M9', 198_000_000);
    $sheet->setCellValue('N9', 179_000_000);
    $sheet->setCellValue('O9', 2_500_000);

    // Sheet kerja milik Admin KPR yang tidak boleh ikut terbaca.
    foreach (['REKAP', 'REALISASI AKAD', 'FU UM'] as $namaLain) {
        $lain = $spreadsheet->createSheet()->setTitle($namaLain);
        $lain->setCellValue('B4', 'ACC SP3K');
        $lain->setCellValue('A5', 'NO');
        $lain->setCellValue('B5', 'NAMA');
        $lain->setCellValue('A7', 1);
        $lain->setCellValue('B7', 'JANGAN IKUT TERBACA');
    }

    // Sudah Akad: judul kolom di baris 5, susunannya sama dengan Mikro/Makro.
    $sheet = $spreadsheet->createSheet()->setTitle('SUDAH AKAD');
    $sheet->setCellValue('B4', 'SUDAH AKAD');
    $sheet->setCellValue('A5', 'NO');
    $sheet->setCellValue('B5', 'NAMA');
    $sheet->setCellValue('A7', 1);
    $sheet->setCellValue('B7', 'FAJAR SELESAI');
    $sheet->setCellValue('C7', 'AE');
    $sheet->setCellValue('D7', '3');
    $sheet->setCellValue('K7', 'MSM');
    $sheet->setCellValue('N7', 198_000_000);
    $sheet->setCellValue('AA7', 46168);

    $path = tempnam(sys_get_temp_dir(), 'matrix').'.xlsx';
    (new PenulisXlsx($spreadsheet))->save($path);

    return $path;
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->berkas = berkasMatrixTiruan();
});

afterEach(function () {
    if (isset($this->berkas) && is_file($this->berkas)) {
        @unlink($this->berkas);
    }
});

it('membaca semua sheet jadi baris unit', function () {
    $import = app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    expect($import->proyek)->toBe('GRAHA ARYANA')
        ->and($import->minggu_ke)->toBe('MINGGU KE-24')
        ->and($import->periode)->toBe('Per September 2026')
        ->and($import->jumlah_baris)->toBe(10);

    expect(MatrixUnit::kategori('mikro')->count())->toBe(3)
        ->and(MatrixUnit::kategori('makro_a')->count())->toBe(2)
        ->and(MatrixUnit::kategori('makro_b')->count())->toBe(3)
        ->and(MatrixUnit::kategori('non_lot')->count())->toBe(1)
        ->and(MatrixUnit::kategori('akad')->count())->toBe(1);
});

it('melewati sheet kerja Admin KPR yang bukan bagian laporan', function () {
    // Berkas aslinya juga berisi Rekap, Realisasi Akad, FU UM, dan sheet kerja lain.
    // Semuanya harus diabaikan supaya berkas bisa diunggah apa adanya.
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    expect(MatrixUnit::where('nama', 'JANGAN IKUT TERBACA')->count())->toBe(0);
});

it('mengenali sheet akad baik bernama "SUDAH AKAD" maupun "AKAD"', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    expect(MatrixUnit::kategori('akad')->count())->toBe(1);

    $daftar = collect(MatrixImportService::PETA_SHEET)
        ->firstWhere('kategori', 'akad');

    expect($daftar['nama'])->toBe(['SUDAH AKAD', 'AKAD']);
});

it('membaca sheet Akad yang judul kolomnya di baris berbeda', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $u = MatrixUnit::kategori('akad')->first();

    expect($u->nama)->toBe('FAJAR SELESAI')
        ->and($u->seksi)->toBe('SUDAH AKAD')
        ->and($u->kode_unit)->toBe('AE-3')
        ->and($u->subcon)->toBe('MSM')
        ->and($u->rencana_akad->toDateString())->toBe('2026-05-26');
});

it('memisahkan tab Akad dari tab lainnya', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring()->call('pilihTab', 'akad');

    expect($halaman->instance()->kategoriDipakai())->toBe(['akad'])
        // Unit yang sudah akad tidak lagi punya progres untuk disaring.
        ->and($halaman->instance()->pakaiFilterPersen())->toBeFalse();

    $halaman->assertSee('FAJAR SELESAI')->assertDontSee('BUDI MATRIX');

    halamanMonitoring()->assertDontSee('FAJAR SELESAI');
});

it('mengurutkan tabel lewat judul kolom', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring()->set('progMin', '')->set('progMax', '');

    $urut = fn () => $halaman->viewData('unit')->pluck('nama')->unique()->values()->all();

    $halaman->call('sort', 'nama');
    expect($urut()[0])->toBe('ANDI LAMBAT');

    // Klik kedua pada kolom yang sama membalik arahnya.
    $halaman->call('sort', 'nama');
    expect($halaman->get('sortDir'))->toBe('desc')
        ->and($urut()[0])->toBe('SITI TUNAI');
});

it('menolak kolom sort yang tidak ada di daftar putih', function () {
    // Nilai `sort` bisa diketik siapa saja lewat alamat halaman.
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring()->set('sortBy', 'matrix_import_id); drop table matrix_unit;--');

    expect($halaman->viewData('unit'))->not->toBeEmpty();
    expect(MatrixUnit::count())->toBeGreaterThan(0);
});

it('mengurutkan nomor unit secara angka, bukan abjad', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $import = MatrixImport::terakhir();
    MatrixUnit::where('matrix_import_id', $import->id)->delete();

    foreach ([['AE', '9'], ['AE', '10'], ['AE', '2']] as $i => [$blok, $nomor]) {
        MatrixUnit::create([
            'matrix_import_id' => $import->id, 'kategori' => 'mikro', 'seksi' => 'CASH',
            'urutan' => $i + 1, 'nama' => "UNIT $blok-$nomor", 'blok' => $blok, 'nomor_unit' => $nomor,
        ]);
    }

    $halaman = halamanMonitoring()->set('progMin', '')->set('progMax', '')->call('sort', 'unit');

    expect($halaman->viewData('unit')->pluck('nomor_unit')->all())->toBe(['2', '9', '10']);
});

it('mengurutkan tahap mengikuti perjalanan berkas', function () {
    // Kartu dibaca dari kiri: berkas dilengkapi dulu, baru wawancara, lalu SP3K.
    expect(array_slice(MatrixUnit::URUTAN_SEKSI, 0, 4))
        ->toBe(['BERKAS BELUM', 'WAWANCARA', 'ACC SP3K', 'SUDAH AKAD']);
});

it('mengingat tahap yang sedang dibaca, termasuk tahap pertama', function () {
    // Label tahap pertama ada di atas baris judul kolom — pernah terlewat.
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    expect(MatrixUnit::where('nama', 'BUDI MATRIX')->where('kategori', 'mikro')->value('seksi'))
        ->toBe('ACC SP3K')
        ->and(MatrixUnit::where('nama', 'SITI TUNAI')->where('kategori', 'mikro')->value('seksi'))
        ->toBe('CASH')
        ->and(MatrixUnit::where('nama', 'RUDI NONLOT')->value('seksi'))
        ->toBe('BERKAS BELUM');
});

it('memperlakukan "NOTED" sebagai catatan, bukan tahap', function () {
    // Di berkas asli "NOTED :" berdiri persis di bawah tahap STOCK. Kalau ikut
    // dibaca sebagai tahap, unit yang sebenarnya STOCK jadi salah label.
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    expect(MatrixUnit::where('nama', 'RUMAH CONTOH')->value('seksi'))->toBe('STOCK')
        ->and(MatrixUnit::where('seksi', 'NOTED')->count())->toBe(0);
});

it('menerjemahkan tanggal, uang, dan progres', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $u = MatrixUnit::where('nama', 'BUDI MATRIX')->where('kategori', 'mikro')->first();

    // Angka seri Excel 46071 = 18 Februari 2026.
    expect($u->tanggal->toDateString())->toBe('2026-02-18')
        // Kolom tahapan kadang diketik sebagai teks, bukan angka seri.
        ->and($u->bm->toDateString())->toBe('2026-03-14')
        ->and((float) $u->total_harga_jual)->toBe(198000000.0)
        ->and((float) $u->kpr)->toBe(179000000.0)
        // Pecahan 0,9441 dinormalkan ke satuan persen.
        ->and((float) $u->progres)->toBe(94.41)
        // Sedangkan kolom % pembayaran tetap disimpan sebagai pecahan.
        ->and((float) $u->persen_um)->toBe(0.7333)
        ->and($u->subcon)->toBe('MSM')
        ->and($u->kode_unit)->toBe('AE-11');
});

it('memakai peta kolom sendiri untuk Non Lot', function () {
    // Non Lot bergeser satu kolom: tidak ada subcon, tapi ada BBA.
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $u = MatrixUnit::where('nama', 'RUDI NONLOT')->first();

    expect($u->subcon)->toBeNull()
        ->and((float) $u->bba)->toBe(2500000.0)
        ->and((float) $u->total_harga_jual)->toBe(198000000.0)
        ->and((float) $u->kpr)->toBe(179000000.0)
        ->and((float) $u->progres)->toBe(50.0);
});

it('menolak berkas yang bukan Matrix', function () {
    $kosong = new Spreadsheet;
    $kosong->getActiveSheet()->setTitle('Sheet1')->setCellValue('A1', 'bukan matrix');
    $path = tempnam(sys_get_temp_dir(), 'bukan').'.xlsx';
    (new PenulisXlsx($kosong))->save($path);

    expect(fn () => app(MatrixImportService::class)->impor($path, 'bukan.xlsx'))
        ->toThrow(RuntimeException::class);

    expect(MatrixImport::count())->toBe(0);

    @unlink($path);
});

function halamanMonitoring()
{
    $user = User::factory()->create();
    $user->assignRole('admin-kpr');

    return Livewire::actingAs($user)->test('pages::matrix.monitoring-akad');
}

it('membuka tab Mikro dengan rentang bawaan 80–100%', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring();

    expect($halaman->get('progMin'))->toBe('80')
        ->and($halaman->get('progMax'))->toBe('100');

    // BUDI 94,41% dan SITI 100% masuk; ANDI 45% tidak.
    $halaman->assertSee('BUDI MATRIX')
        ->assertSee('SITI TUNAI')
        ->assertDontSee('ANDI LAMBAT')
        ->assertDontSee('RUDI NONLOT');
});

it('memakai kumpulan unit yang sama untuk Mikro dan Makro', function () {
    // Yang membedakan kedua tab hanya rentang progres bawaannya.
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring();
    expect($halaman->instance()->kategoriDipakai())->toBe(['mikro', 'makro_a', 'makro_b']);

    $halaman->call('pilihTab', 'makro');
    expect($halaman->instance()->kategoriDipakai())->toBe(['mikro', 'makro_a', 'makro_b']);

    $halaman->call('pilihTab', 'non-lot');
    expect($halaman->instance()->kategoriDipakai())->toBe(['non_lot']);
});

it('mengganti rentang bawaan saat pindah tab', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring()->call('pilihTab', 'makro');

    expect($halaman->get('progMin'))->toBe('0')
        ->and($halaman->get('progMax'))->toBe('70');

    // Kebalikan dari tab Mikro: yang tampil justru unit berprogres rendah.
    $halaman->assertSee('ANDI LAMBAT')
        ->assertDontSee('BUDI MATRIX')
        ->assertDontSee('SITI TUNAI');

    $halaman->call('pilihTab', 'non-lot');

    expect($halaman->get('progMin'))->toBe('')
        ->and($halaman->get('progMax'))->toBe('');

    $halaman->assertSee('RUDI NONLOT')->assertDontSee('BUDI MATRIX');
});

it('melepas filter tahap saat pindah tab', function () {
    // Tahap milik tab sebelumnya bisa membuat tabel tampak kosong tanpa sebab.
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring()->set('seksi', 'CASH')->set('blok', 'AE');

    $halaman->call('pilihTab', 'non-lot');

    expect($halaman->get('seksi'))->toBe('')
        ->and($halaman->get('blok'))->toBe('');
});

it('menempatkan unit tanpa nilai progres lewat kisaran sheet asalnya', function () {
    // Di Makro B banyak unit yang progresnya belum diisi. Menyaringnya dengan angka
    // yang tidak ada akan membuatnya hilang dari semua tab, padahal judul sheet-nya
    // sudah menyebut kisarannya (< 50%).
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    MatrixUnit::where('nama', 'ANDI LAMBAT')->update(['progres' => null]);

    halamanMonitoring()
        ->call('pilihTab', 'makro')
        ->assertSee('ANDI LAMBAT');

    halamanMonitoring()->assertDontSee('ANDI LAMBAT');
});

it('bisa mengosongkan rentang untuk melihat semua unit', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    halamanMonitoring()
        ->set('progMin', '')
        ->set('progMax', '')
        ->assertSee('BUDI MATRIX')
        ->assertSee('ANDI LAMBAT');
});

it('menyaring lewat pencarian dan kartu tahap', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    halamanMonitoring()
        ->call('pakaiSeksi', 'CASH')
        ->assertSee('SITI TUNAI')
        ->assertDontSee('BUDI MATRIX')
        // Klik kartu yang sama sekali lagi melepas filternya.
        ->call('pakaiSeksi', 'CASH')
        ->assertSee('BUDI MATRIX')
        ->set('search', 'BUDI')
        ->assertSee('BUDI MATRIX')
        ->assertDontSee('SITI TUNAI');
});

it('menyaring berdasarkan rentang progres di tab Mikro dan Makro', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    // BUDI 94,41% · SITI 100%
    halamanMonitoring()
        ->set('progMin', '95')
        ->assertSee('SITI TUNAI')
        ->assertDontSee('BUDI MATRIX')
        ->set('progMin', '')
        ->set('progMax', '95')
        ->assertSee('BUDI MATRIX')
        ->assertDontSee('SITI TUNAI');
});

it('tidak memakai filter persentase di tab Non Lot', function () {
    app(MatrixImportService::class)->impor($this->berkas, 'MATRIX.xlsx');

    $halaman = halamanMonitoring();
    expect($halaman->instance()->pakaiFilterPersen())->toBeTrue();

    $halaman->call('pilihTab', 'non-lot');
    expect($halaman->instance()->pakaiFilterPersen())->toBeFalse();
});

it('mengunggah lewat halaman dan langsung dipakai laporan', function () {
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    Livewire::actingAs($user)
        ->test('pages::matrix.upload')
        ->set('berkas', UploadedFile::fake()->createWithContent(
            'MATRIX.xlsx', file_get_contents($this->berkas)
        ))
        ->call('unggah')
        ->assertHasNoErrors();

    expect(MatrixImport::count())->toBe(1)
        ->and(MatrixImport::terakhir()->jumlah_baris)->toBe(10);
});

it('menutup laporan Matrix dari role yang tidak berhak', function () {
    $teknik = User::factory()->create();
    $teknik->assignRole('admin-teknik');

    $this->actingAs($teknik)->get(route('matrix.monitoring-akad'))->assertForbidden();
    $this->actingAs($teknik)->get(route('matrix.upload'))->assertForbidden();
});

it('hanya membolehkan super admin mengunggah', function () {
    // Matrix disusun manual dan menimpa isi laporan, jadi unggahnya sengaja
    // dipegang satu pintu. Role lain cukup melihat hasilnya.
    $super = User::factory()->create();
    $super->assignRole('super-admin');
    $this->actingAs($super)->get(route('matrix.upload'))->assertOk();

    foreach (['admin-kpr', 'finance', 'project-manager', 'admin-sales'] as $peran) {
        $user = User::factory()->create();
        $user->assignRole($peran);

        $this->actingAs($user)->get(route('matrix.monitoring-akad'))->assertOk();
        $this->actingAs($user)->get(route('matrix.upload'))->assertForbidden();
    }
});

it('membolehkan direktur melihat tapi tidak mengunggah', function () {
    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');

    $this->actingAs($direktur)->get(route('matrix.monitoring-akad'))->assertOk();
    $this->actingAs($direktur)->get(route('matrix.upload'))->assertForbidden();
});
