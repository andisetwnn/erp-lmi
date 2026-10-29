<?php

use App\Models\User;
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
    // Judul grup diambil dari awalan nama izin. Awalan gabungan ("neracalajur",
    // "kasbank") harus dipetakan, kalau tidak judulnya terbaca "NERACALAJUR".
    // Izin baru dengan awalan baru wajib diputuskan grupnya — tambahkan ke
    // daftar ini kalau memang grup sendiri yang namanya sudah terbaca.
    $terbaca = ['user', 'ttd', 'master', 'spr', 'promo', 'biaya tambahan', 'pemberkasan', 'rencana akad',
        'matrix', 'legal', 'teknik', 'target', 'pembayaran', 'akunting', 'laporan', 'log', 'monitoring'];

    $isi = file_get_contents(resource_path('views/pages/master/user.blade.php'));
    preg_match("/public const PERMISSION_GROUP_MAP = \[(.*?)\n    \];/s", $isi, $blok);
    preg_match_all("/'([a-z]+)'\s*=>\s*'([a-z ]+)'/", $blok[1], $pasangan);
    $peta = array_combine($pasangan[1], $pasangan[2]);

    $grup = array_unique(array_map(
        fn ($izin) => $peta[explode('.', $izin)[0]] ?? explode('.', $izin)[0],
        RolePermissionSeeder::daftarIzin(),
    ));

    expect(array_values(array_diff($grup, $terbaca)))->toBe([]);
});

it('menandai izin yang belum dipakai fitur mana pun', function () {
    // Izin yang tidak diperiksa di kode mana pun tidak berpengaruh apa-apa saat
    // dicentang. Keterangannya harus mengatakan begitu, supaya super-admin tidak
    // mengira sudah membatasi atau memberi akses. Begitu fiturnya dibuat, tanda
    // itu dicabut. (Jurnal Bank & Kas Kecil sudah disebut di menu tapi halamannya
    // belum ada, jadi ditandai manual — pemindai ini tidak bisa menangkapnya.)
    $isiHalaman = file_get_contents(resource_path('views/pages/master/user.blade.php'));
    preg_match("/public const PERMISSION_DESC = \[(.*?)\n    \];/s", $isiHalaman, $blokDesc);
    preg_match_all("/^\s*'([a-z0-9._-]+)'\s*=>\s*'(.*)',\s*$/m", $blokDesc[1], $desc);
    $ditandai = array_keys(array_filter(
        array_combine($desc[1], $desc[2]),
        fn ($teks) => str_starts_with($teks, 'Fiturnya belum tersedia'),
    ));

    $kode = '';
    foreach ([app_path(), base_path('routes'), resource_path('views')] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder)) as $berkas) {
            if (str_ends_with($berkas->getFilename(), '.php')) {
                $kode .= file_get_contents($berkas->getPathname());
            }
        }
    }
    // Daftar label & keterangan sendiri bukan pemakaian.
    $kode = preg_replace("/public const PERMISSION_(LABEL|DESC) = \[.*?\n    \];/s", '', $kode);

    $tidakDipakai = array_values(array_filter(
        RolePermissionSeeder::daftarIzin(),
        fn ($izin) => ! str_contains($kode, "'{$izin}'") && ! preg_match('/[\'|:]'.preg_quote($izin, '/').'[\'|]/', $kode),
    ));

    expect(array_values(array_diff($tidakDipakai, $ditandai)))->toBe([])
        ->and($ditandai)->toContain('jurnal.bank.kelola', 'jurnal.kaskecil.kelola');
});

it('menampilkan label, bukan nama teknis, di layarnya', function () {
    $this->seed();

    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    $html = Livewire::actingAs($admin)->test('pages::master.user')
        ->set('activeTab', 'role')
        ->set('selectedRoleName', 'admin-kpr')
        ->html();

    expect($html)->toContain('Susun Rencana Akad')
        ->and($html)->not->toContain('Rencanaakad Kelola')
        ->and($html)->not->toContain('RENCANAAKAD');
});
