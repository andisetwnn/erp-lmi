<?php

namespace App\Services\Import;

/**
 * Normalize + fuzzy match nama customer antar sumber legacy.
 *
 * Handle:
 * - Gelar (S.T., S.E., S.H., Ir., dr., dll)
 * - Pasangan (NAMA / PASANGAN)
 * - Typo umum (MOHAMAD/MUHAMAD → MUHAMMAD)
 * - Apostrophe (NUR'AINI → NURAINI)
 * - Case & whitespace
 */
class NameMatcher
{
    /** Normalize nama untuk matching. */
    public function normalize(?string $nama): string
    {
        if (! $nama) {
            return '';
        }

        $n = strtoupper(trim($nama));

        // Strip suffix dalam kurung "(CASH)", "(Komersil)", "(BATAL)", dll
        $n = preg_replace('/\s*\([^)]*\)/', '', $n);

        // Ambil nama utama sebelum "/" (hilangkan nama pasangan)
        $n = explode('/', $n)[0];

        // Handle typo ejaan MOHAMAD/MUHAMAD → MUHAMMAD
        $n = preg_replace('/\bMOHAMAD\b/', 'MUHAMMAD', $n);
        $n = preg_replace('/\bMUHAMAD\b/', 'MUHAMMAD', $n);

        // Strip apostrophe & backtick (NUR'AINI → NURAINI)
        $n = str_replace(["'", '`', '"'], '', $n);

        // Strip gelar (S.T., S.E., S.H., S.Pd., S.Kom., Dr., Ir., H., Hj., dst)
        $n = preg_replace('/\b(S\.?T\.?|S\.?E\.?|S\.?H\.?|S\.?PD\.?|S\.?KOM\.?|DR\.?|IR\.?|H\.?|HJ\.?|PROF\.?)\b\.?/i', '', $n);

        // Normalize whitespace
        return trim(preg_replace('/\s+/', ' ', $n));
    }

    /** Return true kalau 2 nama match setelah normalize. */
    public function matches(?string $a, ?string $b): bool
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);

        return $na !== '' && $na === $nb;
    }

    /**
     * Cari 1 kandidat match dari array $haystack (key = nama original).
     * Return key kalau ada match, null kalau tidak.
     */
    public function findMatch(?string $needle, array $haystack): ?string
    {
        $norm = $this->normalize($needle);
        if ($norm === '') {
            return null;
        }

        foreach ($haystack as $key => $_) {
            if ($this->normalize($key) === $norm) {
                return $key;
            }
        }

        return null;
    }
}
