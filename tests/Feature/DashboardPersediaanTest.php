<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Matriks Persediaan Rumah mengikuti bentuk laporan lama: tiap baris punya penjelasan,
 * dan dua baris menampilkan persentase terhadap total kavling.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');
    $this->actingAs($direktur);
});

function buatRumah(int $jumlah, array $atribut = []): void
{
    foreach (range(1, $jumlah) as $i) {
        Rumah::create(array_merge([
            'proyek_id' => test()->proyek->id,
            'tipe_rumah_id' => test()->tipe->id,
            'blok' => 'ZZ',
            'nomor_unit' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'status' => 'available',
        ], $atribut));
    }
}

function persediaan(): array
{
    return Livewire::test('pages::dashboard.direksi')->viewData('persediaan');
}

it('menyediakan tujuh baris termasuk stok kavling awal tahun', function () {
    $kunci = collect(persediaan()['metrics'])->pluck('key')->all();

    expect($kunci)->toBe([
        'total_kavling', 'akad', 'utj', 'stok_awal', 'stok_berjalan', 'rumah_selesai', 'rumah_proses',
    ]);
});

it('memberi penjelasan pada setiap baris', function () {
    foreach (persediaan()['metrics'] as $m) {
        expect($m['info'] ?? null)->not->toBeEmpty("baris {$m['key']} belum punya penjelasan");
    }
});

it('menandai hanya baris akad dan stok awal yang memakai persentase', function () {
    $pakaiPersen = collect(persediaan()['metrics'])
        ->filter(fn ($m) => ! empty($m['persen']))
        ->pluck('key')->values()->all();

    expect($pakaiPersen)->toBe(['akad', 'stok_awal']);
});

it('menyertakan tahun terpilih pada label stok kavling awal', function () {
    $tahun = (int) now()->year;

    $label = collect(persediaan()['metrics'])->firstWhere('key', 'stok_awal')['label'];

    expect($label)->toBe("Stok Kavling Awal $tahun");
});

it('menghitung stok awal sama dengan total kavling ketika belum ada penjualan tahun sebelumnya', function () {
    buatRumah(5);

    $p = persediaan();
    $total = collect($p['metrics'])->firstWhere('key', 'total_kavling')['values'];
    $stokAwal = collect($p['metrics'])->firstWhere('key', 'stok_awal')['values'];

    expect((int) $stokAwal[$this->proyek->id])->toBe((int) $total[$this->proyek->id])
        ->and((int) $stokAwal[$this->proyek->id])->toBe(5);
});

it('memisahkan rumah selesai dari yang sedang dibangun berdasar progres fisik', function () {
    buatRumah(2, ['progres_fisik' => 100]);
    Rumah::create([
        'proyek_id' => $this->proyek->id,
        'tipe_rumah_id' => $this->tipe->id,
        'blok' => 'YY',
        'nomor_unit' => '01',
        'status' => 'available',
        'progres_fisik' => 40,
    ]);

    $p = persediaan();
    $selesai = collect($p['metrics'])->firstWhere('key', 'rumah_selesai')['values'];
    $proses = collect($p['metrics'])->firstWhere('key', 'rumah_proses')['values'];

    expect((int) $selesai[$this->proyek->id])->toBe(2)
        ->and((int) $proses[$this->proyek->id])->toBe(1);
});

it('menampilkan persentase dan penjelasan di layar', function () {
    buatRumah(4);

    $html = Livewire::test('pages::dashboard.direksi')->html();

    expect($html)->toContain('Stok Kavling Awal')
        ->and($html)->toContain('100%')
        ->and($html)->toContain('dikurangi akad tahun-tahun sebelum');
});

it('menaruh penjelasan di luar tabel yang bisa digeser menyamping', function () {
    // Modal yang dirender dari dalam kotak overflow-x-auto ikut terpotong oleh kotak itu:
    // judul terpangkas dan muncul scrollbar sendiri di dalam modalnya.
    buatRumah(3);

    $html = Livewire::test('pages::dashboard.direksi')->html();

    $mulai = strpos($html, 'Persediaan Rumah');
    $potong = substr($html, $mulai);
    $isiTabel = substr($potong, strpos($potong, 'overflow-x-auto'), strpos($potong, '</table>') - strpos($potong, 'overflow-x-auto'));

    expect(substr_count($isiTabel, 'aria-label="Info"'))->toBe(0);
});

