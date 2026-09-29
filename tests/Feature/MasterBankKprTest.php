<?php

use App\Models\Master\BankKpr;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Master Bank KPR — daftar cabang tempat berkas KPR diurus, beserta biaya
 * proses akad per unitnya.
 *
 * Terpisah dari master `bank` yang berisi penerbit rekening: "BTN KC Cibinong"
 * dan "BTN Syariah" dua-duanya BTN tapi biayanya berbeda, dan master bank umum
 * tidak bisa menampung perbedaan itu.
 *
 * Yang dijaga paling keras di sini: tarif kosong tidak boleh berubah jadi nol.
 * Nol berarti gratis; kosong berarti belum diketahui. Dua hal itu tidak boleh
 * terbaca sama di lembar Aju Dana.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin-kpr');
    $this->actingAs($this->admin);
});

function halamanBankKpr()
{
    return Livewire::test('pages::master.bank-kpr');
}

it('menyeed lima bank yang selama ini dipakai', function () {
    expect(BankKpr::pluck('kode')->sort()->values()->all())
        ->toBe(['BCA', 'BSN', 'BSY', 'CBN', 'NBU']);
});

it('menyimpan tarif yang sudah diketahui', function () {
    expect((float) BankKpr::where('kode', 'BSN')->value('biaya_proses_akad'))->toBe(1_862_500.0)
        ->and((float) BankKpr::where('kode', 'CBN')->value('biaya_proses_akad'))->toBe(1_645_000.0)
        ->and((float) BankKpr::where('kode', 'NBU')->value('biaya_proses_akad'))->toBe(2_395_000.0);
});

it('membiarkan tarif yang belum diketahui tetap kosong, bukan nol', function () {
    // Nol akan tercetak sebagai "Rp 0" di Aju Dana dan terbaca sebagai gratis.
    foreach (['BSY', 'BCA'] as $kode) {
        expect(BankKpr::where('kode', $kode)->value('biaya_proses_akad'))->toBeNull();
    }
});

it('menambah bank baru beserta tarifnya', function () {
    halamanBankKpr()
        ->call('create')
        ->set('kode', 'mdr')
        ->set('nama', 'Bank Mandiri KC Bogor')
        ->set('biayaProsesAkad', '1.750.000')
        ->call('save')
        ->assertHasNoErrors();

    $bank = BankKpr::where('nama', 'Bank Mandiri KC Bogor')->first();

    expect($bank->kode)->toBe('MDR')                      // dibesarkan sendiri
        ->and((float) $bank->biaya_proses_akad)->toBe(1_750_000.0)
        ->and($bank->is_aktif)->toBeTrue();
});

it('menyimpan tarif kosong sebagai kosong', function () {
    halamanBankKpr()
        ->call('create')
        ->set('kode', 'BRI')
        ->set('nama', 'Bank BRI KC Cibinong')
        ->set('biayaProsesAkad', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(BankKpr::where('kode', 'BRI')->value('biaya_proses_akad'))->toBeNull();
});

it('menolak kode yang sudah dipakai', function () {
    halamanBankKpr()
        ->call('create')
        ->set('kode', 'CBN')
        ->set('nama', 'BTN Cibinong Duplikat')
        ->call('save')
        ->assertHasErrors(['kode']);
});

it('menolak kode berspasi', function () {
    // Kode dipakai apa adanya oleh berkas Matrix dan kolom bank_kode.
    halamanBankKpr()
        ->call('create')
        ->set('kode', 'BTN 2')
        ->set('nama', 'BTN Dua')
        ->call('save')
        ->assertHasErrors(['kode']);
});

it('merapikan nominal yang diketik tanpa pemisah', function () {
    halamanBankKpr()
        ->set('biayaProsesAkad', '1645000')
        ->assertSet('biayaProsesAkad', '1.645.000');
});

it('mengosongkan tarif lewat edit', function () {
    // Kalau tarif lama ternyata keliru, mengosongkannya harus benar-benar
    // mengosongkan — bukan menyisakan angka lama.
    $bsn = BankKpr::where('kode', 'BSN')->first();

    halamanBankKpr()
        ->call('edit', $bsn->id)
        ->set('biayaProsesAkad', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($bsn->fresh()->biaya_proses_akad)->toBeNull();
});

it('memuat kembali tarif dalam bentuk yang mudah dibaca saat diedit', function () {
    $nobu = BankKpr::where('kode', 'NBU')->first();

    halamanBankKpr()
        ->call('edit', $nobu->id)
        ->assertSet('biayaProsesAkad', '2.395.000');
});

it('menolak menghapus bank yang sudah dipakai rencana akad', function () {
    // Menghapusnya akan mengosongkan bank pada rencana yang sudah tersusun,
    // termasuk yang sudah dicetak dan ditandatangani.
    $bank = BankKpr::where('kode', 'CBN')->first();

    App\Models\Master\RencanaAkad::create([
        'proyek_id' => App\Models\Master\Proyek::first()->id,
        'nomor' => '1/GA/R.AKAD/09/2026',
        'tanggal_rencana' => '2026-09-30',
        'bank_kpr_id' => $bank->id,
        'status' => 'draft',
    ]);

    halamanBankKpr()->call('confirmDelete', $bank->id)->call('delete');

    expect(BankKpr::whereKey($bank->id)->exists())->toBeTrue();
});

it('menghapus bank yang belum dipakai', function () {
    $bca = BankKpr::where('kode', 'BCA')->first();

    halamanBankKpr()->call('confirmDelete', $bca->id)->call('delete');

    expect(BankKpr::whereKey($bca->id)->exists())->toBeFalse();
});

it('menyembunyikan bank nonaktif dari pilihan tanpa menghapusnya', function () {
    $bca = BankKpr::where('kode', 'BCA')->first();

    halamanBankKpr()->call('toggleAktif', $bca->id);

    expect(BankKpr::pilihan())->not->toHaveKey('BCA')
        ->and(BankKpr::whereKey($bca->id)->exists())->toBeTrue();
});

it('memperingatkan kalau masih ada bank aktif tanpa tarif', function () {
    expect(halamanBankKpr()->viewData('belumBertarif'))->toBe(2);
});

it('tidak bisa dibuka tanpa izin', function () {
    $teknik = User::factory()->create();
    $teknik->assignRole('admin-teknik');

    $this->actingAs($teknik)->get(route('master.bank-kpr.index'))->assertForbidden();
});
