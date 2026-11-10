<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Carbon\CarbonImmutable;
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

it('menyediakan baris tahap pembangunan, konsumen, dan stok kavling awal tahun', function () {
    $kunci = collect(persediaan()['metrics'])->pluck('key')->all();

    expect($kunci)->toBe([
        'total_kavling', 'akad', 'sudah_dibangun', 'belum_dibangun', 'konsumen_aktif', 'konsumen_non_lot',
        'stok_awal', 'stok_berjalan', 'rumah_selesai', 'rumah_proses',
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

/** Unit dengan atau tanpa LOT, beserta SPR berstatus tertentu bila ada pembelinya. */
function buatUnitPersediaan(int $urut, ?string $statusSpr, ?int $lot): void
{
    $sales = Sales::firstOrCreate(
        ['kode' => 'SLS-PSD'],
        ['nama' => 'Sales Persediaan', 'is_aktif' => true, 'dbos_username' => 'sales-psd', 'dbos_password' => 'rahasia123'],
    );
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'LT', 'nomor_unit' => (string) $urut, 'lot' => $lot,
        'status' => $statusSpr ? 'terjual' : 'available',
    ]);

    if (! $statusSpr) {
        return;
    }

    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => test()->proyek->id, 'nama_lengkap' => "KONSUMEN LT $urut",
        'nik' => str_pad((string) (33000000000000 + $urut), 16, '0'), 'hp' => '628200000'.$urut, 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => $sales->id, 'proyek_id' => test()->proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);
    Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $sales->id, 'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => test()->tipe->kategori, 'nomor_spr' => 'SPR/2026/10/'.str_pad((string) (700 + $urut), 5, '0', STR_PAD_LEFT),
        'tanggal_spr' => now(), 'harga_jual' => 193_000_000, 'total_harga' => 193_000_000, 'um_net' => 10_000_000,
        'utj_nominal' => 1_000_000, 'jenis_pembayaran' => 'kpr', 'status' => $statusSpr,
        'tgl_akad' => $statusSpr === 'akad' ? now()->toDateString() : null,
    ]);
}

it('membagi seluruh kavling menjadi akad, sudah dibangun, dan belum dibangun menurut LOT', function () {
    buatUnitPersediaan(1, 'akad', 1);
    buatUnitPersediaan(2, 'approved', 2);    // dibangun, sudah ada pembeli
    buatUnitPersediaan(3, null, 2);          // dibangun, masih stok
    buatUnitPersediaan(4, 'approved', null); // belum dibangun, sudah ada pembeli
    buatUnitPersediaan(5, 'submitted', null);
    buatUnitPersediaan(6, null, null);
    buatUnitPersediaan(7, 'cancelled', null); // batal: bukan konsumen aktif

    $p = persediaan();
    $nilai = fn (string $kunci) => (int) collect($p['metrics'])->firstWhere('key', $kunci)['values'][test()->proyek->id];

    expect($nilai('total_kavling'))->toBe(7)
        ->and($nilai('akad'))->toBe(1)
        ->and($nilai('sudah_dibangun'))->toBe(2)
        ->and($nilai('belum_dibangun'))->toBe(4)
        ->and($nilai('konsumen_aktif'))->toBe(3)
        ->and($nilai('konsumen_non_lot'))->toBe(2)
        ->and($p['komposisi']->first())->toMatchArray(['total' => 7, 'akad' => 1, 'dibangun' => 2, 'belum' => 4]);
});

it('menaruh Persediaan Rumah di atas Marketing Performance beserta kartu ringkasnya', function () {
    buatUnitPersediaan(1, 'akad', 1);
    buatUnitPersediaan(2, null, null);

    $html = Livewire::test('pages::dashboard.direksi')->html();

    expect(strpos($html, 'Persediaan Rumah'))->toBeLessThan(strpos($html, 'Marketing Performance'))
        ->and($html)->toContain('Total Kavling')
        ->and($html)->toContain('50% dari total kavling')
        ->and($html)->toContain('0 sudah laku · 1 stok')
        ->and($html)->toContain('Sudah beli, belum turun LOT');
});

it('menyebutkan kapan data LOT terakhir diperbarui', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00'));
    buatUnitPersediaan(1, null, null);
    Rumah::where('blok', 'LT')->first()->update(['lot' => 7]);

    expect(Livewire::test('pages::dashboard.direksi')->html())
        ->toContain('Sudah/belum dibangun mengikuti LOT.')
        ->toContain('Data LOT terakhir diperbarui <b>9 Nov 2026</b>');
});

it('menampilkan tabel rincian per proyek hanya kalau proyeknya lebih dari satu', function () {
    buatUnitPersediaan(1, null, 1);

    expect(Livewire::test('pages::dashboard.direksi')->html())->not->toContain('data-rincian="persediaan-per-proyek"');

    $kedua = Proyek::create([
        'nama_proyek' => 'Proyek Kedua', 'nama_perumahan' => 'Kedua', 'kode_surat' => 'PK2',
        'desa' => '-', 'kelurahan' => '-', 'kecamatan' => '-', 'kota_kabupaten' => '-',
        'kode_akuntansi' => 'PK2', 'kode_virtual_account' => '999',
    ]);
    Rumah::create(['proyek_id' => $kedua->id, 'tipe_rumah_id' => test()->tipe->id, 'blok' => 'PK', 'nomor_unit' => '1', 'status' => 'available']);

    expect(Livewire::test('pages::dashboard.direksi')->html())->toContain('data-rincian="persediaan-per-proyek"');
});

