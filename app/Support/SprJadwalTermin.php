<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Helper terpusat untuk menghitung tanggal jadwal termin UM SPR.
 *
 * Aturan bisnis:
 * - Termin ke-1  = anchor + 15 hari
 * - Termin ke-N  = termin sebelumnya + 1 bulan (= anchor + 15 hari + (N-1) bulan)
 *
 * Anchor idealnya = tanggal transfer UTJ (dari `spr.utj_tanggal_transaksi`).
 * Kalau UTJ belum dikonfirmasi Finance, sales/UI bisa pakai `tanggal_spr` sebagai
 * anchor sementara — jadwal akan di-regenerate otomatis saat Finance konfirmasi UTJ.
 */
class SprJadwalTermin
{
    /** Hari offset termin pertama dari anchor. */
    public const OFFSET_TERMIN_PERTAMA_HARI = 15;

    /**
     * Hitung tanggal jatuh tempo untuk 1 termin ke-N (1-indexed).
     */
    public static function tanggalTermin(CarbonInterface $anchor, int $urutan): CarbonInterface
    {
        $offset = self::OFFSET_TERMIN_PERTAMA_HARI;

        return $anchor->copy()->addDays($offset)->addMonthsNoOverflow($urutan - 1);
    }

    /**
     * Generate array jadwal termin lengkap.
     * Return: [ ['urutan' => 1, 'tanggal' => Carbon, 'jumlah' => 5_000_000], ... ]
     */
    public static function generate(CarbonInterface $anchor, int $jumlah, float $perTermin): array
    {
        $rows = [];
        for ($n = 1; $n <= $jumlah; $n++) {
            $rows[] = [
                'urutan' => $n,
                'tanggal' => self::tanggalTermin($anchor, $n),
                'jumlah' => $perTermin,
            ];
        }

        return $rows;
    }

    /**
     * Parse string tanggal (Y-m-d) atau Carbon jadi Carbon, atau null.
     */
    public static function toAnchor(mixed $tanggal): ?Carbon
    {
        if ($tanggal instanceof Carbon) {
            return $tanggal;
        }
        if ($tanggal instanceof CarbonInterface) {
            return Carbon::instance($tanggal);
        }
        if (is_string($tanggal) && $tanggal !== '') {
            return Carbon::parse($tanggal);
        }

        return null;
    }
}
