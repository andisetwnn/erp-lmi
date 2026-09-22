<?php

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as PenulisXlsx;

uses(RefreshDatabase::class);

/**
 * Penjualan yang berakhir batal sebagian tidak ikut terbawa saat import SOP,
 * sehingga penomoran SPR bolong. Perintah ini melengkapinya dari berkas
 * pembatalan milik Admin KPR — dan karena menambah SPR ke data yang sudah
 * dipakai, ia harus melapor dulu sebelum menyimpan apa pun.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::orderBy('id')->first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-BT', 'nama' => 'Delon', 'is_aktif' => true,
        'dbos_username' => 'sales-bt', 'dbos_password' => 'rahasia123',
    ]);

    $this->berkas = tempnam(sys_get_temp_dir(), 'batal').'.xlsx';
});

afterEach(function () {
    if (isset($this->berkas) && is_file($this->berkas)) {
        @unlink($this->berkas);
    }
});

function buatUnit(string $blok, string $nomor): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id,
        'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'available',
    ]);
}

/** Tulis berkas pembatalan tiruan: judul di baris 5, data mulai baris 7. */
function tulisBerkasBatal(array $baris): void
{
    $buku = new Spreadsheet;
    $lembar = $buku->getActiveSheet();

    $lembar->setCellValue('D5', 'NAMA');
    $lembar->setCellValue('F5', 'NO SPR');

    $r = 7;

    foreach ($baris as $b) {
        $b = array_merge([
            'tgl_jual' => '11/04/2026',
            'tgl_proses' => '20/05/2026',
            'nama' => 'KONSUMEN UJI',
            'nomor' => '09001',
            'sales' => 'DELON',
            'unit' => 'ZZ-01',
            'harga' => 198_000_000,
            'um' => 15_000_000,
            'kwitansi' => '00777',
            'utj' => 500_000,
            'tgl_setor' => '12/04/2026',
            'akumulasi' => 500_000,
            'alamat' => 'Jl. Uji Coba 1',
            'telepon' => '085700000001',
            'ket' => 'TIDAK KOORPERATIF',
            'warna' => 'FFFF0000',
        ], $b);

        $lembar->setCellValue("B$r", $b['tgl_jual']);
        $lembar->setCellValue("C$r", $b['tgl_proses']);
        $lembar->setCellValue("D$r", $b['nama']);
        $lembar->setCellValue("F$r", $b['nomor']);
        $lembar->setCellValue("G$r", $b['sales']);
        $lembar->setCellValue("H$r", $b['unit']);
        $lembar->setCellValue("K$r", $b['harga']);
        $lembar->setCellValue("L$r", $b['um']);
        $lembar->setCellValue("M$r", $b['kwitansi']);
        $lembar->setCellValue("N$r", $b['utj']);
        $lembar->setCellValue("O$r", $b['tgl_setor']);
        $lembar->setCellValue("P$r", $b['akumulasi']);
        $lembar->setCellValue("S$r", $b['alamat']);
        $lembar->setCellValue("T$r", $b['telepon']);
        $lembar->setCellValue("U$r", $b['ket']);

        $lembar->getStyle("F$r")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($b['warna']);

        $r++;
    }

    (new PenulisXlsx($buku))->save(test()->berkas);
}

function jalankanImportBatal(array $opsi = []): PendingCommand
{
    return test()->artisan('import:spr-batal', array_merge([
        '--file' => test()->berkas,
    ], $opsi));
}

/** SPR yang sudah lebih dulu ada di database, dengan nomor lengkap apa adanya. */
function buatSprLama(string $nomorPenuh, Rumah $rumah): Spr
{
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'KONSUMEN LAMA', 'hp' => '085700009999',
        'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => '2026-09-01', 'tanggal_expired' => '2026-09-08', 'status' => 'sukses',
    ]);

    return Spr::create([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => $nomorPenuh,
        'tanggal_spr' => '2026-09-01', 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'harga_jual' => 198_000_000, 'total_harga' => 198_000_000, 'um_net' => 15_000_000,
    ]);
}

it('hanya melapor kalau --commit tidak diberikan', function () {
    buatUnit('ZZ', '01');
    tulisBerkasBatal([[]]);

    $sebelum = Spr::count();

    jalankanImportBatal()->assertExitCode(0);

    expect(Spr::count())->toBe($sebelum);
});

