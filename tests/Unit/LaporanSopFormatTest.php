<?php

use App\Support\LaporanSopFormat;

/**
 * Susunan kolom laporan Format SOP dipakai bersama oleh tampilan layar dan export Excel.
 * Kalau header dan penyusun baris lepas sinkron, kolom di Excel bergeser diam-diam —
 * angka masuk ke kolom yang salah tanpa error. Test ini menjaga hitungannya.
 */
it('menghasilkan jumlah kolom yang konsisten dengan jumlah slot pembayaran', function () {
    $headers = LaporanSopFormat::headers();

    // 26 kolom sebelum slot UM (identitas, harga, KPR, dan slot BF/UTJ)
    // + 3 kolom per slot UM
    // + 9 kolom penutup (akumulasi, sisa, %, SP3K x3, cash/kpr, akad, status)
    $diharapkan = 26 + (LaporanSopFormat::SLOT_UM * 3) + 9;

    expect($headers)->toHaveCount($diharapkan);
});

it('mengulang tiga kolom per slot UM dengan urutan kwitansi, nominal, tanggal', function () {
    $headers = LaporanSopFormat::headers();
    $awalUm = 26; // tepat setelah slot BF/UTJ

    for ($i = 0; $i < LaporanSopFormat::SLOT_UM; $i++) {
        $dasar = $awalUm + ($i * 3);
        expect($headers[$dasar])->toBe('NO. KWT')
            ->and($headers[$dasar + 1])->toBe('UM'.($i + 1))
            ->and($headers[$dasar + 2])->toBe('TGL. SETOR');
    }
});

it('menempatkan slot BF/UTJ sebelum slot UM pertama', function () {
    $headers = LaporanSopFormat::headers();

    expect($headers[23])->toBe('NO. KWT')
        ->and($headers[24])->toBe('BF/UTJ')
        ->and($headers[25])->toBe('TGL. SETOR')
        ->and($headers[27])->toBe('UM1');
});

it('memakai satu kolom biaya tambahan, bukan dipecah pinggir/depan/ruang', function () {
    $headers = LaporanSopFormat::headers();

    expect($headers)->toContain('BIAYA TAMBAHAN')
        ->and($headers)->not->toContain('BIAYA PINGGIR')
        ->and($headers)->not->toContain('BIAYA DEPAN');
});

it('membuka dengan kolom identitas yang dibekukan di layar', function () {
    $headers = LaporanSopFormat::headers();
    $beku = array_slice($headers, 0, LaporanSopFormat::KOLOM_BEKU);

    expect($beku)->toBe(['NO', 'NO SPR', 'NAMA']);
});

it('menyertakan relasi yang dibutuhkan penyusun baris', function () {
    expect(LaporanSopFormat::relasi())
        ->toContain('prospectCustomer', 'rumah.tipeRumah', 'sales', 'pemberkasan', 'realisasiPembayaran');
});
