<?php

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalDetail;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\User;
use App\Services\LaporanAkuntingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Dua versi laporan: Detail untuk Accounting, Resume untuk Direksi.
 *
 * Datanya sama — yang berbeda hanya sampai mana rinciannya diturunkan. Resume berhenti
 * di kelompok akun, Detail meneruskan sampai akunnya.
 *
 * Plus Laba Rugi Tahunan: dua belas bulan dalam satu tabel. Sengaja tidak memanggil
 * labaRugi() dua belas kali — fungsi itu satu query per akun, jadi setahun berarti
 * ribuan query untuk satu halaman.
 */
beforeEach(function () {
    $this->seed();

    $perusahaanId = Perusahaan::value('id');

    // Akun uji diberi induk. Tanpa induk, akun jadi header-nya sendiri dan tetap
    // tampil di versi resume — bedanya jadi tidak teruji.
    $indukPendapatan = Coa::create([
        'perusahaan_id' => $perusahaanId, 'kode' => '4900', 'nama' => 'Kelompok Pendapatan Uji',
        'tipe' => 'pendapatan', 'saldo_normal' => 'kredit', 'is_header' => true, 'is_aktif' => true,
    ]);

    $indukBeban = Coa::create([
        'perusahaan_id' => $perusahaanId, 'kode' => '5900', 'nama' => 'Kelompok Beban Uji',
        'tipe' => 'beban', 'saldo_normal' => 'debit', 'is_header' => true, 'is_aktif' => true,
    ]);

    $this->pendapatan = Coa::create([
        'perusahaan_id' => $perusahaanId, 'kode' => '4901', 'nama' => 'Penjualan Uji',
        'tipe' => 'pendapatan', 'saldo_normal' => 'kredit', 'is_header' => false, 'is_aktif' => true,
        'parent_id' => $indukPendapatan->id,
    ]);

    $this->beban = Coa::create([
        'perusahaan_id' => $perusahaanId, 'kode' => '5901', 'nama' => 'Beban Uji',
        'tipe' => 'beban', 'saldo_normal' => 'debit', 'is_header' => false, 'is_aktif' => true,
        'parent_id' => $indukBeban->id,
    ]);

    $this->finance = User::factory()->create();
    $this->finance->assignRole('finance');

    $this->direktur = User::factory()->create();
    $this->direktur->assignRole('direktur');

    $this->actingAs($this->finance);
});

/** Jurnal posted: pendapatan (kredit) diimbangi beban (debet). */
function jurnalLaba(string $tanggal, float $pendapatan, float $beban): void
{
    $jurnal = Jurnal::create([
        'perusahaan_id' => Perusahaan::value('id'),
        'tanggal' => $tanggal,
        'no_bukti' => 'LR/'.str_replace('-', '', $tanggal).'/'.random_int(1000, 9999),
        'tipe' => 'umum',
        'kategori_bukti' => 'KAS',
        'status' => 'posted',
        'created_by_user_id' => test()->finance->id,
    ]);

    JurnalDetail::create([
        'jurnal_id' => $jurnal->id, 'coa_id' => test()->pendapatan->id,
        'debet' => 0, 'kredit' => $pendapatan,
    ]);

    JurnalDetail::create([
        'jurnal_id' => $jurnal->id, 'coa_id' => test()->beban->id,
        'debet' => $beban, 'kredit' => 0,
    ]);
}

it('memilih versi sesuai peran saat pertama dibuka', function () {
    // Direksi butuh gambaran besar, accounting butuh rinciannya.
    Livewire::test('pages::akunting.laba-rugi')->assertSet('versi', 'detail');

    $this->actingAs($this->direktur);
    Livewire::test('pages::akunting.laba-rugi')->assertSet('versi', 'resume');
    Livewire::test('pages::akunting.neraca')->assertSet('versi', 'resume');
});

