<?php

use App\Models\Master\Booking;
use App\Models\Master\PromoPenjualan;
use App\Models\Master\PromoPenjualanTingkat;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\SalesGrup;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\PromoPenjualanService;
use App\Services\RencanaAkadService;
use Database\Seeders\PromoOktober2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Promo Penjualan — ditanggung developer, dikelola Admin Sales.
 *
 * Angka di SPR tidak diubah: UTJ dan UM tetap nominal normal. Yang dicatat
 * potongannya, dan yang dibayar konsumen dihitung dari situ. Satu SPR satu
 * promo, dan promo dalam satu proyek tidak boleh berjalan bersamaan.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();
    $this->sales = Sales::create([
        'kode' => 'SLS-P', 'nama' => 'Sales Promo', 'is_aktif' => true,
        'dbos_username' => 'sales-promo', 'dbos_password' => 'rahasia123',
    ]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-sales');

    $this->svc = app(PromoPenjualanService::class);
});

function unitPromo(string $blok, string $nomor = '1'): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'available',
    ]);
}

/** SPR dengan UTJ 5 juta, UM 15 juta, dan dua termin cicilan 5 juta. */
function sprPromo(Rumah $rumah, string $tanggal = '2026-11-10', string $lahir = '2003-01-01'): Spr
{
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'Konsumen '.$rumah->blok,
        'nik' => '32'.str_pad((string) crc32($rumah->blok.$rumah->nomor_unit), 14, '0'),
        'hp' => '628100000000', 'sumber' => 'Walk-in', 'status' => 'finish', 'tanggal_lahir' => $lahir,
    ]);
    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => $tanggal, 'tanggal_expired' => $tanggal, 'status' => 'sukses',
    ]);
    $spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => 'SPR/PROMO/'.$rumah->id, 'tanggal_spr' => $tanggal,
        'harga_jual' => 166_000_000, 'total_harga' => 166_000_000,
        'um_net' => 15_000_000, 'utj_nominal' => 5_000_000,
        'jenis_pembayaran' => 'kpr', 'status' => 'submitted',
    ]);
    $spr->terminPembayaran()->create(['jenis' => 'bf', 'urutan' => 0, 'jumlah_jadwal' => 5_000_000]);
    $spr->terminPembayaran()->create(['jenis' => 'um', 'urutan' => 1, 'jumlah_jadwal' => 5_000_000]);
    $spr->terminPembayaran()->create(['jenis' => 'um', 'urutan' => 2, 'jumlah_jadwal' => 5_000_000]);

    return $spr->fresh();
}

function promoUm(array $ubah = []): PromoPenjualan
{
    return test()->svc->simpan(array_merge([
        'proyek_id' => test()->proyek->id, 'nama' => 'Promo 11.11', 'jenis' => 'um',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-11-30',
        'potongan_um' => 900_000, 'semua_unit' => true,
    ], $ubah), [], [], test()->admin->id);
}

function promoUsia(array $tingkat, string $dasar = 'usia'): PromoPenjualan
{
    return test()->svc->simpan([
        'proyek_id' => test()->proyek->id, 'nama' => 'Rumah di Masa Muda', 'jenis' => 'booking_fee',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-11-30',
        'dasar_usia' => $dasar, 'semua_unit' => true,
    ], [], $tingkat, test()->admin->id);
}

// ─────────────── Masa berlaku ───────────────

it('menolak promo yang tanggalnya beririsan di proyek yang sama', function () {
    promoUm();

    expect(fn () => promoUm(['nama' => 'Promo Lain', 'berlaku_mulai' => '2026-11-20', 'berlaku_sampai' => '2026-12-05']))
        ->toThrow(ValidationException::class);
});

it('membolehkan promo berikutnya yang tidak beririsan', function () {
    promoUm();
    $desember = promoUm(['nama' => 'Promo 12.12', 'berlaku_mulai' => '2026-12-01', 'berlaku_sampai' => '2026-12-31']);

    expect($desember->exists)->toBeTrue();
});

