<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Tiap role diarahkan ke dashboard-nya sendiri oleh dispatcher di routes/web.php.
 * Super-admin perlu bisa memeriksa dashboard role lain tanpa berganti akun — tapi
 * pemilihnya tidak boleh bocor ke role lain.
 */
beforeEach(fn () => $this->seed());

function bukaDashboard(string $nama): string
{
    return Livewire::test("pages::dashboard.$nama")->html();
}

it('menampilkan pemilih dashboard untuk super admin di semua halaman', function (string $dashboard) {
    $u = User::factory()->create();
    $u->assignRole('super-admin');
    $this->actingAs($u);

    expect(bukaDashboard($dashboard))->toContain('Lihat dashboard role lain');
})->with(['executive', 'direksi', 'finance', 'pm']);

it('menyembunyikan pemilih dashboard dari role selain super admin', function (string $role) {
    $u = User::factory()->create();
    $u->assignRole($role);
    $this->actingAs($u);

    expect(bukaDashboard('executive'))->not->toContain('Lihat dashboard role lain');
})->with(['direktur', 'finance', 'project-manager', 'admin-sales']);

it('memberi judul yang sama pada seluruh dashboard', function (string $dashboard) {
    $u = User::factory()->create();
    $u->assignRole('super-admin');
    $this->actingAs($u);

    $html = bukaDashboard($dashboard);

    expect($html)->toContain('>Dashboard<')
        ->and($html)->not->toContain('Dashboard Keuangan')
        ->and($html)->not->toContain('Dashboard Project Manager');
})->with(['executive', 'direksi', 'finance', 'pm']);