it('membuka daftar unit di balik setiap kartu dengan jumlah yang sama dengan angkanya', function () {
    buatUnitPersediaan(1, 'akad', 1);
    buatUnitPersediaan(2, 'approved', 2);
    buatUnitPersediaan(3, null, 2);
    buatUnitPersediaan(4, 'approved', null);
    buatUnitPersediaan(5, 'submitted', null);
    buatUnitPersediaan(6, null, null);
    buatUnitPersediaan(7, 'cancelled', null);

    $komponen = Livewire::test('pages::dashboard.direksi');
    $metrik = collect($komponen->viewData('persediaan')['metrics']);
    $diharapkan = [
        'total_kavling' => ['LT-01', 'LT-02', 'LT-03', 'LT-04', 'LT-05', 'LT-06', 'LT-07'],
        'akad' => ['LT-01'],
        'sudah_dibangun' => ['LT-02', 'LT-03'],
        'belum_dibangun' => ['LT-04', 'LT-05', 'LT-06', 'LT-07'],
        'konsumen_aktif' => ['LT-02', 'LT-04', 'LT-05'],
        'konsumen_non_lot' => ['LT-04', 'LT-05'],
        'stok_berjalan' => ['LT-02', 'LT-03', 'LT-04', 'LT-05', 'LT-06', 'LT-07'],
    ];

    foreach ($diharapkan as $kunci => $unit) {
        $rincian = $komponen->call('bukaRincianPersediaan', $kunci)->viewData('rincian');

        expect($rincian->pluck('unit')->all())->toBe($unit)
            ->and($rincian->count())->toBe((int) $metrik->firstWhere('key', $kunci)['values']->sum());
    }
});

it('menyebutkan pembeli, tahap, dan sales pada daftar unit', function () {
    buatUnitPersediaan(1, 'akad', 1);
    buatUnitPersediaan(4, 'approved', null);
    buatUnitPersediaan(5, 'submitted', null);
    buatUnitPersediaan(6, null, null);

    $komponen = Livewire::test('pages::dashboard.direksi')->call('bukaRincianPersediaan', 'belum_dibangun');

    expect($komponen->viewData('rincian')->map(fn ($b) => [$b['unit'], $b['konsumen'], $b['tahap'], $b['sales']])->all())->toBe([
        ['LT-04', 'KONSUMEN LT 4', 'Disetujui', 'Sales Persediaan'],
        ['LT-05', 'KONSUMEN LT 5', 'Diajukan', 'Sales Persediaan'],
        ['LT-06', null, null, null],
    ]);
    $komponen->assertSee('Belum Dibangun · 3 unit')->assertSeeHtml('text-zinc-400">Stok</td>')->assertDontSee('Belum laku');

    $akad = Livewire::test('pages::dashboard.direksi')->call('bukaRincianPersediaan', 'akad')->viewData('rincian')->first();
    expect($akad['tahap'])->toBe('Akad')->and($akad['konsumen'])->toBe('KONSUMEN LT 1');
});

it('menyaring daftar unit menurut unit, konsumen, atau sales', function () {
    buatUnitPersediaan(2, 'approved', 2);
    buatUnitPersediaan(4, 'approved', null);
    buatUnitPersediaan(6, null, null);

    $komponen = Livewire::test('pages::dashboard.direksi')->call('bukaRincianPersediaan', 'total_kavling');

    expect($komponen->set('cariRincian', 'lt 4')->viewData('rincian')->pluck('unit')->all())->toBe(['LT-04'])
        ->and($komponen->set('cariRincian', 'LT-06')->viewData('rincian')->pluck('unit')->all())->toBe(['LT-06'])
        ->and($komponen->set('cariRincian', 'sales persediaan')->viewData('rincian')->pluck('unit')->all())->toBe(['LT-02', 'LT-04']);
    $komponen->assertSee('2 unit ditampilkan dari 3');
});

it('mengabaikan kartu yang tidak dikenal dan berhenti memuat daftar setelah ditutup', function () {
    buatUnitPersediaan(1, null, null);

    $komponen = Livewire::test('pages::dashboard.direksi')
        ->call('bukaRincianPersediaan', 'bukan_kartu')
        ->assertSet('rincianPersediaan', null);
    expect($komponen->viewData('rincian'))->toBeEmpty();

    $komponen->call('bukaRincianPersediaan', 'total_kavling')->set('cariRincian', 'LT')
        ->call('tutupRincianPersediaan')
        ->assertSet('rincianPersediaan', null)
        ->assertSet('cariRincian', '');
    expect($komponen->viewData('rincian'))->toBeEmpty();
});
