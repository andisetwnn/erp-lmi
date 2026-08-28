<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\RumahProgresLog;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $proyek = Proyek::first();
    $this->rumah = Rumah::create([
        'proyek_id' => $proyek->id,
        'tipe_rumah_id' => TipeRumah::where('proyek_id', $proyek->id)->first()->id,
        'blok' => 'ZZ',
        'nomor_unit' => '01',
        'status' => 'available',
        'progres_fisik' => 20,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

it('menyimpan progres fisik beserta LOT dan mencatat jejak auditnya', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->call('openUpdate', $this->rumah->id)
        ->set('val_progres', 75)
        ->set('val_lot', 9)
        ->set('val_catatan', 'pengecoran lantai 2')
        ->call('simpanProgres')
        ->assertHasNoErrors();

    $r = $this->rumah->fresh();

    expect($r->progres_fisik)->toBe(75)
        ->and($r->lot)->toBe(9)
        ->and($r->progres_updated_by_user_id)->toBe($this->admin->id)
        ->and($r->progres_updated_at)->not->toBeNull();

    $log = RumahProgresLog::where('rumah_id', $r->id)->latest('id')->first();

    expect($log->progres_dari)->toBe(20)
        ->and($log->progres_ke)->toBe(75)
        ->and($log->catatan)->toBe('pengecoran lantai 2');
});

it('tidak membuat baris log kalau progresnya tidak berubah', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->call('openUpdate', $this->rumah->id)
        ->set('val_progres', 20)   // sama seperti semula
        ->set('val_lot', 4)
        ->call('simpanProgres')
        ->assertHasNoErrors();

    expect($this->rumah->fresh()->lot)->toBe(4)
        ->and(RumahProgresLog::where('rumah_id', $this->rumah->id)->count())->toBe(0);
});

it('menolak progres di luar rentang 0 sampai 100', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->call('openUpdate', $this->rumah->id)
        ->set('val_progres', 150)
        ->call('simpanProgres')
        ->assertHasErrors('val_progres');

    expect($this->rumah->fresh()->progres_fisik)->toBe(20);
});

it('menutup akses update untuk user tanpa izin teknik', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    Livewire::test('pages::teknik.rumah')
        ->call('openUpdate', $this->rumah->id)
        ->assertForbidden();
});
