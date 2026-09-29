<?php

use App\Models\Master\BankKpr;
use App\Models\Master\Booking;
use App\Models\Master\Notaris;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkad;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\RencanaAkadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Rencana Akad — satu sesi akad berjamaah: satu notaris, satu bank, satu tanggal.
 *
 * Syarat unit: SPR sudah disetujui, dan belum dipegang rencana lain yang berjalan.
 *
 * Uang muka SENGAJA bukan penghalang. Lembar Aju Dana yang dipakai selama ini memuat
 * unit dengan UM baru 16% — kekurangannya dicatat sebagai target dan dilunasi sambil
 * proses berjalan. Yang dilakukan sistem: menghitung dan menampilkannya, bukan
 * menggugurkan unitnya.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->sales = Sales::create([
        'kode' => 'SLS-A', 'nama' => 'Sales Akad', 'is_aktif' => true,
        'dbos_username' => 'sales-a', 'dbos_password' => 'rahasia123',
    ]);

    $this->bank = BankKpr::create(['kode' => 'SOL', 'nama' => 'BTN KC Solo', 'biaya_proses_akad' => 1_645_000]);
    $this->notaris = Notaris::create([
        'nama' => 'Slamet Utomo, S.H., M.Kn.',
        'nik' => '3374010101800001',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-kpr');

    $this->svc = app(RencanaAkadService::class);
});

/** SPR siap akad: approved, UM lunas lewat realisasi + UTJ. */
function sprAkad(string $blok, float $umNet = 15_000_000, float $umBayar = 14_500_000, float $utj = 500_000, string $status = 'approved'): Spr
{
    $rumah = Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => '01', 'status' => 'available',
    ]);

    $prospect = ProspectCustomer::create([
        'sales_id' => test()->sales->id, 'proyek_id' => test()->proyek->id,
        'nama_lengkap' => 'Konsumen '.$blok, 'nik' => '32'.str_pad((string) crc32($blok), 14, '0'),
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
        'kategori' => 'subsidi', 'nomor_spr' => 'SPR/AKAD/'.$blok,
        'tanggal_spr' => now()->subMonth(), 'harga_jual' => 166_000_000,
        'total_harga' => 166_000_000, 'um_net' => $umNet, 'utj_nominal' => $utj,
        'jenis_pembayaran' => 'kpr', 'status' => $status,
    ]);

    if ($umBayar > 0) {
        SprRealisasiPembayaran::create([
            'spr_id' => $spr->id, 'jenis' => 'um',
            'tanggal_bayar' => now()->subDays(5), 'jumlah' => $umBayar,
            'nomor_kwitansi' => 'KW-'.$blok, 'metode' => 'transfer',
        ]);
    }

    SprPemberkasan::create(['spr_id' => $spr->id]);

    return $spr;
}

function rencanaBaru(string $tanggal = '2026-01-29'): RencanaAkad
{
    return RencanaAkad::create([
        'proyek_id' => test()->proyek->id,
        'nomor' => test()->svc->nomorBerikutnya(test()->proyek, $tanggal),
        'tanggal_rencana' => $tanggal,
        'bank_kpr_id' => test()->bank->id,
        'notaris_id' => test()->notaris->id,
        'created_by_user_id' => test()->admin->id,
    ]);
}

it('menomori per proyek per bulan mengikuti kode surat', function () {
    expect($this->proyek->kode_surat)->not->toBeEmpty();

    $satu = rencanaBaru('2026-01-29');
    expect($satu->nomor)->toBe('1/'.$this->proyek->kode_surat.'/R.AKAD/01/2026');

    $dua = rencanaBaru('2026-01-30');
    expect($dua->nomor)->toBe('2/'.$this->proyek->kode_surat.'/R.AKAD/01/2026');

    // Bulan baru mulai dari satu lagi.
    $tiga = rencanaBaru('2026-02-03');
    expect($tiga->nomor)->toBe('1/'.$this->proyek->kode_surat.'/R.AKAD/02/2026');
});

it('menghitung UM dari realisasi ditambah UTJ', function () {
    $spr = sprAkad('AA', umNet: 15_000_000, umBayar: 14_500_000, utj: 500_000);

    $um = $this->svc->ringkasanUm($spr);

    expect($um['um'])->toBe(14500000.0)
        ->and($um['utj'])->toBe(500000.0)
        ->and($um['terkumpul'])->toBe(15000000.0)
        ->and($um['kurang'])->toBe(0.0)
        ->and($um['lunas'])->toBeTrue();
});

it('tetap menerima unit yang uang mukanya belum lunas', function () {
    // Lembar Aju Dana yang dipakai selama ini memuat unit dengan UM baru 16%.
    // Kekurangannya dilunasi sambil proses berjalan, jadi ini bukan penghalang.
    $spr = sprAkad('BB', umNet: 15_000_000, umBayar: 2_500_000, utj: 0);

    expect($this->svc->layak($spr))->toBeTrue()
        ->and($this->svc->alasanTidakLayak($spr))->toBeNull();

    $unit = $this->svc->tambahUnit(rencanaBaru(), $spr);
    expect($unit)->not->toBeNull();

    // Kekurangannya tetap dihitung dan ditampilkan, bukan disembunyikan.
    $um = $this->svc->ringkasanUm($spr);
    expect($um['kurang'])->toBe(12500000.0)
        ->and($um['lunas'])->toBeFalse();
});

