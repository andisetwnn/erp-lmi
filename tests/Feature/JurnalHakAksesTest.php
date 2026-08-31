<?php

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalDetail;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Pemisahan wewenang di jurnal.
 *
 * Izin jurnal.post dan jurnal.delete sudah lama didefinisikan tapi tidak pernah dicek —
 * siapa pun yang bisa membuka Jurnal Umum bisa memposting dan menghapus. Pengujian ini
 * mengunci penjagaannya supaya tidak lepas lagi tanpa disadari.
 *
 * Penjagaan ada di method, bukan di tampilan: menyembunyikan tombol bukan pengamanan.
 */
beforeEach(function () {
    $this->seed();

    $this->kas = Coa::where('is_header', false)->orderBy('kode')->first();
    $this->lawan = Coa::where('is_header', false)->where('id', '!=', $this->kas->id)->orderBy('kode')->first();

    // Penginput: boleh mencatat, tidak boleh mengesahkan maupun menghapus.
    $penginput = Role::firstOrCreate(['name' => 'penginput-uji']);
    $penginput->givePermissionTo('jurnal.umum.kelola');

    $this->penginput = User::factory()->create();
    $this->penginput->assignRole($penginput);

    $this->finance = User::factory()->create();
    $this->finance->assignRole('finance');

    $this->jurnal = Jurnal::create([
        'perusahaan_id' => Perusahaan::value('id'),
        'tanggal' => now()->toDateString(),
        'no_bukti' => 'KAS/08/26/8001',
        'tipe' => 'umum',
        'kategori_bukti' => 'KAS',
        'status' => 'draft',
        'created_by_user_id' => $this->finance->id,
    ]);

    foreach ([[1_000_000, 0], [0, 1_000_000]] as $i => [$debet, $kredit]) {
        JurnalDetail::create([
            'jurnal_id' => $this->jurnal->id,
            'coa_id' => $i === 0 ? $this->kas->id : $this->lawan->id,
            'debet' => $debet,
            'kredit' => $kredit,
        ]);
    }
});

function jurnalUmum()
{
    return Livewire::test('pages::akunting.jurnal-umum');
}

it('menolak posting dari user yang tidak punya izin jurnal.post', function () {
    $this->actingAs($this->penginput);

    jurnalUmum()->call('postJurnal', $this->jurnal->id)->assertForbidden();

    expect($this->jurnal->fresh()->status)->toBe('draft');
});

it('menolak posting lewat jalan pintas Simpan & Posting', function () {
    // simpan(true) memanggil service langsung, bukan lewat postJurnal() — kalau
    // izinnya tidak dicek di sini juga, tombol itu jadi celah.
    $this->actingAs($this->penginput);

    // Seeder sudah meninggalkan jurnal posted, jadi yang dihitung selisihnya.
    $sebelum = Jurnal::where('status', 'posted')->count();

    jurnalUmum()
        ->call('openCreate')
        ->set('detail.0', ['coa_id' => $this->kas->id, 'debet' => '1.000.000', 'kredit' => '', 'rekanan' => ''])
        ->set('detail.1', ['coa_id' => $this->lawan->id, 'debet' => '', 'kredit' => '1.000.000', 'rekanan' => ''])
        ->call('simpan', true)
        ->assertForbidden();

    expect(Jurnal::where('status', 'posted')->count())->toBe($sebelum);
});

it('menolak reverse dari user yang tidak punya izin jurnal.post', function () {
    $this->jurnal->update(['status' => 'posted', 'posted_at' => now()]);
    $this->actingAs($this->penginput);

    jurnalUmum()->call('reverseJurnal', $this->jurnal->id)->assertForbidden();

    expect(Jurnal::where('reversed_from_jurnal_id', $this->jurnal->id)->count())->toBe(0);
});

it('menolak hapus dari user yang tidak punya izin jurnal.delete', function () {
    $this->actingAs($this->penginput);

    jurnalUmum()->call('hapusJurnal', $this->jurnal->id)->assertForbidden();

    expect(Jurnal::find($this->jurnal->id))->not->toBeNull();
});

it('membolehkan penginput menyimpan draft', function () {
    $this->actingAs($this->penginput);

    $sebelum = Jurnal::where('status', 'draft')->count();

    jurnalUmum()
        ->call('openCreate')
        ->set('detail.0', ['coa_id' => $this->kas->id, 'debet' => '500.000', 'kredit' => '', 'rekanan' => ''])
        ->set('detail.1', ['coa_id' => $this->lawan->id, 'debet' => '', 'kredit' => '500.000', 'rekanan' => ''])
        ->call('simpan')
        ->assertHasNoErrors();

    expect(Jurnal::where('status', 'draft')->count())->toBe($sebelum + 1);
});

it('membolehkan finance memposting dan menghapus', function () {
    $this->actingAs($this->finance);

    jurnalUmum()->call('postJurnal', $this->jurnal->id);
    expect($this->jurnal->fresh()->status)->toBe('posted');

    $draft = Jurnal::create([
        'perusahaan_id' => Perusahaan::value('id'),
        'tanggal' => now()->toDateString(),
        'no_bukti' => 'KAS/08/26/8002',
        'tipe' => 'umum',
        'kategori_bukti' => 'KAS',
        'status' => 'draft',
        'created_by_user_id' => $this->finance->id,
    ]);

    jurnalUmum()->call('hapusJurnal', $draft->id);
    expect(Jurnal::find($draft->id))->toBeNull();
});

it('menyembunyikan tombol yang tidak diizinkan', function () {
    $this->actingAs($this->penginput);

    jurnalUmum()
        ->assertViewHas('bolehKelola', true)
        ->assertViewHas('bolehPost', false)
        ->assertViewHas('bolehHapus', false);

    $this->actingAs($this->finance);

    jurnalUmum()
        ->assertViewHas('bolehKelola', true)
        ->assertViewHas('bolehPost', true)
        ->assertViewHas('bolehHapus', true);
});

it('menolak buka form edit dari user tanpa izin kelola', function () {
    $lihatSaja = User::factory()->create();
    $lihatSaja->assignRole('direktur');
    $this->actingAs($lihatSaja);

    jurnalUmum()->call('openEdit', $this->jurnal->id)->assertForbidden();
    jurnalUmum()->call('openCreate')->assertForbidden();
});
