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
 * Laba Rugi berlapis: penjualan − harga pokok = laba kotor, lalu dikurangi biaya
 * usaha sampai laba bersih, masing-masing dengan persentasenya terhadap penjualan.
 *
 * Lapisannya dikenali dari awalan kode akun — 4 penjualan, 5 harga pokok, 6 biaya
 * usaha, 7 pendapatan di luar usaha, 8 pajak final — karena bagan akunnya memang
 * sudah tersusun begitu, dan nomor tidak ikut berubah saat orang menyunting nama
 * kelompok di master COA.
 *
 * Penjaga terpentingnya ada di tes pertama: berapa pun lapisannya, laba bersih
 * harus selalu sama dengan total lama. Kalau ada akun yang jatuh ke celah dan
 * menguap, selisihnya muncul di situ.
 */
beforeEach(function () {
    $this->seed();

    $this->perusahaanId = Perusahaan::value('id');

    $this->finance = User::factory()->create();
    $this->finance->assignRole('finance');
    $this->actingAs($this->finance);

    $this->svc = app(LaporanAkuntingService::class);
});

/** Akun beserta induknya, supaya kelompoknya terbaca seperti di master sungguhan. */
function akunUji(string $kode, string $nama, string $tipe): Coa
{
    $saldoNormal = $tipe === 'pendapatan' ? 'kredit' : 'debit';

    $induk = Coa::create([
        'perusahaan_id' => test()->perusahaanId,
        'kode' => $kode, 'nama' => $nama, 'tipe' => $tipe,
        'saldo_normal' => $saldoNormal, 'is_header' => true, 'is_aktif' => true,
    ]);

    return Coa::create([
        'perusahaan_id' => test()->perusahaanId,
        'kode' => $kode.'1', 'nama' => $nama.' Rinci', 'tipe' => $tipe,
        'saldo_normal' => $saldoNormal, 'is_header' => false, 'is_aktif' => true,
        'parent_id' => $induk->id,
    ]);
}

/** Satu jurnal berpasangan: satu akun didebet, satu dikredit, nominal sama. */
function jurnalUji(string $tanggal, Coa $debet, Coa $kredit, float $nominal): void
{
    $jurnal = Jurnal::create([
        'perusahaan_id' => test()->perusahaanId,
        'tanggal' => $tanggal,
        'no_bukti' => 'UJI/'.str_replace('-', '', $tanggal).'/'.random_int(100000, 999999),
        'tipe' => 'umum',
        'kategori_bukti' => 'KAS',
        'status' => 'posted',
        'created_by_user_id' => test()->finance->id,
    ]);

    JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $debet->id, 'debet' => $nominal, 'kredit' => 0]);
    JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kredit->id, 'debet' => 0, 'kredit' => $nominal]);
}

/** Satu set lengkap: penjualan, harga pokok, biaya usaha, pendapatan lain, pajak final. */
function isiSatuTahun(): void
{
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $lain = akunUji('7900', 'Pendapatan Lain Uji', 'pendapatan');
    $hpp = akunUji('5900', 'HPP Uji', 'beban');
    $biaya = akunUji('6900', 'Biaya Uji', 'beban');
    $pajak = akunUji('8900', 'Pajak Final Uji', 'beban');

    // Nominal tiap lapisan sengaja dibuat berbeda — kalau dua lapisan tertukar,
    // angkanya ikut tertukar dan ketahuan, bukan lolos karena kebetulan sama.
    jurnalUji('2027-03-01', $hpp, $jual, 1_000_000_000);
    jurnalUji('2027-03-02', $hpp, $lain, 200_000_000);
    jurnalUji('2027-03-03', $biaya, $lain, 150_000_000);
    jurnalUji('2027-03-04', $pajak, $lain, 50_000_000);
}

