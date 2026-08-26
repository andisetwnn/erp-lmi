<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Dokumen SPR mengambil tanda tangan dari user approver, bukan dari kolom path.
 * Waktu import historis ke production, username PM ternyata dieja "febry" sementara
 * importer mencari "febri" — 207 dokumen tercetak tanpa tanda tangan tanpa satu pun
 * peringatan. Test ini menjaga agar kegagalan seperti itu selalu berisik.
 */
it('berhenti dan menampilkan daftar username ketika approver tidak ditemukan', function () {
    User::factory()->create(['name' => 'Orang Lain', 'username' => 'oranglain']);

    $this->artisan('import:backfill-approver')
        ->expectsOutputToContain('User approver tidak ditemukan.')
        ->expectsOutputToContain('oranglain')
        ->assertFailed();
});

it('menerima ejaan username febry maupun febri', function (string $ejaan) {
    User::factory()->create(['name' => 'Febry Ferdinan', 'username' => $ejaan]);
    User::factory()->create(['name' => 'Uli Panjaitan', 'username' => 'uli']);

    $this->artisan('import:backfill-approver')
        ->expectsOutputToContain('Febry Ferdinan')
        ->assertSuccessful();
})->with(['febri', 'febry']);

it('mencari lewat nama kalau username tidak dikenali sama sekali', function () {
    User::factory()->create(['name' => 'Febry Ferdinan', 'username' => 'pm-lmi']);
    User::factory()->create(['name' => 'Uli Panjaitan', 'username' => 'finance-lmi']);

    $this->artisan('import:backfill-approver')
        ->expectsOutputToContain('Febry Ferdinan')
        ->expectsOutputToContain('Uli Panjaitan')
        ->assertSuccessful();
});

it('menghormati username yang ditentukan lewat opsi', function () {
    User::factory()->create(['name' => 'Febry Ferdinan', 'username' => 'febry']);
    User::factory()->create(['name' => 'Uli Panjaitan', 'username' => 'uli']);
    User::factory()->create(['name' => 'PM Pengganti', 'username' => 'pm2']);

    $this->artisan('import:backfill-approver', ['--pm' => 'pm2'])
        ->expectsOutputToContain('PM Pengganti')
        ->assertSuccessful();
});
