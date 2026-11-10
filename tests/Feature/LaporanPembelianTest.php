<?php

use App\Models\Master\PermintaanPembelian;
use App\Models\Master\PesananPembelian;
use App\Models\Master\Proyek;
use App\Models\Master\Supplier;
use App\Models\User;
use App\Services\LaporanPembelianService;
use App\Services\PembelianService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

/**
 * Laporan Pembelian: rekap realisasi PO, status pembayaran, lead time per barang
 * PR, dan grafik tahunan — semuanya dari data alur PR → PO → bayar → barang datang.
 */
beforeEach(function () {
    $this->seed();

    $this->pembelian = app(PembelianService::class);
    $this->laporan = app(LaporanPembelianService::class);
    $this->proyek = Proyek::first();
    $this->peminta = User::factory()->create(['name' => 'Admin Teknik']);
    $this->peminta->assignRole('admin-teknik');
    $this->fat = User::factory()->create();
    $this->fat->assignRole('finance');
    $this->tbSumber = Supplier::create(['nama' => 'TB Sumber Bangunan']);
    $this->cvBaja = Supplier::create(['nama' => 'CV Baja Utama']);
});

/** PR berisi semen 10 sak @65.000 dan besi 20 batang @85.000, diajukan & disetujui FAT pada tanggalnya. */
function laporanPrDisetujui(string $tanggal, array $atribut = [], bool $setujui = true): PermintaanPembelian
{
    test()->travelTo(CarbonImmutable::parse($tanggal.' 09:00'));
    $pr = test()->pembelian->buatPermintaan($atribut + [
        'tanggal' => $tanggal, 'proyek_id' => test()->proyek->id, 'departemen' => 'Teknik',
        'keperluan' => 'Material pondasi', 'tanggal_dibutuhkan' => null,
    ], [
        ['nama_barang' => 'Semen', 'spesifikasi' => '50 kg', 'jumlah' => 10, 'satuan' => 'sak', 'harga_perkiraan' => 65000],
        ['nama_barang' => 'Besi beton', 'spesifikasi' => '10 mm', 'jumlah' => 20, 'satuan' => 'batang', 'harga_perkiraan' => 85000],
    ], test()->peminta->id);
    test()->pembelian->ajukan($pr, test()->peminta->id);
    if ($setujui) {
        test()->pembelian->tinjau($pr->fresh(), 'setujui', null, test()->fat->id);
    }

    return $pr->fresh('item');
}

/** PO untuk barang pertama PR saja (atau semua barang), dengan harga tertentu. */
function laporanPo(PermintaanPembelian $pr, Supplier $supplier, string $tanggal, int $harga = 60000, bool $semuaBarang = false): PesananPembelian
{
    test()->travelTo(CarbonImmutable::parse($tanggal.' 10:00'));
    $item = $semuaBarang ? $pr->item : $pr->item->take(1);

    return test()->pembelian->terbitkanPesanan($pr, [
        'supplier_id' => $supplier->id, 'tanggal' => $tanggal, 'tanggal_kirim' => null, 'syarat_bayar' => 'Tunai',
        'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], $item->map(fn ($i) => [
        'permintaan_pembelian_item_id' => $i->id, 'nama_barang' => $i->nama_barang, 'spesifikasi' => $i->spesifikasi,
        'jumlah' => $i->jumlah, 'satuan' => $i->satuan, 'harga' => $harga,
    ])->all(), test()->fat->id);
}

function laporanBayar(PesananPembelian $po, string $tanggal, float $jumlah): void
{
    test()->pembelian->catatPembayaran($po, ['tanggal' => $tanggal, 'jumlah' => $jumlah], test()->fat->id);
}

function laporanTerima(PesananPembelian $po, string $tanggal): void
{
    test()->pembelian->catatPenerimaan($po, ['tanggal' => $tanggal], $po->item()->pluck('jumlah', 'id')->all(), test()->fat->id);
}

/** @return array{proyek: string, departemen: string, supplier: string, dari: string, sampai: string} */
function saringNovember(array $ubah = []): array
{
    return $ubah + ['proyek' => '', 'departemen' => '', 'supplier' => '', 'dari' => '2026-11-01', 'sampai' => '2026-11-10'];
}

it('merekap PO pada rentang tanggal beserta pembayaran, barang, dan lama prosesnya', function () {
    $po = laporanPo(laporanPrDisetujui('2026-11-02'), $this->tbSumber, '2026-11-05');
    laporanBayar($po, '2026-11-06', 200000);
    laporanPo(laporanPrDisetujui('2026-10-01'), $this->tbSumber, '2026-10-20');
    $batal = laporanPo(laporanPrDisetujui('2026-11-03'), $this->cvBaja, '2026-11-10');
    $this->pembelian->batalkanPesanan($batal, 'Salah supplier', $this->fat->id);

    $rekap = $this->laporan->realisasi(saringNovember());

    expect($rekap)->toHaveCount(1)
        ->and($rekap->first())->toMatchArray([
            'nomor' => $po->nomor, 'supplier' => 'TB Sumber Bangunan', 'departemen' => 'Teknik',
            'total' => 600000.0, 'dibayar' => 200000.0, 'sisa' => 400000.0,
            'status_bayar' => 'sebagian', 'status_barang' => 'belum', 'lama_proses' => 3,
        ]);
});

