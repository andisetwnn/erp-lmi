<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\RumahProgresLog;
use App\Models\Master\Subcon;
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

    $this->subcon = Subcon::create(['nama' => 'WIN']);

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

it('menyimpan subcont bersama LOT dan progres', function () {
    // Subcont sebenarnya urusan Teknik. Sampai modulnya berdiri sendiri, diisi
    // dari layar ini juga supaya Rencana Akad punya isinya.
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->call('openUpdate', $this->rumah->id)
        ->set('val_progres', 60)
        ->set('val_lot', 12)
        ->set('val_subcon_id', $this->subcon->id)
        ->call('simpanProgres')
        ->assertHasNoErrors();

    expect($this->rumah->fresh()->subcon_id)->toBe($this->subcon->id)
        ->and($this->rumah->fresh()->lot)->toBe(12);
});

it('memuat subcont yang sudah ada saat modal dibuka', function () {
    $this->rumah->update(['subcon_id' => $this->subcon->id]);
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->call('openUpdate', $this->rumah->id)
        ->assertSet('val_subcon_id', $this->subcon->id);
});

it('mengosongkan subcon jadi null', function () {
    $this->rumah->update(['subcon_id' => $this->subcon->id]);
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->call('openUpdate', $this->rumah->id)
        ->set('val_subcon_id', null)
        ->call('simpanProgres')
        ->assertHasNoErrors();

    expect($this->rumah->fresh()->subcon_id)->toBeNull();
});

it('bisa mencari unit berdasarkan subcont', function () {
    $this->rumah->update(['subcon_id' => $this->subcon->id]);
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->set('selectedProyekId', $this->rumah->proyek_id)
        ->set('search', 'WIN')
        ->assertSeeText($this->rumah->kode_unit);
});

it('mengubah subcont beberapa unit sekaligus', function () {
    $lain = Rumah::create([
        'proyek_id' => $this->rumah->proyek_id,
        'tipe_rumah_id' => $this->rumah->tipe_rumah_id,
        'blok' => 'ZZ', 'nomor_unit' => '02', 'status' => 'available', 'progres_fisik' => 10,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->set('terpilih', [$this->rumah->id, $lain->id])
        ->call('openMassal')
        ->set('massal_subcon_id', $this->subcon->id)
        ->call('simpanMassal')
        ->assertHasNoErrors()
        ->assertSet('terpilih', []);

    expect($this->rumah->fresh()->subcon_id)->toBe($this->subcon->id)
        ->and($lain->fresh()->subcon_id)->toBe($this->subcon->id);
});

it('mengisi LOT berurutan, bukan nomor yang sama untuk semua', function () {
    // LOT adalah nomor sertifikat — menyeragamkannya membuat data kembar.
    $dua = Rumah::create([
        'proyek_id' => $this->rumah->proyek_id, 'tipe_rumah_id' => $this->rumah->tipe_rumah_id,
        'blok' => 'ZZ', 'nomor_unit' => '02', 'status' => 'available',
    ]);
    $tiga = Rumah::create([
        'proyek_id' => $this->rumah->proyek_id, 'tipe_rumah_id' => $this->rumah->tipe_rumah_id,
        'blok' => 'ZZ', 'nomor_unit' => '03', 'status' => 'available',
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->set('terpilih', [$tiga->id, $this->rumah->id, $dua->id])
        ->call('openMassal')
        ->set('massal_lot_mulai', 20)
        ->call('simpanMassal')
        ->assertHasNoErrors();

    // Urutannya mengikuti blok-unit, bukan urutan dicentang.
    expect($this->rumah->fresh()->lot)->toBe(20)
        ->and($dua->fresh()->lot)->toBe(21)
        ->and($tiga->fresh()->lot)->toBe(22);
});

it('tidak menimpa isian yang dikosongkan', function () {
    $this->rumah->update(['subcon_id' => $this->subcon->id, 'lot' => 7]);
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->set('terpilih', [$this->rumah->id])
        ->call('openMassal')
        ->set('massal_progres', 90)
        ->call('simpanMassal')
        ->assertHasNoErrors();

    $segar = $this->rumah->fresh();

    expect($segar->progres_fisik)->toBe(90)
        ->and($segar->subcon_id)->toBe($this->subcon->id)
        ->and($segar->lot)->toBe(7);
});

it('mencatat riwayat untuk tiap unit yang progresnya berubah', function () {
    $lain = Rumah::create([
        'proyek_id' => $this->rumah->proyek_id, 'tipe_rumah_id' => $this->rumah->tipe_rumah_id,
        'blok' => 'ZZ', 'nomor_unit' => '02', 'status' => 'available', 'progres_fisik' => 50,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->set('terpilih', [$this->rumah->id, $lain->id])
        ->call('openMassal')
        ->set('massal_progres', 50)
        ->set('val_catatan', 'pengecoran serentak')
        ->call('simpanMassal');

    // Yang sudah 50% tidak menghasilkan baris riwayat palsu.
    expect(RumahProgresLog::where('rumah_id', $this->rumah->id)->count())->toBe(1)
        ->and(RumahProgresLog::where('rumah_id', $lain->id)->count())->toBe(0);
});

it('menolak ubah massal kalau tidak ada yang diisi', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::teknik.rumah')
        ->set('terpilih', [$this->rumah->id])
        ->call('openMassal')
        ->call('simpanMassal');

    expect($this->rumah->fresh()->subcon_id)->toBeNull();
});

it('menutup ubah massal untuk user tanpa izin teknik', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    Livewire::test('pages::teknik.rumah')
        ->set('terpilih', [$this->rumah->id])
        ->call('openMassal')
        ->assertForbidden();
});