it('tidak menghitung promo nonaktif sebagai bentrok', function () {
    promoUm()->update(['is_aktif' => false]);

    expect(promoUm(['nama' => 'Pengganti'])->exists)->toBeTrue();
});

// ─────────────── Hitungan potongan ───────────────

it('memotong UM nominal tetap tanpa mengubah angka di SPR', function () {
    $promo = promoUm();
    $spr = sprPromo(unitPromo('PA'));

    $this->svc->lampirkan($spr, $promo, 'terlewat saat input', $this->admin->id);
    $spr = $spr->fresh();

    expect((float) $spr->um_net)->toBe(15_000_000.0)          // dokumen tetap
        ->and((float) $spr->utj_nominal)->toBe(5_000_000.0)
        ->and((float) $spr->promo_potongan_um)->toBe(900_000.0)
        ->and($spr->umKonsumen())->toBe(14_100_000.0)
        ->and($spr->utjKonsumen())->toBe(5_000_000.0);
});

it('menghitung booking fee dari usia konsumen pada tanggal UTJ', function () {
    // Usia 23 → booking fee 230 ribu; UTJ normal 5 juta, ditanggung developer 4,77 juta.
    $tingkat = collect(range(23, 40))->map(fn ($u) => ['dari' => $u, 'sampai' => $u, 'booking_fee' => $u * 10_000])->all();
    $promo = promoUsia($tingkat);
    $spr = sprPromo(unitPromo('PB'), '2026-11-10', '2003-05-01');   // 23 tahun pada 10 Nov 2026

    $this->svc->lampirkan($spr, $promo, 'promo usia', $this->admin->id);
    $spr = $spr->fresh();

    expect((float) $spr->promo_potongan_utj)->toBe(4_770_000.0)
        ->and($spr->utjKonsumen())->toBe(230_000.0)
        ->and($spr->umKonsumen())->toBe(10_230_000.0);
});

it('membaca tabel berdasarkan tahun lahir', function () {
    $promo = promoUsia([
        ['dari' => 1997, 'sampai' => 2005, 'booking_fee' => 300_000, 'label' => 'Gen Z'],
        ['dari' => 1981, 'sampai' => 1996, 'booking_fee' => 500_000, 'label' => 'Milenial'],
    ], 'tahun_lahir');

    $hasil = $this->svc->hitung($promo, now()->setDate(2026, 11, 10), now()->setDate(1990, 3, 1), 5_000_000, 15_000_000);

    expect($hasil['potongan_utj'])->toBe(4_500_000.0)
        ->and($hasil['keterangan'])->toBe('Rumah di Masa Muda (Milenial)');
});

it('tidak memberi promo usia kepada konsumen di luar tabel', function () {
    $promo = promoUsia([['dari' => 23, 'sampai' => 40, 'booking_fee' => 300_000]]);
    $spr = sprPromo(unitPromo('PC'), '2026-11-10', '1970-01-01');   // 56 tahun

    expect(fn () => $this->svc->lampirkan($spr, $promo, 'coba', $this->admin->id))
        ->toThrow(ValidationException::class);
});

it('hanya berlaku untuk unit pilihan kalau tidak semua unit', function () {
    $ikut = unitPromo('PD', '1');
    $tidak = unitPromo('PD', '2');

    $promo = $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo Hook', 'jenis' => 'um',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-11-30',
        'potongan_um' => 900_000, 'semua_unit' => false,
    ], [$ikut->id], [], $this->admin->id);

    expect($this->svc->untuk($ikut, '2026-11-10', null, 5_000_000, 15_000_000)['promo']->id)->toBe($promo->id)
        ->and($this->svc->untuk($tidak, '2026-11-10', null, 5_000_000, 15_000_000))->toBeNull();
});

// ─────────────── Promo susulan ───────────────

it('mengurangi termin terakhir yang belum dibayar saat promo dilampirkan', function () {
    $spr = sprPromo(unitPromo('PE'));
    $this->svc->lampirkan($spr, promoUm(), 'terlewat', $this->admin->id);

    $termin = $spr->terminPembayaran()->where('jenis', 'um')->reorder('urutan')->pluck('jumlah_jadwal')
        ->map(fn ($v) => (float) $v)->all();

    expect($termin)->toBe([5_000_000.0, 4_100_000.0]);
});

