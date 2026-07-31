<?php

namespace App\Support;

/**
 * Sanitasi nomor HP Indonesia.
 * - Buang karakter non-digit (strip, spasi, tanda kurung, dll).
 * - Normalisasi prefix +62 / 62 / 0 ke satu format standar (mulai dengan 0).
 * - Handle prefix ganda / typo (mis. +62812, 62812, 0812 semua jadi 0812).
 */
class PhoneNumber
{
    /**
     * Sanitize nomor HP jadi format standar Indonesia (0xxx).
     * Null/empty input return null.
     */
    public static function sanitize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        // Buang semua non-digit (termasuk +, -, spasi, kurung, dll)
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        // Handle prefix duplikat: 6262812, 006262812, dll → 62812
        // Buang leading zeros berturut-turut
        $digits = ltrim($digits, '0');

        // Normalisasi prefix 62 → 0 (Indonesia country code)
        // Loop untuk handle 6262812 (prefix ganda karena paste error)
        while (str_starts_with($digits, '62')) {
            $digits = substr($digits, 2);
        }

        // Pastikan mulai dengan 0 (format nasional Indonesia)
        // Kalau sudah mulai dengan 8/9/dsb (mobile), prepend 0
        return '0'.$digits;
    }
}
