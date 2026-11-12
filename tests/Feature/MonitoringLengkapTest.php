<?php

use App\Models\Master\BankKpr;
use App\Models\Master\Booking;
use App\Models\Master\Coa;
use App\Models\Master\Notaris;
use App\Models\Master\Perusahaan;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkad;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\JurnalService;
use App\Services\RencanaAkadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Monitoring menangkap seluruh kegiatan penting, bukan hanya penjualan dan
 * keuangan: status unit beserta sebabnya, pemberkasan, jurnal, biaya tambahan,
 * serta perubahan user dan izin.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();
    $this->sales = Sales::create([
        'kode' => 'SLS-MON', 'nama' => 'Sales Monitor', 'is_aktif' => true,
        'dbos_username' => 'sales-monitor', 'dbos_password' => 'rahasia123',
    ]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
});

function unitMonitor(string $status, string $nomor = '1'): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => 'MN', 'nomor_unit' => $nomor, 'status' => $status,
    ]);
}

function sprMonitor(Rumah $rumah, string $status = 'submitted', array $booking = []): Spr
{
    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'KONSUMEN MONITOR '.$rumah->nomor_unit, 'hp' => '628100002222',
        'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    $b = Booking::create(array_merge([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'tanggal_booking' => now(), 'tanggal_expired' => now()->addDay(), 'status' => 'sukses',
    ], $booking));

    return Spr::create([
        'booking_id' => $b->id, 'sales_id' => test()->sales->id,
        'prospect_customer_id' => $prospect->id, 'rumah_id' => $rumah->id,
        'kategori' => 'subsidi', 'nomor_spr' => 'SPR/2026/11/8'.$rumah->nomor_unit,
        'tanggal_spr' => now(), 'jenis_pembayaran' => 'kpr', 'status' => $status,
        'harga_jual' => 198_000_000, 'total_harga' => 198_000_000, 'um_net' => 15_000_000, 'utj_nominal' => 500_000,
    ]);
}

function logTerakhir(string $event): ?Activity
{
    return Activity::where('event', $event)->latest('id')->first();
}

it('mencatat unit terjual beserta sebabnya saat SPR disetujui', function () {
    $rumah = unitMonitor('booking');
    $spr = sprMonitor($rumah);

    $spr->update(['status' => 'approved']);

    $log = logTerakhir('unit.status_changed');
    expect($rumah->fresh()->status)->toBe('terjual')
        ->and($log->properties['from'])->toBe('booking')
        ->and($log->properties['to'])->toBe('terjual')
        ->and($log->properties['sebab'])->toContain("SPR #{$spr->nomor_display}")
        ->and($log->properties['sumber'])->toBe('otomatis');
});

it('mencatat pelepasan unit oleh job booking 24 jam', function () {
    $rumah = unitMonitor('booking');
    $prospect = ProspectCustomer::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id,
        'nama_lengkap' => 'KONSUMEN LEWAT', 'hp' => '628100003333', 'sumber' => 'Walk-in', 'status' => 'finish',
    ]);
    Booking::create([
        'sales_id' => $this->sales->id, 'proyek_id' => $this->proyek->id, 'prospect_customer_id' => $prospect->id,
        'rumah_id' => $rumah->id, 'tanggal_booking' => now()->subDays(2), 'tanggal_expired' => now()->subHour(), 'status' => 'aktif',
    ]);

    $this->artisan('bookings:expire')->assertSuccessful();

    $log = logTerakhir('unit.status_changed');
    expect($log->properties['to'])->toBe('available')
        ->and($log->properties['sebab'])->toBe('Booking lewat 24 jam tanpa SPR')
        ->and($log->causer)->toBeNull();
});