it('membuat SPR batal beserta realisasi UTJ-nya', function () {
    buatUnit('ZZ', '01');
    tulisBerkasBatal([['nomor' => '09001', 'kwitansi' => '00777']]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    $spr = Spr::where('nomor_spr', 'SPR/2026/04/09001')->first();

    expect($spr)->not->toBeNull()
        ->and($spr->status)->toBe('cancelled')
        ->and((float) $spr->total_harga)->toBe(198_000_000.0)
        ->and((float) $spr->utj_nominal)->toBe(500_000.0)
        ->and($spr->cancelled_at->toDateString())->toBe('2026-05-20')
        ->and($spr->alasanPembatalan->nama)->toBe('Konsumen Tidak Kooperatif');

    $realisasi = SprRealisasiPembayaran::where('spr_id', $spr->id)->first();

    expect($realisasi)->not->toBeNull()
        ->and((float) $realisasi->jumlah)->toBe(500_000.0)
        ->and($realisasi->nomor_kwitansi)->toBe('00777')
        ->and($realisasi->tanggal_bayar->toDateString())->toBe('2026-04-12');
});

it('memakai nilai status yang diterima kolomnya', function () {
    // SQLite tidak menegakkan enum, MySQL menegakkan. Tanpa dipatok di sini,
    // nilai yang salah baru ketahuan waktu impor dijalankan di produksi.
    buatUnit('ZZ', '01');
    tulisBerkasBatal([[]]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    $spr = Spr::where('status', 'cancelled')->first();

    expect($spr->prospectCustomer->status)->toBeIn(['cold', 'warm', 'hot', 'finish', 'archive'])
        ->and($spr->booking->status)->toBeIn(['aktif', 'sukses', 'batal', 'akad'])
        ->and($spr->refund_status)->toBeIn(['pending', 'tidak_ada_refund', 'partial', 'full'])
        ->and($spr->utj_metode)->toBeIn(['transfer', 'tunai'])
        ->and($spr->kategori)->toBeIn(['subsidi', 'komersial'])
        ->and($spr->jenis_pembayaran)->toBeIn(['cash', 'cash_bertahap', 'kpr'])
        ->and(SprRealisasiPembayaran::where('spr_id', $spr->id)->value('jenis'))
        ->toBeIn(['bf', 'um', 'sbum', 'kpr']);
});

it('memakai nomor terakhir saja, sisanya jadi catatan', function () {
    // "00038/00160/00165" bukan tiga penjualan — satu penjualan yang nomornya
    // berganti dua kali. Membuat SPR untuk tiap nomor akan melipatgandakannya.
    buatUnit('ZZ', '01');
    tulisBerkasBatal([['nomor' => '09038/09160/09165']]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    expect(Spr::where('nomor_spr', 'like', 'SPR/%/09165')->count())->toBe(1)
        ->and(Spr::where('nomor_spr', 'like', 'SPR/%/09038')->exists())->toBeFalse()
        ->and(Spr::where('nomor_spr', 'like', 'SPR/%/09160')->exists())->toBeFalse()
        ->and(Spr::where('nomor_spr', 'like', 'SPR/%/09165')->value('catatan'))
        ->toBe('Nomor SPR sebelumnya: 09038, 09160');
});

it('melewati nomor yang sudah ada walau awalan bulannya berbeda', function () {
    // Awalan tahun/bulan di sistem diambil dari tanggal SPR-nya sendiri, dan bisa
    // berbeda dari tanggal jual di berkas ini. Kalau dicocokkan utuh, SPR yang
    // sudah ada terbaca hilang lalu dibuat kembar.
    $unit = buatUnit('ZZ', '01');
    buatSprLama('SPR/2026/09/09001', $unit);

    tulisBerkasBatal([['nomor' => '09001', 'tgl_jual' => '11/04/2026']]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    expect(Spr::where('nomor_spr', 'like', 'SPR/%/09001')->count())->toBe(1)
        // Yang sudah ada tidak disentuh — statusnya urusan terpisah.
        ->and(Spr::where('nomor_spr', 'SPR/2026/09/09001')->value('status'))->toBe('approved');
});

it('membatalkan seluruh impor kalau ada unit yang tidak ada di master', function () {
    buatUnit('ZZ', '01');

    tulisBerkasBatal([
        ['nomor' => '09001', 'unit' => 'ZZ-01'],
        ['nomor' => '09002', 'unit' => 'YY-09', 'nama' => 'KONSUMEN LAIN'],
    ]);

    $sebelum = Spr::count();

    jalankanImportBatal(['--commit' => true])->assertExitCode(1);

    expect(Spr::count())->toBe($sebelum);
});

it('menyamakan nomor unit berawalan nol dengan yang tanpa nol', function () {
    buatUnit('ZZ', '07');
    tulisBerkasBatal([['unit' => 'ZZ-7']]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    expect(Spr::where('status', 'cancelled')->value('rumah_id'))
        ->toBe(Rumah::where('blok', 'ZZ')->where('nomor_unit', '07')->value('id'));
});

it('tidak mengembalikan setoran yang baru sebatas UTJ', function () {
    buatUnit('ZZ', '01');
    tulisBerkasBatal([['utj' => 500_000, 'akumulasi' => 500_000]]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    expect(Spr::where('status', 'cancelled')->value('refund_status'))->toBe('tidak_ada_refund');
});

it('menyerahkan ke keuangan kalau setorannya sudah melebihi UTJ', function () {
    buatUnit('ZZ', '01');
    tulisBerkasBatal([['utj' => 500_000, 'akumulasi' => 5_500_000]]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    expect(Spr::where('status', 'cancelled')->value('refund_status'))->toBe('pending');
});

it('mengosongkan nomor kuitansi yang sudah dipakai kuitansi lain', function () {
    // Kolomnya unik. Lebih baik kehilangan satu nomor daripada seluruh impor gagal.
    $unit = buatUnit('ZZ', '01');
    $lama = buatSprLama('SPR/2026/09/09999', $unit);

    SprRealisasiPembayaran::create([
        'spr_id' => $lama->id, 'jenis' => 'bf', 'tanggal_bayar' => '2026-09-02',
        'jumlah' => 500_000, 'nomor_kwitansi' => '00777', 'metode' => 'transfer',
    ]);

    tulisBerkasBatal([['nomor' => '09001', 'kwitansi' => '00777']]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    $baru = Spr::where('nomor_spr', 'like', 'SPR/%/09001')->first();

    expect(SprRealisasiPembayaran::where('spr_id', $baru->id)->value('nomor_kwitansi'))->toBeNull();
});

it('mengambil alasan pindah kavling dari warna hijau di kolom nomor', function () {
    buatUnit('ZZ', '01');
    tulisBerkasBatal([['warna' => 'FF00B050', 'ket' => 'PINDAH KE DB 14']]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    expect(Spr::where('status', 'cancelled')->first()->alasanPembatalan->nama)->toBe('Pindah Kavling');
});

it('menolak baris yang alasannya tidak ada di master', function () {
    // SPR batal tanpa alasan tidak terbaca di laporan pembatalan — lebih baik
    // ditolak terang-terangan daripada tersimpan kosong.
    AlasanPembatalan::where('nama', 'Mengundurkan diri')->delete();

    buatUnit('ZZ', '01');
    tulisBerkasBatal([['ket' => 'ALASAN YANG TIDAK DIKENAL']]);

    $sebelum = Spr::count();

    jalankanImportBatal(['--commit' => true])->assertExitCode(1);

    expect(Spr::count())->toBe($sebelum);
});

it('menolak baris yang salesnya tidak ada di master', function () {
    // Sales wajib terisi di prospect, booking, dan SPR.
    buatUnit('ZZ', '01');
    tulisBerkasBatal([['sales' => 'ORANG YANG TIDAK TERDAFTAR']]);

    $sebelum = Spr::count();

    jalankanImportBatal(['--commit' => true])->assertExitCode(1);

    expect(Spr::count())->toBe($sebelum);
});

it('memadankan nama panggilan sales dengan nama di master', function () {
    Sales::create([
        'kode' => 'SLS-NF', 'nama' => 'Nur Fitriana', 'is_aktif' => true,
        'dbos_username' => 'sales-nf', 'dbos_password' => 'rahasia123',
    ]);

    buatUnit('ZZ', '01');
    tulisBerkasBatal([['sales' => 'ANA']]);

    jalankanImportBatal(['--commit' => true])->assertExitCode(0);

    expect(Spr::where('status', 'cancelled')->value('sales_id'))
        ->toBe(Sales::where('nama', 'Nur Fitriana')->value('id'));
});
