<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sertifikat;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\SertifikatService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Pecah sertifikat: diajukan → terbit → diserahkan ke bank.
 *
 * Statusnya tidak disimpan sebagai kolom, melainkan dibaca dari data yang
 * sudah terisi. Yang dijaga paling keras: satu unit satu sertifikat, dan satu
 * sertifikat tidak bisa diserahkan dua kali selama masih tercatat di bank —
 * sertifikat itu benda fisik, tercatat ganda berarti salah satunya bohong.
 */
beforeEach(function () {
    $this->seed();

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->user = User::factory()->create();
    $this->svc = app(SertifikatService::class);
});

function unitSertifikat(string $blok, string $nomor): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id,
        'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok,
        'nomor_unit' => $nomor,
        'status' => 'available',
    ]);
}

/** Ajukan beberapa unit sekaligus, luasnya diambil dari usulan tipenya. */
function ajukanUnit(array $rumah, string $tanggal = '2026-09-10', string $metode = 'kavling', string $blok = '')
{
    $luas = [];

    foreach ($rumah as $r) {
        $luas[$r->id] = test()->svc->luasUsulan($r);
    }

    return test()->svc->ajukan(test()->proyek, $tanggal, $metode, $luas, $blok, '', test()->user->id);
}

it('menomori pengajuan per proyek per bulan', function () {
    $satu = ajukanUnit([unitSertifikat('SA', '01')], '2026-09-10');
    $dua = ajukanUnit([unitSertifikat('SA', '02')], '2026-09-20');

    expect($satu->nomor)->toBe('1/'.$this->proyek->kode_surat.'/P.SERT/09/2026')
        ->and($dua->nomor)->toBe('2/'.$this->proyek->kode_surat.'/P.SERT/09/2026');

    // Bulan baru mulai dari satu lagi.
    $tiga = ajukanUnit([unitSertifikat('SA', '03')], '2026-10-01');
    expect($tiga->nomor)->toBe('1/'.$this->proyek->kode_surat.'/P.SERT/10/2026');
});

it('membuat baris sertifikat kosong untuk tiap unit yang diajukan', function () {
    $a = unitSertifikat('SB', '01');
    $b = unitSertifikat('SB', '02');

    $pengajuan = ajukanUnit([$a, $b]);

    expect($pengajuan->sertifikat()->count())->toBe(2);

    $sertifikat = Sertifikat::where('rumah_id', $a->id)->first();

    expect($sertifikat->nomor_sertifikat)->toBeNull()
        ->and($sertifikat->status())->toBe('diajukan')
        ->and((float) $sertifikat->luas_diajukan)->toBe((float) $this->tipe->luas_tanah);
});

it('mengusulkan luas dari tipe rumahnya, tapi tetap bisa dikoreksi', function () {
    // Sistem tidak menyimpan luas per unit — kolomnya pernah ada di master
    // rumah lalu dihapus. Jadi angkanya usulan, bukan kebenaran.
    $unit = unitSertifikat('SC', '01');

    $this->svc->ajukan($this->proyek, '2026-09-10', 'kavling', [$unit->id => 97.5], '', '', $this->user->id);

    expect((float) Sertifikat::where('rumah_id', $unit->id)->value('luas_diajukan'))->toBe(97.5);
});

it('tidak menawarkan lagi unit yang sudah pernah diajukan', function () {
    $a = unitSertifikat('SD', '01');
    unitSertifikat('SD', '02');

    expect($this->svc->unitBelumDiajukan($this->proyek->id)->count())->toBe(2);

    ajukanUnit([$a]);

    expect($this->svc->unitBelumDiajukan($this->proyek->id)->count())->toBe(1);
});

it('menolak mengajukan unit yang sudah punya sertifikat', function () {
    // Diperiksa ulang saat menyimpan: daftar pilihan hidup di sisi pengguna
    // dan bisa memuat unit yang sudah diajukan orang lain.
    $unit = unitSertifikat('SE', '01');
    ajukanUnit([$unit]);

    expect(fn () => ajukanUnit([$unit]))->toThrow(ValidationException::class);
});

it('menolak pengajuan tanpa unit', function () {
    expect(fn () => $this->svc->ajukan($this->proyek, '2026-09-10', 'kavling', []))
        ->toThrow(ValidationException::class);
});

it('mencatat blok hanya saat metodenya kolektif', function () {
    $kolektif = ajukanUnit([unitSertifikat('SF', '01')], '2026-09-10', 'blok', 'SF');
    $satuan = ajukanUnit([unitSertifikat('SG', '01')], '2026-09-11', 'kavling', 'SG');

    expect($kolektif->blok)->toBe('SF')
        ->and($satuan->blok)->toBeNull();
});

it('menaikkan status ke terbit begitu nomornya diisi', function () {
    $unit = unitSertifikat('SH', '01');
    ajukanUnit([$unit]);

    $sertifikat = Sertifikat::where('rumah_id', $unit->id)->first();
    $sertifikat->update([
        'nomor_sertifikat' => 'SHGB 01234',
        'nib' => '64885',
        'hgb_induk' => 'HGB 9/Sukamaju',
        'tanggal_terbit' => '2026-10-05',
        'luas_sertifikat' => 72.4,
    ]);

    expect($sertifikat->fresh()->status())->toBe('terbit')
        ->and($sertifikat->fresh()->luasBerlaku())->toBe(72.4);
});

