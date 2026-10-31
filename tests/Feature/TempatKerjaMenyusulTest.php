<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\SalesGrup;
use App\Models\Master\Spr;
use App\Models\Master\TempatKerja;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Tempat kerja boleh menyusul supaya konsumen bisa booking lebih dulu, tapi SPR
 * tidak boleh sampai ke konsumen sebelum nama dan alamat kantornya lengkap.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-TK', 'nama' => 'Sales Menyusul', 'telepon' => '081200000001', 'is_aktif' => true,
        'dbos_username' => 'sales-tk', 'dbos_password' => 'rahasia123',
    ]);
    $rumah = Rumah::create([
        'proyek_id' => $this->proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'TK', 'nomor_unit' => '07', 'status' => 'available',
    ]);
    $this->prospect = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'Konsumen Menyusul', 'hp' => '628120000002', 'sumber' => 'Walk-in', 'status' => 'finish',
        'nik' => '3201000000002222', 'npwp' => '12.345.678.9-012.000', 'foto_ktp' => 'prospect-ktp/contoh.jpg',
        'alamat' => 'Jl. Contoh No. 2', 'bi_kol' => '1', 'bi_dbr' => 30, 'tempat_kerja_menyusul' => true,
    ]);
    foreach ([1, 2] as $i) {
        $this->prospect->kontakDarurat()->create(['nama' => "Kontak $i", 'hubungan' => 'saudara', 'nomor_telepon' => '62813000000'.$i]);
    }
    $booking = Booking::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id, 'prospect_customer_id' => $this->prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);
    $this->spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $this->sales->id, 'prospect_customer_id' => $this->prospect->id,
        'rumah_id' => $rumah->id, 'kategori' => $tipe->kategori, 'nomor_spr' => 'SPR/2026/10/00901',
        'tanggal_spr' => now(), 'harga_jual' => 198_000_000, 'total_harga' => 198_000_000,
        'um_net' => 15_000_000, 'utj_nominal' => 0, 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'pm_approved_at' => now(),
    ]);
});

function lengkapiKantor(ProspectCustomer $prospect, ?string $alamat = 'Jl. Sudirman No. 1'): TempatKerja
{
    $perusahaan = TempatKerja::create(['nama' => 'PT Uji Kantor', 'alamat' => $alamat]);
    $prospect->update(['tempat_kerja_id' => $perusahaan->id, 'tempat_kerja_menyusul' => false]);

    return $perusahaan;
}

function halamanSprSales()
{
    return Livewire::actingAs(test()->sales, 'sales')
        ->test('pages::dbos.spr.show', ['id' => test()->spr->id]);
}

function formKonsumen()
{
    return Livewire::actingAs(test()->sales, 'sales')
        ->test('pages::dbos.database.form', ['id' => test()->prospect->id]);
}

function dashboardAdminSales()
{
    $user = User::factory()->create();
    $user->assignRole('admin-sales');

    return Livewire::actingAs($user)
        ->test('pages::dashboard.sales')
        ->call('syncFromGlobalPicker', test()->proyek->id);
}

it('meloloskan FINISH kalau tempat kerja dipilih menyusul', function () {
    expect($this->prospect->fresh()->readyForFinish())->toBeTrue();
});

it('tetap menolak FINISH kalau perusahaan dibiarkan kosong', function () {
    $this->prospect->update(['tempat_kerja_menyusul' => false]);

    expect($this->prospect->fresh()->missingForFinish())->toBe(['Perusahaan']);
});

it('menyimpan Data Menyusul sebagai penanda, bukan perusahaan di master', function () {
    $this->prospect->update(['tempat_kerja_menyusul' => false]);
    $jumlahPerusahaan = TempatKerja::count();

    formKonsumen()
        ->assertSee('(Data Menyusul)')
        ->set('tempatKerjaId', ProspectCustomer::TEMPAT_KERJA_MENYUSUL)
        ->assertSee('wajib diisi sebelum link TTD dikirim')
        ->call('save')
        ->assertHasNoErrors();

    $prospect = $this->prospect->fresh();

    expect($prospect->tempat_kerja_menyusul)->toBeTrue()
        ->and($prospect->tempat_kerja_id)->toBeNull()
        ->and(TempatKerja::count())->toBe($jumlahPerusahaan);
});

it('menampilkan kembali pilihan Data Menyusul saat form dibuka', function () {
    formKonsumen()->assertSet('tempatKerjaId', ProspectCustomer::TEMPAT_KERJA_MENYUSUL);
});

it('menghapus penanda menyusul setelah perusahaan dipilih', function () {
    $perusahaan = TempatKerja::create(['nama' => 'PT Sudah Diketahui', 'alamat' => 'Jl. Merdeka No. 9']);

    formKonsumen()
        ->set('tempatKerjaId', (string) $perusahaan->id)
        ->call('save')
        ->assertHasNoErrors();

    $prospect = $this->prospect->fresh();

    expect($prospect->tempat_kerja_id)->toBe($perusahaan->id)
        ->and($prospect->tempat_kerja_menyusul)->toBeFalse();
});

it('meminta alamat kantor kalau perusahaan terpilih belum punya alamat', function () {
    $perusahaan = TempatKerja::create(['nama' => 'PT Tanpa Alamat']);

    formKonsumen()
        ->set('tempatKerjaId', (string) $perusahaan->id)
        ->assertSee('Alamat perusahaan ini belum ada')
        ->set('alamatKantor', 'Jl. Gatot Subroto No. 3')
        ->call('save')
        ->assertHasNoErrors();

    expect($perusahaan->fresh()->alamat)->toBe('Jl. Gatot Subroto No. 3');
});

