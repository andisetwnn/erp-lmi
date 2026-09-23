<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Tahap Wawancara di pemberkasan KPR.
 *
 * Tanggalnya saja sering tidak cukup: hasil wawancara bisa perlu keterangan
 * singkat — wawancara ulang, diwakilkan pasangan, menunggu berkas susulan.
 * Catatannya menempel pada tahapnya sendiri, bukan tercampur ke catatan
 * pemberkasan yang umum, supaya bisa tampil tepat di bawah tanggalnya.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-WCR', 'nama' => 'Sales Wawancara', 'is_aktif' => true,
        'dbos_username' => 'sales-wcr', 'dbos_password' => 'rahasia123',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-kpr');

    $this->spr = sprWawancara('WC', '01');
});

function sprWawancara(string $blok, string $nomor): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'terjual',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => "KONSUMEN $blok$nomor", 'hp' => '628100001111',
        'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    return Spr::create([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => "SPR/2026/09/09$nomor",
        'tanggal_spr' => now(), 'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'harga_jual' => 198_000_000, 'total_harga' => 198_000_000, 'um_net' => 15_000_000,
    ]);
}

// Tanpa tipe kembalian: `use Livewire\Livewire` membuat `Livewire\Features\...`
// ikut dipendekkan jadi `Livewire\Livewire\Features\...` dan kelasnya tidak ketemu.
function isiWawancara(array $nilai = [])
{
    return Livewire::test('pages::pemberkasan.input')
        ->call('openField', test()->spr->id, 'wcr')
        ->set(array_merge([
            'val_wcr_tanggal' => '2026-09-18',
            'val_wcr_catatan' => 'Wawancara ulang, suami menyusul',
        ], $nilai))
        ->call('simpanField');
}

it('menyimpan catatan bersama tanggal wawancara', function () {
    $this->actingAs($this->admin);

    isiWawancara()->assertHasNoErrors();

    $berkas = SprPemberkasan::where('spr_id', $this->spr->id)->first();

    expect($berkas->wcr_tanggal->toDateString())->toBe('2026-09-18')
        ->and($berkas->wcr_catatan)->toBe('Wawancara ulang, suami menyusul');
});

it('memunculkan catatan lagi saat baris yang sama dibuka ulang', function () {
    $this->actingAs($this->admin);

    isiWawancara();

    Livewire::test('pages::pemberkasan.input')
        ->call('openField', $this->spr->id, 'wcr')
        ->assertSet('val_wcr_catatan', 'Wawancara ulang, suami menyusul');
});

it('menghapus catatan lama kalau isiannya dikosongkan', function () {
    // Dikosongkan berarti memang mau dihapus. Kalau nilai lama dipertahankan,
    // keterangan yang sudah tidak berlaku ikut terbawa terus di tabel.
    $this->actingAs($this->admin);

    isiWawancara();
    isiWawancara(['val_wcr_catatan' => '   ']);

    expect(SprPemberkasan::where('spr_id', $this->spr->id)->value('wcr_catatan'))->toBeNull();
});

it('tetap menyimpan tanggal walau catatannya tidak diisi', function () {
    $this->actingAs($this->admin);

    isiWawancara(['val_wcr_catatan' => null])->assertHasNoErrors();

    $berkas = SprPemberkasan::where('spr_id', $this->spr->id)->first();

    expect($berkas->wcr_tanggal->toDateString())->toBe('2026-09-18')
        ->and($berkas->wcr_catatan)->toBeNull();
});

it('menolak catatan yang melebihi panjang kolomnya', function () {
    // Kolomnya 255 karakter. Tanpa penolakan, MySQL memotong diam-diam.
    $this->actingAs($this->admin);

    isiWawancara(['val_wcr_catatan' => str_repeat('a', 256)])
        ->assertHasErrors(['val_wcr_catatan']);

    expect(SprPemberkasan::where('spr_id', $this->spr->id)->exists())->toBeFalse();
});

it('menampilkan catatan di tabel', function () {
    $this->actingAs($this->admin);

    isiWawancara();

    Livewire::test('pages::pemberkasan.input')
        ->set('selectedProyekId', $this->proyek->id)
        ->set('search', $this->spr->nomor_spr)
        ->assertSee('18/09/26')
        ->assertSee('Wawancara ulang, suami menyusul');
});

it('tidak bisa diisi tanpa hak kelola pemberkasan', function () {
    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');

    $this->actingAs($direktur);

    Livewire::test('pages::pemberkasan.input')
        ->call('openField', $this->spr->id, 'wcr')
        ->assertForbidden();

    expect(SprPemberkasan::where('spr_id', $this->spr->id)->exists())->toBeFalse();
});