it('menjaga laba bersih tetap sama dengan total lama', function () {
    // Penjaga utama: uraian berlapis tidak boleh mengubah angkanya, cuma
    // menyusunnya ulang. Akun yang jatuh ke celah akan muncul sebagai selisih.
    isiSatuTahun();

    $r = $this->svc->labaRugi($this->perusahaanId, '2027-01-01', '2027-12-31');

    expect(round($r['uraian']['net_profit'], 2))->toBe(round($r['laba_rugi'], 2));
});

it('memisahkan lapisan sesuai awalan kode akunnya', function () {
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $lain = akunUji('7900', 'Pendapatan Lain Uji', 'pendapatan');
    $hpp = akunUji('5900', 'HPP Uji', 'beban');
    $biaya = akunUji('6900', 'Biaya Uji', 'beban');
    $pajak = akunUji('8900', 'Pajak Final Uji', 'beban');

    jurnalUji('2027-03-01', $hpp, $jual, 600_000_000);
    jurnalUji('2027-03-02', $biaya, $lain, 90_000_000);
    jurnalUji('2027-03-03', $pajak, $lain, 10_000_000);

    $u = $this->svc->labaRugi($this->perusahaanId, '2027-01-01', '2027-12-31')['uraian'];

    expect($u['penjualan']['total'])->toBe(600_000_000.0)
        ->and($u['hpp']['total'])->toBe(600_000_000.0)
        ->and($u['biaya']['total'])->toBe(90_000_000.0)
        ->and($u['pendapatan_lain']['total'])->toBe(100_000_000.0)
        ->and($u['pajak_final']['total'])->toBe(10_000_000.0);
});

it('menghitung laba kotor dari penjualan dikurangi harga pokok saja', function () {
    // Biaya usaha dan pendapatan lain TIDAK boleh ikut ke laba kotor — kalau ikut,
    // marjin kotornya tidak lagi menggambarkan untung per rumah terjual.
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $lain = akunUji('7900', 'Pendapatan Lain Uji', 'pendapatan');
    $hpp = akunUji('5900', 'HPP Uji', 'beban');
    $biaya = akunUji('6900', 'Biaya Uji', 'beban');

    jurnalUji('2027-03-01', $hpp, $jual, 1_000_000_000);
    jurnalUji('2027-03-02', $biaya, $lain, 80_000_000);

    $u = $this->svc->labaRugi($this->perusahaanId, '2027-01-01', '2027-12-31')['uraian'];

    // Penjualan 1 M, HPP 1 M → laba kotor nol, walau ada pendapatan lain 80 jt.
    expect($u['gross_profit'])->toBe(0.0)
        ->and($u['pendapatan_lain']['total'])->toBe(80_000_000.0);
});

it('memakai penjualan sebagai penyebut persen, bukan seluruh pendapatan', function () {
    // Pendapatan di luar usaha insidental. Kalau ikut jadi penyebut, marjinnya
    // naik-turun mengikuti hal yang tidak ada kaitannya dengan penjualan.
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $lain = akunUji('7900', 'Pendapatan Lain Uji', 'pendapatan');
    $hpp = akunUji('5900', 'HPP Uji', 'beban');

    jurnalUji('2027-03-01', $hpp, $jual, 1_000_000_000);
    jurnalUji('2027-03-02', $hpp, $lain, 500_000_000);

    $u = $this->svc->labaRugi($this->perusahaanId, '2027-01-01', '2027-12-31')['uraian'];

    expect($u['dasar_persen'])->toBe(1_000_000_000.0)
        ->and(LaporanAkuntingService::persen($u['penjualan']['total'], $u['dasar_persen']))->toBe(100.0);
});

it('tidak membagi dengan nol saat belum ada penjualan', function () {
    // Periode yang belum ada penjualannya tetap harus bisa dibuka laporannya.
    expect(LaporanAkuntingService::persen(5_000_000, 0))->toBe(0.0)
        ->and(LaporanAkuntingService::persen(0, 0))->toBe(0.0);

    $r = $this->svc->labaRugi($this->perusahaanId, '2030-01-01', '2030-12-31');

    expect($r['uraian']['dasar_persen'])->toBe(0.0)
        ->and($r['uraian']['net_profit'])->toBe(0.0);
});

