<?php

use App\Models\Master\PermintaanPembelian;
use App\Models\Master\Perusahaan;
use App\Models\Master\PesananPembelian;
use App\Models\Master\Proyek;
use App\Models\Master\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Alur Modul Pembelian sesuai konfirmasi FAT (30 Okt 2026) dan matrix approval (3 Nov 2026):
 * PR → review FAT → [direktur bila di atas Rp 50 jt] → PO → bayar dulu → barang datang.
 */
beforeEach(function () {
    $this->seed();

    $this->layanan = app(PembelianService::class);
    $this->proyek = Proyek::first();
    $this->peminta = User::factory()->create();
    $this->peminta->assignRole('admin-teknik');
    $this->supplier = Supplier::create(['nama' => 'TB Sumber Bangunan', 'is_pkp' => true]);
});

/** PR draft berisi dua barang: semen 10 sak @65.000 dan besi 20 batang @85.000. */
function prBaru(string $tanggal = '2026-10-30'): PermintaanPembelian
{
    return test()->layanan->buatPermintaan([
        'tanggal' => $tanggal,
        'proyek_id' => test()->proyek->id,
        'departemen' => 'Teknik',
        'keperluan' => 'Material pondasi Blok AD',
        'tanggal_dibutuhkan' => null,
    ], [
        ['nama_barang' => 'Semen', 'spesifikasi' => '50 kg', 'jumlah' => 10, 'satuan' => 'sak', 'harga_perkiraan' => 65000],
        ['nama_barang' => 'Besi beton', 'spesifikasi' => '10 mm', 'jumlah' => 20, 'satuan' => 'batang', 'harga_perkiraan' => 85000],
    ], test()->peminta->id);
}

/** PR draft di atas batas: beton ready mix 60 m3 @950.000 = Rp 57 jt. */
function prBesar(): PermintaanPembelian
{
    return test()->layanan->buatPermintaan([
        'tanggal' => '2026-10-30',
        'proyek_id' => test()->proyek->id,
        'departemen' => 'Teknik',
        'keperluan' => 'Jalan lingkungan Blok AD',
        'tanggal_dibutuhkan' => null,
    ], [
        ['nama_barang' => 'Beton ready mix', 'spesifikasi' => 'K-225', 'jumlah' => 60, 'satuan' => 'm3', 'harga_perkiraan' => 950000],
    ], test()->peminta->id);
}

/** PR kecil yang sudah disetujui — cukup FAT karena di bawah batas. */
function prDisetujui(): PermintaanPembelian
{
    $pr = prBaru();
    test()->layanan->ajukan($pr, test()->peminta->id);

    return test()->layanan->tinjau($pr, 'setujui', null, null);
}

/** PO untuk seluruh barang PR, harga final 60.000 & 80.000, PPN 11% terpisah. */
function poDari(PermintaanPembelian $pr, int $hargaSemen = 60000): PesananPembelian
{
    $pr->load('item');

    return test()->layanan->terbitkanPesanan($pr, [
        'supplier_id' => test()->supplier->id,
        'tanggal' => '2026-10-31',
        'tanggal_kirim' => null,
        'syarat_bayar' => 'Bayar di muka',
        'ppn_terpisah' => true,
        'ppn_persen' => 11,
        'catatan' => null,
    ], [
        ['permintaan_pembelian_item_id' => $pr->item[0]->id, 'nama_barang' => 'Semen', 'spesifikasi' => '50 kg', 'jumlah' => 10, 'satuan' => 'sak', 'harga' => $hargaSemen],
        ['permintaan_pembelian_item_id' => $pr->item[1]->id, 'nama_barang' => 'Besi beton', 'spesifikasi' => '10 mm', 'jumlah' => 20, 'satuan' => 'batang', 'harga' => 80000],
    ], null);
}

