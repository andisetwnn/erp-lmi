<?php

use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Livewire mengeset ulang locale aplikasi di setiap request update, dan Carbon
 * ikut locale aplikasi. Kalau locale aplikasi masih "en", waktu relatif seperti
 * "23 jam dari sekarang" berubah jadi "23 hours from now" begitu tombol ditekan.
 */
it('tetap memakai bahasa Indonesia untuk waktu relatif setelah aksi Livewire', function () {
    $this->seed();

    $sales = Sales::create([
        'kode' => 'SLS-ID', 'nama' => 'Sales Locale', 'is_aktif' => true,
        'dbos_username' => 'sales-id', 'dbos_password' => 'rahasia123',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => $sales->id, 'proyek_id' => Proyek::first()->id,
        'nama_lengkap' => 'Konsumen Locale', 'hp' => '628120000009', 'sumber' => 'Walk-in',
    ]);
    $log = ProspectCustomerStatusLog::create([
        'prospect_customer_id' => $prospect->id, 'status_ke' => 'cold', 'changed_by_sales_id' => $sales->id,
    ]);
    $log->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    Livewire::actingAs($sales, 'sales')
        ->test('pages::dbos.home')
        ->assertSee('2 hari yang lalu')
        ->call('$refresh')
        ->assertSee('2 hari yang lalu')
        ->assertDontSee('2 days ago');
});