it('menolak SPR yang belum disetujui', function () {
    $spr = sprAkad('CC', status: 'draft');

    expect($this->svc->alasanTidakLayak($spr))->toContain('belum disetujui');
});

it('menerima unit tanpa sertifikat dan tanpa titipan', function () {
    // Aturan Juli 2024: sertifikat boleh belum ada, titipan boleh nol.
    $spr = sprAkad('DD');
    $unit = $this->svc->tambahUnit(rencanaBaru(), $spr);

    expect($unit->hgb)->toBeNull()
        ->and($unit->jenis_akad)->toBe('ppjb')
        ->and($unit->status)->toBe('draft');
});

it('mencegah satu unit dijadwalkan di dua rencana sekaligus', function () {
    $spr = sprAkad('EE');
    $pertama = rencanaBaru('2026-01-29');
    $this->svc->tambahUnit($pertama, $spr);

    $kedua = rencanaBaru('2026-02-10');

    expect($this->svc->alasanTidakLayak($spr, $kedua))->toContain($pertama->nomor);
    expect(fn () => $this->svc->tambahUnit($kedua, $spr))->toThrow(ValidationException::class);
});

it('membebaskan unit lagi kalau rencananya dibatalkan', function () {
    $spr = sprAkad('FF');
    $rencana = rencanaBaru();
    $this->svc->tambahUnit($rencana, $spr);

    $this->svc->ubahStatus($rencana, 'batal', $this->admin->id, ['alasan' => 'jadwal bank berubah']);

    expect($this->svc->layak($spr))->toBeTrue();
});

it('menjalankan tiga tahap secara berurutan', function () {
    $spr = sprAkad('GG');
    $rencana = rencanaBaru();
    $this->svc->tambahUnit($rencana, $spr);

    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    expect($rencana->status)->toBe('diajukan')
        ->and($rencana->diajukan_by_user_id)->toBe($this->admin->id);

    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);
    $rencana = $this->svc->ubahStatus($rencana, 'fix', $this->admin->id, ['tanggal_fix' => '2026-02-05']);
    expect($rencana->status)->toBe('fix')
        ->and($rencana->disetujui_at)->not->toBeNull()
        ->and($rencana->tanggal_fix->toDateString())->toBe('2026-02-05');
});

it('menolak lompatan status', function () {
    $spr = sprAkad('HH');
    $rencana = rencanaBaru();
    $this->svc->tambahUnit($rencana, $spr);

    // Draft tidak boleh langsung ke Diketahui — harus lewat Diajukan dulu.
    expect(fn () => $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id))
        ->toThrow(ValidationException::class);

    // Draft juga tidak boleh langsung ke Fix — dua langkah persetujuan terlewat.
    expect(fn () => $this->svc->ubahStatus($rencana, 'fix', $this->admin->id))
        ->toThrow(ValidationException::class);
});

it('menolak pengajuan rencana kosong', function () {
    expect(fn () => $this->svc->ubahStatus(rencanaBaru(), 'diajukan', $this->admin->id))
        ->toThrow(ValidationException::class);
});

it('menyimpan tanggal usulan dan tanggal bank secara terpisah', function () {
    $spr = sprAkad('II');
    $rencana = rencanaBaru('2026-01-29');
    $this->svc->tambahUnit($rencana, $spr);

    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);
    $rencana = $this->svc->ubahStatus($rencana, 'fix', $this->admin->id, ['tanggal_fix' => '2026-02-12']);

    // Kalau usulan ditimpa, jejak pergeseran jadwal bank hilang.
    expect($rencana->tanggal_rencana->toDateString())->toBe('2026-01-29')
        ->and($rencana->tanggal_fix->toDateString())->toBe('2026-02-12');
});

it('mengunci unit dan mengisi tanggal pemberkasan saat fix', function () {
    $spr = sprAkad('JJ');
    $rencana = rencanaBaru('2026-01-29');
    $this->svc->tambahUnit($rencana, $spr);

    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'fix', $this->admin->id, ['tanggal_fix' => '2026-02-12']);

    // Tanggal akad di pemberkasan berasal dari rencana, bukan diketik terpisah.
    expect($spr->pemberkasan->fresh()->rencana_akad_tanggal->toDateString())->toBe('2026-02-12')
        ->and($rencana->fresh()->unit->first()->status)->toBe('fix');
});

it('melarang unit diubah setelah rencana diajukan', function () {
    $spr = sprAkad('KK');
    $rencana = rencanaBaru();
    $unit = $this->svc->tambahUnit($rencana, $spr);
    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);

    expect(fn () => $this->svc->hapusUnit($unit->fresh()))->toThrow(ValidationException::class);
    expect(fn () => $this->svc->tambahUnit($rencana, sprAkad('LL')))->toThrow(ValidationException::class);
});

