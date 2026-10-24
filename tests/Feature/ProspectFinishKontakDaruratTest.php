<?php

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use App\Models\Master\TempatKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Syarat FINISH: kontak darurat cukup 2 (sebelumnya 3). Checklist di model
 * adalah satu-satunya sumber — dipakai modal status, toast, dan validasi.
 */
beforeEach(function () {
    $this->seed();

    $this->sales = Sales::create([
        'kode' => 'SLS-KD', 'nama' => 'Sales Kontak', 'is_aktif' => true,
        'dbos_username' => 'sales-kd', 'dbos_password' => 'rahasia123',
    ]);
    $this->prospect = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => Proyek::first()->id,
        'nama_lengkap' => 'Konsumen Lengkap', 'hp' => '628120000001', 'sumber' => 'Walk-in', 'status' => 'hot',
        'nik' => '3201000000001111', 'npwp' => '12.345.678.9-012.000', 'foto_ktp' => 'prospect-ktp/contoh.jpg',
        'alamat' => 'Jl. Contoh No. 1', 'bi_kol' => '1', 'bi_dbr' => 30,
        'tempat_kerja_id' => TempatKerja::create(['nama' => 'PT Uji Kontak'])->id,
    ]);
});

function tambahKontak(ProspectCustomer $p, int $jumlah): void
{
    foreach (range(1, $jumlah) as $i) {
        $p->kontakDarurat()->create(['nama' => "Kontak $i", 'hubungan' => 'saudara', 'nomor_telepon' => '62813000000'.$i]);
    }
}

it('menganggap prospect siap FINISH dengan 2 kontak darurat', function () {
    tambahKontak($this->prospect, 2);

    expect($this->prospect->fresh()->readyForFinish())->toBeTrue();
});

it('menolak FINISH kalau kontak darurat baru 1', function () {
    tambahKontak($this->prospect, 1);

    expect($this->prospect->fresh()->missingForFinish())->toBe(['Kontak Darurat (min 2)']);
});

it('menyiapkan 2 baris kontak darurat di form tambah calon konsumen', function () {
    Livewire::actingAs($this->sales, 'sales')
        ->test('pages::dbos.database.form')
        ->assertCount('kontakDarurat', ProspectCustomer::MIN_KONTAK_DARURAT)
        ->assertSee('Min 2 untuk FINISH');
});
