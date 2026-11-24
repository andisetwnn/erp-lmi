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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Input Pemberkasan sebagai satu-satunya tempat Admin KPR mencatat tahapan KPR:
 * tab proses/akad, ringkasan per tahap, isi tanggal langsung di sel, peringatan
 * urutan tanggal, pengurutan kolom, dan lembar SP3K.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();
    $this->sales = Sales::create([
        'kode' => 'SLS-EFS', 'nama' => 'Sales Efisien', 'is_aktif' => true,
        'dbos_username' => 'sales-efs', 'dbos_password' => 'rahasia123',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-kpr');
    $this->actingAs($this->admin);
});

function sprPemberkasan(string $blok, string $nomor, string $status = 'approved', array $berkas = []): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'terjual',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => "KONSUMEN $blok$nomor", 'hp' => '628100002222',
        'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);
    $spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => "SPR/2026/10/07$nomor",
        'tanggal_spr' => now(), 'jenis_pembayaran' => 'kpr', 'status' => $status,
        'harga_jual' => 198_000_000, 'total_harga' => 198_000_000, 'um_net' => 15_000_000,
    ]);

    if ($berkas !== []) {
        SprPemberkasan::create(['spr_id' => $spr->id] + $berkas);
    }

    return $spr;
}

function halamanPemberkasan()
{
    return Livewire::withQueryParams(['proyek' => test()->proyek->id])->test('pages::pemberkasan.input');
}

it('menampilkan SPR yang masih berproses saja secara bawaan', function () {
    sprPemberkasan('EA', '01');
    sprPemberkasan('EA', '02', 'akad');

    $halaman = halamanPemberkasan();

    expect($halaman->get('tab'))->toBe('proses')
        ->and($halaman->viewData('jumlahTab'))->toBe(['proses' => 1, 'akad' => 1, 'semua' => 2]);
    $halaman->assertSee('KONSUMEN EA01')->assertDontSee('KONSUMEN EA02');

    $halaman->call('pilihTab', 'akad')->assertSee('KONSUMEN EA02')->assertDontSee('KONSUMEN EA01');
});

it('tidak menghitung belum berkas bila tahap sesudahnya sudah jalan', function () {
    // Dulu SPR yang sudah wawancara ikut terhitung "belum berkas" hanya karena
    // tanggal berkas lengkapnya tidak pernah dicatat.
    sprPemberkasan('EB', '01');
    sprPemberkasan('EB', '02', 'approved', ['wcr_tanggal' => '2026-10-01']);

    $halaman = halamanPemberkasan();

    expect($halaman->viewData('ringkasan')['belum_berkas'])->toBe(1)
        ->and($halaman->viewData('ringkasan')['belum_sp3k'])->toBe(1);

    $halaman->call('pakaiTahap', 'belum_berkas')
        ->assertSee('KONSUMEN EB01')
        ->assertDontSee('KONSUMEN EB02');

    halamanPemberkasan()->assertSee('tidak tercatat');
});

it('mengisi tanggal langsung di sel tanpa membuka jendela', function () {
    $spr = sprPemberkasan('EC', '01');

    halamanPemberkasan()
        ->call('mulaiInline', $spr->id, 'bm')
        ->set('inlineValue', '2026-10-05')
        ->call('simpanInline')
        ->assertHasNoErrors()
        ->assertSet('inlineSprId', null);

    expect(SprPemberkasan::where('spr_id', $spr->id)->value('bm_tanggal')->toDateString())->toBe('2026-10-05');
});

it('menahan tanggal yang urutannya terbalik sampai dikonfirmasi', function () {
    $spr = sprPemberkasan('ED', '01', 'approved', ['bm_tanggal' => '2026-10-10']);

    $halaman = halamanPemberkasan()
        ->call('mulaiInline', $spr->id, 'wcr')
        ->set('inlineValue', '2026-10-01')
        ->call('simpanInline');

    expect($halaman->get('peringatanUrutan'))->toHaveCount(1)
        ->and(SprPemberkasan::where('spr_id', $spr->id)->value('wcr_tanggal'))->toBeNull();

    $halaman->call('simpanInline', true);

    expect(SprPemberkasan::where('spr_id', $spr->id)->value('wcr_tanggal')->toDateString())->toBe('2026-10-01');
});

it('memperingatkan tanggal yang belum terjadi di jendela lengkap', function () {
    $spr = sprPemberkasan('EE', '01');

    $halaman = halamanPemberkasan()
        ->call('openField', $spr->id, 'sp3k')
        ->set('val_sp3k_tanggal', now()->addDays(10)->toDateString())
        ->set('val_sp3k_nominal', '179.000.000')
        ->call('simpanField');

    expect($halaman->get('peringatanUrutan'))->not->toBeEmpty()
        ->and(SprPemberkasan::where('spr_id', $spr->id)->exists())->toBeFalse();

    $halaman->call('simpanField', true)->assertHasNoErrors();

    expect(SprPemberkasan::where('spr_id', $spr->id)->value('sp3k_tanggal'))->not->toBeNull();
});

it('menyaring SPR yang urutan tanggalnya terbalik', function () {
    sprPemberkasan('EF', '01', 'approved', ['bm_tanggal' => '2026-09-01', 'wcr_tanggal' => '2026-09-10']);
    sprPemberkasan('EF', '02', 'approved', ['bm_tanggal' => '2026-09-20', 'wcr_tanggal' => '2026-09-10']);

    halamanPemberkasan()
        ->call('pakaiTahap', 'tidak_runtut')
        ->assertSee('KONSUMEN EF02')
        ->assertDontSee('KONSUMEN EF01');
});

it('mengurutkan lewat kolom tanggal tahapan', function () {
    sprPemberkasan('EG', '01', 'approved', ['sp3k_tanggal' => '2026-10-20']);
    sprPemberkasan('EG', '02', 'approved', ['sp3k_tanggal' => '2026-08-05']);

    $nama = fn ($h) => $h->viewData('sprs')->getCollection()->map(fn ($s) => $s->prospectCustomer->nama_lengkap)->values()->all();

    $halaman = halamanPemberkasan()->call('sort', 'sp3k');
    expect($nama($halaman))->toBe(['KONSUMEN EG02', 'KONSUMEN EG01']);

    $halaman->call('sort', 'sp3k');
    expect($nama($halaman))->toBe(['KONSUMEN EG01', 'KONSUMEN EG02']);
});

it('menyimpan lembar SP3K bersama tanggalnya', function () {
    Storage::fake('private');
    $spr = sprPemberkasan('EH', '01');

    halamanPemberkasan()
        ->call('openField', $spr->id, 'sp3k')
        ->set('val_sp3k_tanggal', '2026-10-15')
        ->set('val_sp3k_nominal', '179.000.000')
        ->set('uploadFile', UploadedFile::fake()->create('SP3K EH-01.pdf', 120, 'application/pdf'))
        ->call('simpanField')
        ->assertHasNoErrors();

    $berkas = SprPemberkasan::where('spr_id', $spr->id)->first();

    expect($berkas->sp3k_file_original_name)->toBe('SP3K EH-01.pdf');
    Storage::disk('private')->assertExists($berkas->sp3k_file_path);
});