it('membatalkan satu unit tanpa menggugurkan yang lain', function () {
    // Kasus nyata: konsumen tidak hadir di hari H.
    $hadir = sprAkad('WA');
    $absen = sprAkad('WB');
    $rencana = rencanaBaru();

    $this->svc->tambahUnit($rencana, $hadir);
    $unitAbsen = $this->svc->tambahUnit($rencana, $absen);

    $this->svc->batalkanUnit($unitAbsen, 'konsumen tidak hadir', $this->admin->id);

    $rencana = $rencana->fresh();
    expect($rencana->unit)->toHaveCount(2)
        ->and($rencana->unit->where('status', 'batal'))->toHaveCount(1)
        ->and($rencana->unit->firstWhere('spr_id', $absen->id)->alasan_batal)->toBe('konsumen tidak hadir');

    // Unit yang batal bebas dijadwalkan ulang; yang hadir masih terpegang.
    expect($this->svc->layak($absen))->toBeTrue()
        ->and($this->svc->layak($hadir))->toBeFalse();
});

it('tidak mengunci maupun menulis tanggal untuk unit yang dibatalkan', function () {
    $hadir = sprAkad('WC');
    $absen = sprAkad('WD');
    $rencana = rencanaBaru('2026-01-29');

    $this->svc->tambahUnit($rencana, $hadir);
    $unitAbsen = $this->svc->tambahUnit($rencana, $absen);
    $this->svc->batalkanUnit($unitAbsen, 'konsumen tidak hadir', $this->admin->id);

    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'fix', $this->admin->id, ['tanggal_fix' => '2026-02-12']);

    expect($rencana->fresh()->unit->firstWhere('spr_id', $absen->id)->status)->toBe('batal')
        ->and($rencana->fresh()->unit->firstWhere('spr_id', $hadir->id)->status)->toBe('fix')
        ->and($hadir->pemberkasan->fresh()->rencana_akad_tanggal->toDateString())->toBe('2026-02-12')
        ->and($absen->pemberkasan->fresh()->rencana_akad_tanggal)->toBeNull();
});

it('menolak pembatalan unit dua kali', function () {
    $spr = sprAkad('WE');
    $unit = $this->svc->tambahUnit(rencanaBaru(), $spr);
    $this->svc->batalkanUnit($unit, 'tidak hadir', $this->admin->id);

    expect(fn () => $this->svc->batalkanUnit($unit->fresh(), 'lagi', $this->admin->id))
        ->toThrow(ValidationException::class);
});

it('menghitung kotak biaya proses seperti lembar aju dana', function () {
    // Angka diambil dari Aju Dana BSN 21 Agustus 2026: 11 unit,
    // by proses 1.862.500/unit, PPh 1.850.000/unit, jumlah 41.514.500.
    $rencana = rencanaBaru();
    foreach ([
        ['nama' => 'Dana Koordinasi', 'nominal' => 225_000],
        ['nama' => 'Sewa Kursi', 'nominal' => 176_000],
        ['nama' => 'Snack', 'nominal' => 176_000],
        ['nama' => 'Kebersihan & Keamanan', 'nominal' => 100_000],
    ] as $i => $bs) {
        $rencana->biayaSesi()->create($bs + ['urutan' => $i]);
    }

    foreach (range(1, 11) as $i) {
        $unit = $this->svc->tambahUnit($rencana, sprAkad('X'.$i));
        $unit->update([
            'bayar_bi_notaris' => 1_862_500,
            'bayar_ps4a2' => 1_850_000,
            'nilai_ajb' => 185_000_000,
        ]);
    }

    $biaya = $this->svc->ringkasanBiaya($rencana->fresh());
    $per = collect($biaya['baris'])->keyBy('nama');

    expect($biaya['unit'])->toBe(11)
        ->and($per['By Proses Akad']['nominal'])->toBe(20487500.0)
        ->and($per['PPh 4 (2)']['nominal'])->toBe(20350000.0)
        ->and($biaya['jumlah'])->toBe(41514500.0);
});

it('tidak membiayai unit yang dibatalkan', function () {
    $rencana = rencanaBaru();
    $ikut = $this->svc->tambahUnit($rencana, sprAkad('YA'));
    $batal = $this->svc->tambahUnit($rencana, sprAkad('YB'));

    foreach ([$ikut, $batal] as $u) {
        $u->update(['bayar_bi_notaris' => 1_000_000, 'bayar_ps4a2' => 500_000]);
    }

    $this->svc->batalkanUnit($batal->fresh(), 'tidak hadir', $this->admin->id);

    $biaya = $this->svc->ringkasanBiaya($rencana->fresh());

    // Yang batal tidak ikut ditagihkan ke Finance.
    expect($biaya['unit'])->toBe(1)
        ->and($biaya['jumlah'])->toBe(1500000.0);
});

it('menampilkan kandidat beserta alasan kenapa belum bisa', function () {
    sprAkad('MM');                     // siap
    $terpakai = sprAkad('NN');         // sudah dipegang rencana lain
    $this->svc->tambahUnit(rencanaBaru('2026-05-05'), $terpakai);

    $kandidat = $this->svc->kandidatBeralasan($this->proyek->id);
    $siap = $kandidat->whereNull('alasan');
    $belum = $kandidat->whereNotNull('alasan');

    // Yang belum memenuhi tetap tampil berikut sebabnya, bukan hilang diam-diam.
    expect($siap)->toHaveCount(1)
        ->and($belum)->toHaveCount(1)
        ->and($belum->first()['alasan'])->toContain('Sudah dijadwalkan');
});