it('mengembalikan termin saat promo dilepas', function () {
    $spr = sprPromo(unitPromo('PF'));
    $this->svc->lampirkan($spr, promoUm(), 'terlewat', $this->admin->id);
    $this->svc->lepas($spr->fresh(), 'salah lampir', $this->admin->id);

    $spr = $spr->fresh();

    expect($spr->promo_penjualan_id)->toBeNull()
        ->and($spr->umKonsumen())->toBe(15_000_000.0)
        ->and((float) $spr->terminPembayaran()->where('jenis', 'um')->where('urutan', 2)->value('jumlah_jadwal'))->toBe(5_000_000.0);
});

it('menolak promo kedua pada SPR yang sama', function () {
    $spr = sprPromo(unitPromo('PG'));
    $this->svc->lampirkan($spr, promoUm(), 'pertama', $this->admin->id);

    expect(fn () => $this->svc->lampirkan($spr->fresh(), PromoPenjualan::first(), 'kedua', $this->admin->id))
        ->toThrow(ValidationException::class);
});

it('menolak promo kalau tanggal UTJ di luar masa berlaku', function () {
    $spr = sprPromo(unitPromo('PH'), '2026-10-20');

    expect(fn () => $this->svc->lampirkan($spr, promoUm(), 'coba', $this->admin->id))
        ->toThrow(ValidationException::class);
});

it('mengunci tanggal dan nominal promo yang sudah dipakai SPR', function () {
    $promo = promoUm();
    $this->svc->lampirkan(sprPromo(unitPromo('PI')), $promo, 'pakai', $this->admin->id);

    expect(fn () => $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo 11.11', 'jenis' => 'um',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-12-15',
        'potongan_um' => 900_000, 'semua_unit' => true,
    ], [], [], $this->admin->id, $promo))->toThrow(ValidationException::class);
});

// ─────────────── Layar ───────────────

it('membuka menu Promo Penjualan untuk Admin Sales saja', function () {
    $this->actingAs($this->admin)->get(route('marketing.promo.index'))->assertOk();

    $teknik = User::factory()->create();
    $teknik->assignRole('admin-teknik');
    $this->actingAs($teknik)->get(route('marketing.promo.index'))->assertForbidden();
});

it('membuat promo lewat layar', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test('pages::marketing.promo-penjualan')
        ->set('proyekId', $this->proyek->id)
        ->call('buat')
        ->set('nama', 'Promo 11.11')
        ->set('jenis', 'um')
        ->set('mulai', '2026-11-01')
        ->set('sampai', '2026-11-30')
        ->set('potonganUm', '900.000')
        ->call('simpan')
        ->assertHasNoErrors();

    expect((float) PromoPenjualan::first()->potongan_um)->toBe(900_000.0);
});

it('menampilkan bentrok tanggal di form, bukan menyimpan', function () {
    promoUm();

    Livewire\Livewire::actingAs($this->admin)
        ->test('pages::marketing.promo-penjualan')
        ->set('proyekId', $this->proyek->id)
        ->call('buat')
        ->set('nama', 'Promo Tabrak')
        ->set('mulai', '2026-11-15')
        ->set('sampai', '2026-12-15')
        ->set('potonganUm', '500.000')
        ->call('simpan')
        ->assertHasErrors(['mulai']);

    expect(PromoPenjualan::count())->toBe(1);
});

it('melampirkan promo susulan dari detail SPR', function () {
    $promo = promoUm();
    $spr = sprPromo(unitPromo('PJ'));

    Livewire\Livewire::actingAs($this->admin)
        ->test('pages::marketing.spr-show', ['id' => $spr->id])
        ->call('bukaPromo')
        ->assertSet('promoPilihId', $promo->id)
        ->set('promoAlasan', 'promo belum terinput saat SPR dibuat')
        ->call('lampirkanPromo')
        ->assertHasNoErrors();

    expect($spr->fresh()->promo_penjualan_id)->toBe($promo->id);
});