it('menyembunyikan rincian akun di versi resume', function () {
    jurnalLaba('2027-03-01', 5_000_000, 2_000_000);

    $periode = ['from' => '2027-01-01', 'to' => '2027-12-31'];

    // Kelompoknya tampil di kedua versi; akunnya hanya di Detail.
    Livewire::test('pages::akunting.laba-rugi', $periode)
        ->set('versi', 'detail')
        ->assertViewHas('rinci', true)
        ->assertSeeText('Kelompok Pendapatan Uji')
        ->assertSeeText('Penjualan Uji');

    Livewire::test('pages::akunting.laba-rugi', $periode)
        ->set('versi', 'resume')
        ->assertViewHas('rinci', false)
        ->assertSeeText('Kelompok Pendapatan Uji')
        ->assertDontSeeText('4901');
});

it('tetap menampilkan total yang sama di kedua versi', function () {
    jurnalLaba('2027-03-01', 5_000_000, 2_000_000);

    $periode = ['from' => '2027-01-01', 'to' => '2027-12-31'];

    $detail = Livewire::test('pages::akunting.laba-rugi', $periode)->set('versi', 'detail');
    $resume = Livewire::test('pages::akunting.laba-rugi', $periode)->set('versi', 'resume');

    // Yang berubah cuma kedalaman rincian, bukan angkanya.
    expect($detail->viewData('data')['laba_rugi'])
        ->toBe($resume->viewData('data')['laba_rugi'])
        ->toBe(3000000.0);
});

it('meneruskan versi ke cetakan PDF', function () {
    jurnalLaba('2027-03-01', 5_000_000, 2_000_000);

    $this->get(route('akunting.laba-rugi.print', [
        'from' => '2027-01-01', 'to' => '2027-12-31', 'versi' => 'resume',
    ]))->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->get(route('akunting.neraca.print', [
        'tgl' => '2027-12-31', 'from' => '2027-01-01', 'versi' => 'resume',
    ]))->assertOk();
});

it('menolak versi yang tidak dikenal di cetakan', function () {
    $this->get(route('akunting.laba-rugi.print', [
        'from' => '2027-01-01', 'to' => '2027-12-31', 'versi' => 'ngawur',
    ]))->assertSessionHasErrors('versi');
});

it('memecah laba rugi tahunan per bulan', function () {
    jurnalLaba('2027-02-10', 3_000_000, 1_000_000);
    jurnalLaba('2027-02-20', 2_000_000, 500_000);
    jurnalLaba('2027-07-05', 4_000_000, 6_000_000);

    $data = app(LaporanAkuntingService::class)->labaRugiTahunan(Perusahaan::value('id'), 2027);

    expect($data['pendapatan']['per_bulan'][2])->toBe(5000000.0)
        ->and($data['beban']['per_bulan'][2])->toBe(1500000.0)
        ->and($data['laba_rugi']['per_bulan'][2])->toBe(3500000.0)
        // Juli rugi: beban lebih besar dari pendapatan.
        ->and($data['laba_rugi']['per_bulan'][7])->toBe(-2000000.0)
        // Bulan tanpa transaksi tetap ada barisnya, isinya nol.
        ->and($data['laba_rugi']['per_bulan'][1])->toBe(0.0)
        ->and($data['laba_rugi']['total'])->toBe(1500000.0);
});

it('tidak mencampur tahun lain', function () {
    jurnalLaba('2028-06-01', 9_000_000, 0);
    jurnalLaba('2027-06-01', 1_000_000, 0);

    $data = app(LaporanAkuntingService::class)->labaRugiTahunan(Perusahaan::value('id'), 2027);

    expect($data['pendapatan']['total'])->toBe(1000000.0);
});