it('mengurutkan yang siap di atas', function () {
    $terpakai = sprAkad('OO');
    $this->svc->tambahUnit(rencanaBaru('2026-05-06'), $terpakai);
    sprAkad('PP');

    $kandidat = $this->svc->kandidatBeralasan($this->proyek->id);

    expect($kandidat->first()['alasan'])->toBeNull()
        ->and($kandidat->last()['alasan'])->not->toBeNull();
});

it('membuka halaman daftar dan membuat rencana lewat layar', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad')
        ->assertSet('tab', 'draft')
        ->call('openCreate')
        ->set('formTanggal', '2026-03-10')
        ->set('formBankId', $this->bank->id)
        ->set('formNotarisId', $this->notaris->id)
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertRedirect();

    $rencana = RencanaAkad::latest('id')->first();

    expect($rencana->nomor)->toBe('1/'.$this->proyek->kode_surat.'/R.AKAD/03/2026')
        ->and($rencana->status)->toBe('draft')
        ->and($rencana->created_by_user_id)->toBe($this->admin->id);
});

it('menolak rencana tanpa bank atau notaris', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad')
        ->call('openCreate')
        ->set('formTanggal', '2026-03-10')
        ->call('simpan')
        ->assertHasErrors(['formBankId', 'formNotarisId']);
});

it('menambah dan mengeluarkan unit lewat halaman detail', function () {
    $spr = sprAkad('SS');
    $rencana = rencanaBaru();
    $this->actingAs($this->admin);

    $komponen = Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('tambahUnit', $spr->id);

    expect($rencana->fresh()->unit)->toHaveCount(1);

    $unit = $rencana->fresh()->unit->first();

    $komponen->call('konfirmasiHapusUnit', $unit->id)
        ->assertSet('konfirmasiHapusUnitId', $unit->id)
        ->call('hapusUnit');

    expect($rencana->fresh()->unit)->toHaveCount(0);
});

it('menjalankan alur tiga tahap lewat layar', function () {
    $spr = sprAkad('TT');
    $rencana = rencanaBaru('2026-01-29');

    // Admin KPR menyusun dan mengajukan.
    $this->actingAs($this->admin);
    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('tambahUnit', $spr->id)
        ->call('openAksi', 'diajukan')
        ->call('jalankanAksi');

    expect($rencana->fresh()->status)->toBe('diajukan');

    // PM mengetahui.
    $pm = User::factory()->create();
    $pm->assignRole('project-manager');
    $this->actingAs($pm);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openAksi', 'diketahui')
        ->call('jalankanAksi');

    expect($rencana->fresh()->status)->toBe('diketahui');

    // Direksi menyetujui + mengunci tanggal.
    $direksi = User::factory()->create();
    $direksi->assignRole('direktur');
    $this->actingAs($direksi);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openAksi', 'fix')
        ->set('tanggalFix', '2026-02-12')
        ->call('jalankanAksi');

    $rencana = $rencana->fresh();
    expect($rencana->status)->toBe('fix')
        ->and($rencana->tanggal_fix->toDateString())->toBe('2026-02-12')
        ->and($spr->pemberkasan->fresh()->rencana_akad_tanggal->toDateString())->toBe('2026-02-12');
});

it('melarang admin KPR menyetujui pengajuannya sendiri', function () {
    // Menyusun dan mengesahkan adalah wewenang yang berbeda.
    $spr = sprAkad('UU');
    $rencana = rencanaBaru();
    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('tambahUnit', $spr->id)
        ->call('openAksi', 'diajukan')
        ->call('jalankanAksi');

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openAksi', 'fix')
        ->assertForbidden();

    expect($rencana->fresh()->status)->toBe('diajukan');
});

it('mewajibkan alasan saat mengembalikan ke draft', function () {
    $spr = sprAkad('VV');
    $rencana = rencanaBaru();
    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('tambahUnit', $spr->id)
        ->call('openAksi', 'diajukan')
        ->call('jalankanAksi');

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');
    $this->actingAs($pm);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openAksi', 'draft')
        ->set('alasanTolak', '')
        ->call('jalankanAksi')
        ->assertHasErrors('alasanTolak');

    expect($rencana->fresh()->status)->toBe('diajukan');
});

it('membatalkan akad satu unit lewat layar setelah rencana fix', function () {
    // Kasus nyata: rencana sudah fix, lalu konsumen tidak hadir di hari H.
    $hadir = sprAkad('ZA');
    $absen = sprAkad('ZB');
    $rencana = rencanaBaru('2026-01-29');

    $this->svc->tambahUnit($rencana, $hadir);
    $unitAbsen = $this->svc->tambahUnit($rencana, $absen);

    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'fix', $this->admin->id, ['tanggal_fix' => '2026-02-12']);

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBatalUnit', $unitAbsen->id)
        ->set('alasanBatalUnit', 'konsumen tidak hadir di hari akad')
        ->call('batalkanUnit')
        ->assertHasNoErrors();

    expect($unitAbsen->fresh()->status)->toBe('batal')
        ->and($this->svc->layak($absen))->toBeTrue();
});

