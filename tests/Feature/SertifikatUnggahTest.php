<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sertifikat;
use App\Models\Master\TipeRumah;
use App\Models\User;
use App\Services\SertifikatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Unggahan berkas di ketiga layar input.
 *
 * Dipisah dari tes halaman karena butuh disk palsu: tanpa itu berkasnya betul
 * ditulis ke penyimpanan asli dan menumpuk tiap kali tes dijalankan.
 */
beforeEach(function () {
    $this->seed();
    Storage::fake('private');

    $this->proyek = Proyek::first();
    $this->tipe = TipeRumah::where('proyek_id', $this->proyek->id)->first();

    $this->legal = User::factory()->create();
    $this->legal->assignRole('admin-legal');
    $this->actingAs($this->legal);

    $this->svc = app(SertifikatService::class);
});

function unitUnggah(string $blok, string $nomor): Rumah
{
    return Rumah::create([
        'proyek_id' => test()->proyek->id, 'tipe_rumah_id' => test()->tipe->id,
        'blok' => $blok, 'nomor_unit' => $nomor, 'status' => 'available',
    ]);
}

it('menyimpan berkas sertifikat yang diunggah', function () {
    $unit = unitUnggah('KA', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0]);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    Livewire::test('pages::perizinan.sertifikat-input')
        ->set('proyekId', $this->proyek->id)
        ->call('openIsi', $s->id)
        ->set('nomorSertifikat', 'SHGB 01234')
        ->set('berkas', UploadedFile::fake()->create('sertifikat.pdf', 120, 'application/pdf'))
        ->call('simpan')
        ->assertHasNoErrors();

    $s = $s->fresh();

    expect($s->file_path)->not->toBeNull()
        ->and($s->file_original_name)->toBe('sertifikat.pdf');

    Storage::disk('private')->assertExists($s->file_path);
});

it('menampilkan berkas sertifikat di riwayat unitnya', function () {
    $unit = unitUnggah('KB', '01');
    $this->svc->ajukan($this->proyek, '2026-09-20', 'kavling', [$unit->id => 70.0]);
    $s = Sertifikat::where('rumah_id', $unit->id)->first();

    Livewire::test('pages::perizinan.sertifikat-input')
        ->set('proyekId', $this->proyek->id)
        ->call('openIsi', $s->id)
        ->set('nomorSertifikat', 'SHGB 01235')
        ->set('berkas', UploadedFile::fake()->image('scan.jpg'))
        ->call('simpan');

    $tahap = collect($this->svc->riwayat($s->fresh())['tahap'])->keyBy('kunci');

    expect($tahap['terbit']['berkas'])->not->toBeNull()
        ->and($tahap['terbit']['berkas']['nama'])->toBe('scan.jpg')
        ->and($tahap['terbit']['berkas']['jenis'])->toBe('sertifikat');
});
