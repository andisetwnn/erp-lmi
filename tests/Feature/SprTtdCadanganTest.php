<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * TTD di SPR disimpan sebagai snapshot path saat submit/konfirmasi/approve.
 * Kalau sales menggambar ulang TTD-nya, file lama dihapus — snapshot lalu
 * menunjuk ke file yang tidak ada dan kotak TTD tampil sebagai gambar rusak.
 * Yang dipakai harus file yang benar-benar ada: snapshot dulu, lalu TTD
 * terdaftar milik sales/user.
 */
beforeEach(function () {
    $this->seed();

    $this->folder = 'ttd-uji-'.uniqid();
    File::ensureDirectoryExists(storage_path('app/public/'.$this->folder));

    $proyek = Proyek::first();
    $tipe = TipeRumah::where('proyek_id', $proyek->id)->first();
    $this->sales = Sales::create([
        'kode' => 'SLS-TTD', 'nama' => 'Sales TTD', 'is_aktif' => true,
        'dbos_username' => 'sales-ttd', 'dbos_password' => 'rahasia123',
    ]);
    $rumah = Rumah::create([
        'proyek_id' => $proyek->id, 'tipe_rumah_id' => $tipe->id,
        'blok' => 'TT', 'nomor_unit' => '1', 'status' => 'booking',
    ]);
    $prospect = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $proyek->id, 'nama_lengkap' => 'Konsumen TTD',
        'nik' => '3201000000007777', 'hp' => '628113', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $booking = Booking::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => '2026-10-01', 'tanggal_expired' => '2026-10-02', 'status' => 'sukses',
    ]);
    $this->spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => $this->sales->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'kategori' => 'subsidi', 'nomor_spr' => 'SPR/TTD/1', 'tanggal_spr' => '2026-10-01',
        'harga_jual' => 166_000_000, 'total_harga' => 166_000_000, 'um_net' => 15_000_000, 'utj_nominal' => 1_000_000,
        'jenis_pembayaran' => 'kpr', 'status' => 'submitted',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/public/'.$this->folder));
});

it('memakai TTD terdaftar sales kalau file snapshot di SPR sudah terhapus', function () {
    $baru = $this->folder.'/ttd-baru.png';
    File::put(storage_path('app/public/'.$baru), 'png');

    $this->sales->forceFill(['tanda_tangan_path' => $baru])->save();
    $this->spr->forceFill(['ttd_sales_path' => $this->folder.'/ttd-lama-terhapus.png'])->save();

    Livewire::actingAs($this->admin)
        ->test('pages::marketing.spr-show', ['id' => $this->spr->id])
        ->set('activeTab', 'spr')
        ->assertSeeHtml('ttd-baru.png')
        ->assertDontSeeHtml('ttd-lama-terhapus.png');
});

it('tetap memakai snapshot SPR selama filenya masih ada', function () {
    $lama = $this->folder.'/ttd-snapshot.png';
    $baru = $this->folder.'/ttd-baru.png';
    File::put(storage_path('app/public/'.$lama), 'png');
    File::put(storage_path('app/public/'.$baru), 'png');

    $this->sales->forceFill(['tanda_tangan_path' => $baru])->save();
    $this->spr->forceFill(['ttd_sales_path' => $lama])->save();

    Livewire::actingAs($this->admin)
        ->test('pages::marketing.spr-show', ['id' => $this->spr->id])
        ->set('activeTab', 'spr')
        ->assertSeeHtml('ttd-snapshot.png')
        ->assertDontSeeHtml('ttd-baru.png');
});
