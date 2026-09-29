<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tiap izin harus punya label dan penjelasan berbahasa manusia di layar
 * Pengguna & Akses.
 *
 * Tanpa itu tampilannya jatuh ke nama yang dikarang dari kunci teknisnya —
 * "Rencanaakad Mengetahui" pernah muncul begitu di produksi, tanpa keterangan
 * apa pun. Yang memakai layar ini super-admin yang sedang menyusun hak akses
 * orang lain; menebak arti izin dari namanya berisiko memberi akses yang salah.
 *
 * Dibandingkan langsung ke daftar izin milik seeder, jadi izin baru yang
 * ditambahkan tanpa label ketahuan di sini.
 */

/** Kunci sebuah konstanta di komponen Volt halaman Pengguna & Akses. */
function kunciKonstantaIzin(string $nama): array
{
    $isi = file_get_contents(resource_path('views/pages/master/user.blade.php'));

    preg_match("/public const $nama = \[(.*?)\n    \];/s", $isi, $blok);

    expect($blok)->not->toBeEmpty("Konstanta $nama tidak ketemu.");

    preg_match_all("/^\s*'([a-z0-9._-]+)'\s*=>/m", $blok[1], $cocok);

    return $cocok[1];
}

it('membaca daftar izin yang tidak sedikit', function () {
    // Penjaga buat tes ini sendiri: kalau pembacaannya meleset dan hasilnya
    // kosong, tes di bawah lulus tanpa memeriksa apa pun.
    expect(count(RolePermissionSeeder::daftarIzin()))->toBeGreaterThan(30)
        ->and(count(kunciKonstantaIzin('PERMISSION_LABEL')))->toBeGreaterThan(30);
});

it('memberi label untuk tiap izin', function () {
    $tanpaLabel = array_diff(RolePermissionSeeder::daftarIzin(), kunciKonstantaIzin('PERMISSION_LABEL'));

    expect($tanpaLabel)->toBe([], 'Izin tanpa label akan tampil sebagai nama teknis: '.implode(', ', $tanpaLabel));
});

it('memberi penjelasan untuk tiap izin', function () {
    $tanpaPenjelasan = array_diff(RolePermissionSeeder::daftarIzin(), kunciKonstantaIzin('PERMISSION_DESC'));

    expect($tanpaPenjelasan)->toBe([], 'Izin tanpa penjelasan: '.implode(', ', $tanpaPenjelasan));
});

it('tidak menyimpan label untuk izin yang sudah tidak ada', function () {
    $yatim = array_diff(kunciKonstantaIzin('PERMISSION_LABEL'), RolePermissionSeeder::daftarIzin());

    expect($yatim)->toBe([], 'Label untuk izin yang tidak dikenal lagi: '.implode(', ', $yatim));
});

it('menamai grup izin dengan kata yang terbaca', function () {
    // Judul grup diambil dari awalan nama izin. Awalan yang satu kata tapi dua
    // suku harus dipetakan, kalau tidak terbaca "RENCANAAKAD".
    $isi = file_get_contents(resource_path('views/pages/master/user.blade.php'));

    preg_match("/public const PERMISSION_GROUP_MAP = \[(.*?)\n    \];/s", $isi, $blok);

    expect($blok[1])->toContain("'rencanaakad' => 'rencana akad'");
});

it('menampilkan label, bukan nama teknis, di layarnya', function () {
    $this->seed();

    $admin = App\Models\User::factory()->create();
    $admin->assignRole('super-admin');

    $html = Livewire::actingAs($admin)->test('pages::master.user')
        ->set('activeTab', 'role')
        ->set('selectedRoleName', 'admin-kpr')
        ->html();

    expect($html)->toContain('Susun Rencana Akad')
        ->and($html)->not->toContain('Rencanaakad Kelola')
        ->and($html)->not->toContain('RENCANAAKAD');
});
