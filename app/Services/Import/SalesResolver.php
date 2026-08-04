<?php

namespace App\Services\Import;

use App\Models\Master\Sales;

/**
 * Resolve nama sales legacy (dari Excel) ke sales_id di master sistem.
 *
 * Mapping alias hardcoded — nama panggilan / typo lama → nama master.
 * Kalau nama tidak ada di mapping, coba lookup exact di master sales.
 *
 * Return sales_id atau null (kalau tidak ketemu).
 */
class SalesResolver
{
    /** Mapping alias legacy (uppercase) → nama master sales (harus exact match di DB). */
    protected const ALIAS = [
        'ANA' => 'Nur Fitriana',
        'NUR FITRIANA' => 'Nur Fitriana',
        'ARIPIN' => 'Arifin',
        'ARIFIN' => 'Arifin',
        'RAMDAN' => 'Muhamad Ramdan',
        'MUHAMAD RAMDAN' => 'Muhamad Ramdan',
        'RIDHO' => 'Ridha Mustafa',
        'RIHDO' => 'Ridha Mustafa',
        'RIDHA MUSTAFA' => 'Ridha Mustafa',
        'HENDRA' => 'Hendra Maryono',
        'HENDRA MARYONO' => 'Hendra Maryono',
        'DELON' => 'Delon',
        'AGUS' => 'Agus Solehudin',
        'AGUS SOLEHUDIN' => 'Agus Solehudin',
        'ADE' => 'Ade Saputra',
        'ADE SAPUTRA' => 'Ade Saputra',
        'ANDI' => 'Andi',
        'DKH' => 'Delta Kahuripan',
        'DELTA KAHURIPAN' => 'Delta Kahuripan',
    ];

    protected array $cache = [];

    /**
     * Resolve nama sales legacy ke Sales model.
     */
    public function resolve(?string $namaLegacy): ?Sales
    {
        if (! $namaLegacy) {
            return null;
        }

        $key = strtoupper(trim($namaLegacy));

        // Handle format "ANA/DELON" (kolaborasi) — ambil sales pertama sebagai primary
        if (str_contains($key, '/')) {
            $key = trim(explode('/', $key)[0]);
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Cari nama master dari alias
        $namaMaster = self::ALIAS[$key] ?? $namaLegacy;

        // Query DB
        $sales = Sales::where('nama', $namaMaster)->first();

        $this->cache[$key] = $sales;

        return $sales;
    }
}
