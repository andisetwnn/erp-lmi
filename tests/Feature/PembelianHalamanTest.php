<?php

use App\Models\Master\PermintaanPembelian;
use App\Models\Master\Perusahaan;
use App\Models\Master\PesananPembelian;
use App\Models\Master\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Modul Pembelian lewat halamannya: tiap peran hanya bisa menjalankan
 * langkahnya sendiri, baik dari tombol maupun dari aksi yang dipanggil langsung.
 */
beforeEach(function () {
    $this->seed();

    $buat = function (string $role): User {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    };
    $this->teknik = $buat('admin-teknik');
    $this->finance = $buat('finance');
    $this->direktur = $buat('direktur');
    $this->sales = $buat('admin-sales');
    $this->supplier = Supplier::create(['nama' => 'TB Sumber Bangunan']);
});

/** PR milik admin teknik, dibuat lewat halaman form. */
function prLewatForm(): PermintaanPembelian
{
    Livewire::actingAs(test()->teknik)->test('pages::pembelian.permintaan-form')
        ->set('departemen', 'Teknik')
        ->set('keperluan', 'Material pondasi Blok AD')
        ->set('item.0.nama_barang', 'Semen')
        ->set('item.0.jumlah', '10')
        ->set('item.0.satuan', 'sak')
        ->set('item.0.harga_perkiraan', 65000)
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertRedirect();

    return PermintaanPembelian::latest('id')->first();
}

it('menjalankan alur lengkap dari PR sampai barang datang lewat halaman', function () {
    $pr = prLewatForm();
    expect($pr->status)->toBe('draft')->and($pr->item)->toHaveCount(1);

    Livewire::actingAs($this->teknik)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])->call('ajukan');
    expect($pr->refresh()->status)->toBe('diajukan');

    // Rp 650.000 — di bawah batas, cukup disetujui FAT.
    Livewire::actingAs($this->finance)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->call('openAksi', 'setujui-fat')->call('jalankanAksi');
    expect($pr->refresh()->status)->toBe('disetujui');

    Livewire::actingAs($this->finance)->test('pages::pembelian.po-form', ['id' => $pr->id])
        ->set('supplierId', (string) $this->supplier->id)
        ->set('baris.0.harga', 60000)
        ->call('terbitkan')
        ->assertHasNoErrors()
        ->assertRedirect();
    $po = PesananPembelian::latest('id')->first();
    expect((float) $po->total)->toBe(600000.0);

    Livewire::actingAs($this->finance)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->call('openBayar')->assertSet('bayarJumlah', 600000)
        ->call('simpanBayar')->assertHasNoErrors();

    $itemId = $po->item()->value('id');
    Livewire::actingAs($this->teknik)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->call('openTerima')->assertSet("terimaJumlah.$itemId", '10')
        ->call('simpanTerima')->assertHasNoErrors();

    expect($po->refresh()->status)->toBe('selesai');

    // Semua barang sudah dipesan: tidak ada lagi tombol untuk PO baru, dan formnya menolak.
    Livewire::actingAs($this->finance)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->assertSee('Semua barang sudah dipesan')
        ->assertDontSee('Terbitkan PO')
        ->assertDontSee('Teruskan ke direktur');
    Livewire::actingAs($this->finance)->test('pages::pembelian.po-form', ['id' => $pr->id])
        ->assertRedirect(route('pembelian.permintaan.show', $pr->id));

    // Daftar PR menyebut nomor PO yang terbit dari PR itu, bisa diklik ke halaman PO.
    Livewire::actingAs($this->finance)->test('pages::pembelian.permintaan', ['tab' => 'semua'])
        ->assertSeeHtml('href="'.route('pembelian.po.show', $po->id).'"')
        ->assertSee($po->nomor)
        ->assertDontSee('Belum dibuat PO');

    // PR yang sudah disetujui tapi belum dibuatkan PO ditandai, bukan dikosongkan.
    $belumPo = prLewatForm();
    app(PembelianService::class)->ajukan($belumPo, $this->teknik->id);
    app(PembelianService::class)->tinjau($belumPo->fresh(), 'setujui', null, $this->finance->id);
    Livewire::actingAs($this->finance)->test('pages::pembelian.permintaan', ['tab' => 'semua'])
        ->assertSee('Belum dibuat PO');
});

it('meminta direktur menyetujui PR di atas batas lewat halaman', function () {
    Perusahaan::query()->update(['batas_persetujuan_direktur' => 500000]);
    $pr = prLewatForm();
    app(PembelianService::class)->ajukan($pr, $this->teknik->id);

    Livewire::actingAs($this->finance)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->call('openAksi', 'setujui-fat')->call('jalankanAksi');
    expect($pr->refresh()->status)->toBe('ditinjau');

    Livewire::actingAs($this->direktur)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->assertSee('Menunggu persetujuan direktur')
        ->call('openAksi', 'setujui-anggaran')->call('jalankanAksi');
    expect($pr->refresh()->status)->toBe('disetujui')
        ->and($pr->disetujui_by_user_id)->toBe($this->direktur->id);
});

