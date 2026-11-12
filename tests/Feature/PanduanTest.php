<?php

use App\Models\Master\Perusahaan;
use App\Models\Master\Sales;
use App\Models\PanduanBaca;
use App\Models\User;
use App\Support\Panduan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Panduan PDF per modul: hanya bisa dibuka setelah login dan oleh orang yang
 * punya izin modulnya; menu dan tombolnya tidak tampil bila tidak relevan.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->folder = storage_path('framework/testing/panduan-'.uniqid());
    File::ensureDirectoryExists($this->folder);
    File::put($this->folder.'/pembelian.pdf', '%PDF-1.4 panduan uji');
    config(['panduan.folder' => $this->folder]);

    $this->fat = User::factory()->create();
    $this->fat->assignRole('finance');
    $this->sales = User::factory()->create();
    $this->sales->givePermissionTo('laporan.lihat');
});

afterEach(fn () => File::deleteDirectory($this->folder));

it('membuka PDF panduan untuk orang yang punya izin modulnya, dan bisa diunduh', function () {
    $this->actingAs($this->fat)->get(route('panduan.buka', 'pembelian'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="Panduan Pembelian - ERP LMI (11 Nov 2026).pdf"');

    expect($this->actingAs($this->fat)->get(route('panduan.buka', ['kunci' => 'pembelian', 'unduh' => 1]))->headers->get('Content-Disposition'))
        ->toStartWith('attachment;');
});

it('menolak orang tanpa izin modulnya, tamu, dan panduan yang belum ada', function () {
    $this->actingAs($this->sales)->get(route('panduan.buka', 'pembelian'))->assertForbidden();
    $this->actingAs($this->fat)->get(route('panduan.buka', 'akunting'))->assertNotFound();

    File::delete($this->folder.'/pembelian.pdf');
    $this->actingAs($this->fat)->get(route('panduan.buka', 'pembelian'))->assertNotFound();

    auth()->logout();
    $this->get(route('panduan.buka', 'pembelian'))->assertRedirect(route('login'));
});

it('menampilkan menu dan daftar panduan sesuai modul yang bisa dibuka', function () {
    $this->actingAs($this->fat)->get(route('panduan.index'))
        ->assertOk()
        ->assertSee('Panduan Pembelian')
        ->assertSee('Diperbarui 11 November 2026')
        ->assertSeeHtml('href="'.route('panduan.index').'"');

    $this->actingAs($this->sales)->get(route('panduan.index'))
        ->assertOk()
        ->assertSee('Panduan untuk modul yang kamu pakai sedang disusun.')
        ->assertDontSee('Panduan Pembelian');
});

it('menaruh tombol Panduan di halaman Pembelian hanya bila PDF-nya ada', function () {
    Livewire::actingAs($this->fat)->test('pages::pembelian.permintaan')
        ->assertSeeHtml('href="'.route('panduan.buka', 'pembelian').'"');

    File::delete($this->folder.'/pembelian.pdf');
    Livewire::actingAs($this->fat)->test('pages::pembelian.po')
        ->assertDontSeeHtml('href="'.route('panduan.buka', 'pembelian').'"');
});

it('menaruh tombol Panduan Akunting di halaman laporan, juga untuk direktur yang hanya melihat', function () {
    File::put($this->folder.'/akunting.pdf', '%PDF-1.4 panduan uji');
    Perusahaan::create(['nama' => 'PT Langit Membangun Indonesia', 'kode_surat' => 'LMI']);
    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');

    Livewire::actingAs($direktur)->test('pages::akunting.neraca-saldo')
        ->assertSeeHtml('href="'.route('panduan.buka', 'akunting').'"');
    $this->actingAs($direktur)->get(route('panduan.buka', 'akunting'))->assertOk();
    $this->actingAs($this->sales)->get(route('panduan.buka', 'akunting'))->assertForbidden();
});

it('mencatat siapa membuka panduan, dan konfirmasi baca hanya setelah dibuka', function () {
    Livewire::actingAs($this->fat)->test('pages::panduan.index')
        ->call('tandaiSudahBaca', 'pembelian');
    expect(Panduan::status(Panduan::jejakSatu($this->fat, 'pembelian')))->toBe(Panduan::BELUM);

    $this->actingAs($this->fat)->get(route('panduan.buka', 'pembelian'))->assertOk();
    $this->actingAs($this->fat)->get(route('panduan.buka', ['kunci' => 'pembelian', 'unduh' => 1]))->assertOk();
    // Potongan lanjutan dari penampil PDF tidak dihitung sebagai pembukaan baru.
    $this->actingAs($this->fat)->get(route('panduan.buka', 'pembelian'), ['Range' => 'bytes=500-'])->assertSuccessful();

    $jejak = Panduan::jejakSatu($this->fat, 'pembelian');
    expect($jejak->jumlah_dibuka)->toBe(2)
        ->and(Panduan::status($jejak))->toBe(Panduan::DIBUKA)
        ->and(Panduan::jumlahBelumDibaca($this->fat))->toBe(1);

    Livewire::actingAs($this->fat)->test('pages::panduan.index')
        ->call('tandaiSudahBaca', 'pembelian')
        ->assertSee('Sudah dibaca')
        ->assertDontSee('Saya sudah membaca');

    expect(Panduan::status(Panduan::jejakSatu($this->fat, 'pembelian')))->toBe(Panduan::DIBACA)
        ->and(Panduan::jumlahBelumDibaca($this->fat))->toBe(0);
});

it('meminta membaca ulang setelah panduan diperbarui ke versi baru', function () {
    $this->actingAs($this->fat)->get(route('panduan.buka', 'pembelian'));
    Panduan::konfirmasiBaca($this->fat, 'pembelian');

    PanduanBaca::query()->update(['versi' => '2026-01-01']);

    expect(Panduan::status(Panduan::jejakSatu($this->fat, 'pembelian')))->toBe(Panduan::BELUM)
        ->and(Panduan::jumlahBelumDibaca($this->fat))->toBe(1);
});

it('menampilkan rekap pembaca hanya untuk direktur dan super-admin', function () {
    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');
    $this->actingAs($this->fat)->get(route('panduan.buka', 'pembelian'));
    Panduan::konfirmasiBaca($this->fat, 'pembelian');

    $this->actingAs($this->fat)->get(route('panduan.rekap'))->assertForbidden();
    $this->actingAs($this->sales)->get(route('panduan.rekap'))->assertForbidden();

    // Direktur boleh membuka panduan Pembelian, tapi tidak dilacak dan tidak diingatkan.
    $this->actingAs($direktur)->get(route('panduan.buka', 'pembelian'))->assertOk();
    expect(Panduan::jejakSatu($direktur, 'pembelian'))->toBeNull()
        ->and(Panduan::jumlahBelumDibaca($direktur))->toBe(0);
    Livewire::actingAs($direktur)->test('pages::panduan.index')
        ->assertSee('Panduan Pembelian')
        ->assertDontSee('Saya sudah membaca');

    Livewire::actingAs($direktur)->test('pages::panduan.rekap')
        ->assertSee($this->fat->name)
        ->assertDontSee($direktur->name)
        ->assertSee(now()->translatedFormat('j M Y'))
        ->assertDontSee($this->sales->name)
        ->set('status', 'belum')
        ->assertDontSee($this->fat->name);
});

it('mencatat sales DBOS yang membuka dan mengonfirmasi panduan DBOS, lalu tampil di rekap', function () {
    File::put($this->folder.'/dbos.pdf', '%PDF-1.4 panduan uji');
    $sales = Sales::create([
        'kode' => 'SLS-PD', 'nama' => 'Sales Panduan', 'is_aktif' => true,
        'dbos_username' => 'sales-panduan', 'dbos_password' => 'rahasia123',
    ]);

    // Belum dibuka: konfirmasi ditolak, kartu pengingat tampil di Cara Kerja.
    $this->actingAs($sales, 'sales')->post(route('dbos.panduan.baca'))->assertRedirect();
    expect(Panduan::status(Panduan::jejakSatu($sales, 'dbos')))->toBe(Panduan::BELUM);
    $this->actingAs($sales, 'sales')->get(route('dbos.cara-kerja'))
        ->assertOk()
        ->assertSee('Panduan DBOS (PDF)')
        ->assertSee('Saya sudah membaca');

    $this->actingAs($sales, 'sales')->get(route('dbos.panduan'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($sales, 'sales')->post(route('dbos.panduan.baca'))->assertRedirect();
    expect(Panduan::status(Panduan::jejakSatu($sales, 'dbos')))->toBe(Panduan::DIBACA);
    $this->actingAs($sales, 'sales')->get(route('dbos.cara-kerja'))
        ->assertSee('Sudah dibaca')
        ->assertDontSee('Saya sudah membaca');

    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');
    Livewire::actingAs($direktur)->test('pages::panduan.rekap')
        ->assertSee('Sales Panduan')
        ->assertSee('Sales lapangan (DBOS)')
        ->set('peran', 'finance')
        ->assertDontSee('Sales Panduan');
});

it('tidak membuka panduan DBOS tanpa login sales', function () {
    File::put($this->folder.'/dbos.pdf', '%PDF-1.4 panduan uji');

    $this->get(route('dbos.panduan'))->assertRedirect();
    expect(PanduanBaca::count())->toBe(0);
});

it('menyertakan PDF untuk setiap panduan yang terdaftar', function () {
    config(['panduan.folder' => null]);

    foreach (array_keys(Panduan::DAFTAR) as $kunci) {
        expect(Panduan::tersedia($kunci))->toBeTrue("PDF panduan {$kunci} belum ada")
            ->and(file_get_contents(Panduan::berkas($kunci), length: 5))->toBe('%PDF-');
    }
});