it('tidak menimpa alamat perusahaan yang sudah terisi', function () {
    $perusahaan = TempatKerja::create(['nama' => 'PT Sudah Beralamat', 'alamat' => 'Jl. Lama No. 1']);

    formKonsumen()
        ->set('tempatKerjaId', (string) $perusahaan->id)
        ->assertDontSee('Alamat perusahaan ini belum ada')
        ->set('alamatKantor', 'Jl. Baru No. 2')
        ->call('save')
        ->assertHasNoErrors();

    expect($perusahaan->fresh()->alamat)->toBe('Jl. Lama No. 1');
});

it('menolak membuat link TTD selama tempat kerja menyusul', function () {
    halamanSprSales()
        ->assertSee('Lengkapi Data Konsumen')
        ->assertDontSee('Buat Link TTD Konsumen')
        ->call('generateSigningLink');

    expect($this->spr->fresh()->konsumen_signing_link_hash)->toBeNull();
});

it('menolak membuat link TTD kalau alamat kantor masih kosong', function () {
    lengkapiKantor($this->prospect, alamat: null);

    halamanSprSales()->call('generateSigningLink');

    expect($this->spr->fresh()->konsumen_signing_link_hash)->toBeNull()
        ->and($this->spr->fresh()->missingForKonsumenSigning())->toBe(['Alamat Kantor']);
});

it('membuat link TTD setelah nama dan alamat kantor lengkap', function () {
    lengkapiKantor($this->prospect);

    halamanSprSales()
        ->assertDontSee('Lengkapi Data Konsumen')
        ->assertSee('Buat Link TTD Konsumen')
        ->call('generateSigningLink');

    expect($this->spr->fresh()->konsumen_signing_link_hash)->not->toBeNull();
});

it('tidak mematikan link lama saat perpanjangan ditolak', function () {
    $this->spr->update(['konsumen_signing_link_hash' => 'link-lama', 'konsumen_signing_link_expires_at' => now()->addHours(5)]);

    halamanSprSales()->call('regenerateSigningLink');

    expect($this->spr->fresh()->konsumen_signing_link_hash)->toBe('link-lama');
});

it('menutup link TTD kalau data kantor tidak lagi lengkap', function () {
    $this->spr->update(['konsumen_signing_link_hash' => 'link-aktif', 'konsumen_signing_link_expires_at' => now()->addHours(5)]);

    Livewire::test('pages::public.spr-sign', ['token' => 'link-aktif'])->assertSet('status', 'invalid');

    lengkapiKantor($this->prospect);

    Livewire::test('pages::public.spr-sign', ['token' => 'link-aktif'])->assertSet('status', 'ready');
});

it('tetap membolehkan PM menyetujui SPR yang datanya menyusul', function () {
    $this->spr->update(['pm_approved_at' => null]);

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');

    Livewire::actingAs($pm)
        ->test('pages::approval.spr-show', ['id' => $this->spr->id])
        ->assertSee('Data menyusul:')
        ->call('setujui');

    expect($this->spr->fresh()->pm_approved_at)->not->toBeNull();
});

it('menampilkan SPR tertahan di dashboard Admin Sales', function () {
    dashboardAdminSales()
        ->assertSee('Perlu Ditindak')
        ->assertSee($this->spr->nomor_display)
        ->assertSee('Konsumen Menyusul')
        ->assertSee('Sales Menyusul')
        ->assertSee('Perusahaan, Alamat Kantor')
        ->assertSee('Siap TTD, tertahan data')
        ->assertSeeHtml('https://wa.me/6281200000001');
});

it('memberi tahu kalau nomor sales belum ada', function () {
    $this->sales->update(['telepon' => null]);

    dashboardAdminSales()->assertSee('HP sales kosong')->assertDontSee('WA Sales');
});

it('tidak menampilkan SPR yang sudah ditandatangani konsumen', function () {
    $this->spr->update(['konsumen_signed_at' => now()]);

    dashboardAdminSales()
        ->assertSee('Semua SPR berjalan datanya sudah lengkap.')
        ->assertDontSee('Siap TTD, tertahan data');
});

it('tidak menampilkan SPR yang datanya sudah lengkap', function () {
    lengkapiKantor($this->prospect);

    dashboardAdminSales()->assertSee('Semua SPR berjalan datanya sudah lengkap.');
});

it('menandai merah SPR yang tertahan lebih dari 3 hari', function (int $hari, bool $merah) {
    $this->spr->update(['tanggal_spr' => now()->subDays($hari)]);

    $halaman = dashboardAdminSales()->assertSee($hari.' hari');

    $merah ? $halaman->assertSeeHtml('text-rose-600') : $halaman->assertDontSeeHtml('text-rose-600');
})->with([
    'tepat 3 hari' => [3, false],
    'sudah 4 hari' => [4, true],
]);

it('mengingatkan sales di beranda DBOS', function () {
    Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.home')
        ->assertSee('1 SPR belum bisa dikirim TTD');
});

it('mengingatkan pimpinan grup di beranda pimpinan', function () {
    $pimpinan = Sales::create([
        'kode' => 'SLS-PMP', 'nama' => 'Pimpinan Grup', 'is_aktif' => true,
        'dbos_username' => 'pimpinan-tk', 'dbos_password' => 'rahasia123',
    ]);
    $grup = SalesGrup::create(['nama' => 'Grup Uji', 'pimpinan_id' => $pimpinan->id]);
    $pimpinan->update(['sales_grup_id' => $grup->id]);
    $this->sales->update(['sales_grup_id' => $grup->id]);

    Livewire::actingAs($pimpinan, 'sales')
        ->test('pages::dbos.pimpinan.home')
        ->assertSee('Ingatkan anggota: 1 SPR');
});
