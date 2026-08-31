<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Tiap role diarahkan ke dashboard-nya sendiri. Sebelum ini, role yang belum punya
 * dashboard jatuh ke `executive` — halaman tanpa satu pun penjagaan izin yang
 * menampilkan piutang, kas masuk, dan tunggakan. Artinya admin teknik yang izinnya
 * cuma memperbarui progres bangunan ikut melihat angka keuangan perusahaan.
 */
beforeEach(fn () => $this->seed());

function userDenganRole(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

it('mengarahkan tiap role ke dashboardnya sendiri', function (string $role, string $tujuan) {
    $this->actingAs(userDenganRole($role));

    $this->get(route('dashboard'))->assertRedirect(route($tujuan));
})->with([
    ['direktur', 'dashboard.direksi'],
    ['finance', 'dashboard.finance'],
    ['project-manager', 'dashboard.pm'],
    ['admin-kpr', 'dashboard.kpr'],
    ['admin-sales', 'dashboard.sales'],
    ['admin-teknik', 'dashboard.teknik'],
    ['super-admin', 'dashboard.executive'],
]);

it('merender dashboard baru tanpa error', function (string $dashboard) {
    $this->actingAs(userDenganRole('super-admin'));

    expect(Livewire::test("pages::dashboard.$dashboard")->html())->toContain('Dashboard');
})->with(['kpr', 'sales', 'teknik']);

it('tidak menampilkan angka keuangan di dashboard teknik', function () {
    $this->actingAs(userDenganRole('admin-teknik'));

    $html = Livewire::test('pages::dashboard.teknik')->html();

    expect($html)->not->toContain('Piutang')
        ->and($html)->not->toContain('Kas Masuk')
        ->and($html)->not->toContain('Belum Tertagih')
        ->and($html)->not->toContain('Rp ');
});

it('tidak menampilkan saldo kas maupun piutang di dashboard sales', function () {
    $this->actingAs(userDenganRole('admin-sales'));

    $html = Livewire::test('pages::dashboard.sales')->html();

    // Biaya tambahan memang wewenangnya (izin biayatambahan.kelola), tapi saldo
    // perusahaan dan piutang UM bukan.
    expect($html)->not->toContain('Total Piutang UM')
        ->and($html)->not->toContain('Kas Masuk')
        ->and($html)->toContain('Biaya Tambahan');
});

it('menampilkan berkas yang perlu ditindaklanjuti di dashboard KPR', function () {
    $this->actingAs(userDenganRole('admin-kpr'));

    $html = Livewire::test('pages::dashboard.kpr')->html();

    expect($html)->toContain('Belum Ada Berkas')
        ->and($html)->toContain('SP3K Lewat Tanggal')
        ->and($html)->toContain('Jatuh Tempo 30 Hari');
});

it('membedakan progres belum dicatat dari unit yang belum dibangun', function () {
    $this->actingAs(userDenganRole('admin-teknik'));

    $html = Livewire::test('pages::dashboard.teknik')->html();

    // Label harus "Belum Dicatat", bukan "Belum Dibangun" — sebagian besar unit
    // bernilai nol karena datanya belum diisi, bukan karena pekerjaannya belum mulai.
    expect($html)->toContain('Belum Dicatat')
        ->and($html)->not->toContain('Belum Dibangun');
});

it('menolak akses dashboard milik role lain lewat URL langsung', function (string $role, array $bolehkan) {
    $this->actingAs(userDenganRole($role));

    foreach (['executive', 'pm', 'finance', 'direksi', 'kpr', 'sales', 'teknik'] as $d) {
        $status = $this->get(route("dashboard.$d"))->getStatusCode();

        in_array($d, $bolehkan, true)
            ? expect($status)->toBe(200, "$role seharusnya boleh membuka $d")
            : expect($status)->toBe(403, "$role seharusnya DITOLAK di $d");
    }
})->with([
    ['admin-teknik', ['teknik']],
    ['admin-sales', ['sales']],
    ['admin-kpr', ['kpr']],
    ['finance', ['finance']],
    ['direktur', ['direksi']],
    ['project-manager', ['pm']],
]);

it('mengizinkan super admin membuka seluruh dashboard', function () {
    $this->actingAs(userDenganRole('super-admin'));

    foreach (['executive', 'pm', 'finance', 'direksi', 'kpr', 'sales', 'teknik'] as $d) {
        $this->get(route("dashboard.$d"))->assertOk();
    }
});