it('menyaring menurut proyek, kantor pusat, bagian, dan supplier', function () {
    laporanPo(laporanPrDisetujui('2026-11-02'), $this->tbSumber, '2026-11-03');
    laporanPo(laporanPrDisetujui('2026-11-02', ['proyek_id' => null, 'departemen' => 'Umum']), $this->cvBaja, '2026-11-04');

    $nomor = fn (array $s) => $this->laporan->realisasi(saringNovember($s))->pluck('supplier')->all();

    expect($nomor([]))->toBe(['TB Sumber Bangunan', 'CV Baja Utama'])
        ->and($nomor(['proyek' => (string) $this->proyek->id]))->toBe(['TB Sumber Bangunan'])
        ->and($nomor(['proyek' => 'pusat']))->toBe(['CV Baja Utama'])
        ->and($nomor(['departemen' => 'Umum']))->toBe(['CV Baja Utama'])
        ->and($nomor(['supplier' => (string) $this->tbSumber->id]))->toBe(['TB Sumber Bangunan'])
        ->and($this->laporan->realisasi(saringNovember())->first()['proyek'])->toBe($this->proyek->nama_proyek);
});

it('mengelompokkan PO menurut status pembayaran tanpa melihat tanggal', function () {
    $lama = laporanPo(laporanPrDisetujui('2026-08-01'), $this->tbSumber, '2026-08-03');
    $sebagian = laporanPo(laporanPrDisetujui('2026-11-02'), $this->tbSumber, '2026-11-03');
    laporanBayar($sebagian, '2026-11-04', 100000);
    $lunas = laporanPo(laporanPrDisetujui('2026-11-02'), $this->cvBaja, '2026-11-03');
    laporanBayar($lunas, '2026-11-05', 600000);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00'));

    $bayar = $this->laporan->pembayaran(saringNovember());

    expect($bayar['belum']->pluck('nomor')->all())->toBe([$lama->nomor])
        ->and($bayar['belum']->first()['umur'])->toBe(99)
        ->and($bayar['sebagian']->pluck('nomor')->all())->toBe([$sebagian->nomor])
        ->and($bayar['lunas']->pluck('nomor')->all())->toBe([$lunas->nomor])
        ->and($bayar['lunas']->first()['bayar_terakhir']->toDateString())->toBe('2026-11-05');
});

it('menghitung lead time tiap barang dari PR dibuat sampai barangnya lengkap', function () {
    $pr = laporanPrDisetujui('2026-11-02', ['tanggal_dibutuhkan' => '2026-11-08']);
    $po = laporanPo($pr, $this->tbSumber, '2026-11-03', 70000);
    laporanBayar($po, '2026-11-04', 700000);
    laporanTerima($po, '2026-11-06');
    laporanPrDisetujui('2026-11-05', [], setujui: false);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00'));

    $baris = $this->laporan->leadTime(saringNovember())->keyBy(fn ($b) => $b['nomor_pr'].' '.$b['barang']);
    $semen = $baris[$pr->nomor.' Semen'];
    $besi = $baris[$pr->nomor.' Besi beton'];

    expect($baris)->toHaveCount(4)
        ->and($semen)->toMatchArray([
            'pemohon' => 'Admin Teknik', 'harga_po' => 70000.0, 'selisih' => 5000.0, 'nomor_po' => $po->nomor,
            'supplier' => 'TB Sumber Bangunan', 'tahap' => 'lengkap', 'lead_time' => 4, 'berjalan' => false, 'sla' => 'tepat',
        ])
        ->and($semen['tanggal_disetujui']->toDateString())->toBe('2026-11-02')
        ->and($semen['tanggal_po']->toDateString())->toBe('2026-11-03')
        ->and($semen['tanggal_lunas']->toDateString())->toBe('2026-11-04')
        ->and($semen['tanggal_lengkap']->toDateString())->toBe('2026-11-06')
        // Besi belum dipesan: masih berjalan sampai hari ini dan sudah lewat tanggal dibutuhkan.
        ->and($besi)->toMatchArray(['tahap' => 'belum-po', 'lead_time' => 8, 'berjalan' => true, 'sla' => 'terlambat', 'harga_po' => null])
        ->and($baris->where('tahap', 'persetujuan'))->toHaveCount(2);
});

