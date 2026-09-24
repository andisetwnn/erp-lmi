<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * DBOS dipasang sebagai aplikasi di ponsel sales.
 *
 * Syaratnya tiga: ada manifest yang ditautkan, ikon 192 dan 512 piksel, serta
 * service worker yang terdaftar. Kalau salah satu hilang, peramban diam saja —
 * tidak ada pesan galat, tombol pasang cuma tidak pernah muncul. Karena itu
 * ketiganya dipatok di sini.
 */
function manifestDbos(): array
{
    return json_decode(file_get_contents(public_path('dbos-app/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('menautkan manifest dan mendaftarkan service worker di halaman DBOS', function () {
    $html = $this->get(route('dbos.login'))->assertOk()->getContent();

    expect($html)->toContain('rel="manifest"')
        ->and($html)->toContain('/dbos-app/manifest.json')
        ->and($html)->toContain("navigator.serviceWorker.register('/sw-dbos.js'")
        ->and($html)->toContain("scope: '/dbos'");
});

it('tidak memasang penanda itu di halaman ERP', function () {
    // ERP bukan aplikasi yang dipasang. Kalau manifest DBOS ikut tertaut di
    // sana, peramban menawarkan memasang ERP dengan identitas DBOS.
    $this->seed();

    $user = App\Models\User::factory()->create();
    $user->assignRole('super-admin');

    // Dashboard mengarahkan ke milik role-nya, jadi pengalihannya diikuti dulu.
    $html = $this->actingAs($user)->followingRedirects()->get(route('dashboard'))
        ->assertOk()->getContent();

    expect($html)->not->toContain('/dbos-app/manifest.json')
        ->and($html)->not->toContain('sw-dbos.js');
});

it('menyediakan berkas yang dirujuk manifest', function () {
    $manifest = manifestDbos();

    expect(public_path('sw-dbos.js'))->toBeFile()
        ->and(public_path('dbos-app/offline.html'))->toBeFile();

    foreach ($manifest['icons'] as $ikon) {
        expect(public_path(ltrim($ikon['src'], '/')))->toBeFile();
    }
});

it('memakai ikon berukuran 192 dan 512 piksel sesuai yang disebutkan', function () {
    // Peramban menolak manifest yang ukuran ikonnya tidak cocok dengan
    // berkasnya, dan ukuran 192 serta 512 dua-duanya wajib ada.
    $ukuran = [];

    foreach (manifestDbos()['icons'] as $ikon) {
        [$lebar, $tinggi] = getimagesize(public_path(ltrim($ikon['src'], '/')));

        expect("{$lebar}x{$tinggi}")->toBe($ikon['sizes']);

        $ukuran[] = $lebar;
    }

    expect($ukuran)->toContain(192)->toContain(512);
});

it('menyediakan ikon maskable supaya tidak terpangkas di peluncur bulat', function () {
    $maskable = collect(manifestDbos()['icons'])->firstWhere('purpose', 'maskable');

    expect($maskable)->not->toBeNull();
});

it('memakai lingkup yang memuat halaman awalnya', function () {
    // Kalau halaman awal di luar lingkup, aplikasi yang dipasang membukanya
    // sebagai tab peramban biasa, bukan sebagai aplikasi.
    $manifest = manifestDbos();

    expect($manifest['start_url'])->toStartWith($manifest['scope'])
        ->and($manifest['scope'])->toBe('/dbos')
        ->and($manifest['display'])->toBe('standalone');
});

it('menunjuk ke alamat yang benar-benar ada pada pintasannya', function () {
    // Pintasan muncul saat ikon aplikasi ditekan lama. Alamat yang salah baru
    // ketahuan saat dipakai sales di lapangan.
    $alamat = collect(app('router')->getRoutes()->getRoutesByMethod()['GET'] ?? [])
        ->keys()
        ->map(fn (string $uri) => '/'.ltrim($uri, '/'))
        ->all();

    foreach (manifestDbos()['shortcuts'] as $pintasan) {
        expect($alamat)->toContain($pintasan['url']);
    }
});

it('tidak menaruh berkas statis di alamat yang sudah dipakai route', function () {
    // Aturan rewrite Laravel tidak meneruskan permintaan yang cocok dengan
    // direktori nyata. Direktori bernama "dbos" di public/ membuat /dbos
    // berhenti di server web dan tidak pernah sampai ke aplikasi — seluruh
    // DBOS mati, dan tidak ada tes lain yang menangkapnya karena tes tidak
    // lewat server web.
    $segmenRoute = collect(app('router')->getRoutes())
        ->map(fn ($r) => strtok($r->uri(), '/'))
        ->reject(fn (?string $s) => $s === null || $s === '' || str_contains($s, '{'))
        ->unique();

    $direktori = collect(glob(public_path('*'), GLOB_ONLYDIR))
        ->map(fn (string $d) => basename($d))
        // public/storage memang menaungi route storage/{path}, dan itu disengaja:
        // berkas unggahan dilayani langsung oleh server web, routenya cuma cadangan.
        ->reject(fn (string $d) => $d === 'storage');

    expect($direktori->intersect($segmenRoute)->all())->toBe([]);
});