it('memperingatkan di form PO lalu meneruskan PR ke direktur bila total PO melewati batas', function () {
    Perusahaan::query()->update(['batas_persetujuan_direktur' => 700000]);
    $pr = prLewatForm();
    app(PembelianService::class)->ajukan($pr, $this->teknik->id);
    app(PembelianService::class)->tinjau($pr, 'setujui', null, $this->finance->id);

    // 10 sak × 80.000 = 800.000, di atas batas 700.000.
    Livewire::actingAs($this->finance)->test('pages::pembelian.po-form', ['id' => $pr->id])
        ->set('supplierId', (string) $this->supplier->id)
        ->set('baris.0.harga', 80000)
        ->assertSee('Teruskan PR ke direktur dulu')
        ->call('terbitkan')
        ->assertNoRedirect();
    expect($pr->pesanan()->count())->toBe(0);

    Livewire::actingAs($this->finance)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->assertSee('Teruskan ke direktur')
        ->call('openAksi', 'teruskan-direktur')->call('jalankanAksi');
    expect($pr->refresh()->status)->toBe('ditinjau');
});

it('mewajibkan alasan saat FAT mengembalikan PR', function () {
    $pr = prLewatForm();
    app(PembelianService::class)->ajukan($pr, $this->teknik->id);

    Livewire::actingAs($this->finance)->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->call('openAksi', 'kembalikan')->call('jalankanAksi')->assertHasErrors('alasan')
        ->set('alasan', 'Harga semen terlalu tinggi')->call('jalankanAksi')->assertHasNoErrors();

    expect($pr->refresh()->status)->toBe('draft')->and($pr->alasan)->toBe('Harga semen terlalu tinggi');
});

it('menutup PR orang lain bagi peminta biasa', function () {
    $pr = prLewatForm();

    $this->actingAs($this->sales)->get(route('pembelian.permintaan.show', $pr->id))->assertForbidden();
    Livewire::actingAs($this->sales)->test('pages::pembelian.permintaan')->assertDontSee($pr->nomor);
    Livewire::actingAs($this->teknik)->test('pages::pembelian.permintaan')->assertSee($pr->nomor);
});

it('menolak peran yang mencoba melompati langkah orang lain', function (string $peran, string $aksi) {
    $pr = prLewatForm();
    app(PembelianService::class)->ajukan($pr, $this->teknik->id);

    Livewire::actingAs($this->{$peran})->test('pages::pembelian.permintaan-show', ['id' => $pr->id])
        ->call('openAksi', $aksi)->assertForbidden();
})->with([
    'keuangan menyetujui sebagai direktur' => ['finance', 'setujui-anggaran'],
    'direktur mereview sebagai FAT' => ['direktur', 'setujui-fat'],
    'peminta menyetujui PR sendiri' => ['teknik', 'setujui-fat'],
    'peminta meneruskan PR ke direktur' => ['teknik', 'teruskan-direktur'],
]);

it('membatasi pembayaran dan cetak PO pada yang berhak', function () {
    $layanan = app(PembelianService::class);
    $pr = prLewatForm();
    $layanan->ajukan($pr, null);
    $layanan->tinjau($pr, 'setujui', null, null);
    $pr->load('item');
    $po = $layanan->terbitkanPesanan($pr, [
        'supplier_id' => $this->supplier->id, 'tanggal' => now()->toDateString(), 'tanggal_kirim' => null,
        'syarat_bayar' => null, 'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], [[
        'permintaan_pembelian_item_id' => $pr->item[0]->id, 'nama_barang' => 'Semen', 'spesifikasi' => null,
        'jumlah' => 10, 'satuan' => 'sak', 'harga' => 60000,
    ]], null);

    Livewire::actingAs($this->direktur)->test('pages::pembelian.po-show', ['id' => $po->id])->call('openBayar')->assertForbidden();
    Livewire::actingAs($this->teknik)->test('pages::pembelian.po-show', ['id' => $po->id])->call('simpanBayar')->assertForbidden();

    $this->actingAs($this->finance)->get(route('pembelian.po.cetak', $po->id))->assertOk()->assertSee($po->nomor);
    $this->actingAs($this->sales)->get(route('pembelian.po.cetak', $po->id))->assertForbidden();
});

it('menambah supplier dari halaman master', function () {
    Livewire::actingAs($this->finance)->test('pages::master.supplier')
        ->call('create')
        ->set('nama', 'CV Baja Utama')
        ->set('isPkp', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Supplier::where('nama', 'CV Baja Utama')->value('is_pkp'))->toBeTrue();
});

it('menampilkan menu Pembelian sesuai izin', function () {
    $this->actingAs($this->finance)->get(route('pembelian.po.index'))->assertOk()->assertSee('Purchase Order');
    $this->actingAs($this->sales)->get(route('pembelian.po.index'))->assertForbidden();
});