it('menomori PR per bulan dan mengulang dari 1 di bulan berikutnya', function () {
    expect(prBaru('2026-10-05')->nomor)->toBe('1/LMI/PR/10/2026')
        ->and(prBaru('2026-10-20')->nomor)->toBe('2/LMI/PR/10/2026')
        ->and(prBaru('2026-11-02')->nomor)->toBe('1/LMI/PR/11/2026');
});

it('cukup disetujui FAT untuk PR sampai batas', function () {
    $fat = User::factory()->create();

    $pr = prBaru();
    $this->layanan->ajukan($pr, $this->peminta->id);
    $this->layanan->tinjau($pr, 'setujui', null, $fat->id);

    $pr->refresh();
    expect($pr->totalPerkiraan())->toBe(2350000.0)
        ->and($pr->status)->toBe('disetujui')
        ->and($pr->diajukan_by_user_id)->toBe($this->peminta->id)
        ->and($pr->ditinjau_by_user_id)->toBe($fat->id)
        ->and($pr->disetujuiDirektur())->toBeFalse();
});

it('meneruskan PR di atas batas ke direktur dengan jejak siapa dan kapan', function () {
    $fat = User::factory()->create();
    $direktur = User::factory()->create();

    $pr = prBesar();
    $this->layanan->ajukan($pr, $this->peminta->id);
    $this->layanan->tinjau($pr, 'setujui', null, $fat->id);
    expect($pr->refresh()->status)->toBe('ditinjau');

    $this->layanan->putuskanAnggaran($pr, 'setujui', null, $direktur->id);

    $pr->refresh();
    expect($pr->status)->toBe('disetujui')
        ->and($pr->ditinjau_by_user_id)->toBe($fat->id)
        ->and($pr->disetujui_by_user_id)->toBe($direktur->id)
        ->and($pr->disetujuiDirektur())->toBeTrue();
});

it('memakai batas dari master perusahaan', function () {
    Perusahaan::query()->update(['batas_persetujuan_direktur' => 2000000]);

    $pr = prBaru();
    $this->layanan->ajukan($pr, $this->peminta->id);
    $this->layanan->tinjau($pr, 'setujui', null, null);

    expect($pr->refresh()->status)->toBe('ditinjau');
});

it('menahan PO yang membuat total PR cukup-FAT melewati batas sampai direktur menyetujui', function () {
    Perusahaan::query()->update(['batas_persetujuan_direktur' => 3000000]);
    $pr = prDisetujui();

    // Harga final semen naik: 10 × 120.000 + 20 × 80.000 = 2,8 jt + PPN 11% = 3.108.000 > 3 jt.
    expect(fn () => poDari($pr, 120000))->toThrow(ValidationException::class, 'Teruskan PR ke direktur');
    expect($pr->pesanan()->count())->toBe(0);

    $this->layanan->teruskanKeDirektur($pr);
    expect($pr->refresh()->status)->toBe('ditinjau');

    $this->layanan->putuskanAnggaran($pr, 'setujui', null, null);
    expect((float) poDari($pr->refresh(), 120000)->total)->toBe(3108000.0);

    expect(fn () => $this->layanan->teruskanKeDirektur($pr->refresh()))->toThrow(ValidationException::class, 'sudah disetujui direktur');
});

it('mengembalikan PR ke draft dengan alasan, lalu bisa diajukan lagi', function () {
    $pr = prBaru();
    $this->layanan->ajukan($pr, $this->peminta->id);

    expect(fn () => $this->layanan->tinjau($pr, 'kembalikan', ' ', null))->toThrow(ValidationException::class);

    $this->layanan->tinjau($pr, 'kembalikan', 'Harga semen terlalu tinggi', null);
    expect($pr->refresh()->status)->toBe('draft')->and($pr->alasan)->toBe('Harga semen terlalu tinggi');

    $this->layanan->ajukan($pr, $this->peminta->id);
    expect($pr->refresh()->status)->toBe('diajukan')->and($pr->alasan)->toBeNull();
});