it('mewajibkan alasan saat membatalkan akad unit', function () {
    $spr = sprAkad('ZC');
    $rencana = rencanaBaru();
    $unit = $this->svc->tambahUnit($rencana, $spr);

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBatalUnit', $unit->id)
        ->set('alasanBatalUnit', '')
        ->call('batalkanUnit')
        ->assertHasErrors('alasanBatalUnit');

    expect($unit->fresh()->status)->toBe('draft');
});

it('menampilkan tipe rumah beserta luas bangunan dan tanahnya', function () {
    // Kolomnya bernama nama_tipe, bukan nama — sempat salah dan selalu tampil kosong.
    $spr = sprAkad('ZT');
    $rencana = rencanaBaru();
    $this->svc->tambahUnit($rencana, $spr);

    $tipe = $spr->rumah->tipeRumah;
    expect($tipe->nama_tipe)->not->toBeEmpty();

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->assertSeeText($tipe->nama_tipe)
        ->assertSeeText((int) $tipe->luas_bangunan.'/'.(int) $tipe->luas_tanah);
});

it('menampilkan kotak biaya proses di halaman detail', function () {
    $rencana = rencanaBaru();
    $rencana->biayaSesi()->createMany([
        ['nama' => 'Dana Koordinasi', 'nominal' => 225_000, 'urutan' => 0],
        ['nama' => 'Kebersihan & Keamanan', 'nominal' => 100_000, 'urutan' => 1],
    ]);
    $unit = $this->svc->tambahUnit($rencana, sprAkad('ZD'));
    $unit->update(['bayar_bi_notaris' => 1_862_500, 'bayar_ps4a2' => 1_850_000]);

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->assertViewHas('biaya', fn ($b) => $b['jumlah'] === 4037500.0 && $b['unit'] === 1);
});

it('menutup halaman dari user tanpa izin rencana akad', function () {
    $tamu = User::factory()->create();
    $tamu->assignRole('admin-sales');
    $this->actingAs($tamu);

    $this->get(route('pemberkasan.rencana-akad.index'))->assertForbidden();
});

it('hanya memuat unit siap di unitSiap', function () {
    sprAkad('QQ');
    $terpakai = sprAkad('RR');
    $this->svc->tambahUnit(rencanaBaru('2026-05-07'), $terpakai);

    expect($this->svc->unitSiap($this->proyek->id))->toHaveCount(1);
});

// -------------------- View "Berdasarkan Blok" --------------------

it('menampilkan unit di view blok berdasarkan bulan dan proyek', function () {
    $spr = sprAkad('AB');
    $rencana = rencanaBaru('2026-03-15');
    $this->svc->tambahUnit($rencana, $spr);

    $this->actingAs($this->admin);

    $resp = $this->get(route('pemberkasan.rencana-akad.blok', [
        'bulan' => '2026-03',
        'proyek' => $this->proyek->id,
    ]));

    $resp->assertOk()
        ->assertSeeText('AB') // blok
        ->assertSeeText('Konsumen AB');
});

it('menyembunyikan unit di bulan lain di view blok', function () {
    $spr = sprAkad('CD');
    $rencana = rencanaBaru('2026-04-10');
    $this->svc->tambahUnit($rencana, $spr);

    $this->actingAs($this->admin);

    $resp = $this->get(route('pemberkasan.rencana-akad.blok', [
        'bulan' => '2026-05',
        'proyek' => $this->proyek->id,
    ]));

    $resp->assertOk()
        ->assertDontSeeText('Konsumen CD');
});

it('menghitung stats jml rumah/sudah akad/belum akad', function () {
    $sprBelum = sprAkad('EF');
    $sprSudah = sprAkad('GH');

    $rencana = rencanaBaru('2026-06-20');
    $unitBelum = $this->svc->tambahUnit($rencana, $sprBelum);
    $unitSudah = $this->svc->tambahUnit($rencana, $sprSudah);
    $unitSudah->update(['tanggal_ppjb' => '2026-06-20']);

    $this->actingAs($this->admin);

    $comp = Livewire\Livewire::test('pages::pemberkasan.rencana-akad-blok', [
        'bulan' => '2026-06', 'proyekId' => $this->proyek->id,
    ]);

    $comp->assertViewHas('stats', fn ($s) => $s['jumlah_rumah'] === 2 && $s['sudah_akad'] === 1 && $s['belum_akad'] === 1);
});

// -------------------- Cetak & Export --------------------

