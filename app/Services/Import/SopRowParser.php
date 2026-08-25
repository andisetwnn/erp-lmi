<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Parser khusus untuk sheet SOP di DATA MASTER GRHA ARYANA.xlsx.
 *
 * Menangani:
 * - Tanggal: serial Excel (46071) + string "dd/mm/yy" + "25 MEI 2026"
 * - Bank/jenis pembayaran dari kolom CK "CASH/KPR" (mis "KPR BTN SYARIAH" → [kpr, BSY])
 * - Formula error `=IFERROR(__xludf.DUMMYFUNCTION(...),"NAMA")` — extract fallback value
 * - Multi-nomor SPR (mis "00022/00162" → SPR aktif = 00162, batal = 00022)
 * - TGL SETOR format "18/02/26 (BRI 23/12/2025)" atau "BCA 05/02/26" — extract tanggal saja
 */
class SopRowParser
{
    /** Mapping label CASH/KPR di Excel → kode bank pemberkasan. */
    protected const BANK_MAP = [
        'KPR BTN CIBINONG' => 'CBN',
        'KPR BTN SYARIAH' => 'BSY',
        'KPR BSN WARUNG JAMBU' => 'BSN',
        'KPR NOBU KARAWACI' => 'NBU',
        'KPR BCA' => 'BCA',
    ];