it('memperhitungkan promo di kekurangan UM rencana akad', function () {
    $spr = sprPromo(unitPromo('PK'));
    $this->svc->lampirkan($spr, promoUm(), 'terlewat', $this->admin->id);

    $um = app(RencanaAkadService::class)->ringkasanUm($spr->fresh());

    expect($um['seharusnya'])->toBe(14_100_000.0);
});

it('mengusulkan nominal UTJ setelah promo saat Finance konfirmasi', function () {
    $tingkat = [['dari' => 18, 'sampai' => 40, 'booking_fee' => 300_000]];
    $spr = sprPromo(unitPromo('PL'), '2026-11-10', '2000-01-01');
    $this->svc->lampirkan($spr, promoUsia($tingkat), 'promo usia', $this->admin->id);

    $finance = User::factory()->create();
    $finance->assignRole('finance');

    Livewire\Livewire::actingAs($finance)
        ->test('pages::finance.penerimaan-konsumen')
        ->call('openKonfirmasi', $spr->id)
        ->assertSet('confirmNominalAktual', '300000')
        // Input tanggal bawaan browser — Flatpickr tanpa wire:ignore merusak isinya.
        ->assertSeeHtml('type="date"')
        ->set('confirmTanggalTransaksi', '2026-11-10')
        ->call('submitKonfirmasi')
        ->assertHasNoErrors();

    $bf = $spr->realisasiPembayaran()->where('jenis', 'bf')->sole();

    expect($bf->tanggal_bayar->toDateString())->toBe('2026-11-10')
        ->and((float) $bf->jumlah)->toBe(300_000.0);
});

it('mendeteksi promo otomatis di form SPR sales', function () {
    $promo = promoUm(['berlaku_mulai' => now()->toDateString(), 'berlaku_sampai' => now()->addDays(10)->toDateString()]);
    $rumah = unitPromo('PM');

    $prospect = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Konsumen PM', 'nik' => '3201000000009999', 'hp' => '628111',
        'sumber' => 'Walk-in', 'status' => 'booking', 'tanggal_lahir' => '1995-01-01',
    ]);
    $booking = Booking::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'aktif',
    ]);

    $form = Livewire\Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.spr.form', ['bookingId' => $booking->id])
        ->instance();

    if ($form->isKomersial) {
        $this->markTestSkipped('Tipe bawaan seeder komersial — promo UM tidak berlaku.');
    }

    $tanpaPromo = max(0, $form->umNet - (float) $form->utjNominal);

    expect($form->promo['promo']->id)->toBe($promo->id)
        ->and($form->sisaCicil)->toBe(max(0, $tanpaPromo - $form->promo['potongan_um']));
});

it('menyemai promo Oktober sekali per proyek tanpa menggandakan', function () {
    $this->seed(PromoOktober2026Seeder::class);
    $this->seed(PromoOktober2026Seeder::class);

    expect(PromoPenjualan::count())->toBe(2 * Proyek::count())
        ->and(PromoPenjualan::where('semua_unit', false)->count())->toBe(0);
});

it('memberi booking fee KEJORA kepada semua usia', function () {
    $this->seed(PromoOktober2026Seeder::class);

    // Usia 23–45 di lembar KEJORA hanya sasaran pemasaran: konsumen 51 tahun
    // tetap cukup membayar booking fee 101 ribu.
    $spr = sprPromo(unitPromo('KJ'), '2026-10-10', '1975-01-01');
    $hasil = $this->svc->untuk($spr->rumah, '2026-10-10', $spr->prospectCustomer->tanggal_lahir, 5_000_000, 15_000_000);

    expect($hasil['promo']->nama)->toBe('Promo KEJORA 10.10')
        ->and($hasil['promo']->labelJenis())->toBe('Booking fee spesial')
        ->and($hasil['promo']->tingkat)->toHaveCount(0)
        ->and($hasil['potongan_utj'])->toBe(4_899_000.0)
        ->and($this->svc->untuk($spr->rumah, '2026-10-12', $spr->prospectCustomer->tanggal_lahir, 5_000_000, 15_000_000))->toBeNull();
});