/**
 * Unit yang sudah akad. Nomor SPR & blok dibedakan lewat $urut supaya bisa
 * dipanggil berkali-kali dalam satu tes.
 */
function buatSprAkadPersediaan(int $urut, ?string $tglAkad): void
{
    $proyek = test()->proyek;
    $tipe = test()->tipe;

    $sales = Sales::firstOrCreate(
        ['kode' => 'SLS-PSD'],
        ['nama' => 'Sales Persediaan', 'is_aktif' => true,
            'dbos_username' => 'sales-psd', 'dbos_password' => 'rahasia123'],
    );

    $rumah = Rumah::create([
        'proyek_id' => $proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'AK', 'nomor_unit' => str_pad((string) $urut, 2, '0', STR_PAD_LEFT),
        'status' => 'terjual',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'nama_lengkap' => "AKAD $urut", 'nik' => str_pad((string) (32000000000000 + $urut), 16, '0'),
        'hp' => '628100000'.$urut, 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => $proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => $tipe->kategori, 'nomor_spr' => 'SPR/2026/09/'.str_pad((string) (900 + $urut), 5, '0', STR_PAD_LEFT),
        'tanggal_spr' => now()->subYear(), 'harga_jual' => 198_000_000, 'total_harga' => 198_000_000,
        'um_net' => 15_000_000, 'utj_nominal' => 0, 'jenis_pembayaran' => 'kpr',
        'status' => 'akad', 'tgl_akad' => $tglAkad,
    ]);
}

it('menghitung stok berjalan dari akad, bukan dari status unit', function () {
    // Unit ditandai terjual begitu SPR disetujui, jadi menghitung yang berstatus
    // available membuat angkanya nol walau banyak unit belum diakadkan.
    buatRumah(5);
    buatSprAkadPersediaan(1, now()->toDateString());
    buatSprAkadPersediaan(2, now()->toDateString());

    $p = persediaan();
    $total = (int) collect($p['metrics'])->firstWhere('key', 'total_kavling')['values'][test()->proyek->id];
    $berjalan = (int) collect($p['metrics'])->firstWhere('key', 'stok_berjalan')['values'][test()->proyek->id];

    // 5 unit polos + 2 unit berakad = 7 total, dikurangi 2 akad.
    expect($total)->toBe(7)->and($berjalan)->toBe(5);
});

it('mengurangi stok awal hanya dengan akad tahun-tahun sebelumnya', function () {
    buatRumah(5);
    buatSprAkadPersediaan(1, '2025-06-10');   // tahun lalu — mengurangi stok awal
    buatSprAkadPersediaan(2, '2026-03-04');   // tahun berjalan — tidak mengurangi

    $stokAwal = (int) collect(persediaan()['metrics'])
        ->firstWhere('key', 'stok_awal')['values'][test()->proyek->id];

    // 7 kavling, satu sudah diakadkan sebelum 2026.
    expect($stokAwal)->toBe(6);
});

it('tidak mengurangi stok awal dengan SPR yang baru disetujui', function () {
    // Sebelumnya semua SPR aktif ikut dikurangkan, sehingga unit yang baru
    // disetujui tapi belum akad terhitung sudah lepas dari stok.
    buatRumah(3);

    $sales = Sales::firstOrCreate(
        ['kode' => 'SLS-PSD2'],
        ['nama' => 'Sales Dua', 'is_aktif' => true,
            'dbos_username' => 'sales-psd2', 'dbos_password' => 'rahasia123'],
    );
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'AP', 'nomor_unit' => '01', 'status' => 'terjual',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'BELUM AKAD', 'nik' => '3200000000009999',
        'hp' => '628100009999', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now()->subYear(), 'tanggal_expired' => now()->subYear()->addDay(), 'status' => 'sukses',
    ]);
    Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => test()->tipe->kategori, 'nomor_spr' => 'SPR/2025/12/00999',
        'tanggal_spr' => '2025-12-20', 'harga_jual' => 198_000_000, 'total_harga' => 198_000_000,
        'um_net' => 15_000_000, 'utj_nominal' => 0, 'jenis_pembayaran' => 'kpr',
        'status' => 'approved',
    ]);

    $stokAwal = (int) collect(persediaan()['metrics'])
        ->firstWhere('key', 'stok_awal')['values'][test()->proyek->id];

    expect($stokAwal)->toBe(4);
});
