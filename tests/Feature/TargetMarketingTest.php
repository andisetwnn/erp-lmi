<?php

use App\Models\Master\Proyek;
use App\Models\Master\TargetMarketing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();

    $this->direktur = User::factory()->create();
    $this->direktur->assignRole('direktur');
});

it('menyimpan target dua belas bulan sekaligus', function () {
    $this->actingAs($this->direktur);

    Livewire::test('pages::marketing.target')
        ->set('selectedProyekId', $this->proyek->id)
        ->set('selectedTahun', 2027)
        ->call('isiSemuaAkad', 5)
        ->call('isiSemuaPenjualan', 7)
        ->call('simpan')
        ->assertHasNoErrors();

    $rows = TargetMarketing::where('proyek_id', $this->proyek->id)->where('tahun', 2027)->get();

    expect($rows)->toHaveCount(12)
        ->and($rows->sum('target_akad'))->toBe(60)
        ->and($rows->sum('target_penjualan'))->toBe(84)
        ->and($rows->pluck('bulan')->sort()->values()->all())->toBe(range(1, 12));
});

it('memperbarui target yang sudah ada, bukan menggandakannya', function () {
    $this->actingAs($this->direktur);

    $simpan = fn (int $akad) => Livewire::test('pages::marketing.target')
        ->set('selectedProyekId', $this->proyek->id)
        ->set('selectedTahun', 2027)
        ->call('isiSemuaAkad', $akad)
        ->call('simpan');

    $simpan(5);
    $simpan(8);

    $rows = TargetMarketing::where('tahun', 2027)->get();

    expect($rows)->toHaveCount(12)
        ->and($rows->sum('target_akad'))->toBe(96);
});

it('mencatat siapa yang terakhir mengubah target', function () {
    $this->actingAs($this->direktur);

    Livewire::test('pages::marketing.target')
        ->set('selectedProyekId', $this->proyek->id)
        ->set('selectedTahun', 2027)
        ->call('isiSemuaAkad', 3)
        ->call('simpan');

    expect(TargetMarketing::first()->updated_by_user_id)->toBe($this->direktur->id);
});

it('menolak target di luar batas wajar', function () {
    $this->actingAs($this->direktur);

    Livewire::test('pages::marketing.target')
        ->set('selectedProyekId', $this->proyek->id)
        ->set('selectedTahun', 2027)
        ->set('rows.1.target_akad', 99999)
        ->call('simpan')
        ->assertHasErrors('rows.1.target_akad');

    expect(TargetMarketing::count())->toBe(0);
});

it('menutup akses simpan untuk user tanpa izin target', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    Livewire::test('pages::marketing.target')
        ->set('selectedProyekId', $this->proyek->id)
        ->call('simpan')
        ->assertForbidden();
});
