<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Matriks Persediaan Rumah mengikuti bentuk laporan lama: tiap baris punya penjelasan,
 * dan dua baris menampilkan persentase terhadap total kavling.
 */
beforeEach(function () {
    $this->seed();
    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');
    $this->actingAs($direktur);
});

function buatRumah(int $jumlah, array $atribut = []): void
{
    foreach (range(1, $jumlah) as $i) {
        Rumah::create(array_merge([
            'proyek_id' => test()->proyek->id,
            'tipe_rumah_id' => test()->tipe->id,
            'blok' => 'ZZ',
            'nomor_unit' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'status' => 'available',
        ], $atribut));
    }
}

function persediaan(): array
{
    return Livewire::test('pages::dashboard.direksi')->viewData('persediaan');
}

it('menyediakan tujuh baris termasuk stok kavling awal tahun', function () {
    $kunci = collect(persediaan()['metrics'])->pluck('key')->all();

    expect($kunci)->toBe([
        'total_kavling', 'akad', 'utj', 'stok_awal', 'stok_berjalan', 'rumah_selesai', 'rumah_proses',
    ]);
});

it('memberi penjelasan pada setiap baris', function () {
    foreach (persediaan()['metrics'] as $m) {
        expect($m['info'] ?? null)->not->toBeEmpty("baris {$m['key']} belum punya penjelasan");
    }
});

it('menandai hanya baris akad dan stok awal yang memakai persentase', function () {
    $pakaiPersen = collect(persediaan()['metrics'])
        ->filter(fn ($m) => ! empty($m['persen']))
        ->pluck('key')->values()->all();

    expect($pakaiPersen)->toBe(['akad', 'stok_awal']);
});

it('menyertakan tahun terpilih pada label stok kavling awal', function () {
    $tahun = (int) now()->year;

    $label = collect(persediaan()['metrics'])->firstWhere('key', 'stok_awal')['label'];

    expect($label)->toBe("Stok Kavling Awal $tahun");
});

it('menghitung stok awal sama dengan total kavling ketika belum ada penjualan tahun sebelumnya', function () {
    buatRumah(5);

    $p = persediaan();
    $total = collect($p['metrics'])->firstWhere('key', 'total_kavling')['values'];
    $stokAwal = collect($p['metrics'])->firstWhere('key', 'stok_awal')['values'];

    expect((int) $stokAwal[$this->proyek->id])->toBe((int) $total[$this->proyek->id])
        ->and((int) $stokAwal[$this->proyek->id])->toBe(5);
});

it('memisahkan rumah selesai dari yang sedang dibangun berdasar progres fisik', function () {
    buatRumah(2, ['progres_fisik' => 100]);
    Rumah::create([
        'proyek_id' => $this->proyek->id,
        'tipe_rumah_id' => $this->tipe->id,
        'blok' => 'YY',
        'nomor_unit' => '01',
        'status' => 'available',
        'progres_fisik' => 40,
    ]);

    $p = persediaan();
    $selesai = collect($p['metrics'])->firstWhere('key', 'rumah_selesai')['values'];
    $proses = collect($p['metrics'])->firstWhere('key', 'rumah_proses')['values'];

    expect((int) $selesai[$this->proyek->id])->toBe(2)
        ->and((int) $proses[$this->proyek->id])->toBe(1);
});

it('menampilkan persentase dan penjelasan di layar', function () {
    buatRumah(4);

    $html = Livewire::test('pages::dashboard.direksi')->html();

    expect($html)->toContain('Stok Kavling Awal')
        ->and($html)->toContain('100%')
        ->and($html)->toContain('belum dipegang SPR aktif per 1 Januari');
});

it('menaruh penjelasan di luar tabel yang bisa digeser menyamping', function () {
    // Modal yang dirender dari dalam kotak overflow-x-auto ikut terpotong oleh kotak itu:
    // judul terpangkas dan muncul scrollbar sendiri di dalam modalnya.
    buatRumah(3);

    $html = Livewire::test('pages::dashboard.direksi')->html();

    $mulai = strpos($html, 'Persediaan Rumah');
    $potong = substr($html, $mulai);
    $isiTabel = substr($potong, strpos($potong, 'overflow-x-auto'), strpos($potong, '</table>') - strpos($potong, 'overflow-x-auto'));

    expect(substr_count($isiTabel, 'aria-label="Info"'))->toBe(0);
});
