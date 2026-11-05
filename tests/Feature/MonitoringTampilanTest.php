<?php

use App\Exports\AktivitasExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Halaman Monitoring: pencarian bebas, panel "Perlu dilihat" untuk pembatalan
 * dan koreksi, serta grafik denyut per 15 menit.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 14:00:00'));
    $this->rina = User::factory()->create(['name' => 'Rina Keuangan']);
    $this->budi = User::factory()->create(['name' => 'Budi Teknik']);
    $this->actingAs($this->rina);
});

function catatMonitor(string $log, string $event, string $deskripsi, string $waktu, ?User $oleh = null, array $properti = []): Activity
{
    return activity($log)
        ->causedBy($oleh)
        ->event($event)
        ->withProperties($properti)
        ->createdAt(CarbonImmutable::parse($waktu))
        ->log($deskripsi);
}

it('dibuka dengan periode Hari Ini', function () {
    catatMonitor('penjualan', 'spr.submitted', 'SPR #00297 diajukan kemarin', '2026-11-04 16:00');
    catatMonitor('penjualan', 'spr.submitted', 'SPR #00298 diajukan pagi ini', '2026-11-05 08:00');

    Livewire::test('pages::monitoring.index')
        ->assertSet('range', 'today')
        ->assertSee('SPR #00298 diajukan pagi ini')
        ->assertDontSee('SPR #00297 diajukan kemarin');
});

it('mencari bebas di deskripsi dan nama user, lalu membuka periode ke Semua', function () {
    catatMonitor('penjualan', 'spr.submitted', 'SPR #00298 diajukan · ALDO · unit AA-23', '2026-10-10 10:00', $this->rina);
    catatMonitor('unit', 'unit.status_changed', 'Unit AB-01 · Tersedia → Booking', '2026-11-05 09:00', $this->budi);

    $halaman = Livewire::test('pages::monitoring.index')
        ->set('cari', 'AA-23')
        ->assertSet('range', 'all')
        ->assertSeeHtml('unit <mark>AA-23</mark>')
        ->assertDontSee('AB-01');

    expect($halaman->viewData('saluran')['penjualan']['jumlah'])->toBe(1)
        ->and($halaman->viewData('saluran')['unit']['jumlah'])->toBe(0);

    $halaman->set('cari', 'budi')
        ->assertSee('AB-01')
        ->assertDontSee('ALDO')
        ->set('cari', 'tidak ada yang begini')
        ->assertSee('Tidak ada aktivitas yang cocok dengan');
});

it('menandai kata yang dicari tanpa meloloskan HTML dari deskripsi', function () {
    catatMonitor('keuangan', 'realisasi.updated', 'Kuitansi <i>salah</i> dikoreksi', '2026-11-05 10:00', $this->rina);

    Livewire::test('pages::monitoring.index')
        ->set('cari', 'salah')
        ->assertSeeHtml('&lt;i&gt;<mark>salah</mark>&lt;/i&gt;')
        ->assertDontSeeHtml('<i>salah</i>');
});

it('mengumpulkan pembatalan, penghapusan, dan perubahan manual di Perlu dilihat', function () {
    catatMonitor('penjualan', 'spr.cancelled', 'SPR #00100 dibatalkan · KONSUMEN BATAL', '2026-11-05 08:00', $this->rina);
    catatMonitor('keuangan', 'realisasi.deleted', 'Realisasi UM dihapus · KONSUMEN HAPUS', '2026-11-05 09:00', $this->rina);
    catatMonitor('unit', 'unit.status_changed', 'Unit CC-02 · Booking → Tersedia', '2026-11-05 10:00', $this->budi, ['sumber' => 'manual']);
    catatMonitor('unit', 'unit.status_changed', 'Unit CC-03 · Tersedia → Booking', '2026-11-05 11:00', null, ['sumber' => 'otomatis']);
    catatMonitor('penjualan', 'spr.approved', 'SPR #00101 disetujui', '2026-11-05 12:00', $this->rina);

    $perlu = Livewire::test('pages::monitoring.index')->viewData('perluDilihat')->pluck('description')->all();

    expect($perlu)->toBe([
        'Unit CC-02 · Booking → Tersedia',
        'Realisasi UM dihapus · KONSUMEN HAPUS',
        'SPR #00100 dibatalkan · KONSUMEN BATAL',
    ]);
});