it('mengunduh XLSX export rencana akad', function () {
    $spr = sprAkad('KL');
    $rencana = rencanaBaru('2026-08-12');
    $this->svc->tambahUnit($rencana, $spr);

    $this->actingAs($this->admin);

    $resp = $this->get(route('pemberkasan.rencana-akad.export.xlsx', $rencana->id));

    $resp->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

// -------------------- Ubah Biaya Sesi (dinamis) --------------------

it('mengizinkan admin KPR mengubah biaya sesi selama status draft', function () {
    $spr = sprAkad('OP');
    $rencana = rencanaBaru('2026-10-15');
    $this->svc->tambahUnit($rencana, $spr);

    $this->actingAs($this->admin);

    Livewire\Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBiayaSesi')
        ->set('formBiayaSesi', [
            ['nama' => 'Dana Koordinasi', 'nominal' => '300000'],
            ['nama' => 'Air Mineral', 'nominal' => '75000'],
            ['nama' => 'Snack', 'nominal' => '250000'],
        ])
        ->call('simpanBiayaSesi')
        ->assertHasNoErrors();

    $baris = $rencana->fresh()->biayaSesi;
    expect($baris)->toHaveCount(3)
        ->and($baris->pluck('nama')->all())->toBe(['Dana Koordinasi', 'Air Mineral', 'Snack'])
        ->and((float) $baris->firstWhere('nama', 'Dana Koordinasi')->nominal)->toBe(300_000.0)
        ->and((float) $baris->firstWhere('nama', 'Air Mineral')->nominal)->toBe(75_000.0);
});

it('mengizinkan PM mengoreksi biaya sesi ketika rencana masih diajukan', function () {
    $spr = sprAkad('QR');
    $rencana = rencanaBaru('2026-11-05');
    $this->svc->tambahUnit($rencana, $spr);
    $rencana->biayaSesi()->create(['nama' => 'Dana Koordinasi', 'nominal' => 225_000, 'urutan' => 0]);
    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');
    $this->actingAs($pm);

    Livewire\Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBiayaSesi')
        ->set('formBiayaSesi.0.nominal', '400000')
        ->call('simpanBiayaSesi')
        ->assertHasNoErrors();

    expect((float) $rencana->fresh()->biayaSesi->first()->nominal)->toBe(400_000.0);
});

it('menolak ubah biaya sesi setelah rencana fix', function () {
    $spr = sprAkad('ST');
    $rencana = rencanaBaru('2026-12-01');
    $this->svc->tambahUnit($rencana, $spr);

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');

    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $pm->id);
    $rencana = $this->svc->ubahStatus($rencana, 'fix', $pm->id, ['tanggal_fix' => '2026-12-15']);

    $this->actingAs($pm);

    Livewire\Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBiayaSesi')
        ->assertForbidden();
});

it('menolak admin KPR mengoreksi biaya sesi setelah rencana diajukan', function () {
    // Setelah diajukan, hanya PM/Direktur yang boleh koreksi biaya sesi.
    $spr = sprAkad('UV');
    $rencana = rencanaBaru('2026-11-20');
    $this->svc->tambahUnit($rencana, $spr);
    $rencana = $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);

    $this->actingAs($this->admin);

    Livewire\Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBiayaSesi')
        ->assertForbidden();
});

it('menolak biaya sesi negatif', function () {
    $spr = sprAkad('WX');
    $rencana = rencanaBaru('2026-12-10');
    $this->svc->tambahUnit($rencana, $spr);

    $this->actingAs($this->admin);

    Livewire\Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBiayaSesi')
        ->set('formBiayaSesi', [['nama' => 'Dana Koordinasi', 'nominal' => '-100']])
        ->call('simpanBiayaSesi')
        ->assertHasErrors('formBiayaSesi.0.nominal');
});

it('membuang baris biaya sesi yang kosong dan menghapus baris via UI', function () {
    $spr = sprAkad('YZ');
    $rencana = rencanaBaru('2026-10-20');
    $this->svc->tambahUnit($rencana, $spr);

    $this->actingAs($this->admin);

    Livewire\Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openBiayaSesi')
        ->set('formBiayaSesi', [
            ['nama' => 'Dana Koordinasi', 'nominal' => '225000'],
            ['nama' => '', 'nominal' => ''],  // baris kosong — harus di-drop
            ['nama' => 'Snack', 'nominal' => '150000'],
        ])
        ->call('simpanBiayaSesi')
        ->assertHasNoErrors();

    expect($rencana->fresh()->biayaSesi)->toHaveCount(2);
});

it('mengunduh PDF Aju Dana', function () {
    $spr = sprAkad('AJ');
    $rencana = rencanaBaru('2026-07-10');
    $unit = $this->svc->tambahUnit($rencana, $spr);
    $unit->update([
        'bayar_bi_notaris' => 1_645_000,
        'setoran_ajb' => 185_000,
        'bayar_ps4a2' => 1_850_000,
        'hgb' => '000144369',
    ]);
    $rencana->biayaSesi()->create(['nama' => 'Dana Koordinasi', 'nominal' => 225_000, 'urutan' => 0]);
    $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'fix', $this->admin->id, ['tanggal_fix' => '2026-07-10']);

    $response = $this->actingAs($this->admin)
        ->get(route('pemberkasan.rencana-akad.cetak.aju-dana', $rencana->id));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect(strlen($response->getContent()))->toBeGreaterThan(1000);
});

