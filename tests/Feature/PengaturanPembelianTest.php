<?php

use App\Models\Master\PermintaanPembelian;
use App\Models\Master\Perusahaan;
use App\Models\Master\PesananPembelian;
use App\Models\Master\Proyek;
use App\Models\Master\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Pengaturan Pembelian (setara "Setting PO" di DDM): batas persetujuan direktur
 * dan batal otomatis PO yang lewat batas hari tanpa pembayaran.
 */
beforeEach(function () {
    $this->seed();

    $this->pembelian = app(PembelianService::class);
    $this->direktur = User::factory()->create();
    $this->direktur->assignRole('direktur');
    $this->fat = User::factory()->create();
    $this->fat->assignRole('finance');
    $this->supplier = Supplier::create(['nama' => 'TB Sumber Bangunan']);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00'));
});

/** PO semen 10 sak @60.000 dari PR kecil yang sudah disetujui FAT. */
function pengaturanPo(string $tanggal): PesananPembelian
{
    $layanan = test()->pembelian;
    $pr = $layanan->buatPermintaan([
        'tanggal' => $tanggal, 'proyek_id' => Proyek::first()->id, 'departemen' => 'Teknik',
        'keperluan' => 'Material', 'tanggal_dibutuhkan' => null,
    ], [
        ['nama_barang' => 'Semen', 'spesifikasi' => null, 'jumlah' => 10, 'satuan' => 'sak', 'harga_perkiraan' => 60000],
    ], test()->fat->id);
    $layanan->ajukan($pr, test()->fat->id);
    $layanan->tinjau($pr->fresh(), 'setujui', null, test()->fat->id);
    $pr = PermintaanPembelian::with('item')->find($pr->id);

    return $layanan->terbitkanPesanan($pr, [
        'supplier_id' => test()->supplier->id, 'tanggal' => $tanggal, 'tanggal_kirim' => null, 'syarat_bayar' => null,
        'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], [[
        'permintaan_pembelian_item_id' => $pr->item->first()->id, 'nama_barang' => 'Semen', 'spesifikasi' => null,
        'jumlah' => 10, 'satuan' => 'sak', 'harga' => 60000,
    ]], test()->fat->id);
}

it('hanya bisa dibuka direktur', function () {
    $this->actingAs($this->fat)->get(route('pembelian.pengaturan'))->assertForbidden();
    $this->actingAs($this->direktur)->get(route('pembelian.pengaturan'))->assertOk()
        ->assertSee('Batas persetujuan direktur')
        ->assertSee('Batal otomatis PO gantung');
});

it('menyimpan batas persetujuan direktur yang dipakai alur PR', function () {
    Livewire::actingAs($this->direktur)->test('pages::pembelian.pengaturan')
        ->assertSet('batas_persetujuan_direktur', '50000000')
        ->set('batas_persetujuan_direktur', '75000000')
        ->call('simpanBatasPersetujuan')
        ->assertHasNoErrors();

    expect($this->pembelian->batasPersetujuanDirektur())->toBe(75000000.0);

    // Tidak lagi diatur dari Master › Perusahaan.
    Livewire::actingAs(User::role('super-admin')->first())->test('pages::master.perusahaan')
        ->call('edit', Perusahaan::first()->id)
        ->assertDontSee('Batas Persetujuan Direktur');
});

it('menyimpan, menolak isian keliru, dan mematikan batas PO gantung', function () {
    $halaman = Livewire::actingAs($this->direktur)->test('pages::pembelian.pengaturan')
        ->assertSet('batas_po_gantung_hari', '')
        ->set('batas_po_gantung_hari', '30')
        ->call('simpanPoGantung')
        ->assertHasNoErrors();
    expect($this->pembelian->batasPoGantungHari())->toBe(30);

    $halaman->set('batas_po_gantung_hari', '0')->call('simpanPoGantung')->assertHasErrors('batas_po_gantung_hari');
    expect($this->pembelian->batasPoGantungHari())->toBe(30);

    $halaman->set('batas_po_gantung_hari', '')->call('simpanPoGantung')->assertHasNoErrors();
    expect($this->pembelian->batasPoGantungHari())->toBeNull();
});

it('membatalkan otomatis hanya PO yang lewat batas tanpa pembayaran sama sekali', function () {
    Perusahaan::query()->update(['batas_po_gantung_hari' => 30]);
    $gantung = pengaturanPo('2026-10-01');
    $dibayar = pengaturanPo('2026-10-01');
    $this->pembelian->catatPembayaran($dibayar, ['tanggal' => '2026-10-02', 'jumlah' => 100000], $this->fat->id);
    $tepatBatas = pengaturanPo('2026-10-11');
    $baru = pengaturanPo('2026-11-05');

    $this->artisan('pembelian:batalkan-po-gantung')
        ->expectsOutputToContain('Membatalkan 1 PO: '.$gantung->nomor)
        ->assertSuccessful();

    expect($gantung->fresh()->status)->toBe('batal')
        ->and($gantung->fresh()->alasan_batal)->toBe('Dibatalkan otomatis: lewat 30 hari tanpa pembayaran.')
        ->and($dibayar->fresh()->status)->toBe('terbit')
        ->and($tepatBatas->fresh()->status)->toBe('terbit')
        ->and($baru->fresh()->status)->toBe('terbit');
});

it('tidak membatalkan apa pun selama batasnya kosong', function () {
    $lama = pengaturanPo('2026-01-05');

    $this->artisan('pembelian:batalkan-po-gantung')
        ->expectsOutputToContain('tidak aktif')
        ->assertSuccessful();

    expect($lama->fresh()->status)->toBe('terbit');
});

it('menjadwalkan pengecekan PO gantung tiap hari', function () {
    $jadwal = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pembelian:batalkan-po-gantung'));

    expect($jadwal)->not->toBeNull()
        ->and($jadwal->expression)->toBe('0 1 * * *');
});

it('menunjukkan PO yang akan dibatalkan pada pengecekan berikutnya', function () {
    $gantung = pengaturanPo('2026-10-01');

    Livewire::actingAs($this->direktur)->test('pages::pembelian.pengaturan')
        ->assertSee('Tidak aktif')
        ->set('batas_po_gantung_hari', '30')
        ->call('simpanPoGantung')
        ->assertSee('1 PO sudah lewat 30 hari tanpa pembayaran')
        ->assertSee($gantung->nomor)
        ->set('batas_po_gantung_hari', '60')
        ->call('simpanPoGantung')
        ->assertSee('Saat ini tidak ada PO yang lewat 60 hari tanpa pembayaran')
        ->assertDontSee($gantung->nomor);
});