it('menutup PO lewat halaman setelah dibayar dan menunjukkan kelebihan bayar', function () {
    $pr = prLewatForm();
    $layanan = app(PembelianService::class);
    $layanan->ajukan($pr, $this->teknik->id);
    $layanan->tinjau($pr->fresh(), 'setujui', null, $this->finance->id);
    $pr = $pr->fresh('item');
    $po = $layanan->terbitkanPesanan($pr, [
        'supplier_id' => $this->supplier->id, 'tanggal' => '2026-11-10', 'tanggal_kirim' => null, 'syarat_bayar' => null,
        'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], [[
        'permintaan_pembelian_item_id' => $pr->item[0]->id, 'nama_barang' => 'Semen', 'spesifikasi' => null,
        'jumlah' => 10, 'satuan' => 'sak', 'harga' => 60000,
    ]], $this->finance->id);

    // Belum dibayar: dibatalkan, bukan ditutup.
    Livewire::actingAs($this->finance)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->assertSeeHtml('wire:click="openBatal"')
        ->assertDontSeeHtml('wire:click="openTutup"');

    $layanan->catatPembayaran($po, ['tanggal' => '2026-11-10', 'jumlah' => 600000], $this->finance->id);
    $layanan->catatPenerimaan($po->fresh(), ['tanggal' => '2026-11-11'], [$po->item()->value('id') => 4], $this->teknik->id);

    Livewire::actingAs($this->teknik)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->assertDontSeeHtml('wire:click="openTutup"')
        ->call('tutup')->assertForbidden();

    Livewire::actingAs($this->finance)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->assertDontSeeHtml('wire:click="openBatal"')
        ->assertSeeHtml('wire:click="openTutup"')
        ->assertSee('6 tidak dikirim')
        ->call('openTutup')
        ->call('tutup')->assertHasErrors('alasanTutup')
        ->set('alasanTutup', 'Stok semen supplier habis')
        ->call('tutup')->assertHasNoErrors()
        ->assertSeeHtml('data-status="po-ditutup"')
        ->assertSee('Stok semen supplier habis')
        ->assertSee('Kelebihan bayar Rp 360.000')
        ->assertSee('dari 10')
        ->assertDontSeeHtml('wire:click="openTutup"');

    expect($po->fresh()->ditutup())->toBeTrue();
});

it('mencatat pengembalian dari supplier lewat halaman PO beserta riwayatnya', function () {
    $pr = prLewatForm();
    $layanan = app(PembelianService::class);
    $layanan->ajukan($pr, $this->teknik->id);
    $layanan->tinjau($pr->fresh(), 'setujui', null, $this->finance->id);
    $pr = $pr->fresh('item');
    $po = $layanan->terbitkanPesanan($pr, [
        'supplier_id' => $this->supplier->id, 'tanggal' => '2026-11-10', 'tanggal_kirim' => null, 'syarat_bayar' => null,
        'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], [[
        'permintaan_pembelian_item_id' => $pr->item[0]->id, 'nama_barang' => 'Semen', 'spesifikasi' => null,
        'jumlah' => 10, 'satuan' => 'sak', 'harga' => 60000,
    ]], $this->finance->id);
    $layanan->catatPembayaran($po, ['tanggal' => '2026-11-10', 'jumlah' => 600000], $this->finance->id);

    // Belum ada kelebihan bayar: belum ada tombol maupun riwayatnya.
    Livewire::actingAs($this->finance)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->assertDontSeeHtml('wire:click="openKembali"')
        ->assertDontSeeHtml('data-riwayat="pengembalian"');

    $layanan->catatPenerimaan($po->fresh(), ['tanggal' => '2026-11-11'], [$po->item()->value('id') => 4], $this->teknik->id);
    $layanan->tutupPesanan($po->fresh(), 'Stok semen supplier habis', $this->finance->id);

    Livewire::actingAs($this->teknik)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->assertDontSeeHtml('wire:click="openKembali"')
        ->call('simpanKembali')->assertForbidden();

    Livewire::actingAs($this->finance)->test('pages::pembelian.po-show', ['id' => $po->id])
        ->assertSeeHtml('wire:click="openKembali"')
        ->assertSee('Supplier belum mengembalikan kelebihan bayar.')
        ->call('openKembali')
        ->assertSet('kembaliJumlah', 360000)
        ->set('kembaliJumlah', 200000)
        ->set('kembaliKeterangan', 'Transfer balik dari TB Sumber')
        ->call('simpanKembali')->assertHasNoErrors()
        ->assertSee('Transfer balik dari TB Sumber')
        ->assertSee('Kelebihan bayar Rp 160.000')
        ->call('openKembali')
        ->set('kembaliJumlah', 200000)
        ->call('simpanKembali')->assertHasErrors('kembaliJumlah')
        ->set('kembaliJumlah', 160000)
        ->call('simpanKembali')->assertHasNoErrors()
        ->assertSee('Sudah dikembalikan supplier: Rp 360.000.')
        ->assertDontSeeHtml('wire:click="openKembali"');

    expect($po->fresh(['pembayaran', 'pengembalian'])->kelebihanBayar())->toBe(0.0);
});