it('memakai luas terbit, bukan luas yang diajukan', function () {
    // Hasil ukur BPN kerap berbeda; yang dipakai di dokumen yang terbit.
    $unit = unitSertifikat('SI', '01');
    $this->svc->ajukan($this->proyek, '2026-09-10', 'kavling', [$unit->id => 80.0], '', '', $this->user->id);

    $sertifikat = Sertifikat::where('rumah_id', $unit->id)->first();
    expect($sertifikat->luasBerlaku())->toBe(80.0);

    $sertifikat->update(['luas_sertifikat' => 78.25]);
    expect($sertifikat->fresh()->luasBerlaku())->toBe(78.25);
});

// ─────────────────── Serah terima ke bank ───────────────────

/** Sertifikat yang sudah terbit dan siap diserahkan. */
function sertifikatTerbit(string $blok, string $nomor, string $noSertifikat): Sertifikat
{
    $unit = unitSertifikat($blok, $nomor);
    ajukanUnit([$unit]);

    $sertifikat = Sertifikat::where('rumah_id', $unit->id)->first();
    $sertifikat->update([
        'nomor_sertifikat' => $noSertifikat,
        'nib' => '6'.str_pad((string) $unit->id, 4, '0', STR_PAD_LEFT),
        'tanggal_terbit' => '2026-10-05',
    ]);

    return $sertifikat->fresh();
}

it('menyerahkan beberapa sertifikat sekaligus dengan satu BAST', function () {
    $a = sertifikatTerbit('TA', '01', 'SHGB 100');
    $b = sertifikatTerbit('TA', '02', 'SHGB 101');

    $serah = $this->svc->serahkanKeBank(
        $this->proyek, '2026-11-02', [$a->id, $b->id], 'bast/x.pdf', 'BAST.pdf', '', $this->user->id
    );

    expect($serah->sertifikat()->count())->toBe(2)
        ->and($a->fresh()->status())->toBe('di_bank')
        ->and($b->fresh()->status())->toBe('di_bank')
        ->and($serah->file_original_name)->toBe('BAST.pdf');
});

it('menolak menyerahkan sertifikat yang belum terbit', function () {
    $unit = unitSertifikat('TB', '01');
    ajukanUnit([$unit]);
    $belum = Sertifikat::where('rumah_id', $unit->id)->first();

    expect(fn () => $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$belum->id]))
        ->toThrow(ValidationException::class);
});

it('menolak menyerahkan sertifikat yang sudah ada di bank', function () {
    // Sertifikat itu benda fisik — tercatat diserahkan dua kali berarti salah
    // satunya bohong.
    $a = sertifikatTerbit('TC', '01', 'SHGB 200');
    $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$a->id]);

    expect(fn () => $this->svc->serahkanKeBank($this->proyek, '2026-11-20', [$a->id]))
        ->toThrow(ValidationException::class);
});

it('tidak menyerahkan sebagian diam-diam kalau ada yang tidak layak', function () {
    // Operator harus tahu bahwa yang ia centang tidak semuanya ikut.
    $layak = sertifikatTerbit('TD', '01', 'SHGB 300');
    $unit = unitSertifikat('TD', '02');
    ajukanUnit([$unit]);
    $belum = Sertifikat::where('rumah_id', $unit->id)->first();

    expect(fn () => $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$layak->id, $belum->id]))
        ->toThrow(ValidationException::class);

    expect($layak->fresh()->sudahDiBank())->toBeFalse();
});

it('mengembalikan sertifikat ke status terbit saat penyerahan dibatalkan', function () {
    $a = sertifikatTerbit('TE', '01', 'SHGB 400');
    $serah = $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$a->id]);

    $this->svc->batalkanSerahTerima($serah);

    expect($a->fresh()->status())->toBe('terbit')
        ->and($a->fresh()->sertifikat_serah_terima_id)->toBeNull();
});

it('hanya menawarkan sertifikat terbit yang belum di bank', function () {
    $siap = sertifikatTerbit('TF', '01', 'SHGB 500');
    $sudah = sertifikatTerbit('TF', '02', 'SHGB 501');
    $this->svc->serahkanKeBank($this->proyek, '2026-11-02', [$sudah->id]);

    $unit = unitSertifikat('TF', '03');
    ajukanUnit([$unit]);   // belum terbit

    $daftar = $this->svc->siapDiserahkan($this->proyek->id)->pluck('id')->all();

    expect($daftar)->toBe([$siap->id]);
});

it('menjaga satu unit hanya punya satu sertifikat', function () {
    $unit = unitSertifikat('TG', '01');
    ajukanUnit([$unit]);

    expect(fn () => Sertifikat::create(['rumah_id' => $unit->id]))
        ->toThrow(QueryException::class);
});
