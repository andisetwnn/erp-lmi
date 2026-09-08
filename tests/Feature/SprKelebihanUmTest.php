<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Kelebihan bayar UM.
 *
 * Sebelumnya jumlah dipangkas diam-diam ke sisa UM: bayar 3 juta saat sisa 2,5 juta
 * tersimpan jadi 2,5 juta, dan 500 ribu yang benar-benar masuk hilang dari catatan.
 * Padahal kelebihan itu hak refund konsumen — kalau tidak tercatat, tidak ada yang
 * tahu ada uang yang mesti dikembalikan.
 */
beforeEach(function () {
    $this->seed();

    $proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $proyek->id)->first();
    $this->proyek = $proyek;

    $sales = Sales::create([
        'kode' => 'SLS-U', 'nama' => 'Sales UM', 'is_aktif' => true,
        'dbos_username' => 'sales-u', 'dbos_password' => 'rahasia123',
    ]);

    $rumah = Rumah::create([
        'proyek_id' => $proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'UM', 'nomor_unit' => '01', 'status' => 'available',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'nama_lengkap' => 'BUDI LEBIH', 'nik' => '3200000000000088',
        'hp' => '628100000000', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    $this->spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => $tipe->kategori, 'nomor_spr' => 'SPR/2026/09/00800',
        'tanggal_spr' => now()->subMonth(), 'harga_jual' => 198_000_000,
        'total_harga' => 198_000_000, 'um_net' => 15_000_000, 'utj_nominal' => 0,
        'jenis_pembayaran' => 'kpr', 'status' => 'approved',
    ]);

    $this->finance = User::factory()->create();
    $this->finance->assignRole('finance');
    $this->actingAs($this->finance);
});

function halamanUm(int $id)
{
    return Livewire::test('pages::marketing.spr-show', ['id' => $id]);
}

/** Panel Realisasi Pembayaran ada di tab Rincian, bukan tab bawaan (SPR). */
function rincianUm(int $id)
{
    return halamanUm($id)->call('setTab', 'rincian');
}

it('mencatat jumlah apa adanya, tidak dipangkas ke sisa UM', function () {
    // Sisa 15 juta, dibayar 15,5 juta.
    halamanUm($this->spr->id)
        ->call('openTambahTransaksi')
        ->set('trxTanggal', now()->toDateString())
        ->set('trxJumlah', '15500000')
        ->set('trxMetode', 'transfer')
        ->call('saveTransaksi')
        ->assertHasNoErrors();

    $tersimpan = (float) SprRealisasiPembayaran::where('spr_id', $this->spr->id)
        ->where('jenis', 'um')->sum('jumlah');

    expect($tersimpan)->toBe(15500000.0);
});

it('menampilkan kelebihan bayar di ringkasan', function () {
    SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDay(), 'jumlah' => 15_500_000,
        'nomor_kwitansi' => '90100', 'metode' => 'transfer',
    ]);

    rincianUm($this->spr->id)
        ->assertSeeText('Kelebihan Bayar')
        ->assertSeeText('500.000');
});

it('tetap menampilkan UM Lunas kalau pas, bukan kelebihan', function () {
    SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDay(), 'jumlah' => 15_000_000,
        'nomor_kwitansi' => '90101', 'metode' => 'transfer',
    ]);

    rincianUm($this->spr->id)
        ->assertSeeText('UM Lunas')
        ->assertDontSeeText('Kelebihan Bayar');
});

it('masih bisa menambah transaksi walau UM sudah lunas', function () {
    // Formnya dulu ditutup dengan "Tidak ada sisa untuk dicatat", jadi uang yang
    // terlanjur masuk tidak punya tempat sama sekali.
    SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDays(2), 'jumlah' => 15_000_000,
        'nomor_kwitansi' => '90102', 'metode' => 'transfer',
    ]);

    halamanUm($this->spr->id)
        ->call('openTambahTransaksi')
        ->set('trxTanggal', now()->toDateString())
        ->set('trxJumlah', '500000')
        ->set('trxMetode', 'tunai')
        ->call('saveTransaksi')
        ->assertHasNoErrors();

    expect((float) SprRealisasiPembayaran::where('spr_id', $this->spr->id)->where('jenis', 'um')->sum('jumlah'))
        ->toBe(15500000.0);
});

it('membolehkan edit realisasi jadi melebihi kewajiban', function () {
    $r = SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDay(), 'jumlah' => 15_000_000,
        'nomor_kwitansi' => '90103', 'metode' => 'transfer',
    ]);

    halamanUm($this->spr->id)
        ->call('openEditRealisasi', $r->id)
        ->set('editRealisasiJumlah', '15500000')
        ->call('saveEditRealisasi')
        ->assertHasNoErrors();

    expect((float) $r->fresh()->jumlah)->toBe(15500000.0);
});

it('memunculkan nominal kelebihan di daftar pemberkasan', function () {
    // Di sini dulu kelebihan cuma terbaca sebagai persentase di atas 100,
    // nominalnya sendiri tidak pernah kelihatan.
    SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDay(), 'jumlah' => 15_500_000,
        'nomor_kwitansi' => '90106', 'metode' => 'transfer',
    ]);

    // Kedua daftar baru terisi kalau proyek sudah dipilih di picker sidebar.
    session(['active_proyek_id' => $this->proyek->id]);

    Livewire::test('pages::pemberkasan.input')
        ->assertSeeText('lebih')
        ->assertSeeText('500.000');
});

it('memunculkan nominal kelebihan di rekap SPR laporan', function () {
    // Kolom Sisa UM dipagari max(0, ...), jadi kelebihannya tertelan jadi nol.
    SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDay(), 'jumlah' => 15_500_000,
        'nomor_kwitansi' => '90107', 'metode' => 'transfer',
    ]);

    session(['active_proyek_id' => $this->proyek->id]);

    Livewire::test('pages::laporan.index')
        ->set('tab', 'rekap')
        ->assertSeeText('lebih')
        ->assertSeeText('500.000');
});

it('menghitung UTJ sebagai bagian uang masuk', function () {
    // UTJ 500rb + UM 15jt = 15,5jt terhadap kewajiban 15jt → lebih 500rb.
    $this->spr->update(['utj_nominal' => 500_000]);

    SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'bf',
        'tanggal_bayar' => now()->subDays(3), 'jumlah' => 500_000,
        'nomor_kwitansi' => '90104', 'metode' => 'transfer',
    ]);
    SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um',
        'tanggal_bayar' => now()->subDay(), 'jumlah' => 15_000_000,
        'nomor_kwitansi' => '90105', 'metode' => 'transfer',
    ]);

    rincianUm($this->spr->id)
        ->assertSeeText('Kelebihan Bayar')
        ->assertSeeText('500.000');
});