it('menghitung denyut per 15 menit untuk setiap hari di periode', function () {
    catatMonitor('penjualan', 'booking.created', 'Booking unit AA-01', '2026-11-05 09:02');
    catatMonitor('keuangan', 'utj.verified', 'UTJ diverifikasi', '2026-11-05 09:14');
    catatMonitor('penjualan', 'spr.submitted', 'SPR diajukan', '2026-11-05 09:16');
    catatMonitor('akses', 'user.diubah', 'User diubah', '2026-11-04 22:40');
    catatMonitor('penjualan', 'spr.submitted', 'SPR lama', '2026-10-01 09:00');

    $halaman = Livewire::test('pages::monitoring.index')->call('setRange', '7d');
    $denyut = $halaman->viewData('denyut');
    $slot = fn (array $hari) => collect($hari['ketukan'])->mapWithKeys(fn ($t) => [$t['slot'] => $t['n']])->all();

    expect($denyut['hari'])->toHaveCount(7)
        ->and($denyut['hari'][0]['tanggal']->toDateString())->toBe('2026-11-05')
        ->and($slot($denyut['hari'][0]))->toBe([36 => 2, 37 => 1])
        ->and($slot($denyut['hari'][1]))->toBe([90 => 1])
        ->and($denyut['puncak'])->toBe(2);

    $halaman->assertSee('09:00–09:15 · 2 aktivitas');
});

it('memilih event dari rincian saluran dan melepasnya lagi', function () {
    catatMonitor('penjualan', 'spr.cancelled', 'SPR #00100 dibatalkan', '2026-11-05 08:00');
    catatMonitor('penjualan', 'spr.approved', 'SPR #00101 disetujui', '2026-11-05 09:00');

    Livewire::test('pages::monitoring.index')
        ->call('setCategory', 'penjualan')
        ->call('pilihEvent', 'spr.cancelled')
        ->assertSet('eventFilter', 'spr.cancelled')
        ->assertDontSee('SPR #00101 disetujui')
        ->call('pilihEvent', 'spr.cancelled')
        ->assertSet('eventFilter', '')
        ->assertSee('SPR #00101 disetujui');
});

it('menggantikan Log User: izin lamanya tetap membuka Monitoring dan alamatnya dialihkan', function () {
    $this->seed(RolePermissionSeeder::class);
    $auditor = User::factory()->create();
    $auditor->givePermissionTo('log.lihat');
    $tanpaIzin = User::factory()->create();

    $this->actingAs($auditor)->get('/monitoring')->assertOk()->assertDontSee('Log User');
    $this->actingAs($auditor)->get('/log-user')->assertRedirect('/monitoring');
    $this->actingAs($tanpaIzin)->get('/monitoring')->assertForbidden();
});

it('mengunduh Excel berisi aktivitas sesuai saringan yang tampil', function () {
    Excel::fake();
    catatMonitor('penjualan', 'spr.submitted', 'SPR #00298 diajukan · ALDO · unit AA-23', '2026-10-10 10:00', $this->rina, ['sales' => 'Ramdan', 'nilai' => 350_000_000]);
    catatMonitor('unit', 'unit.status_changed', 'Unit AA-23 · Booking → Terjual', '2026-11-05 09:00', $this->budi);
    catatMonitor('unit', 'unit.status_changed', 'Unit AB-01 · Tersedia → Booking', '2026-11-05 09:30', $this->budi);

    Livewire::test('pages::monitoring.index')
        ->set('cari', 'AA-23')
        ->call('unduhExcel');

    Excel::assertDownloaded('aktivitas_2026-11-05_140000.xlsx', function (AktivitasExport $export) {
        $baris = $export->query()->get()->map(fn ($act) => $export->map($act));

        return $baris->count() === 2
            && $baris[0][4] === 'Unit AA-23 · Booking → Terjual'
            && $baris[1] === ['2026-10-10 10:00:00', 'Rina Keuangan', 'Penjualan', 'SPR Diajukan', 'SPR #00298 diajukan · ALDO · unit AA-23', 'Ramdan', 350_000_000.0, '{"sales":"Ramdan","nilai":350000000}'];
    });
});
