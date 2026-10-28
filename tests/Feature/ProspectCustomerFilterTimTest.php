<?php

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use App\Models\Master\SalesGrup;
use App\Models\User;
use App\Support\ProyekAktif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Prospect Customer bisa disaring per tim sales: prospect milik anggota tim
 * beserta ketuanya, dan pilihan sales ikut menyempit ke anggota tim itu.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    session([ProyekAktif::SESI => $this->proyek->id]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-kpr');

    $sales = fn (string $kode, string $nama) => Sales::create([
        'kode' => $kode, 'nama' => $nama, 'is_aktif' => true,
        'dbos_username' => strtolower($kode), 'dbos_password' => 'rahasia123',
    ]);
    $this->ketuaA = $sales('SLS-KA', 'Ketua Agus');
    $this->anggotaA = $sales('SLS-AA', 'Anggota Agus');
    $this->anggotaB = $sales('SLS-AB', 'Anggota Delon');

    $this->timA = SalesGrup::create(['nama' => 'Tim Agus', 'pimpinan_id' => $this->ketuaA->id]);
    $this->timB = SalesGrup::create(['nama' => 'Tim Delon', 'pimpinan_id' => $sales('SLS-KD', 'Ketua Delon')->id]);
    $this->anggotaA->update(['sales_grup_id' => $this->timA->id]);
    $this->anggotaB->update(['sales_grup_id' => $this->timB->id]);

    foreach ([[$this->ketuaA, 'Prospek Milik Ketua'], [$this->anggotaA, 'Prospek Milik Anggota'], [$this->anggotaB, 'Prospek Tim Sebelah']] as [$s, $nama]) {
        ProspectCustomer::create([
            'sales_id' => $s->id, 'proyek_id' => $this->proyek->id, 'nama_lengkap' => $nama,
            'hp' => '6281200000'.$s->id, 'sumber' => 'Walk-in', 'status' => 'cold',
        ]);
    }
});

it('menampilkan prospect anggota tim dan ketuanya saja', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::master.prospect-customer')
        ->assertSee('Tim Agus · Ketua Ketua Agus')
        ->set('filterTim', (string) $this->timA->id)
        ->assertSee('Prospek Milik Ketua')
        ->assertSee('Prospek Milik Anggota')
        ->assertDontSee('Prospek Tim Sebelah');
});

it('menyempitkan pilihan sales ke anggota tim dan mengosongkan sales dari tim lain', function () {
    $komponen = Livewire::actingAs($this->admin)
        ->test('pages::master.prospect-customer')
        ->set('filterSales', (string) $this->anggotaB->id)
        ->set('filterTim', (string) $this->timA->id)
        ->assertSet('filterSales', '');

    $pilihan = $komponen->viewData('salesOptions')->pluck('id')->all();
    expect($pilihan)->toEqualCanonicalizing([$this->ketuaA->id, $this->anggotaA->id]);
});
