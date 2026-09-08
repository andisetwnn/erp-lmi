<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\SprSwitchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pindah kavling & swap.
 *
 * SPR lama dibatalkan, SPR baru dibuat untuk unit tujuan. Persetujuan IKUT PINDAH:
 * konsumen dan syaratnya sama, hanya unitnya berganti, dan yang lama sudah disetujui.
 *
 * Tanpa ini SPR baru jatuh lagi ke antrean PM — tab "Menunggu" menyaring
 * status=approved yang pm_approved_at-nya kosong.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-P', 'nama' => 'Sales Pindah', 'is_aktif' => true,
        'dbos_username' => 'sales-p', 'dbos_password' => 'rahasia123',
    ]);

    $this->pm = User::factory()->create();
    $this->pm->assignRole('project-manager');

    $this->svc = app(SprSwitchingService::class);
});

function unitKosong(string $blok, string $nomor = '01'): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id,
        'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'available',
    ]);
}

/** SPR yang sudah SELESAI: approved, disetujui PM, dan bermeterai. */
function sprSelesai(Rumah $rumah, string $kode): Spr
{
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'Konsumen '.$kode, 'nik' => '32'.str_pad((string) crc32($kode), 14, '0'),
        'hp' => '628100000000', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);

    $booking = Booking::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ]);

    $spr = Spr::create([
        'booking_id' => $booking->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => test()->tipe->kategori, 'nomor_spr' => 'SPR/PINDAH/'.$kode,
        'tanggal_spr' => now()->subMonth(), 'harga_jual' => 198_000_000,
        'total_harga' => 198_000_000, 'um_net' => 15_000_000, 'utj_nominal' => 500_000,
        'jenis_pembayaran' => 'kpr', 'status' => 'approved',
        'approved_by_user_id' => test()->pm->id,
        'approved_at' => now()->subDays(20),
        'pm_approved_by_user_id' => test()->pm->id,
        'pm_approved_at' => now()->subDays(18),
        'pm_catatan' => 'disetujui, berkas lengkap',
        'ttd_sales_path' => 'ttd/sales.png',
        'ttd_finance_path' => 'ttd/finance.png',
        'ttd_pm_path' => 'ttd/pm.png',
        'dokumen_signed_path' => 'spr/lama-bertandatangan.pdf',
        'materai_file_path' => 'spr/lama-materai.pdf',
        'konsumen_ttd_path' => 'ttd/konsumen-lama.png',
        'konsumen_signed_at' => now()->subDays(15),
        'spr_finalized_at' => now()->subDays(14),
    ]);

    $rumah->update(['status' => 'terjual']);

    return $spr;
}

it('membawa persetujuan PM ke SPR baru', function () {
    $lama = sprSelesai(unitKosong('CD', '21'), 'A');
    $tujuan = unitKosong('DB', '14');

    $baru = $this->svc->pindahUnit($lama, $tujuan->id, 'konsumen minta pindah', $this->pm->id);

    expect($baru->pm_approved_at)->not->toBeNull()
        ->and($baru->pm_approved_by_user_id)->toBe($this->pm->id)
        ->and($baru->approved_at)->not->toBeNull()
        ->and($baru->approved_by_user_id)->toBe($this->pm->id)
        ->and($baru->pm_catatan)->toBe('disetujui, berkas lengkap');
});

it('tidak memunculkan SPR pindahan di antrean persetujuan', function () {
    // Antrean "Menunggu" = status approved + pm_approved_at kosong.
    $lama = sprSelesai(unitKosong('CD', '22'), 'B');
    $tujuan = unitKosong('DB', '15');

    $sebelum = Spr::where('status', 'approved')->whereNull('pm_approved_at')->count();

    $this->svc->pindahUnit($lama, $tujuan->id, 'pindah', $this->pm->id);

    expect(Spr::where('status', 'approved')->whereNull('pm_approved_at')->count())->toBe($sebelum);
});

it('membawa spesimen tanda tangan supaya cetakan tetap lengkap', function () {
    $lama = sprSelesai(unitKosong('CD', '23'), 'C');
    $tujuan = unitKosong('DB', '16');

    $baru = $this->svc->pindahUnit($lama, $tujuan->id, 'pindah', $this->pm->id);

    expect($baru->ttd_sales_path)->toBe('ttd/sales.png')
        ->and($baru->ttd_finance_path)->toBe('ttd/finance.png')
        ->and($baru->ttd_pm_path)->toBe('ttd/pm.png');
});

it('tidak membawa berkas bertanda tangan milik unit lama', function () {
    // Dokumen final, materai, dan tanda tangan konsumen itu artefak unit LAMA.
    // Menyalinnya membuat SPR unit baru menampilkan berkas untuk kavling yang salah.
    $lama = sprSelesai(unitKosong('CD', '24'), 'D');
    $tujuan = unitKosong('DB', '17');

    $baru = $this->svc->pindahUnit($lama, $tujuan->id, 'pindah', $this->pm->id);

    expect($baru->dokumen_signed_path)->toBeNull()
        ->and($baru->materai_file_path)->toBeNull()
        ->and($baru->konsumen_ttd_path)->toBeNull()
        ->and($baru->konsumen_signed_at)->toBeNull()
        ->and($baru->spr_finalized_at)->toBeNull();
});

it('membatalkan SPR lama dan menautkannya ke yang baru', function () {
    $lama = sprSelesai(unitKosong('CD', '25'), 'E');
    $tujuan = unitKosong('DB', '18');

    $baru = $this->svc->pindahUnit($lama, $tujuan->id, 'pindah', $this->pm->id);
    $lama = $lama->fresh();

    expect($lama->status)->toBe('cancelled')
        ->and($lama->switched_to_spr_id)->toBe($baru->id)
        ->and($baru->switched_from_spr_id)->toBe($lama->id);
});

it('membawa persetujuan pada kedua SPR hasil swap', function () {
    $unitA = unitKosong('CD', '26');
    $unitB = unitKosong('DB', '19');
    $sprA = sprSelesai($unitA, 'F');
    $sprB = sprSelesai($unitB, 'G');

    [$baruA, $baruB] = array_values($this->svc->swapSpr($sprA, $sprB, 'tukar unit', $this->pm->id));

    expect($baruA->pm_approved_at)->not->toBeNull()
        ->and($baruB->pm_approved_at)->not->toBeNull();
});

it('menampilkan pemilih SPR yang bisa dicari di halaman pindah kavling', function () {
    // Dropdown biasa tidak bisa dicari; dengan ratusan SPR itu menyusahkan.
    // Yang dicek nama konsumennya, bukan nomor SPR: @js() mengubah "/" jadi "\/"
    // di dalam atribut, jadi mencocokkan nomor SPR utuh selalu meleset.
    sprSelesai(unitKosong('EA', '01'), 'H');

    $this->actingAs($this->pm);

    $html = Livewire::test('pages::marketing.spr-pindah-list')->html();

    expect($html)->toContain('pilihCariCmp')
        ->and($html)->toContain('Konsumen H');
});

it('menampilkan pemilih SPR yang bisa dicari di halaman pembatalan', function () {
    // mount() mengambil proyek dari sesi, jadi propertinya diisi setelah komponen jalan.
    sprSelesai(unitKosong('EC', '01'), 'I');

    $this->actingAs($this->pm);

    $html = Livewire::test('pages::marketing.spr-pembatalan-input')
        ->set('proyekId', $this->proyek->id)
        ->html();

    expect($html)->toContain('pilihCariCmp')
        ->and($html)->toContain('Konsumen I');
});
