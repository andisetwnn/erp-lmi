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

uses(RefreshDatabase::class);

/**
 * Kuitansi cetak mengikuti kuitansi kertas Grha Aryana: isian bergaris,
 * "UM ke-sekian", stempel perumahan — tanpa tanda tangan dan nama petugas.
 */
beforeEach(function () {
    $this->seed();

    $proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $proyek->id)->first();
    $sales = Sales::create([
        'kode' => 'SLS-KW', 'nama' => 'Sales Kuitansi', 'is_aktif' => true,
        'dbos_username' => 'sales-kw', 'dbos_password' => 'rahasia123',
    ]);
    $rumah = Rumah::create([
        'proyek_id' => $proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'AD', 'nomor_unit' => '9', 'status' => 'available',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id, 'nama_lengkap' => 'Noerainie',
        'nik' => '3200000000000088', 'hp' => '628100000088', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);
    $this->spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'kategori' => $tipe->kategori, 'nomor_spr' => 'SPR/2026/06/00197',
        'tanggal_spr' => now()->subMonths(4), 'harga_jual' => 198_000_000, 'total_harga' => 198_000_000,
        'um_net' => 15_000_000, 'utj_nominal' => 0, 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
    ]);

    $this->finance = User::factory()->create(['name' => 'Petugas Keuangan Uji']);
    $this->finance->assignRole('finance');

    $bayar = fn (string $tanggal, int $jumlah, string $nomor) => SprRealisasiPembayaran::create([
        'spr_id' => $this->spr->id, 'jenis' => 'um', 'tanggal_bayar' => $tanggal, 'jumlah' => $jumlah,
        'nomor_kwitansi' => $nomor, 'metode' => 'transfer', 'input_by_user_id' => $this->finance->id,
    ]);
    // Dicatat tidak urut: urutan UM mengikuti tanggal bayar, bukan urutan input.
    $this->umKedua = $bayar('2026-09-26', 1_000_000, '00849');
    $this->umPertama = $bayar('2026-08-26', 2_500_000, '00801');
});

it('mencetak isian kuitansi seperti kuitansi kertas', function () {
    $this->actingAs($this->finance)
        ->get(route('marketing.spr.kuitansi', [$this->spr->id, $this->umKedua->id]))
        ->assertOk()
        ->assertSeeInOrder(['KWITANSI', '00849', 'Telah diterima dari', 'Noerainie', 'Uang Sebanyak', 'SATU JUTA RUPIAH',
            'Guna Pembayaran', 'UANG MUKA (UM) KE-2', 'Blok', 'AD', 'No', '09', 'No. SPR', '00197', '1.000.000,-'])
        ->assertSee('Bank BTN Cabang Ciputat : 000.4401.3000.09130')
        // Ikon tab aplikasi, bukan ikon bawaan Laravel
        ->assertSee('<link rel="icon" href="/favicon.ico?v=2"', false);
});

it('menomori UM menurut tanggal bayar', function () {
    expect($this->umPertama->urutanSejenis())->toBe(1)
        ->and($this->umKedua->urutanSejenis())->toBe(2);
});

it('tidak mencantumkan tanda tangan maupun nama petugas penginput', function () {
    $this->actingAs($this->finance)
        ->get(route('marketing.spr.kuitansi', [$this->spr->id, $this->umKedua->id]))
        ->assertOk()
        ->assertDontSee('Petugas Keuangan Uji')
        ->assertDontSee('tanda-tangan/', false)
        ->assertDontSee('KWT-');
});
