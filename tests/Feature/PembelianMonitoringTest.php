<?php

use App\Models\Master\PermintaanPembelian;
use App\Models\Master\Perusahaan;
use App\Models\Master\PesananPembelian;
use App\Models\Master\Proyek;
use App\Models\Master\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Tiap langkah Modul Pembelian tercatat di Monitoring (kategori Pembelian):
 * siapa, kapan, dan nilainya — sama seperti modul lain.
 */
beforeEach(function () {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00'));

    $this->layanan = app(PembelianService::class);
    $this->rina = User::factory()->create(['name' => 'Rina Teknik']);
    $this->rina->assignRole('admin-teknik');
    $this->dewi = User::factory()->create(['name' => 'Dewi Keuangan']);
    $this->dewi->assignRole('finance');
    $this->bambang = User::factory()->create(['name' => 'Bambang Direktur']);
    $this->bambang->assignRole('direktur');
    $this->supplier = Supplier::create(['nama' => 'TB Sumber Bangunan', 'is_pkp' => false]);
});

/** PR semen 10 sak @65.000 (atau beton bernilai besar), sudah diajukan Rina. */
function monitorPr(bool $besar = false): PermintaanPembelian
{
    $pr = test()->layanan->buatPermintaan([
        'tanggal' => '2026-11-10', 'proyek_id' => Proyek::first()->id, 'departemen' => 'Teknik',
        'keperluan' => 'Material pondasi Blok AD', 'tanggal_dibutuhkan' => null,
    ], [$besar
        ? ['nama_barang' => 'Beton ready mix', 'spesifikasi' => 'K-225', 'jumlah' => 60, 'satuan' => 'm3', 'harga_perkiraan' => 950000]
        : ['nama_barang' => 'Semen', 'spesifikasi' => '50 kg', 'jumlah' => 10, 'satuan' => 'sak', 'harga_perkiraan' => 65000],
    ], test()->rina->id);

    return test()->layanan->ajukan($pr, test()->rina->id);
}

function monitorPo(PermintaanPembelian $pr): PesananPembelian
{
    $pr = $pr->fresh('item');

    return test()->layanan->terbitkanPesanan($pr, [
        'supplier_id' => test()->supplier->id, 'tanggal' => '2026-11-10', 'tanggal_kirim' => null, 'syarat_bayar' => null,
        'ppn_terpisah' => false, 'ppn_persen' => 0, 'catatan' => null,
    ], [[
        'permintaan_pembelian_item_id' => $pr->item[0]->id, 'nama_barang' => $pr->item[0]->nama_barang, 'spesifikasi' => null,
        'jumlah' => $pr->item[0]->jumlah, 'satuan' => $pr->item[0]->satuan, 'harga' => 60000,
    ]], test()->dewi->id);
}

/** @return list<array{0: string, 1: ?string, 2: string}> [event, pelaku, deskripsi] */
function catatanPembelian(): array
{
    return Activity::where('log_name', 'pembelian')->with('causer')->orderBy('id')->get()
        ->map(fn ($a) => [$a->event, $a->causer?->name, $a->description])->all();
}

it('mencatat tiap langkah PR sampai barang datang beserta pelakunya', function () {
    $pr = monitorPr();
    $this->layanan->tinjau($pr, 'setujui', null, $this->dewi->id);
    $po = monitorPo($pr);
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-10', 'jumlah' => 600000], $this->dewi->id);
    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-11', 'nomor_surat_jalan' => 'SJ-01'], $po->item->pluck('jumlah', 'id')->all(), $this->rina->id);

    expect(catatanPembelian())->toBe([
        ['pr.diajukan', 'Rina Teknik', "PR {$pr->nomor} diajukan ke FAT · Teknik · Material pondasi Blok AD"],
        ['pr.disetujui', 'Dewi Keuangan', "PR {$pr->nomor} disetujui FAT"],
        ['po.diterbitkan', 'Dewi Keuangan', "PO {$po->nomor} diterbitkan ke TB Sumber Bangunan · dari PR {$pr->nomor}"],
        ['po.dibayar', 'Dewi Keuangan', "PO {$po->nomor} dibayar ke TB Sumber Bangunan · lunas"],
        ['po.barang_datang', 'Rina Teknik', "PO {$po->nomor} barang datang (SJ SJ-01): Semen 10 sak · PO selesai"],
    ]);

    $bayar = Activity::where('event', 'po.dibayar')->first();
    expect((float) $bayar->properties['nilai'])->toBe(600000.0)
        ->and($bayar->subject->is($po))->toBeTrue()
        ->and((float) Activity::where('event', 'pr.diajukan')->first()->properties['nilai'])->toBe(650000.0);
});

it('mencatat PR yang diteruskan ke direktur, ditolak, dan dikembalikan', function () {
    $besar = monitorPr(besar: true);
    $this->layanan->tinjau($besar, 'setujui', null, $this->dewi->id);
    $this->layanan->putuskanAnggaran($besar->fresh(), 'tolak', 'Anggaran Blok AD belum turun', $this->bambang->id);
    $kecil = monitorPr();
    $this->layanan->tinjau($kecil, 'kembalikan', 'Lampirkan penawaran supplier', $this->dewi->id);

    expect(array_slice(catatanPembelian(), 1, 2))->toBe([
        ['pr.diteruskan', 'Dewi Keuangan', "PR {$besar->nomor} disetujui FAT, diteruskan ke direktur karena di atas Rp 50.000.000"],
        ['pr.ditolak', 'Bambang Direktur', "PR {$besar->nomor} ditolak direktur: Anggaran Blok AD belum turun"],
    ])->and(collect(catatanPembelian())->last())->toBe(
        ['pr.dikembalikan', 'Dewi Keuangan', "PR {$kecil->nomor} dikembalikan ke peminta: Lampirkan penawaran supplier"],
    );
});