it('mengabaikan jurnal draft di laporan tahunan', function () {
    $jurnal = Jurnal::create([
        'perusahaan_id' => Perusahaan::value('id'),
        'tanggal' => '2027-04-01',
        'no_bukti' => 'LR/DRAFT/0001',
        'tipe' => 'umum',
        'kategori_bukti' => 'KAS',
        'status' => 'draft',
        'created_by_user_id' => $this->finance->id,
    ]);

    JurnalDetail::create([
        'jurnal_id' => $jurnal->id, 'coa_id' => $this->pendapatan->id,
        'debet' => 0, 'kredit' => 7_000_000,
    ]);

    $data = app(LaporanAkuntingService::class)->labaRugiTahunan(Perusahaan::value('id'), 2027);

    expect($data['pendapatan']['per_bulan'][4])->toBe(0.0);
});

it('memulangkan dua belas bulan kosong kalau tahunnya tidak ada transaksi', function () {
    $data = app(LaporanAkuntingService::class)->labaRugiTahunan(Perusahaan::value('id'), 1999);

    expect($data['laba_rugi']['per_bulan'])->toHaveCount(12)
        ->and($data['pendapatan']['baris'])->toBeEmpty()
        ->and($data['laba_rugi']['total'])->toBe(0.0);
});

it('mencocokkan total tahunan dengan laba rugi periode setahun penuh', function () {
    jurnalLaba('2027-02-10', 3_000_000, 1_000_000);
    jurnalLaba('2027-09-15', 4_000_000, 2_500_000);

    $svc = app(LaporanAkuntingService::class);
    $perusahaanId = Perusahaan::value('id');

    $tahunan = $svc->labaRugiTahunan($perusahaanId, 2027);
    $periode = $svc->labaRugi($perusahaanId, '2027-01-01', '2027-12-31');

    // Kalau kedua jalur ini berbeda, salah satunya salah hitung.
    expect($tahunan['laba_rugi']['total'])->toBe($periode['laba_rugi']);
});

it('menghitung setahun tanpa membanjiri database dengan query', function () {
    foreach (range(1, 12) as $bulan) {
        jurnalLaba(sprintf('2026-%02d-15', $bulan), 1_000_000, 400_000);
    }

    DB::enableQueryLog();
    app(LaporanAkuntingService::class)->labaRugiTahunan(Perusahaan::value('id'), 2027);
    $jumlah = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Satu query agregat + sekali ambil COA. Kalau ini melonjak, berarti ada yang
    // kembali menghitung saldo per akun per bulan.
    expect($jumlah)->toBeLessThan(5);
});

it('halaman tahunan bisa dibuka dan pindah tahun', function () {
    jurnalLaba('2027-03-01', 1_000_000, 0);

    Livewire::test('pages::akunting.laba-rugi-tahunan')
        ->assertSet('tahun', (int) now()->year)
        ->call('gantiTahun', -1)
        ->assertSet('tahun', (int) now()->year - 1)
        ->assertViewHas('data', fn ($d) => $d['tahun'] === (int) now()->year - 1);
});

it('mencetak laporan tahunan jadi PDF', function () {
    jurnalLaba('2027-03-01', 1_000_000, 0);

    $this->get(route('akunting.laba-rugi-tahunan.print', ['tahun' => 2027]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('menolak tahun yang tidak masuk akal di cetakan tahunan', function () {
    $this->get(route('akunting.laba-rugi-tahunan.print', ['tahun' => 1800]))
        ->assertSessionHasErrors('tahun');
});

it('menautkan kedua tampilan lewat tab, bukan menu sidebar terpisah', function () {
    // Sidebar cuma punya satu entri Laba Rugi; versi tahunan dijangkau lewat tab.
    $this->get(route('akunting.laba-rugi.index'))
        ->assertOk()
        ->assertSee(route('akunting.laba-rugi-tahunan.index'), false)
        ->assertSeeText('Tahunan');

    $this->get(route('akunting.laba-rugi-tahunan.index'))
        ->assertOk()
        ->assertSee(route('akunting.laba-rugi.index'), false)
        ->assertSeeText('Per Periode');
});

it('menutup halaman tahunan dari user tanpa izin laba rugi', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    $this->get(route('akunting.laba-rugi-tahunan.index'))->assertForbidden();
});
