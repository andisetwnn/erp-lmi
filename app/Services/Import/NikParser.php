<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;

/**
 * Parser NIK Dukcapil 16 digit → info kependudukan.
 *
 * Format NIK: PPKKKKDDMMYY####
 *   PP    = kode provinsi (2 digit)
 *   KKKK  = kode kabupaten/kota + kecamatan (4 digit)
 *   DD    = tanggal lahir (+40 kalau perempuan, mis. 27 laki-laki, 67 perempuan lahir tgl 27)
 *   MM    = bulan lahir
 *   YY    = tahun lahir (2 digit, YY < current_YY = 20YY, else 19YY)
 *   ####  = urutan
 */
class NikParser
{
    /**
     * Parse NIK jadi array info.
     *
     * @return array{
     *   valid: bool,
     *   provinsi_code: ?string,
     *   provinsi_nama: ?string,
     *   kota_code: ?string,
     *   tanggal_lahir: ?string,
     *   jenis_kelamin: ?string,
     * }
     */
    public function parse(?string $nik): array
    {
        $default = [
            'valid' => false,
            'provinsi_code' => null,
            'provinsi_nama' => null,
            'kota_code' => null,
            'tanggal_lahir' => null,
            'jenis_kelamin' => null,
        ];

        if (! $nik || strlen($nik) !== 16 || ! ctype_digit($nik)) {
            return $default;
        }

        $provCode = substr($nik, 0, 2);
        $kotaCode = substr($nik, 0, 4); // kode BPS = 4 digit prov+kab
        $dd = (int) substr($nik, 6, 2);
        $mm = (int) substr($nik, 8, 2);
        $yy = (int) substr($nik, 10, 2);

        // Jenis kelamin
        $jk = $dd > 40 ? 'P' : 'L';
        $tglAsli = $dd > 40 ? $dd - 40 : $dd;

        // Tahun: kalau YY <= current_YY → 20YY, else 19YY
        $currentYY = (int) date('y');
        $year = $yy <= $currentYY ? 2000 + $yy : 1900 + $yy;

        // Validasi tanggal
        if (! checkdate($mm, $tglAsli, $year)) {
            return $default;
        }

        $tglLahir = CarbonImmutable::createFromDate($year, $mm, $tglAsli)->toDateString();

        return [
            'valid' => true,
            'provinsi_code' => $provCode,
            'provinsi_nama' => self::provinsiName($provCode),
            'kota_code' => $kotaCode,
            'tanggal_lahir' => $tglLahir,
            'jenis_kelamin' => $jk,
        ];
    }

    /** Lookup nama provinsi dari 2-digit kode (BPS). */
    public static function provinsiName(string $code): ?string
    {
        static $map = [
            '11' => 'Aceh', '12' => 'Sumatera Utara', '13' => 'Sumatera Barat', '14' => 'Riau',
            '15' => 'Jambi', '16' => 'Sumatera Selatan', '17' => 'Bengkulu', '18' => 'Lampung',
            '19' => 'Kepulauan Bangka Belitung', '21' => 'Kepulauan Riau',
            '31' => 'DKI Jakarta', '32' => 'Jawa Barat', '33' => 'Jawa Tengah', '34' => 'DI Yogyakarta',
            '35' => 'Jawa Timur', '36' => 'Banten',
            '51' => 'Bali', '52' => 'Nusa Tenggara Barat', '53' => 'Nusa Tenggara Timur',
            '61' => 'Kalimantan Barat', '62' => 'Kalimantan Tengah', '63' => 'Kalimantan Selatan',
            '64' => 'Kalimantan Timur', '65' => 'Kalimantan Utara',
            '71' => 'Sulawesi Utara', '72' => 'Sulawesi Tengah', '73' => 'Sulawesi Selatan',
            '74' => 'Sulawesi Tenggara', '75' => 'Gorontalo', '76' => 'Sulawesi Barat',
            '81' => 'Maluku', '82' => 'Maluku Utara',
            '91' => 'Papua Barat', '94' => 'Papua',
        ];

        return $map[$code] ?? null;
    }
}
