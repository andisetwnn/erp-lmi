<?php

use App\Models\Master\Proyek;
use App\Models\Master\TargetMarketing;
use Database\Seeders\TargetMarketingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tahun = (int) now()->year;
});

it('mengisi dua belas bulan tahun berjalan untuk tiap proyek', function () {
    $this->seed(TargetMarketingSeeder::class);

    $rows = TargetMarketing::where('proyek_id', $this->proyek->id)
        ->where('tahun', $this->tahun)->get();

    expect($rows)->toHaveCount(12)
        ->and($rows->pluck('bulan')->sort()->values()->all())->toBe(range(1, 12))
        ->and($rows->every(fn ($r) => $r->target_akad > 0 && $r->target_penjualan > 0))->toBeTrue();
});

it('tidak menimpa target yang sudah diisi manual', function () {
    $asli = TargetMarketing::create([
        'proyek_id' => $this->proyek->id,
        'tahun' => $this->tahun,
        'bulan' => 3,
        'target_akad' => 99,
        'target_penjualan' => 88,
        'catatan' => 'angka resmi rapat direksi',
    ]);

    $this->seed(TargetMarketingSeeder::class);

    $tetap = $asli->fresh();

    expect($tetap->target_akad)->toBe(99)
        ->and($tetap->target_penjualan)->toBe(88)
        ->and($tetap->catatan)->toBe('angka resmi rapat direksi')
        ->and(TargetMarketing::where('tahun', $this->tahun)->count())->toBe(12);
});

it('aman dijalankan berulang tanpa menggandakan baris', function () {
    $this->seed(TargetMarketingSeeder::class);
    $this->seed(TargetMarketingSeeder::class);
    $this->seed(TargetMarketingSeeder::class);

    expect(TargetMarketing::where('tahun', $this->tahun)->count())->toBe(12);
});

it('menandai barisnya sebagai angka sementara supaya mudah dikenali', function () {
    $this->seed(TargetMarketingSeeder::class);

    expect(TargetMarketing::first()->catatan)->toContain('sementara');
});