it('memberi booking fee spesial tanpa membaca tanggal lahir', function () {
    $promo = $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'BF Spesial', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-11-30',
        'booking_fee_tetap' => 101_000, 'semua_unit' => true,
    ], [], [], $this->admin->id);

    $hasil = $this->svc->untuk(unitPromo('BT'), '2026-11-10', null, 5_000_000, 15_000_000);

    expect($promo->dasar_usia)->toBeNull()
        ->and($hasil['potongan_utj'])->toBe(4_899_000.0)
        ->and($hasil['potongan_um'])->toBe(0.0)
        // UTJ normal yang sudah di bawah booking fee promo tidak mendapat apa-apa.
        ->and($this->svc->untuk(unitPromo('BU'), '2026-11-10', null, 100_000, 15_000_000))->toBeNull();
});

it('membuat promo booking fee spesial lewat layar dan mewajibkan nominalnya', function () {
    $layar = Livewire\Livewire::actingAs($this->admin)
        ->test('pages::marketing.promo-penjualan')
        ->set('proyekId', $this->proyek->id)
        ->call('buat')
        ->set('nama', 'Promo KEJORA')
        ->set('jenis', 'booking_fee_tetap')
        ->set('mulai', '2026-11-01')
        ->set('sampai', '2026-11-02')
        ->call('simpan')
        ->assertHasErrors('bookingFeeTetap');

    $layar->set('bookingFeeTetap', '101.000')
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertSee('Booking fee spesial');

    expect((float) PromoPenjualan::first()->booking_fee_tetap)->toBe(101_000.0);
});

it('memberi booking fee MUMPUNI sesuai usia, hanya 23 sampai 40 tahun', function (string $lahir, ?float $bookingFee) {
    $this->seed(PromoOktober2026Seeder::class);

    $hasil = $this->svc->untuk(unitPromo('MP'), '2026-10-28', Carbon::parse($lahir), 5_000_000, 15_000_000);

    if ($bookingFee === null) {
        expect($hasil)->toBeNull();

        return;
    }

    expect($hasil['promo']->nama)->toBe('MUMPUNI: Muda Punya Rumah Sendiri')
        ->and(5_000_000 - $hasil['potongan_utj'])->toBe($bookingFee);
})->with([
    'usia 23 → 230 ribu' => ['2003-01-01', 230_000.0],
    'usia 40 → 400 ribu' => ['1986-01-01', 400_000.0],
    'usia 22 → booking fee normal' => ['2004-01-01', null],
    'usia 41 → booking fee normal' => ['1985-01-01', null],
]);

it('menyajikan promo booking fee sebagai potongan uang muka di form SPR sales', function () {
    $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo KEJORA', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => now()->toDateString(), 'berlaku_sampai' => now()->addDays(10)->toDateString(),
        'booking_fee_tetap' => 101_000, 'semua_unit' => true,
    ], [], [], $this->admin->id);
    $rumah = unitPromo('PK');

    $prospect = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Konsumen PK', 'nik' => '3201000000008888', 'hp' => '628112',
        'sumber' => 'Walk-in', 'status' => 'booking', 'tanggal_lahir' => '1995-01-01',
    ]);
    $booking = Booking::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'aktif',
    ]);

    $layar = Livewire\Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.spr.form', ['bookingId' => $booking->id]);
    $form = $layar->instance();

    if ($form->isKomersial || (float) $form->utjNominal <= 101_000) {
        $this->markTestSkipped('Tipe bawaan seeder tidak mendapat promo booking fee.');
    }

    $potongan = (float) $form->utjNominal - 101_000;

    // UTJ di SPR tetap normal; yang ditransfer saat booking cukup 101 ribu, dan
    // selisihnya terlihat sebagai potongan uang muka.
    expect($form->promo['potongan_utj'])->toBe($potongan);
    $layar->set('currentStep', 2)
        ->assertSee('Potongan uang muka (promo)')
        ->assertSee('Rp '.number_format($form->umNet - $potongan, 0, ',', '.'))
        ->assertSee('Transfer booking fee')
        ->assertSee('Rp 101.000')
        ->assertSee('Potongan diambil dari booking fee.');

    // Langkah 3: jadwal termin dan ringkasan juga menyebut nominal transfer setelah promo.
    $layar->set('currentStep', 3)
        ->assertSee('UTJ Rp '.number_format((float) $form->utjNominal, 0, ',', '.').' · promo')
        ->assertSee('Transfer booking fee')
        ->assertSee('UM dibayar konsumen')
        ->assertSee('Rp '.number_format($form->umNet - $potongan, 0, ',', '.'));
});

