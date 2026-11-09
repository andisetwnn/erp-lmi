<?php

use App\Models\Akunting\Jurnal;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\User;
use App\Services\JurnalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Staf menyiapkan draft jurnal, FAT memposting. Draft sebulan bisa ratusan, jadi FAT
 * memposting beberapa sekaligus — yang dicentang, atau semua draft sesuai saringan.
 */
beforeEach(function () {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00'));
    $this->fat = User::factory()->create(['name' => 'Uli FAT']);
    $this->fat->assignRole('finance');
    $this->staf = User::factory()->create(['name' => 'Septia Staf']);
    $this->staf->assignRole('staf-keuangan');
});

function draftMassal(string $tanggal, int $nilai = 1_000_000): Jurnal
{
    $svc = app(JurnalService::class);
    $perusahaanId = Perusahaan::value('id');

    return $svc->create([
        'perusahaan_id' => $perusahaanId, 'tanggal' => $tanggal, 'kategori_bukti' => 'BANK',
        'no_bukti' => $svc->generateNoBukti($perusahaanId, 'BANK', $tanggal), 'keterangan' => 'UM konsumen',
        'created_by_user_id' => test()->staf->id,
    ], [
        ['coa_id' => Coa::where('kode', '1002.001')->value('id'), 'debet' => $nilai],
        ['coa_id' => Coa::where('kode', '2012.001')->value('id'), 'kredit' => $nilai],
    ]);
}

it('memposting draft yang dicentang sekaligus dan mencatatnya sekali di Monitoring', function () {
    $a = draftMassal('2026-10-02');
    $b = draftMassal('2026-10-03', 2_500_000);
    $c = draftMassal('2026-10-04');

    Livewire::actingAs($this->fat)
        ->test('pages::akunting.jurnal-umum')
        ->assertSee('Posting semua draft (3)')
        ->set('dipilih', [(string) $a->id, (string) $b->id])
        ->assertSee('Posting 2 terpilih')
        ->call('siapkanPostingMassal', 'terpilih')
        ->assertSet('konfirmJumlah', 2)
        ->assertSet('konfirmNilai', 3_500_000.0)
        ->call('jalankanPostingMassal')
        ->assertSet('dipilih', []);

    expect($a->fresh()->status)->toBe('posted')
        ->and($a->fresh()->posted_by_user_id)->toBe($this->fat->id)
        ->and($b->fresh()->status)->toBe('posted')
        ->and($c->fresh()->status)->toBe('draft');

    $log = Activity::where('event', 'jurnal.diposting_massal')->sole();
    expect($log->properties['jumlah'])->toBe(2)
        ->and($log->properties['no_bukti'])->toBe([$a->no_bukti, $b->no_bukti])
        ->and($log->causer_id)->toBe($this->fat->id)
        ->and(Activity::where('event', 'jurnal.diposting')->count())->toBe(0);
});

it('memposting semua draft sesuai saringan tanggal', function () {
    $oktober = [draftMassal('2026-10-10'), draftMassal('2026-10-31')];
    $november = draftMassal('2026-11-02');

    Livewire::actingAs($this->fat)
        ->test('pages::akunting.jurnal-umum')
        ->set('filterFrom', '2026-10-01')
        ->set('filterTo', '2026-10-31')
        ->assertSee('Posting semua draft (2)')
        ->call('siapkanPostingMassal', 'saringan')
        ->assertSet('konfirmJumlah', 2)
        ->call('jalankanPostingMassal');

    expect(collect($oktober)->map(fn ($j) => $j->fresh()->status)->all())->toBe(['posted', 'posted'])
        ->and($november->fresh()->status)->toBe('draft');
});

it('melewati draft yang belum balance tanpa menggagalkan yang lain', function () {
    $siap = draftMassal('2026-10-05');
    $timpang = draftMassal('2026-10-06');
    $timpang->detail()->where('debet', '>', 0)->update(['debet' => 900_000]);

    $hasil = app(JurnalService::class)->postBanyak([$siap->id, $timpang->id], $this->fat->id);

    expect($hasil['diposting'])->toBe([$siap->no_bukti])
        ->and(array_keys($hasil['dilewati']))->toBe([$timpang->no_bukti])
        ->and($timpang->fresh()->status)->toBe('draft');
});

it('tidak menampilkan posting sekaligus bagi staf keuangan', function () {
    draftMassal('2026-10-05');

    Livewire::actingAs($this->staf)
        ->test('pages::akunting.jurnal-umum')
        ->assertDontSee('Posting semua draft')
        ->call('siapkanPostingMassal', 'saringan')
        ->assertForbidden();
});
