<?php

use App\Models\Master\Proyek;
use App\Models\Master\TargetMarketing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Matriks Marketing Performance membandingkan target dan realisasi per proyek.
 * Target disimpan per bulan, jadi angka setahun adalah penjumlahan dua belas bulan —
 * kalau penjumlahannya salah, direktur membaca pencapaian yang keliru.
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

function isiTarget(int $proyekId, int $tahun, int $bulan, int $akad, int $jual): void
{
    TargetMarketing::create([
        'proyek_id' => $proyekId,
        'tahun' => $tahun,
        'bulan' => $bulan,
        'target_akad' => $akad,
        'target_penjualan' => $jual,
    ]);
}

it('menjumlahkan target dua belas bulan jadi angka setahun per proyek', function () {
    foreach ([1, 6, 12] as $bulan) {
        isiTarget($this->proyek->id, $this->tahun, $bulan, 4, 9);
    }

    $m = matriks($this->tahun);

    expect($m['akadTarget'][$this->proyek->id])->toBe(12)
        ->and($m['penjualanTarget'][$this->proyek->id])->toBe(27);
});

it('menyediakan angka untuk setiap proyek walau targetnya belum diisi', function () {
    $m = matriks($this->tahun);

    foreach ($m['proyekList'] as $p) {
        expect($m['akadTarget'])->toHaveKey($p->id)
            ->and($m['penjualanTarget'])->toHaveKey($p->id)
            ->and($m['akadTarget'][$p->id])->toBe(0);
    }
});

it('tidak membawa target tahun lain ke tahun yang sedang dilihat', function () {
    isiTarget($this->proyek->id, $this->tahun - 1, 5, 50, 50);

    $m = matriks($this->tahun);

    expect($m['akadTarget'][$this->proyek->id])->toBe(0);
});

it('menampilkan matriks per proyek beserta kolom gabungan', function () {
    isiTarget($this->proyek->id, $this->tahun, 1, 3, 8);

    $html = Livewire::test('pages::dashboard.direksi')
        ->set('selectedTahun', $this->tahun)
        ->html();

    expect($html)->toContain('Akad progress')
        ->and($html)->toContain('Penjualan progress')
        ->and($html)->toContain('*ALL')
        ->and($html)->toContain('TARGET')
        ->and($html)->toContain('REAL')
        ->and($html)->toContain($this->proyek->nama_proyek);
});

it('menyusun pilihan tahun tanpa bergantung fungsi tanggal khusus MySQL', function () {
    // YEAR() tidak ada di SQLite. Test ini yang menjaga dashboard tetap bisa diuji.
    $m = matriks($this->tahun);

    expect($m['tahunOptions'])->toContain($this->tahun);
});

it('menyediakan dua pilihan rincian dan bertahan di pilihan yang dipakai', function () {
    $c = Livewire::test('pages::dashboard.direksi');

    expect($c->get('rincianMatriks'))->toBe('proyek');

    $c->call('setRincian', 'bulan');
    expect($c->get('rincianMatriks'))->toBe('bulan');

    $c->call('setRincian', 'proyek');
    expect($c->get('rincianMatriks'))->toBe('proyek');
});

it('mengabaikan pilihan rincian yang tidak dikenal', function () {
    $c = Livewire::test('pages::dashboard.direksi')->call('setRincian', 'mingguan');

    expect($c->get('rincianMatriks'))->toBe('proyek');
});

it('menampilkan kolom bulan hanya pada rincian bulanan', function () {
    isiTarget($this->proyek->id, $this->tahun, 5, 3, 8);

    $perProyek = Livewire::test('pages::dashboard.direksi')->call('setRincian', 'proyek')->html();
    $perBulan = Livewire::test('pages::dashboard.direksi')->call('setRincian', 'bulan')->html();

    expect($perProyek)->not->toContain('>Mei<')
        ->and($perBulan)->toContain('>Mei<')
        ->and($perBulan)->toContain('SELISIH');
});

it('menjaga angka setahun sama di kedua rincian', function () {
    foreach (range(1, 12) as $bulan) {
        isiTarget($this->proyek->id, $this->tahun, $bulan, 2, 5);
    }

    $m = matriks($this->tahun);
    $pid = $this->proyek->id;

    expect($m['akadTarget'][$pid])->toBe(24)
        ->and(array_sum($m['akadTargetBulan'][$pid]))->toBe(24)
        ->and($m['penjualanTarget'][$pid])->toBe(60)
        ->and(array_sum($m['penjualanTargetBulan'][$pid]))->toBe(60);
});