it('menyajikan promo di detail SPR sebagai potongan uang muka beserta transfer booking', function () {
    $promo = $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo KEJORA', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-11-30',
        'booking_fee_tetap' => 101_000, 'semua_unit' => true,
    ], [], [], $this->admin->id);
    $spr = sprPromo(unitPromo('PS'));
    $this->svc->lampirkan($spr, $promo, 'promo terlewat', $this->admin->id);

    Livewire\Livewire::actingAs($this->admin)
        ->test('pages::marketing.spr-show', ['id' => $spr->id])
        ->set('activeTab', 'rincian')
        ->assertSee('Potongan uang muka (developer)')
        ->assertSee('4.899.000')
        ->assertSee('10.101.000')
        ->assertSee('Transfer booking fee')
        ->assertSee('101.000')
        ->assertSee('Diambil dari booking fee.')
        ->assertDontSee('Promo UTJ (developer)');
});

it('menampilkan potongan uang muka dan transfer booking di detail SPR DBOS', function () {
    $promo = $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo KEJORA', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-11-30',
        'booking_fee_tetap' => 101_000, 'semua_unit' => true,
    ], [], [], $this->admin->id);
    $spr = sprPromo(unitPromo('PD'));
    $this->svc->lampirkan($spr, $promo, 'promo terlewat', $this->admin->id);

    Livewire\Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.spr.show', ['id' => $spr->id])
        ->assertSee('Promo KEJORA')
        ->assertSee('Potongan uang muka (promo)')
        ->assertSee('Rp 4.899.000')
        ->assertSee('UM dibayar konsumen')
        ->assertSee('Rp 10.101.000')
        ->assertSee('Transfer booking fee')
        ->assertSee('Rp 101.000');
});

it('menganggap UTJ lunas begitu booking fee promo diterima Finance', function () {
    $promo = $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo KEJORA', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => '2026-11-01', 'berlaku_sampai' => '2026-11-30',
        'booking_fee_tetap' => 101_000, 'semua_unit' => true,
    ], [], [], $this->admin->id);
    $spr = sprPromo(unitPromo('PL'));
    $this->svc->lampirkan($spr, $promo, 'promo terlewat', $this->admin->id);

    // Seperti konfirmasi Finance: yang diterima hanya booking fee promo, bukan UTJ penuh.
    SprRealisasiPembayaran::create([
        'spr_id' => $spr->id, 'jenis' => 'bf', 'tanggal_bayar' => '2026-11-10', 'jumlah' => 101_000,
        'nomor_kwitansi' => SprRealisasiPembayaran::generateNextNomor(), 'metode' => 'transfer',
    ]);
    $spr->update(['utj_confirmed_at' => now(), 'utj_tanggal_transaksi' => '2026-11-10']);

    Livewire\Livewire::actingAs($this->admin)
        ->test('pages::marketing.spr-show', ['id' => $spr->id])
        ->set('activeTab', 'rincian')
        ->assertSeeHtml('title="Sudah cair"')
        ->assertSee('UTJ 5.000.000 · promo')
        ->assertSee('Potongan promo (developer)')
        ->assertSee('10.101.000');   // kewajiban UM setelah promo, bukan 15 juta

    Livewire\Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.spr.show', ['id' => $spr->id])
        ->assertSee('Lunas')
        ->assertDontSee('Partial')
        ->assertSee('UTJ Rp 5.000.000 · promo');
});