it('mencatat PO yang dibatalkan otomatis atas nama sistem dan PO yang ditutup', function () {
    Perusahaan::query()->update(['batas_po_gantung_hari' => 30]);
    $prLama = monitorPr();
    $this->layanan->tinjau($prLama, 'setujui', null, $this->dewi->id);
    $gantung = monitorPo($prLama);
    $gantung->update(['tanggal' => '2026-09-01']);

    $pr = monitorPr();
    $this->layanan->tinjau($pr, 'setujui', null, $this->dewi->id);
    $po = monitorPo($pr);
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-10', 'jumlah' => 600000], $this->dewi->id);
    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-11'], [$po->item[0]->id => 4], $this->rina->id);
    $this->layanan->tutupPesanan($po->fresh(), 'Stok semen supplier habis', $this->dewi->id);

    $this->artisan('pembelian:batalkan-po-gantung')->assertSuccessful();

    $tutup = Activity::where('event', 'po.ditutup')->with('causer')->first();
    expect($tutup->description)->toBe("PO {$po->nomor} ditutup: Stok semen supplier habis · nilai Rp 600.000 → Rp 240.000")
        ->and($tutup->causer->name)->toBe('Dewi Keuangan')
        ->and((float) $tutup->properties['kelebihan_bayar'])->toBe(360000.0)
        ->and($tutup->properties['tidak_dikirim'])->toBe(['Semen 6 sak']);

    $batal = Activity::where('event', 'po.dibatalkan')->first();
    expect($batal->causer)->toBeNull()
        ->and($batal->description)->toBe("PO {$gantung->nomor} dibatalkan: Dibatalkan otomatis: lewat 30 hari tanpa pembayaran.");
});

it('mencatat perubahan supplier dan pengaturan pembelian hanya bila ada yang berubah', function () {
    $admin = User::role('super-admin')->first();
    $supplierPage = Livewire::actingAs($admin)->test('pages::master.supplier')
        ->call('create')->set('nama', 'CV Baja Utama')->call('save');
    $baja = Supplier::where('nama', 'CV Baja Utama')->first();
    $supplierPage->call('edit', $baja->id)->set('hp', '081234500022')->set('isPkp', true)->call('save')
        ->call('edit', $baja->id)->call('save')
        ->call('confirmDelete', $baja->id)->call('delete');

    Livewire::actingAs($this->bambang)->test('pages::pembelian.pengaturan')
        ->set('batas_persetujuan_direktur', '75000000')->call('simpanBatasPersetujuan')
        ->call('simpanBatasPersetujuan')
        ->set('batas_po_gantung_hari', '30')->call('simpanPoGantung');

    expect(collect(catatanPembelian())->map(fn ($c) => [$c[0], $c[2]])->all())->toBe([
        ['supplier.dibuat', 'Supplier CV Baja Utama ditambahkan'],
        ['supplier.diubah', 'Data supplier CV Baja Utama diubah: HP, PKP'],
        ['supplier.dihapus', 'Supplier CV Baja Utama dihapus'],
        ['pengaturan_pembelian.diubah', 'Batas persetujuan direktur diubah: Rp 50.000.000 → Rp 75.000.000'],
        ['pengaturan_pembelian.diubah', 'Batal otomatis PO gantung diubah: tidak aktif → 30 hari'],
    ]);
});

it('menampilkan aktivitas pembelian di Monitoring dengan kategori dan labelnya sendiri', function () {
    $pr = monitorPr();
    $this->layanan->tinjau($pr, 'tolak', 'Sudah ada stok di gudang', $this->dewi->id);

    $halaman = Livewire::actingAs($this->bambang)->test('pages::monitoring.index')
        ->call('setCategory', 'pembelian')
        ->assertSee('Pembelian')
        ->assertSee('PR Diajukan')
        ->assertSee("PR {$pr->nomor} ditolak FAT: Sudah ada stok di gudang");

    expect($halaman->viewData('saluran')['pembelian']['jumlah'])->toBe(2)
        ->and($halaman->viewData('perluDilihat')->pluck('event')->all())->toContain('pr.ditolak');
});

it('mencatat pengembalian dari supplier', function () {
    $pr = monitorPr();
    $this->layanan->tinjau($pr, 'setujui', null, $this->dewi->id);
    $po = monitorPo($pr);
    $this->layanan->catatPembayaran($po, ['tanggal' => '2026-11-10', 'jumlah' => 600000], $this->dewi->id);
    $this->layanan->catatPenerimaan($po, ['tanggal' => '2026-11-11'], [$po->item[0]->id => 4], $this->rina->id);
    $this->layanan->tutupPesanan($po->fresh(), 'Stok semen supplier habis', $this->dewi->id);
    $this->layanan->catatPengembalian($po->fresh(), ['tanggal' => '2026-11-12', 'jumlah' => 300000], $this->dewi->id);
    $this->layanan->catatPengembalian($po->fresh(), ['tanggal' => '2026-11-13', 'jumlah' => 60000], $this->dewi->id);

    expect(collect(catatanPembelian())->where(0, 'po.dikembalikan')->values()->all())->toBe([
        ['po.dikembalikan', 'Dewi Keuangan', "PO {$po->nomor} pengembalian dari TB Sumber Bangunan · masih kurang Rp 60.000"],
        ['po.dikembalikan', 'Dewi Keuangan', "PO {$po->nomor} pengembalian dari TB Sumber Bangunan · selesai dikembalikan"],
    ]);
});
