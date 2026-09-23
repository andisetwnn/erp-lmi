<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Dashboard Admin KPR — kartu per tahap dan lama tempuh antar tahap.
 *
 * Dua hal yang dijaga di sini: satu berkas tidak boleh terhitung di dua kartu
 * sekaligus, dan angka lama tempuh tidak boleh bisa digeser oleh satu tanggal
 * salah ketik.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-DK', 'nama' => 'Sales Dashboard', 'is_aktif' => true,
        'dbos_username' => 'sales-dk', 'dbos_password' => 'rahasia123',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-kpr');

    $this->actingAs($this->admin);
});

/** SPR ber-KPR yang sudah disetujui, lengkap dengan baris pemberkasannya. */
function sprDashboardKpr(string $nomor, array $berkas = [], array $atribut = []): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'DK', 'nomor_unit' => $nomor, 'status' => 'terjual',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => "KONSUMEN DK$nomor", 'hp' => '628100002222',
        'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    $spr = Spr::create(array_merge([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => "SPR/2026/09/07$nomor",
        'tanggal_spr' => now(), 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'harga_jual' => 198_000_000, 'total_harga' => 198_000_000, 'um_net' => 15_000_000,
    ], $atribut));

    if ($berkas !== []) {
        SprPemberkasan::create(array_merge(['spr_id' => $spr->id], $berkas));
    }

    return $spr;
}

/** Nilai yang dikirim komponen ke tampilannya. */
function dataKpr(string $kunci)
{
    return Livewire::test('pages::dashboard.kpr')->viewData($kunci);
}

/** Lama tempuh satu tahap, dicari lewat labelnya. */
function tempuhKpr(string $label): array
{
    return collect(dataKpr('kinerja'))->firstWhere('label', $label)['data'];
}

it('menghitung tiap berkas di satu tahap saja', function () {
    sprDashboardKpr('01');                                  // belum ada berkas
    sprDashboardKpr('02', ['bm_tanggal' => '2026-03-01']);  // menunggu wawancara
    sprDashboardKpr('03', ['bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-08']);
    sprDashboardKpr('04', [
        'bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-08', 'sp3k_tanggal' => '2026-03-20',
    ]);

    $komponen = Livewire::test('pages::dashboard.kpr');

    expect($komponen->viewData('tanpaBerkas'))->toBe(1)
        ->and($komponen->viewData('menungguWawancara'))->toBe(1)
        ->and($komponen->viewData('menungguSp3k'))->toBe(1)
        ->and($komponen->viewData('sp3kTerbit'))->toBe(1);
});

it('menghitung baris pemberkasan tanpa tanggal berkas sebagai belum ada berkas', function () {
    // Barisnya sudah terbuka karena banknya dipilih duluan, tapi berkasnya sendiri
    // belum masuk. Kalau tidak dihitung, berkas ini hilang dari semua kartu.
    sprDashboardKpr('05', ['bank_kode' => 'CBN']);

    expect(dataKpr('tanpaBerkas'))->toBe(1)
        ->and(dataKpr('menungguWawancara'))->toBe(0);
});

it('memakai median, bukan rata-rata, untuk lama tempuh', function () {
    // Selisih 5, 10, dan 30 hari. Rata-ratanya 15, mediannya 10 — yang dipakai 10.
    sprDashboardKpr('06', ['bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-06']);
    sprDashboardKpr('07', ['bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-11']);
    sprDashboardKpr('08', ['bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-31']);

    expect(tempuhKpr('Berkas → Wawancara'))->toBe(['median' => 10, 'jumlah' => 3]);
});

it('membuang tanggal yang salah ketik dari hitungan', function () {
    // Di data berjalan ada tanggal bertahun 0026. Selisih seperti itu bukan lama
    // pengerjaan, dan tidak boleh ikut menentukan angkanya.
    sprDashboardKpr('09', ['bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-06']);
    sprDashboardKpr('10', ['bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-11']);
    sprDashboardKpr('11', ['bm_tanggal' => '0026-03-01', 'wcr_tanggal' => '2026-03-08']);

    // Tersisa 5 dan 10 hari → mediannya 8 setelah dibulatkan.
    expect(tempuhKpr('Berkas → Wawancara'))->toBe(['median' => 8, 'jumlah' => 2]);
});

it('membuang tanggal yang urutannya terbalik', function () {
    // Wawancara mendahului berkas masuk itu salah catat, bukan pengerjaan minus.
    sprDashboardKpr('12', ['bm_tanggal' => '2026-03-10', 'wcr_tanggal' => '2026-03-01']);

    expect(tempuhKpr('Berkas → Wawancara'))->toBe(['median' => null, 'jumlah' => 0]);
});

it('ikut menghitung yang sudah akad pada lama tempuh', function () {
    // Yang sudah akad justru perjalanannya paling lengkap. Kalau dibuang, tahap
    // SP3K → Akad tidak akan pernah punya angka.
    sprDashboardKpr('13', [
        'bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-08', 'sp3k_tanggal' => '2026-03-20',
    ], ['status' => 'akad', 'tgl_akad' => '2026-04-09']);

    expect(tempuhKpr('SP3K → Akad'))->toBe(['median' => 20, 'jumlah' => 1])
        ->and(tempuhKpr('Berkas → Akad'))->toBe(['median' => 39, 'jumlah' => 1]);
});

it('tidak menghitung yang sudah akad sebagai berkas berjalan', function () {
    // Kartu tahap hanya untuk yang masih perlu dikejar.
    sprDashboardKpr('14', ['bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-08'],
        ['status' => 'akad', 'tgl_akad' => '2026-04-09']);

    expect(dataKpr('menungguSp3k'))->toBe(0);
});

it('menyebut datanya belum ada, bukan nol hari', function () {
    // Nol terbaca sebagai "selesai hari itu juga" — padahal tanggalnya memang
    // belum pernah dicatat.
    sprDashboardKpr('15', ['sp3k_tanggal' => '2026-03-20']);

    expect(tempuhKpr('Berkas → Wawancara')['median'])->toBeNull();
});
