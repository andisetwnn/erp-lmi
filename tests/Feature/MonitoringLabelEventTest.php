<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tiap event yang dicatat sistem harus punya label dan penjelasan berbahasa
 * manusia di halaman Monitoring.
 *
 * Kalau tidak ada, tampilannya jatuh ke nama teknis mentah — "REALISASI.DELETED"
 * pernah muncul begitu di produksi. Yang membaca halaman ini Direktur dan
 * Project Manager, bukan pemrogram.
 *
 * Tesnya membandingkan langsung ke pemancar eventnya, jadi event baru yang
 * ditambahkan tanpa label akan ketahuan di sini — bukan di layar orang.
 */

/** @return list<string> event yang benar-benar dipancarkan BusinessActivityLogger. */
function eventDipancarkan(): array
{
    $isi = file_get_contents(app_path('Support/BusinessActivityLogger.php'));

    preg_match_all("/->event\('([^']+)'\)/", $isi, $cocok);

    return array_values(array_unique($cocok[1]));
}

/** Nilai konstanta di komponen Volt halaman monitoring. */
function konstantaMonitoring(string $nama): array
{
    $isi = file_get_contents(resource_path('views/pages/monitoring/index.blade.php'));

    // Komponen Volt berupa kelas anonim di dalam Blade, jadi konstantanya tidak
    // bisa dibaca lewat refleksi tanpa merender halamannya lebih dulu.
    preg_match("/public const $nama = \[(.*?)\n    \];/s", $isi, $blok);

    expect($blok)->not->toBeEmpty("Konstanta $nama tidak ketemu di halaman monitoring.");

    preg_match_all("/^\s*'([^']+)'\s*=>/m", $blok[1], $cocok);

    return $cocok[1];
}

it('memancarkan event yang tidak sedikit', function () {
    // Penjaga buat tes ini sendiri: kalau pola pembacaannya meleset dan hasilnya
    // kosong, dua tes di bawah akan lulus tanpa memeriksa apa pun.
    expect(eventDipancarkan())->not->toBeEmpty()
        ->and(count(eventDipancarkan()))->toBeGreaterThan(10);
});

it('memberi label berbahasa manusia untuk tiap event', function () {
    $tanpaLabel = array_diff(eventDipancarkan(), konstantaMonitoring('EVENT_LABELS'));

    expect($tanpaLabel)->toBe([], 'Event tanpa label akan tampil sebagai nama teknis mentah: '.implode(', ', $tanpaLabel));
});

it('memberi penjelasan untuk tiap event', function () {
    $tanpaPenjelasan = array_diff(eventDipancarkan(), konstantaMonitoring('EVENT_DESC'));

    expect($tanpaPenjelasan)->toBe([], 'Event tanpa penjelasan: '.implode(', ', $tanpaPenjelasan));
});

it('tidak melabeli event yang sudah tidak dipancarkan lagi', function () {
    // Label yatim menambah kartu yang jumlahnya selalu nol di halaman.
    $yatim = array_diff(konstantaMonitoring('EVENT_LABELS'), eventDipancarkan());

    expect($yatim)->toBe([], 'Label untuk event yang tidak pernah terjadi: '.implode(', ', $yatim));
});