it('menandai perubahan status di Master Rumah sebagai manual dan hanya itu yang masuk lonceng', function () {
    $otomatis = unitMonitor('booking', '2');
    sprMonitor($otomatis)->update(['status' => 'approved']);

    $rumah = unitMonitor('available', '3');
    $this->actingAs($this->admin);

    Livewire::test('pages::master.rumah')
        ->set('selectedProyekId', $this->proyek->id)
        ->set('editId', $rumah->id)
        ->set('mode', 'single')
        ->set('blok', 'MN')
        ->set('nomor_unit', '3')
        ->set('tipe_rumah_id', (string) $this->tipe->id)
        ->set('biaya_tambahan', '5000000')
        ->set('discount', '')
        ->set('ppn', '')
        ->set('status', 'draft')
        ->call('save')
        ->assertHasNoErrors();

    $log = logTerakhir('unit.status_changed');
    expect($log->properties['sumber'])->toBe('manual')
        ->and($log->properties['sebab'])->toBe('Diubah manual di Master Rumah')
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and(logTerakhir('unit.updated')->description)->toContain('Biaya tambahan');

    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');

    Livewire::actingAs($direktur)
        ->test('notification-bell')
        ->assertSee('Unit MN-03 · Tersedia → Draft')
        ->assertDontSee('Booking → Terjual');
});

it('hanya menautkan halaman Monitoring untuk yang boleh membukanya', function () {
    $direktur = User::factory()->create();
    $direktur->assignRole('direktur');
    $keuangan = User::factory()->create();
    $keuangan->assignRole('finance');

    Livewire::actingAs($direktur)->test('notification-bell')->assertSee('Lihat semua di Monitoring');
    Livewire::actingAs($keuangan)->test('notification-bell')->assertDontSee('Lihat semua di Monitoring');
    $this->actingAs($keuangan)->get(route('monitoring.index'))->assertForbidden();
});

it('mencatat tahap pemberkasan beserta isi lama dan barunya, sekali saja', function () {
    $spr = sprMonitor(unitMonitor('terjual'), 'approved');
    $this->actingAs($this->admin);

    $isiSp3k = fn () => Livewire::test('pages::pemberkasan.input')
        ->call('openField', $spr->id, 'sp3k')
        ->set('val_sp3k_tanggal', '2026-11-03')
        ->set('val_sp3k_nomor', 'SP3K/01')
        ->set('val_sp3k_nominal', '179.000.000')
        ->call('simpanField')
        ->assertHasNoErrors();

    $isiSp3k();
    $isiSp3k();

    $log = logTerakhir('pemberkasan.sp3k');
    expect(Activity::where('event', 'pemberkasan.sp3k')->count())->toBe(1)
        ->and($log->log_name)->toBe('kpr')
        ->and($log->properties['perubahan']['Nominal SP3K'])->toBe([null, 'Rp 179.000.000'])
        ->and($log->properties['perubahan']['Tanggal SP3K'])->toBe([null, '03/11/2026']);
});

it('mencatat jurnal dari dibuat sampai dibalik', function () {
    $this->actingAs($this->admin);
    $svc = app(JurnalService::class);
    [$akunA, $akunB] = Coa::where('is_header', false)->limit(2)->pluck('id')->all();
    $baris = [['coa_id' => $akunA, 'debet' => 250_000, 'kredit' => 0], ['coa_id' => $akunB, 'debet' => 0, 'kredit' => 250_000]];
    $data = ['perusahaan_id' => Perusahaan::value('id'), 'tanggal' => '2026-11-04', 'tipe' => 'umum', 'keterangan' => 'Beli ATK'];

    $draft = $svc->create($data + ['no_bukti' => 'KAS/11/26/0001'], $baris);
    $svc->update($draft, $data + ['no_bukti' => 'KAS/11/26/0001', 'keterangan' => 'Beli ATK kantor'], $baris);
    $svc->delete($draft->fresh());

    $posting = $svc->post($svc->create($data + ['no_bukti' => 'KAS/11/26/0002'], $baris));
    $svc->reverse($posting, 'salah akun');

    foreach (['jurnal.dibuat', 'jurnal.diubah', 'jurnal.dihapus', 'jurnal.diposting', 'jurnal.dibalik'] as $event) {
        expect(logTerakhir($event))->not->toBeNull();
    }
    expect(logTerakhir('jurnal.diposting')->properties['nilai'])->toEqual(250000)
        ->and(logTerakhir('jurnal.dibalik')->properties['catatan'])->toContain('KAS/11/26/0002');
});

