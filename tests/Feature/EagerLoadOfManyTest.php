<?php

/**
 * Relasi ber-`ofMany` tidak boleh dibatasi kolomnya saat di-eager-load.
 *
 * `latestOfMany()` menggabungkan tabel dengan dirinya sendiri lewat subkueri.
 * Begitu daftar kolomnya dipersempit — `with('relasi:id,spr_id')` — nama kolom
 * tanpa awalan tabel jadi ambigu dan MySQL menolaknya:
 *
 *     Column 'spr_id' in field list is ambiguous
 *
 * SQLite membiarkannya lewat, jadi seluruh suite ini tidak akan pernah
 * menangkapnya — barunya ketahuan saat halaman dibuka di produksi. Sudah
 * terjadi sekali di arsip sertifikat.
 *
 * Tes ini memeriksa kodenya, bukan menjalankan kuerinya: ia mencari relasi mana
 * saja yang memakai ofMany, lalu memastikan tidak ada eager-load yang
 * membatasi kolomnya.
 */

/** @return list<string> nama relasi yang memakai latestOfMany/oldestOfMany/ofMany */
function relasiOfMany(): array
{
    $nama = [];

    foreach (glob(app_path('Models/**/*.php'), GLOB_BRACE) + glob(app_path('Models/*.php')) as $berkas) {
        $isi = file_get_contents($berkas);

        // Nama method di atas pemanggilan ofMany dalam blok yang sama.
        preg_match_all(
            '/public function (\w+)\(\)[^{]*\{(.*?)\n    \}/s',
            $isi,
            $cocok,
            PREG_SET_ORDER,
        );

        foreach ($cocok as $m) {
            if (preg_match('/latestOfMany\(|oldestOfMany\(|->ofMany\(/', $m[2])) {
                $nama[] = $m[1];
            }
        }
    }

    return array_values(array_unique($nama));
}

/** @return list<string> eager-load bertanda titik dua di seluruh kode */
function eagerLoadBerkolom(): array
{
    $hasil = [];
    $pola = '/[\'"]([a-zA-Z.]*\b(%s))\s*:\s*[a-zA-Z_,. ]+[\'"]/';

    $berkas = array_merge(
        glob(app_path('**/*.php'), GLOB_BRACE),
        glob(app_path('*/*/*.php')),
        glob(resource_path('views/pages/**/*.blade.php'), GLOB_BRACE),
        glob(resource_path('views/pages/*/*.blade.php')),
    );

    $relasi = relasiOfMany();

    if ($relasi === []) {
        return [];
    }

    $cari = sprintf($pola, implode('|', array_map('preg_quote', $relasi)));

    foreach (array_unique($berkas) as $f) {
        if (! is_file($f)) {
            continue;
        }

        if (preg_match_all($cari, file_get_contents($f), $m)) {
            foreach ($m[1] as $temuan) {
                $hasil[] = basename($f).' → '.$temuan;
            }
        }
    }

    return $hasil;
}

it('menemukan relasi ofMany yang perlu dijaga', function () {
    // Penjaga buat tes ini sendiri: kalau pembacaannya meleset dan hasilnya
    // kosong, tes di bawah lulus tanpa memeriksa apa pun.
    expect(relasiOfMany())->not->toBeEmpty()
        ->and(relasiOfMany())->toContain('rencanaAkadUnit');
});

it('tidak membatasi kolom saat eager-load relasi ofMany', function () {
    $melanggar = eagerLoadBerkolom();

    expect($melanggar)->toBe([], implode("\n", array_merge(
        ['Relasi ofMany tidak boleh dibatasi kolomnya — MySQL menolaknya sebagai ambigu:'],
        $melanggar,
    )));
});