it('memilah promo untuk sales: yang berjalan dan yang segera dimulai', function () {
    $buat = fn (string $nama, string $mulai, string $sampai, bool $aktif = true) => $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => $nama, 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => $mulai, 'berlaku_sampai' => $sampai, 'booking_fee_tetap' => 101_000,
        'semua_unit' => true, 'is_aktif' => $aktif,
    ], [], [], $this->admin->id);

    $buat('Sudah lewat', '2026-10-10', '2026-10-11');
    $buat('Sedang jalan', '2026-10-27', '2026-10-31');
    $buat('Nonaktif', '2026-10-28', '2026-10-29', aktif: false);
    $buat('Bulan depan', '2026-11-11', '2026-11-12');
    $buat('Masih jauh', '2027-01-01', '2027-01-02');

    $daftar = $this->svc->untukSales(Carbon::parse('2026-10-28'));

    expect($daftar['berjalan']->pluck('nama')->all())->toBe(['Sedang jalan'])
        ->and($daftar['akanDatang']->pluck('nama')->all())->toBe(['Bulan depan']);
});

it('meringkas potongan promo dalam satu kalimat untuk sales', function () {
    // Promo usia tidak disimpan: tanggalnya akan bertabrakan dengan promo UM di proyek yang sama.
    $usia = new PromoPenjualan(['jenis' => 'booking_fee', 'dasar_usia' => 'usia']);
    $usia->setRelation('tingkat', collect([
        new PromoPenjualanTingkat(['dari' => 23, 'sampai' => 23, 'booking_fee' => 230_000]),
        new PromoPenjualanTingkat(['dari' => 24, 'sampai' => 40, 'booking_fee' => 400_000]),
    ]));

    expect(promoUm()->ringkasanPotongan())->toBe('Potongan UM Rp 900.000')
        ->and($usia->ringkasanPotongan())->toBe('Booking fee sesuai usia 23–40 th · Rp 230.000 – Rp 400.000');
});

it('menampilkan promo berjalan dan akan datang di beranda DBOS sales dan pimpinan', function () {
    $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo Pekan Ini', 'jenis' => 'booking_fee_tetap',
        'berlaku_mulai' => now()->toDateString(), 'berlaku_sampai' => now()->addDays(3)->toDateString(),
        'booking_fee_tetap' => 101_000, 'semua_unit' => true, 'keterangan' => 'Kejar promonya',
    ], [], [], $this->admin->id);
    $this->svc->simpan([
        'proyek_id' => $this->proyek->id, 'nama' => 'Promo Berikutnya', 'jenis' => 'um',
        'berlaku_mulai' => now()->addDays(10)->toDateString(), 'berlaku_sampai' => now()->addDays(12)->toDateString(),
        'potongan_um' => 900_000, 'semua_unit' => true,
    ], [], [], $this->admin->id);

    Livewire\Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.home')
        ->assertSee('Promo Penjualan')
        ->assertSee('Promo Pekan Ini')
        ->assertSee('Berjalan · sisa 4 hari')
        ->assertSee('Booking fee Rp 101.000 · semua konsumen')
        ->assertSee('Kejar promonya')
        ->assertSee('Akan datang')
        ->assertSee('Promo Berikutnya')
        ->assertSee('Potongan UM Rp 900.000');

    $grup = SalesGrup::create(['nama' => 'Tim Promo']);
    $pimpinan = Sales::create([
        'kode' => 'SLS-PP', 'nama' => 'Pimpinan Promo', 'sales_grup_id' => $grup->id, 'is_aktif' => true,
        'dbos_username' => 'pimpinan-promo', 'dbos_password' => 'rahasia123',
    ]);
    $grup->update(['pimpinan_id' => $pimpinan->id]);

    $this->actingAs($pimpinan, 'sales')
        ->get(route('dbos.pimpinan.home'))
        ->assertOk()
        ->assertSee('Promo Pekan Ini');
});

it('tidak menampilkan kartu promo di beranda DBOS kalau tidak ada promo', function () {
    Livewire\Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.home')
        ->assertDontSee('Promo Penjualan');
});