it('menyaring lead time menurut tahap, pencarian, dan lead time minimal', function () {
    $pr = laporanPrDisetujui('2026-11-02');
    laporanPo($pr, $this->tbSumber, '2026-11-03');
    laporanPrDisetujui('2026-11-08', [], setujui: false);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00'));

    $barang = fn (array $opsi) => $this->laporan->leadTime(saringNovember(), $opsi)->map(fn ($b) => $b['barang'].' '.$b['tahap'])->all();

    expect($barang(['tahap' => 'menunggu']))->toBe(['Semen menunggu'])
        ->and($barang(['cari' => 'besi']))->toBe(['Besi beton belum-po', 'Besi beton persetujuan'])
        ->and($barang(['cari' => $pr->nomor, 'tahap' => 'belum-po']))->toBe(['Besi beton belum-po'])
        ->and($barang(['min' => 5]))->toBe(['Semen menunggu', 'Besi beton belum-po']);
});

it('menjumlahkan nilai PO per bulan untuk grafik tahunan', function () {
    laporanPo(laporanPrDisetujui('2026-10-01'), $this->tbSumber, '2026-10-02');
    laporanPo(laporanPrDisetujui('2026-11-02'), $this->tbSumber, '2026-11-03', semuaBarang: true);
    laporanPo(laporanPrDisetujui('2026-11-02'), $this->cvBaja, '2026-11-04');

    $grafik = $this->laporan->grafikTahunan(2026, saringNovember());

    expect($grafik)->toHaveCount(12)
        ->and($grafik[10])->toBe(['jumlah' => 1, 'nilai' => 600000.0])
        ->and($grafik[11])->toBe(['jumlah' => 2, 'nilai' => 2400000.0])
        ->and($grafik[1])->toBe(['jumlah' => 0, 'nilai' => 0.0]);
});

it('hanya bisa dibuka peran yang mengurus atau memantau pembelian', function () {
    $peminta = User::factory()->create();
    $peminta->givePermissionTo('pembelian.minta');

    $this->actingAs($peminta)->get(route('pembelian.laporan'))->assertForbidden();
    $this->actingAs($this->fat)->get(route('pembelian.laporan'))->assertOk()
        ->assertSee('Rekap Realisasi')->assertSee('Status Pembayaran')->assertSee('Lead Time Pengadaan');
});

it('menampilkan tiap tab, grafik, dan mengunduh Excel sesuai saringan', function () {
    $po = laporanPo(laporanPrDisetujui('2026-11-02'), $this->tbSumber, '2026-11-05');
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00:00'));
    Excel::fake();

    $halaman = Livewire::actingAs($this->fat)->test('pages::pembelian.laporan')
        ->assertSet('dari', '2026-11-01')
        ->assertSet('sampai', '2026-11-10')
        ->assertSee($po->nomor)
        ->assertSee('3 hari')
        ->set('tab', 'pembayaran')
        ->assertSee('Belum dibayar')
        ->assertSee($po->nomor)
        ->set('tab', 'lead-time')
        ->assertSee('Besi beton')
        ->assertSee('Belum dibuat PO')
        ->call('bukaGrafik')
        ->assertSeeHtml('data-grafik="pembelian-tahunan"');

    $halaman->call('unduhExcel');
    Excel::assertDownloaded('laporan-pembelian_lead-time_2026-11-10_080000.xlsx', function ($export) {
        return $export->title() === 'Lead Time Procurement'
            && count($export->array()) === 2
            && $export->array()[0][4] === 'Semen';
    });
});

it('memisahkan PO yang kelebihan bayarnya belum dikembalikan supplier', function () {
    $po = laporanPo(laporanPrDisetujui('2026-11-02'), $this->tbSumber, '2026-11-03'); // semen 10 × 60.000
    laporanBayar($po, '2026-11-04', 600000);
    $this->pembelian->catatPenerimaan($po, ['tanggal' => '2026-11-05'], [$po->item()->value('id') => 4], $this->fat->id);
    $this->pembelian->tutupPesanan($po->fresh(), 'Stok semen habis', $this->fat->id); // nilai jadi 240.000
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00'));

    $bayar = $this->laporan->pembayaran(saringNovember());
    expect($bayar['kelebihan']->pluck('nomor')->all())->toBe([$po->nomor])
        ->and($bayar['lunas'])->toBeEmpty()
        ->and($bayar['kelebihan']->first())->toMatchArray(['total' => 240000.0, 'dibayar' => 600000.0, 'kelebihan_bayar' => 360000.0, 'sisa' => 0.0])
        ->and($this->laporan->realisasi(saringNovember())->first())->toMatchArray(['status_bayar' => 'kelebihan', 'status_barang' => 'ditutup']);

    $this->pembelian->catatPengembalian($po->fresh(), ['tanggal' => '2026-11-07', 'jumlah' => 360000], $this->fat->id);

    $bayar = $this->laporan->pembayaran(saringNovember());
    expect($bayar['kelebihan'])->toBeEmpty()
        ->and($bayar['lunas']->first())->toMatchArray(['nomor' => $po->nomor, 'dibayar' => 240000.0, 'dikembalikan' => 360000.0]);

    Livewire::actingAs($this->fat)->test('pages::pembelian.laporan', ['tab' => 'pembayaran'])
        ->set('statusBayar', 'kelebihan')
        ->assertSee('Kelebihan bayar')
        ->assertSee('Tidak ada PO dengan status ini.');
});
