<?php

use App\Models\Master\Proyek;
use App\Models\User;
use App\Support\ProyekAktif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Proyek aktif — picker di sidebar adalah satu-satunya sumber.
 *
 * Halaman yang datanya milik satu proyek tidak boleh menampilkan apa pun sebelum
 * proyek dipilih. Sekarang baru satu proyek, jadi kebocoran ke "semua proyek"
 * tidak terlihat; begitu ada proyek kedua, angka gabungan itu menyesatkan.
 *
 * Pengecualian yang disengaja: Dashboard Direksi (ringkasan lintas proyek),
 * Akunting (pembukuan per perusahaan), dan Matrix (belum terhubung ke proyek).
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->proyek = Proyek::first();
    session()->forget(ProyekAktif::SESI);
});

it('mengganti picker saat halaman dibuka lewat link yang membawa proyek', function () {
    expect(ProyekAktif::sinkron($this->proyek->id))->toBe($this->proyek->id)
        ->and(session(ProyekAktif::SESI))->toBe($this->proyek->id);
});

it('mengabaikan link yang membawa proyek yang tidak ada', function () {
    session([ProyekAktif::SESI => $this->proyek->id]);

    expect(ProyekAktif::sinkron(999999))->toBe($this->proyek->id);
});

it('memakai picker kalau link tidak membawa proyek', function () {
    expect(ProyekAktif::sinkron())->toBeNull();

    session([ProyekAktif::SESI => $this->proyek->id]);

    expect(ProyekAktif::sinkron())->toBe($this->proyek->id);
});

it('meminta proyek dipilih dulu di setiap halaman per proyek', function (string $halaman) {
    Livewire::actingAs($this->admin)
        ->test($halaman)
        ->assertSee('Pilih proyek');
})->with([
    'dashboard executive' => 'pages::dashboard.executive',
    'dashboard finance' => 'pages::dashboard.finance',
    'dashboard kpr' => 'pages::dashboard.kpr',
    'dashboard pm' => 'pages::dashboard.pm',
    'dashboard sales' => 'pages::dashboard.sales',
    'dashboard teknik' => 'pages::dashboard.teknik',
    'pindah kavling' => 'pages::marketing.spr-pindah-list',
    'target' => 'pages::marketing.target',
    'menu spr' => 'pages::marketing.spr',
    'rencana akad' => 'pages::pemberkasan.rencana-akad',
    'rencana akad per blok' => 'pages::pemberkasan.rencana-akad-blok',
    'approval rencana akad' => 'pages::pemberkasan.approval-rencana-akad',
    'customer' => 'pages::master.customer',
    'prospect customer' => 'pages::master.prospect-customer',
    'virtual account' => 'pages::master.virtual-account',
    'penerimaan konsumen' => 'pages::finance.penerimaan-konsumen',
    'e-materai' => 'pages::finance.tempel-materai',
    'input pemberkasan' => 'pages::pemberkasan.input',
    'data rumah teknik' => 'pages::teknik.rumah',
]);

it('tidak lagi menawarkan pilihan "Semua Proyek" di halaman per proyek', function (string $halaman) {
    session([ProyekAktif::SESI => $this->proyek->id]);

    Livewire::actingAs($this->admin)
        ->test($halaman)
        ->assertDontSee('Semua Proyek')
        ->assertDontSee('Semua proyek');
})->with([
    'customer' => 'pages::master.customer',
    'prospect customer' => 'pages::master.prospect-customer',
    'virtual account' => 'pages::master.virtual-account',
]);

it('mengikuti proyek yang dipilih di picker', function () {
    session([ProyekAktif::SESI => $this->proyek->id]);

    Livewire::actingAs($this->admin)
        ->test('pages::pemberkasan.rencana-akad')
        ->assertSet('proyekId', $this->proyek->id)
        ->assertDontSee('Pilih proyek dulu');
});

it('memuat ulang halaman yang sedang dibuka setelah proyek dipilih di picker', function () {
    session([ProyekAktif::SESI => 999]);

    // ?proyek= lama dibuang supaya tidak menimpa pilihan baru; filter lain tetap.
    // Di browser, request Livewire membawa Referer halaman ini; di tes dicadangkan lewat sesi.
    session()->setPreviousUrl(url('/finance/penerimaan-konsumen?proyek=999&search=rina'));

    Livewire::actingAs($this->admin)
        ->test('active-proyek')
        ->call('selectProyek', $this->proyek->id)
        ->assertRedirect(url('/finance/penerimaan-konsumen?search=rina'));

    expect(session(ProyekAktif::SESI))->toBe($this->proyek->id);
});

it('kembali ke dashboard kalau halaman asal bukan dari aplikasi ini', function () {
    session()->setPreviousUrl('https://contoh.example/halaman');

    Livewire::actingAs($this->admin)
        ->test('active-proyek')
        ->call('selectProyek', $this->proyek->id)
        ->assertRedirect(route('dashboard'));
});

it('mengabaikan pilihan proyek yang tidak ada', function () {
    session([ProyekAktif::SESI => $this->proyek->id]);

    Livewire::actingAs($this->admin)
        ->test('active-proyek')
        ->call('selectProyek', 999999)
        ->assertNoRedirect();

    expect(session(ProyekAktif::SESI))->toBe($this->proyek->id);
});