it('tidak melompati persetujuan direktur', function () {
    $pr = prBesar();
    $this->layanan->ajukan($pr, $this->peminta->id);

    expect(fn () => $this->layanan->putuskanAnggaran($pr, 'setujui', null, null))->toThrow(ValidationException::class);

    $this->layanan->tinjau($pr, 'setujui', null, null);
    expect(fn () => $this->layanan->terbitkanPesanan($pr->refresh()->load('item'), [
        'supplier_id' => $this->supplier->id, 'tanggal' => '2026-10-31', 'tanggal_kirim' => null,
        'syarat_bayar' => null, 'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], [[
        'permintaan_pembelian_item_id' => $pr->item[0]->id, 'nama_barang' => 'Beton ready mix', 'spesifikasi' => 'K-225',
        'jumlah' => 60, 'satuan' => 'm3', 'harga' => 950000,
    ]], null))->toThrow(ValidationException::class, 'sudah disetujui');
});

it('menghitung PO dengan PPN terpisah', function () {
    $po = poDari(prDisetujui());

    expect($po->nomor)->toBe('1/LMI/PO/10/2026')
        ->and((float) $po->subtotal)->toBe(2200000.0)
        ->and((float) $po->ppn)->toBe(242000.0)
        ->and((float) $po->total)->toBe(2442000.0);
});