it('mengizinkan direksi menyetujui via signed link universal dan menyimpan TTD', function () {
    $spr = sprAkad('DR');
    $rencana = rencanaBaru('2026-04-01');
    $this->svc->tambahUnit($rencana, $spr);
    $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);

    // Simulasi Admin KPR "Generate Link" (universal — tidak pilih user).
    $rencana->update(['direksi_link_generated_at' => now()]);

    $url = URL::signedRoute(
        'public.persetujuan-direksi.show',
        ['rencanaId' => $rencana->id],
        now()->addDays(7)
    );

    // Direksi buka link — tidak login. Nama hardcoded di halaman.
    $this->get($url)->assertOk()
        ->assertSee($rencana->nomor)
        ->assertSee('Haryanto / Julianto Boentaran');

    // POST setuju — kirim base64 PNG (canvas signature).
    $urlSetuju = URL::signedRoute(
        'public.persetujuan-direksi.setuju',
        ['rencanaId' => $rencana->id],
        now()->addDays(7)
    );

    // 1x1 PNG hitam sebagai fake signature.
    $fakePng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAeImBZsAAAAASUVORK5CYII=';

    Storage::fake('public');

    $this->post($urlSetuju, ['tanda_tangan' => $fakePng])->assertRedirect();

    $rencana = $rencana->fresh();
    expect($rencana->status)->toBe('fix')
        ->and($rencana->direksi_ttd_path)->not->toBeNull()
        ->and($rencana->direksi_setuju_ip)->not->toBeEmpty();

    Storage::disk('public')->assertExists($rencana->direksi_ttd_path);
});

it('menolak POST setuju tanpa tanda tangan', function () {
    $spr = sprAkad('DZ');
    $rencana = rencanaBaru('2026-04-15');
    $this->svc->tambahUnit($rencana, $spr);
    $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);

    $urlSetuju = URL::signedRoute(
        'public.persetujuan-direksi.setuju',
        ['rencanaId' => $rencana->id],
        now()->addDays(7)
    );

    $this->post($urlSetuju, [])->assertSessionHasErrors('tanda_tangan');
    expect($rencana->fresh()->status)->toBe('diketahui');
});

it('menolak link direksi yang expired', function () {
    $spr = sprAkad('DX');
    $rencana = rencanaBaru('2026-05-01');
    $this->svc->tambahUnit($rencana, $spr);
    $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);
    $this->svc->ubahStatus($rencana, 'diketahui', $this->admin->id);

    // URL dengan expiry di masa lalu — signed middleware harus tolak.
    $url = URL::signedRoute(
        'public.persetujuan-direksi.show',
        ['rencanaId' => $rencana->id],
        now()->subMinute()
    );

    $response = $this->get($url);
    expect($response->status())->toBeIn([401, 403]);
});

it('PM bisa Ketahui rencana lewat halaman Approval', function () {
    $spr = sprAkad('AK');
    $rencana = rencanaBaru('2026-04-10');
    $this->svc->tambahUnit($rencana, $spr);
    $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');
    $this->actingAs($pm);

    Livewire::test('pages::pemberkasan.approval-rencana-akad')
        ->call('konfirmasiKetahui', $rencana->id)
        ->assertSet('konfirmasiId', $rencana->id)
        ->call('ketahui')
        ->assertHasNoErrors();

    expect($rencana->fresh()->status)->toBe('diketahui')
        ->and($rencana->fresh()->diketahui_by_user_id)->toBe($pm->id);
});

it('PM bisa Tolak rencana lewat halaman Approval', function () {
    $spr = sprAkad('AT');
    $rencana = rencanaBaru('2026-04-11');
    $this->svc->tambahUnit($rencana, $spr);
    $this->svc->ubahStatus($rencana, 'diajukan', $this->admin->id);

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');
    $this->actingAs($pm);

    Livewire::test('pages::pemberkasan.approval-rencana-akad')
        ->call('konfirmasiTolak', $rencana->id)
        ->set('alasanTolak', 'Tanggal bentrok dengan proyek lain')
        ->call('tolak')
        ->assertHasNoErrors();

    $rencana = $rencana->fresh();
    expect($rencana->status)->toBe('draft')
        ->and($rencana->alasan_tolak)->toContain('bentrok');
});

it('menolak akses halaman Approval untuk user tanpa izin', function () {
    $userBiasa = User::factory()->create();
    $userBiasa->assignRole('admin-kpr');
    $this->actingAs($userBiasa);

    // Livewire mount() abort_unless 403
    $this->get('/pemberkasan/approval-rencana-akad')->assertStatus(403);
});

// -------------------- Kelengkapan Data di Layar Tambah Rumah --------------------

it('menampilkan progres bangunan dan tahapan berkas saat memilih unit', function () {
    // Admin KPR memutuskan bukan cuma dari uang muka: rumah yang belum jadi dan
    // berkas yang baru sampai wawancara tidak layak masuk jadwal akad terdekat.
    $spr = sprAkad('PQ');
    $spr->rumah->update(['progres_fisik' => 75]);
    $spr->pemberkasan->update([
        'bank_kode' => 'BSN',
        'bm_tanggal' => '2026-03-01',
        'wcr_tanggal' => '2026-03-08',
        'sp3k_tanggal' => '2026-03-20',
    ]);

    $rencana = rencanaBaru('2026-10-05');

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openTambahUnit')
        ->assertSee('75%')
        ->assertSee('SP3K')
        ->assertSee('3/4 tahap')
        ->assertSee('BSN');
});

it('menyebut progres nol sebagai belum dicatat, bukan belum dibangun', function () {
    // Kolomnya berdefault nol dan sebagian besar unit bernilai nol karena
    // datanya memang belum diisi. Menyebutnya "belum dibangun" akan terbaca
    // sebagai kelalaian tim teknik. Istilah yang sama dipakai dashboard teknik.
    $spr = sprAkad('RS');

    expect((int) $spr->rumah->progres_fisik)->toBe(0);

    $rencana = rencanaBaru('2026-10-06');

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openTambahUnit')
        ->assertSee('belum dicatat')
        ->assertDontSee('belum dibangun');
});