it('tidak membuang akun yang awalan kodenya tak dikenal', function () {
    // Lebih baik salah tempat tapi utuh daripada hilang diam-diam. Beban berawalan
    // 9 jatuh ke biaya usaha, dan jumlah totalnya tetap bulat.
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $aneh = akunUji('9900', 'Beban Tak Berkelompok', 'beban');

    jurnalUji('2027-03-01', $aneh, $jual, 300_000_000);

    $r = $this->svc->labaRugi($this->perusahaanId, '2027-01-01', '2027-12-31');
    $u = $r['uraian'];

    expect($u['biaya']['total'])->toBe(300_000_000.0)
        ->and(round($u['net_profit'], 2))->toBe(round($r['laba_rugi'], 2))
        ->and($u['net_profit'])->toBe(0.0);
});

it('menampilkan lapisan beserta persennya di layar', function () {
    isiSatuTahun();

    $html = Livewire::test('pages::akunting.laba-rugi')
        ->set('from', '2027-01-01')->set('to', '2027-12-31')->set('versi', 'resume')
        ->html();

    $teks = preg_replace('/\s+/', ' ', strip_tags($html));

    expect($teks)->toContain('Laba Kotor')
        ->and($teks)->toContain('Gross Profit')
        ->and($teks)->toContain('Net Profit')
        ->and($teks)->toContain('100,0%');
});

it('menyusun ekspor Excel dengan kolom persen berupa angka', function () {
    // Persen disimpan sebagai pecahan dan diformat persen oleh Excel — bukan teks
    // berakhiran "%" — supaya masih bisa dipakai di rumus oleh Accounting.
    isiSatuTahun();

    $rows = (new App\Exports\Akunting\LabaRugiExport('2027-01-01', '2027-12-31'))->array();

    $baris = collect($rows)->firstWhere(0, 'LABA KOTOR (GROSS PROFIT)');

    expect($baris)->not->toBeNull()
        ->and($baris[3])->toBeNumeric()
        ->and(collect($rows)->pluck(0)->filter(fn ($x) => str_starts_with((string) $x, 'TOTAL '))->count())
        ->toBeGreaterThan(2);
});

// ─────────────── Versi tahunan ───────────────

it('menguraikan lapisan per bulan di laporan tahunan', function () {
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $hpp = akunUji('5900', 'HPP Uji', 'beban');
    $biaya = akunUji('6900', 'Biaya Uji', 'beban');

    jurnalUji('2027-05-10', $hpp, $jual, 1_000_000_000);
    jurnalUji('2027-08-10', $hpp, $jual, 400_000_000);
    jurnalUji('2027-05-20', $biaya, $jual, 100_000_000);

    $u = $this->svc->labaRugiTahunan($this->perusahaanId, 2027)['uraian'];

    expect($u['penjualan']['per_bulan'][5])->toBe(1_100_000_000.0)
        ->and($u['penjualan']['per_bulan'][8])->toBe(400_000_000.0)
        ->and($u['penjualan']['per_bulan'][1])->toBe(0.0)
        ->and($u['hpp']['per_bulan'][5])->toBe(1_000_000_000.0)
        ->and($u['biaya']['per_bulan'][5])->toBe(100_000_000.0);
});

it('menjaga laba bersih tahunan tetap sama dengan total lama', function () {
    isiSatuTahun();

    $r = $this->svc->labaRugiTahunan($this->perusahaanId, 2027);

    expect(round($r['uraian']['net_profit']['total'], 2))->toBe(round($r['laba_rugi']['total'], 2));

    // Dan cocok bulan per bulan, bukan cuma pada jumlah setahunnya.
    foreach (range(1, 12) as $m) {
        expect(round($r['uraian']['net_profit']['per_bulan'][$m], 2))
            ->toBe(round($r['laba_rugi']['per_bulan'][$m], 2), "bulan ke-$m tidak cocok");
    }
});

