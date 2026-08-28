<?php

use App\Models\Master\Proyek;
use App\Models\Master\TargetMarketing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Matriks Marketing Performance dirinci per bulan. Angka setahun menyembunyikan bulan
 * yang meleset — satu bulan panen bisa menutupi lima bulan kosong. Test ini menjaga
 * agar tiap target mendarat di bulan yang benar dan tidak ada bulan yang hilang.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tahun = (int) now()->year;

    $this->direktur = User::factory()->create();
    $this->direktur->assignRole('direktur');
    $this->actingAs($this->direktur);
});

function matriks(int $tahun): array
{
    return Livewire::test('pages::dashboard.direksi')
        ->set('selectedTahun', $tahun)
        ->viewData('marketing');
}

it('selalu menyediakan dua belas bulan penuh untuk tiap proyek', function () {
    $m = matriks($this->tahun);

    foreach (['akadTargetBulan', 'akadRealBulan', 'penjualanTargetBulan', 'penjualanRealBulan'] as $kunci) {
        expect($m[$kunci])->toHaveKey($this->proyek->id);
        expect(array_keys($m[$kunci][$this->proyek->id]))->toBe(range(1, 12), "$kunci tidak lengkap 12 bulan");
    }
});

it('menempatkan target pada bulan yang benar, bukan bergeser', function () {
    TargetMarketing::create([
        'proyek_id' => $this->proyek->id,
        'tahun' => $this->tahun,
        'bulan' => 3,
        'target_akad' => 7,
        'target_penjualan' => 11,
    ]);

    $m = matriks($this->tahun);
    $akad = $m['akadTargetBulan'][$this->proyek->id];
    $jual = $m['penjualanTargetBulan'][$this->proyek->id];

    expect($akad[3])->toBe(7)
        ->and($jual[3])->toBe(11)
        ->and($akad[2])->toBe(0)
        ->and($akad[4])->toBe(0);
});

it('menjumlahkan rincian bulanan sama dengan angka setahun', function () {
    foreach ([1, 6, 12] as $bulan) {
        TargetMarketing::create([
            'proyek_id' => $this->proyek->id,
            'tahun' => $this->tahun,
            'bulan' => $bulan,
            'target_akad' => 4,
            'target_penjualan' => 9,
        ]);
    }

    $m = matriks($this->tahun);

    expect(array_sum($m['akadTargetBulan'][$this->proyek->id]))->toBe(12)
        ->and(array_sum($m['penjualanTargetBulan'][$this->proyek->id]))->toBe(27)
        ->and($m['akadTarget'][$this->proyek->id])->toBe(12)
        ->and($m['penjualanTarget'][$this->proyek->id])->toBe(27);
});

it('tidak membawa target tahun lain ke tahun yang sedang dilihat', function () {
    TargetMarketing::create([
        'proyek_id' => $this->proyek->id,
        'tahun' => $this->tahun - 1,
        'bulan' => 5,
        'target_akad' => 50,
        'target_penjualan' => 50,
    ]);

    $m = matriks($this->tahun);

    expect(array_sum($m['akadTargetBulan'][$this->proyek->id]))->toBe(0);
});
