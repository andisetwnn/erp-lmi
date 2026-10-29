<?php

use App\Models\Master\BankKpr;
use App\Models\Master\Notaris;
use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkad;
use App\Models\User;
use App\Support\ProyekAktif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Halaman yang rutenya juga dibuka izin "lihat" harus mengecek izin tulis di
 * setiap aksinya — tombol yang disembunyikan tidak cukup, karena aksi Livewire
 * bisa dipanggil langsung.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    session([ProyekAktif::SESI => $this->proyek->id]);
});

function penggunaDenganRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('menolak Direktur membuka halaman input pembatalan SPR', function () {
    Livewire::actingAs(penggunaDenganRole('direktur'))
        ->test('pages::marketing.spr-pembatalan-input')
        ->assertForbidden();
});

it('tetap membuka halaman input pembatalan untuk Admin KPR', function () {
    Livewire::actingAs(penggunaDenganRole('admin-kpr'))
        ->test('pages::marketing.spr-pembatalan-input')
        ->assertOk();
});

it('menolak Direktur mengubah aktiva tetap', function (string $aksi, array $argumen) {
    Livewire::actingAs(penggunaDenganRole('direktur'))
        ->test('pages::akunting.aktiva-tetap')
        ->call($aksi, ...$argumen)
        ->assertForbidden();
})->with([
    'buka form tambah' => ['openCreate', []],
    'simpan' => ['simpan', []],
    'buka konfirmasi hapus' => ['openConfirmDelete', [1, 'Laptop']],
    'hapus' => ['hapus', []],
]);

it('hanya pihak alur yang bisa membuat link persetujuan Direksi', function (string $role, bool $boleh) {
    $rencana = RencanaAkad::create([
        'proyek_id' => $this->proyek->id,
        'nomor' => '1/TES/R.AKAD/10/2026',
        'tanggal_rencana' => '2026-10-29',
        'bank_kpr_id' => BankKpr::create(['kode' => 'TES', 'nama' => 'Bank Tes'])->id,
        'notaris_id' => Notaris::create(['nama' => 'Notaris Tes', 'nik' => '3374010101800009'])->id,
        'created_by_user_id' => penggunaDenganRole('admin-kpr')->id,
        'status' => 'diketahui',
    ]);

    $uji = Livewire::actingAs(penggunaDenganRole($role))
        ->test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openKirimDireksi');

    $boleh ? $uji->assertOk()->assertSet('urlDireksi', fn ($url) => str_contains((string) $url, 'signature='))
        : $uji->assertForbidden();
})->with([
    'Keuangan (hanya melihat)' => ['finance', false],
    'Admin KPR (penyusun)' => ['admin-kpr', true],
]);