it('menghitung marjin tahunan dari total, bukan merata-ratakan marjin bulanan', function () {
    // Bulan penjualan 100 jt tidak boleh sama bobotnya dengan bulan 1 M.
    //
    // Lawan jurnalnya akun kas, bukan HPP langsung: kalau penjualan dan HPP
    // selalu dipasangkan dengan nominal yang sama, marjinnya selalu nol dan
    // tesnya tidak membuktikan apa pun.
    $kas = akunUji('1900', 'Kas Uji', 'aset');
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $hpp = akunUji('5900', 'HPP Uji', 'beban');

    jurnalUji('2027-05-10', $kas, $jual, 100_000_000);     // Mei : jual 100 jt
    jurnalUji('2027-05-11', $hpp, $kas, 100_000_000);      //       hpp  100 jt → marjin 0%
    jurnalUji('2027-06-10', $kas, $jual, 1_000_000_000);   // Juni: jual 1 M
    jurnalUji('2027-06-11', $hpp, $kas, 500_000_000);      //       hpp  500 jt → marjin 50%

    $u = $this->svc->labaRugiTahunan($this->perusahaanId, 2027)['uraian'];

    expect(round($u['marjin_kotor']['per_bulan'][5], 1))->toBe(0.0)
        ->and(round($u['marjin_kotor']['per_bulan'][6], 1))->toBe(50.0);

    // Tertimbang: 500 jt dari 1,1 M = 45,5%. Rata-rata dua bulan: 25%.
    $tertimbang = round($u['marjin_kotor']['total'], 1);
    $rataRata = round(($u['marjin_kotor']['per_bulan'][5] + $u['marjin_kotor']['per_bulan'][6]) / 2, 1);

    expect($tertimbang)->toBe(45.5)
        ->and($rataRata)->toBe(25.0)
        ->and($tertimbang)->not->toBe($rataRata);
});

it('membedakan bulan tanpa penjualan dari bulan yang marjinnya nol', function () {
    // Dua keadaan berbeda: Januari tidak ada penjualan sama sekali, Mei ada
    // penjualan dengan marjin sungguhan. Layarnya menulis strip untuk yang
    // pertama — kalau ditulis 0%, bulan kosong terbaca seperti bulan impas.
    $kas = akunUji('1900', 'Kas Uji', 'aset');
    $jual = akunUji('4900', 'Penjualan Uji', 'pendapatan');
    $hpp = akunUji('5900', 'HPP Uji', 'beban');

    jurnalUji('2027-05-10', $kas, $jual, 500_000_000);
    jurnalUji('2027-05-11', $hpp, $kas, 300_000_000);

    $u = $this->svc->labaRugiTahunan($this->perusahaanId, 2027)['uraian'];

    expect($u['penjualan']['per_bulan'][1])->toBe(0.0)
        ->and($u['marjin_kotor']['per_bulan'][1])->toBe(0.0)
        ->and(round($u['marjin_kotor']['per_bulan'][5], 1))->toBe(40.0);

    $teks = preg_replace('/\s+/', ' ', strip_tags(
        Livewire::test('pages::akunting.laba-rugi-tahunan')
            ->set('tahun', 2027)->set('versi', 'resume')->html()
    ));

    expect($teks)->toContain('Marjin Kotor')
        ->and($teks)->toContain('40,0%');
});

it('tidak gugur saat tahun yang diminta belum ada jurnalnya', function () {
    $r = $this->svc->labaRugiTahunan($this->perusahaanId, 2035);

    expect($r['uraian']['penjualan']['total'])->toBe(0.0)
        ->and($r['uraian']['marjin_kotor']['total'])->toBe(0.0)
        ->and($r['uraian']['net_profit']['per_bulan'][7])->toBe(0.0);

    Livewire::test('pages::akunting.laba-rugi-tahunan')->set('tahun', 2035)->assertOk();
});