it('mewajibkan bayar dulu sebelum barang datang', function () {
    $po = poDari(prDisetujui())->load('item');

    expect(fn () => $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-01'], [$po->item[0]->id => 5], null))
        ->toThrow(ValidationException::class, 'Supplier dibayar dulu');
});

it('menerima pembayaran bertahap tanpa melebihi nilai PO', function () {
    $po = poDari(prDisetujui());

    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-01', 'jumlah' => 1000000], null);
    expect($po->refresh()->load('pembayaran')->statusBayar())->toBe('sebagian');

    expect(fn () => $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-02', 'jumlah' => 1500000], null))
        ->toThrow(ValidationException::class, 'Melebihi sisa');

    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-02', 'jumlah' => 1442000], null);
    expect($po->refresh()->load('pembayaran')->statusBayar())->toBe('lunas');
});

it('mencatat barang datang bertahap dan menyelesaikan PO saat lengkap', function () {
    $po = poDari(prDisetujui())->load('item');
    [$semen, $besi] = [$po->item[0], $po->item[1]];
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-01', 'jumlah' => 2442000], null);

    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-03', 'nomor_surat_jalan' => 'SJ-01'], [$semen->id => 4, $besi->id => 0], null);
    expect($po->refresh()->load('item')->statusBarang())->toBe('sebagian')->and($po->status)->toBe('terbit');

    expect(fn () => $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-04'], [$semen->id => 7], null))
        ->toThrow(ValidationException::class);

    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-05'], [$semen->id => 6, $besi->id => 20], null);
    expect($po->refresh()->load('item')->statusBarang())->toBe('lengkap')
        ->and($po->status)->toBe('selesai')
        ->and($po->penerimaan()->count())->toBe(2);
});

it('hanya membatalkan PO yang belum dibayar', function () {
    $poA = poDari(prDisetujui());
    $poB = poDari(prDisetujui());

    $this->layanan->catatPembayaran($poB, ['tanggal' => '2026-11-01', 'jumlah' => 500000], null);

    expect(fn () => $this->layanan->batalkanPesanan($poB, 'Supplier tidak sanggup', null))->toThrow(ValidationException::class);

    $this->layanan->batalkanPesanan($poA, 'Ganti supplier', null);
    expect($poA->refresh()->status)->toBe('batal')->and($poA->alasan_batal)->toBe('Ganti supplier');
});

it('tidak memesan barang melebihi yang diminta di PR', function () {
    $pr = prDisetujui()->load('item');
    [$semen, $besi] = [$pr->item[0], $pr->item[1]];
    $terbitkan = fn (array $baris) => $this->layanan->terbitkanPesanan($pr->fresh(), [
        'supplier_id' => $this->supplier->id, 'tanggal' => '2026-10-31', 'tanggal_kirim' => null, 'syarat_bayar' => null,
        'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], $baris, null);
    $baris = fn ($barang, $jumlah) => [
        'permintaan_pembelian_item_id' => $barang->id, 'nama_barang' => $barang->nama_barang, 'spesifikasi' => $barang->spesifikasi,
        'jumlah' => $jumlah, 'satuan' => $barang->satuan, 'harga' => 60000,
    ];

    // Dipecah ke dua PO: semen 6 sak dulu, sisanya belakangan.
    $pertama = $terbitkan([$baris($semen, 6)]);
    expect(fn () => $terbitkan([$baris($semen, 5)]))
        ->toThrow(ValidationException::class, 'Semen: melebihi yang belum dipesan di PR (4 sak).');
    $terbitkan([$baris($semen, 4), $baris($besi, 20)]);

    $sisa = fn () => PermintaanPembelian::with('item.itemPesanan.pesanan')->find($pr->id);
    expect($sisa()->masihBisaDipesan())->toBeFalse()
        ->and(fn () => $terbitkan([$baris($besi, 1)]))->toThrow(ValidationException::class, 'Besi beton: melebihi');

    // PO yang batal mengembalikan jumlahnya ke PR.
    $this->layanan->batalkanPesanan($pertama, 'Ganti supplier', null);
    expect($sisa()->masihBisaDipesan())->toBeTrue()
        ->and($sisa()->item[0]->sisaBelumDipesan())->toBe(6.0);
});

it('menutup PO yang supplier-nya tidak sanggup mengirim sisa barang', function () {
    $pr = prDisetujui();
    $po = poDari($pr); // semen 10 × 60.000 + besi 20 × 80.000 = 2,2 jt + PPN 11% = 2.442.000
    [$semen, $besi] = [$po->item[0], $po->item[1]];
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-01', 'jumlah' => 2442000], null);
    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-03'], [$semen->id => 10, $besi->id => 5], null);

    $po = $this->layanan->tutupPesanan($po->fresh(), 'Stok besi supplier habis', null)->fresh(['item', 'pembayaran']);

    // Nilai PO tinggal barang yang datang: 600.000 + 5 × 80.000 = 1 jt + PPN = 1.110.000.
    expect($po->status)->toBe('selesai')
        ->and($po->ditutup())->toBeTrue()
        ->and($po->labelStatus())->toBe('Ditutup')
        ->and($po->alasan_tutup)->toBe('Stok besi supplier habis')
        ->and((float) $po->total_awal)->toBe(2442000.0)
        ->and((float) $po->total)->toBe(1110000.0)
        ->and($po->kelebihanBayar())->toBe(1332000.0)
        ->and((float) $po->item[1]->jumlah)->toBe(5.0)
        ->and((float) $po->item[1]->jumlah_awal)->toBe(20.0)
        ->and($po->item[0]->jumlah_awal)->toBeNull();

    // Sisa besi kembali bisa dipesan dari PR, tapi tidak lebih.
    $sisa = PermintaanPembelian::with('item.itemPesanan.pesanan')->find($pr->id);
    expect($sisa->masihBisaDipesan())->toBeTrue()
        ->and($sisa->item[1]->sisaBelumDipesan())->toBe(15.0);

    $pesanBesi = fn ($jumlah) => $this->layanan->terbitkanPesanan($pr->fresh(), [
        'supplier_id' => $this->supplier->id, 'tanggal' => '2026-11-05', 'tanggal_kirim' => null, 'syarat_bayar' => null,
        'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], [[
        'permintaan_pembelian_item_id' => $besi->id, 'nama_barang' => 'Besi beton', 'spesifikasi' => '10 mm',
        'jumlah' => $jumlah, 'satuan' => 'batang', 'harga' => 82000,
    ]], null);
    expect(fn () => $pesanBesi(16))->toThrow(ValidationException::class, 'melebihi yang belum dipesan');
    expect((float) $pesanBesi(15)->total)->toBe(1230000.0);

    // PO yang sudah ditutup tidak menerima barang lagi.
    expect(fn () => $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-06'], [$besi->id => 1], null))
        ->toThrow(ValidationException::class);
});

it('tidak menutup PO yang belum dibayar dan belum ada barang datang', function () {
    $po = poDari(prDisetujui());

    expect(fn () => $this->layanan->tutupPesanan($po, 'Supplier tidak sanggup', null))
        ->toThrow(ValidationException::class, 'batalkan saja');
    expect($po->fresh()->status)->toBe('terbit');
});

it('masih bisa melunasi PO yang barangnya sudah lengkap atau ditutup', function () {
    // Uang muka, barang datang lengkap (PO selesai), lalu pelunasan.
    $po = poDari(prDisetujui());
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-01', 'jumlah' => 1000000], null);
    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-02'], $po->item->pluck('jumlah', 'id')->all(), null);
    expect($po->fresh()->status)->toBe('selesai');

    $this->layanan->catatPembayaran($po->fresh(), ['tanggal' => '2026-11-04', 'jumlah' => 1442000], null);
    expect($po->fresh('pembayaran')->statusBayar())->toBe('lunas')
        ->and(fn () => $this->layanan->catatPembayaran($po->fresh(), ['tanggal' => '2026-11-05', 'jumlah' => 1], null))
        ->toThrow(ValidationException::class, 'Melebihi sisa');

    // Ditutup saat baru dibayar uang muka: sisanya dihitung dari barang yang datang saja.
    $po = poDari(prDisetujui());
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-01', 'jumlah' => 100000], null);
    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-02'], [$po->item[0]->id => 10], null);
    $po = $this->layanan->tutupPesanan($po->fresh(), 'Besi diganti supplier lain', null)->fresh('pembayaran');

    expect((float) $po->total)->toBe(666000.0)
        ->and($po->sisaBayar())->toBe(566000.0);
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-03', 'jumlah' => 566000], null);
    expect($po->fresh('pembayaran')->statusBayar())->toBe('lunas');
});

it('mencatat pengembalian kelebihan bayar dari supplier, boleh dicicil', function () {
    $po = poDari(prDisetujui()); // 2.442.000
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-01', 'jumlah' => 2442000], null);
    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-03'], [$po->item[0]->id => 10, $po->item[1]->id => 5], null);
    $po = $this->layanan->tutupPesanan($po->fresh(), 'Stok besi supplier habis', null);
    $kembali = fn (float $jumlah) => $this->layanan->catatPengembalian($po->fresh(), ['tanggal' => '2026-11-07', 'jumlah' => $jumlah], null);

    expect(fn () => $kembali(1400000))->toThrow(ValidationException::class, 'Melebihi kelebihan bayar yang belum dikembalikan (Rp 1.332.000)');

    $kembali(1000000);
    $po = $po->fresh(['pembayaran', 'pengembalian']);
    expect($po->kelebihanBayar())->toBe(332000.0)
        ->and($po->totalDikembalikan())->toBe(1000000.0)
        ->and($po->statusBayar())->toBe('lunas')
        ->and($po->sisaBayar())->toBe(0.0);

    $kembali(332000);
    $po = $po->fresh(['pembayaran', 'pengembalian']);
    expect($po->kelebihanBayar())->toBe(0.0)
        ->and($po->totalDibayarBersih())->toBe(1110000.0)
        ->and(fn () => $kembali(1))->toThrow(ValidationException::class, 'tidak punya kelebihan bayar');
});

it('tidak mencatat pengembalian untuk PO yang tidak kelebihan bayar', function () {
    $po = poDari(prDisetujui());
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-01', 'jumlah' => 2442000], null);

    expect(fn () => $this->layanan->catatPengembalian($po->fresh(), ['tanggal' => '2026-11-02', 'jumlah' => 1000], null))
        ->toThrow(ValidationException::class, 'tidak punya kelebihan bayar');
});
