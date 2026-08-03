<?php

/**
 * Konfigurasi jembatan dari data legacy (Excel/buku fisik) ke sistem baru.
 *
 * Dipakai supaya nomor SPR & kwitansi baru meneruskan sequence dari data legacy
 * tanpa perlu import data legacy dulu. Contoh: legacy sudah pakai nomor kwitansi
 * sampai 00537 di Excel, tapi sistem baru DB masih kosong. Set env
 * LEGACY_MAX_NOMOR_KWITANSI=537 supaya kwitansi baru pertama = 00538.
 *
 * Setelah data legacy di-import beneran ke DB, set kembali ke 0 (atau hapus dari
 * .env) — sistem akan otomatis pakai MAX DB.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Nomor SPR terakhir dari data legacy (buku manual / Excel lama)
    |--------------------------------------------------------------------------
    |
    | Nomor SPR baru yang di-generate sistem = max(DB, LEGACY) + 1.
    | Nilai 0 (default) berarti sistem murni pakai DB.
    |
    | Contoh: legacy sampai 00235 di Excel → set 235. Nomor SPR baru pertama
    | akan jadi SPR/YYYY/MM/0236.
    |
    */
    'max_nomor_spr' => (int) env('LEGACY_MAX_NOMOR_SPR', 0),

    /*
    |--------------------------------------------------------------------------
    | Nomor kwitansi terakhir dari data legacy
    |--------------------------------------------------------------------------
    |
    | Sama seperti max_nomor_spr, tapi untuk kwitansi realisasi pembayaran
    | (spr_realisasi_pembayaran.nomor_kwitansi). Format 5-digit sequential
    | global (bukan per bulan).
    |
    | Contoh: legacy sampai 00537 → set 537. Kwitansi baru pertama akan 00538.
    |
    */
    'max_nomor_kwitansi' => (int) env('LEGACY_MAX_NOMOR_KWITANSI', 0),

];