it('memperingatkan SP3K yang sudah lewat tanggal saat memilih unit', function () {
    // SP3K kedaluwarsa harus diurus ulang ke bank sebelum akad. Kalau baru
    // ketahuan di hari H, satu sesi akad berjamaah ikut tertunda.
    $spr = sprAkad('TU');
    $spr->pemberkasan->update([
        'sp3k_tanggal' => now()->subDays(120)->toDateString(),
        'sp3k_expired' => now()->subDays(30)->toDateString(),
    ]);

    $rencana = rencanaBaru('2026-10-07');

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openTambahUnit')
        ->assertSee('SP3K lewat 30 hari');
});

it('menyebut tahapan belum ada kalau berkasnya masih kosong', function () {
    $spr = sprAkad('VW');
    $rencana = rencanaBaru('2026-10-08');

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('openTambahUnit')
        ->assertSee('belum ada');
});

// -------------------- Urutan Daftar Kandidat --------------------

/** @return list<string> blok tiap kandidat, urut sesuai hasil. */
function urutanKandidat(string $urut = 'unit', string $arah = 'asc'): array
{
    return test()->svc->kandidatBeralasan(test()->proyek->id, null, '', $urut, $arah)
        ->map(fn ($k) => $k['spr']->rumah->blok)
        ->all();
}

it('mengurutkan kandidat menurut kolom yang dipilih', function () {
    sprAkad('XB');
    sprAkad('XA');
    sprAkad('XC');

    expect(urutanKandidat('unit'))->toBe(['XA', 'XB', 'XC'])
        ->and(urutanKandidat('unit', 'desc'))->toBe(['XC', 'XB', 'XA']);
});

it('mengurutkan menurut progres bangunan', function () {
    sprAkad('YA')->rumah->update(['progres_fisik' => 20]);
    sprAkad('YB')->rumah->update(['progres_fisik' => 100]);
    sprAkad('YC')->rumah->update(['progres_fisik' => 60]);

    expect(urutanKandidat('bangunan', 'desc'))->toBe(['YB', 'YC', 'YA']);
});

it('mengurutkan uang muka menurut persentase, bukan nominal', function () {
    // Nominal tidak bisa dibandingkan lurus: uang muka tiap unit beda besarnya.
    // ZA baru 10% walau nominalnya paling besar; ZB sudah 90%.
    sprAkad('ZA', umNet: 100_000_000, umBayar: 10_000_000, utj: 0);
    sprAkad('ZB', umNet: 10_000_000, umBayar: 9_000_000, utj: 0);

    expect(urutanKandidat('um', 'desc'))->toBe(['ZB', 'ZA']);
});

it('mengurutkan menurut jumlah tahap berkas yang beres', function () {
    $satu = sprAkad('WA');
    $satu->pemberkasan->update(['bm_tanggal' => '2026-03-01']);

    $tiga = sprAkad('WB');
    $tiga->pemberkasan->update([
        'bm_tanggal' => '2026-03-01', 'wcr_tanggal' => '2026-03-05', 'sp3k_tanggal' => '2026-03-10',
    ]);

    expect(urutanKandidat('berkas', 'desc'))->toBe(['WB', 'WA']);
});

it('menaruh unit yang tidak layak di bawah, apa pun kolom urutannya', function () {
    // Unit tidak layak tidak punya tombol Tambah — menaikkannya ke puncak
    // hanya menyulitkan Admin KPR yang sedang memilih.
    $layak = sprAkad('UA');
    $layak->rumah->update(['progres_fisik' => 10]);

    $terpakai = sprAkad('UB');
    $terpakai->rumah->update(['progres_fisik' => 100]);
    test()->svc->tambahUnit(rencanaBaru('2026-11-02'), $terpakai);

    expect(urutanKandidat('bangunan', 'desc'))->toBe(['UA', 'UB']);
});

it('membalik arah saat kolom yang sama diklik dua kali', function () {
    $rencana = rencanaBaru('2026-11-03');

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('urutkanKandidat', 'bangunan')
        ->assertSet('urutKandidat', 'bangunan')
        ->assertSet('arahKandidat', 'asc')
        ->call('urutkanKandidat', 'bangunan')
        ->assertSet('arahKandidat', 'desc')
        ->call('urutkanKandidat', 'konsumen')
        ->assertSet('urutKandidat', 'konsumen')
        ->assertSet('arahKandidat', 'asc');
});

it('mengabaikan kolom urutan yang tidak dikenal', function () {
    // Nama kolom datang dari sisi pengguna; yang asing tidak boleh mengubah apa pun.
    $rencana = rencanaBaru('2026-11-04');

    $this->actingAs($this->admin);

    Livewire::test('pages::pemberkasan.rencana-akad-show', ['id' => $rencana->id])
        ->call('urutkanKandidat', 'harga_jual')
        ->assertSet('urutKandidat', 'unit');

    expect(urutanKandidat('harga_jual'))->toBe(urutanKandidat('unit'));
});