    /**
     * Parse cell tanggal ke CarbonImmutable, atau null kalau tidak bisa.
     * Return null untuk kosong / string non-tanggal.
     */
    public function parseDate(mixed $val): ?CarbonImmutable
    {
        if ($val === null || $val === '' || $val === 0 || $val === '0') {
            return null;
        }

        // Handle formula error yang berisi tanggal di fallback: `=IFERROR(...,46175)` → "46175"
        if (is_string($val) && str_starts_with(trim($val), '=')) {
            $val = $this->parseText($val);
            if ($val === '') {
                return null;
            }
        }

        // Excel serial (angka murni)
        if (is_numeric($val)) {
            $n = (float) $val;
            // Serial Excel valid range: > 1 (1900-01-02) dan < 60000 (~2064)
            if ($n < 1 || $n > 60000) {
                return null;
            }
            try {
                $dt = ExcelDate::excelToDateTimeObject($n);

                return CarbonImmutable::instance($dt)->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        }

        $s = trim((string) $val);
        if ($s === '') {
            return null;
        }

        // Coba parse berbagai format string
        $formats = [
            'd/m/y', 'd/m/Y', 'd-m-Y', 'd-m-y',
            'Y-m-d', 'm/d/Y', 'j F Y', 'j-M-Y',
            'd F Y',
        ];
        foreach ($formats as $fmt) {
            try {
                $dt = CarbonImmutable::createFromFormat($fmt, $s);
                if ($dt && $dt->format($fmt) === $s) {
                    return $dt->startOfDay();
                }
            } catch (\Throwable $e) {
                // continue
            }
        }

        // String bahasa Indonesia "25 MEI 2026" → normalize dulu
        $bulanId = [
            'JAN' => '01', 'JANUARI' => '01',
            'FEB' => '02', 'FEBRUARI' => '02',
            'MAR' => '03', 'MARET' => '03',
            'APR' => '04', 'APRIL' => '04',
            'MEI' => '05',
            'JUN' => '06', 'JUNI' => '06',
            'JUL' => '07', 'JULI' => '07',
            'AGU' => '08', 'AGT' => '08', 'AGUSTUS' => '08',
            'SEP' => '09', 'SEPT' => '09', 'SEPTEMBER' => '09',
            'OKT' => '10', 'OKTOBER' => '10',
            'NOV' => '11', 'NOVEMBER' => '11',
            'DES' => '12', 'DESEMBER' => '12',
        ];
        $upper = strtoupper($s);
        if (preg_match('/(\d{1,2})\s+([A-Z]+)\s+(\d{4})/', $upper, $m)) {
            $bln = $bulanId[$m[2]] ?? null;
            if ($bln) {
                try {
                    return CarbonImmutable::createFromFormat('d-m-Y', "{$m[1]}-{$bln}-{$m[3]}")->startOfDay();
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Parse kolom CASH/KPR (CK) ke [jenis_pembayaran, bank_kode].
     *
     * Contoh:
     * - "KPR BTN CIBINONG" → ['kpr', 'CBN']
     * - "KPR BTN SYARIAH" → ['kpr', 'BSY']
     * - "KPR" (generic) → ['kpr', null]
     * - "CASH BERTAHAP" → ['cash_bertahap', null]
     * - "CASH" → ['cash', null]
     * - "" → ['kpr', null] (default)
     *
     * @return array{0: string, 1: ?string}
     */
    public function parseBank(?string $val): array
    {
        $s = strtoupper(trim((string) $val));

        if ($s === '' || $s === 'KPR') {
            return ['kpr', null];
        }

        if (isset(self::BANK_MAP[$s])) {
            return ['kpr', self::BANK_MAP[$s]];
        }

        if (str_starts_with($s, 'CASH BERTAHAP')) {
            return ['cash_bertahap', null];
        }

        if (str_starts_with($s, 'CASH')) {
            return ['cash', null];
        }

        // KPR + nama bank tidak dikenal — fallback KPR tanpa bank
        if (str_starts_with($s, 'KPR')) {
            return ['kpr', null];
        }

        return ['kpr', null];
    }

    /**
     * Extract text dari cell yang mungkin berisi formula error Google Sheets:
     * `=IFERROR(__xludf.DUMMYFUNCTION("""COMPUTED_VALUE"""),"NILAI")` → "NILAI"
     *
     * Kalau normal string, return as-is (trimmed).
     * Kalau formula error tapi fallback numeric (mis 601571086411000), string cast tetap benar.
     */
    public function parseText(mixed $val): string
    {
        $s = trim((string) $val);
        if ($s === '') {
            return '';
        }

        // Formula IFERROR: ekstrak fallback dari `,"VALUE")` atau `,VALUE)` (numeric)
        if (str_starts_with($s, '=') && preg_match('/,\s*"?([^",]+)"?\s*\)\s*$/', $s, $m)) {
            return trim($m[1]);
        }

        return $s;
    }

    /** Alias untuk backward compatibility — parse nama sales. */
    public function parseSalesName(mixed $val): string
    {
        return $this->parseText($val);
    }

    /**
     * Parse cell NO SPR yang mungkin multi-nomor "00022/00162/00170".
     *
     * Return array: ['active' => '00170', 'old' => ['00022', '00162']]
     * Nomor active = paling terakhir setelah `/`; sisanya = old (di-batalkan dgn alasan pindah kavling).
     *
     * @return array{active: ?string, old: array<string>}
     */
    public function parseSprNumbers(?string $val): array
    {
        $s = trim((string) $val);
        if ($s === '') {
            return ['active' => null, 'old' => []];
        }

        $parts = array_values(array_filter(array_map('trim', explode('/', $s))));
        if (empty($parts)) {
            return ['active' => null, 'old' => []];
        }

        $active = array_pop($parts);

        return ['active' => $active, 'old' => $parts];
    }

    /**
     * Convert nomor legacy 4-digit "00042" ke format sistem "SPR/YYYY/MM/0042".
     * Tanggal source dari tgl_spr (kalau ada), fallback ke tanggal sekarang.
     */
    public function formatNomorSpr(string $legacyNumber, ?CarbonImmutable $tglSpr = null): string
    {
        $legacyNumber = str_pad(trim($legacyNumber), 4, '0', STR_PAD_LEFT);
        $dt = $tglSpr ?? CarbonImmutable::now();

        return sprintf('SPR/%s/%s/%s', $dt->format('Y'), $dt->format('m'), $legacyNumber);
    }

    /**
     * Extract tanggal saja dari string TGL SETOR:
     * - "18/02/26 (BRI 23/12/2025)" → parse "18/02/26"
     * - "BCA 05/02/26"             → parse "05/02/26"
     * - "MANDIRI 25/05/26"         → parse "25/05/26"
     * - 46071 (serial)              → parse via parseDate
     */
    public function parseTglSetor(mixed $val): ?CarbonImmutable
    {
        if (is_numeric($val)) {
            return $this->parseDate($val);
        }

        $s = trim((string) $val);
        if ($s === '') {
            return null;
        }

        // Cari pola tanggal dd/mm/yy di dalam string
        if (preg_match('#(\d{1,2}/\d{1,2}/\d{2,4})#', $s, $m)) {
            return $this->parseDate($m[1]);
        }

        // Coba parseDate langsung
        return $this->parseDate($s);
    }

    /**
     * Normalisasi nomor HP Indonesia ke format `62xxxxxxxxxx` (tanpa `+`).
     *
     * Contoh:
     *   "085711790292"   → "6285711790292"
     *   "89603508596"    → "6289603508596"
     *   "+6281234567"    → "6281234567"
     *   "62812xxxx"      → "62812xxxx"
     *   ""               → null (kosong)
     *   "abcd" / "1"     → null (invalid, kurang dari 8 digit)
     */
    public function parsePhone(mixed $val): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $val);
        if (! $digits || strlen($digits) < 8) {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }
        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        // Format tidak dikenal — return as-is (jangan silently corrupt)
        return $digits;
    }

    /**
     * Normalisasi nomor unit ke 2 digit — "4" → "04", "12" → "12".
     *
     * Sistem berjalan menyimpan unit zero-padded ("AB-04"), sedangkan Excel SOP menulis
     * tanpa padding ("AB-4"). Tanpa normalisasi, lookup blok+unit meleset dan importer
     * membuat record rumah kedua untuk unit fisik yang sama.
     *
     * Nilai non-numerik dikembalikan apa adanya.
     */
    public function parseNomorUnit(mixed $val): string
    {
        $unit = trim((string) $val);
        if ($unit === '' || ! ctype_digit($unit)) {
            return $unit;
        }

        return str_pad(ltrim($unit, '0') ?: '0', 2, '0', STR_PAD_LEFT);
    }

    /**
     * Koreksi NIK yang salah ketik di Excel SOP.
     *
     * Nilai kanan = NIK yang sudah diverifikasi admin dari KTP fisik saat SPR dibuat di
     * sistem berjalan. Tanpa koreksi ini, importer gagal mencocokkan orang yang sama dan
     * membuat prospect + SPR ganda untuk unit yang sudah terjual.
     *
     * @var array<string, string>
     */
    public const KOREKSI_NIK = [
        '3674051107830014' => '3174051107830014', // MIFTAHUDIN
        '7315115807990005' => '3172020201940002', // NURRAHMAYANI JAFAR
    ];

    /**
     * Bersihkan NIK — ambil digit saja, potong 16 char, lalu terapkan koreksi yang diketahui.
     * Return string kosong kalau tidak ada digit sama sekali.
     */
    public function parseNik(mixed $val): string
    {
        $digits = preg_replace('/\D/', '', (string) $val);
        if (! $digits) {
            return '';
        }

        $nik = substr($digits, 0, 16);

        return self::KOREKSI_NIK[$nik] ?? $nik;
    }

    /**
     * Clean nominal — handle formula string, comma, currency prefix.
     * "Rp 179.000.000" → 179000000.0
     */
    public function parseNominal(mixed $val): float
    {
        if (is_numeric($val)) {
            return (float) $val;
        }

        $s = trim((string) $val);
        if ($s === '' || str_starts_with($s, '=')) {
            return 0.0;
        }

        // Buang Rp, spasi, dot separator; ganti koma desimal ke dot
        $s = preg_replace('/[^\d,\-]/', '', $s);
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : 0.0;
    }
}