it('mencatat pembayaran dan koreksi biaya tambahan', function () {
    $spr = sprMonitor(unitMonitor('terjual'), 'approved');
    $this->actingAs($this->admin);

    $layar = Livewire::test('pages::marketing.spr-show', ['id' => $spr->id])
        ->call('openInputBiayaTambahan')
        ->set('btJumlah', '2000000')
        ->set('btMetode', 'transfer')
        ->call('saveBiayaTambahan')
        ->assertHasNoErrors();

    $dibayar = logTerakhir('biaya_tambahan.dibayar');
    expect($dibayar->properties['nilai'])->toEqual(2000000)
        ->and($dibayar->log_name)->toBe('keuangan');

    $layar->call('openEditBiayaTambahan', $dibayar->subject_id)
        ->set('btJumlah', '2500000')
        ->call('saveBiayaTambahan')
        ->assertHasNoErrors();

    expect(logTerakhir('biaya_tambahan.dikoreksi')->properties['perubahan']['Jumlah'])
        ->toBe(['Rp 2.000.000', 'Rp 2.500.000']);
});

it('mencatat perubahan izin dan user tanpa pernah menyimpan password', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::master.user')
        ->call('togglePermission', 'admin-sales', 'pembayaran.kelola')
        ->call('openCreate')
        ->set('name', 'Rina Baru')
        ->set('username', 'rina.baru')
        ->set('email', 'rina.baru@lmi.test')
        ->set('password', 'RahasiaSekali9')
        ->set('selectedRole', 'admin-sales')
        ->call('save')
        ->assertHasNoErrors();

    $baru = User::where('username', 'rina.baru')->first();

    Livewire::test('pages::master.user')
        ->call('openReset', $baru->id)
        ->set('newPassword', 'GantiLagi9999')
        ->call('submitReset');

    expect(logTerakhir('izin.diberikan')->properties['role'])->toBe('admin-sales')
        ->and(logTerakhir('user.dibuat')->subject_id)->toBe($baru->id)
        ->and(logTerakhir('user.sandi_direset'))->not->toBeNull()
        ->and(Activity::where('log_name', 'akses')->get()->pluck('properties')->toJson())
        ->not->toContain('RahasiaSekali9')
        ->not->toContain('GantiLagi9999');
});

it('mencatat perpindahan status rencana akad', function () {
    $this->actingAs($this->admin);
    $svc = app(RencanaAkadService::class);
    $rencana = RencanaAkad::create([
        'proyek_id' => $this->proyek->id,
        'nomor' => $svc->nomorBerikutnya($this->proyek, '2026-11-20'),
        'tanggal_rencana' => '2026-11-20',
        'bank_kpr_id' => BankKpr::create(['kode' => 'MON', 'nama' => 'Bank Monitor', 'biaya_proses_akad' => 0])->id,
        'notaris_id' => Notaris::create(['nama' => 'Notaris Monitor', 'nik' => '3374010101800009'])->id,
        'created_by_user_id' => $this->admin->id,
    ]);

    $svc->ubahStatus($rencana, 'batal', $this->admin->id, ['alasan' => 'jadwal bank berubah']);
    $svc->ubahStatus($rencana->fresh(), 'draft', $this->admin->id);

    expect(logTerakhir('rencana_akad.batal')->properties['alasan'])->toBe('jadwal bank berubah')
        ->and(logTerakhir('rencana_akad.dikembalikan')->properties['from'])->toBe('batal');
});

it('menampilkan kategori baru dan perubahan tipe rumah di Monitoring', function () {
    $this->actingAs($this->admin);
    $this->tipe->update(['harga_jual' => (float) $this->tipe->harga_jual + 1_000_000]);
    app(JurnalService::class)->create(
        ['perusahaan_id' => Perusahaan::value('id'), 'tanggal' => '2026-11-04', 'no_bukti' => 'KAS/11/26/0009', 'keterangan' => 'Bayar listrik'],
        [['coa_id' => Coa::where('is_header', false)->value('id'), 'debet' => 100_000], ['coa_id' => Coa::where('is_header', false)->skip(1)->value('id'), 'kredit' => 100_000]],
    );

    Livewire::test('pages::monitoring.index')
        ->assertSee('Pemberkasan')
        ->assertSee('Akunting')
        ->assertSee('Akses')
        ->call('setCategory', 'unit')
        ->assertSee('Tipe Rumah Diubah')
        ->call('setCategory', 'akunting')
        ->assertSee('Jurnal KAS/11/26/0009 dibuat');
});
