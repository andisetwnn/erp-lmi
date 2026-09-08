<?php

namespace App\Services;

use App\Models\Master\Subcon;

/**
 * Menyarankan tarif PPh final jasa konstruksi untuk satu subcon.
 *
 * PENTING: ini SARAN, bukan penetapan. Tarif yang tersimpan tetap milik kolom
 * subcon.pph_persen dan boleh ditimpa — pajak bukan urusan yang boleh diputuskan
 * diam-diam oleh aplikasi.
 *
 * Besarannya disusun dari pola data yang sudah berjalan di sistem lama:
 *   tanpa sertifikat            4,00%
 *   kecil / pribadi + sertifikat 1,75%
 *   menengah / besar            2,65%
 * dan mengikuti tarif jasa konstruksi yang berlaku umum. Kalau peraturannya berubah,
 * yang diubah cukup tabel di bawah ini — bukan mencari-cari angka di seluruh aplikasi.
 */
class SubconTarifPph
{
    /**
     * Tarif per kombinasi. Kunci: jenis_jasa, punya sertifikat, kualifikasi.
     *
     * @var array<string, array<string, float>>
     */
    private const TARIF = [
        'pelaksana' => [
            'bersertifikat_kecil' => 1.75,
            'bersertifikat_besar' => 2.65,
            'tanpa_sertifikat' => 4.00,
        ],
        'perencana_pengawas' => [
            'bersertifikat_kecil' => 3.50,
            'bersertifikat_besar' => 3.50,
            'tanpa_sertifikat' => 6.00,
        ],
    ];

    /**
     * Tarif yang disarankan, atau null kalau datanya belum cukup untuk menyimpulkan.
     */
    public function saran(Subcon $subcon): ?float
    {
        $jenis = $subcon->jenis_jasa;

        // Jasa lain & notaris tidak memakai tarif jasa konstruksi — biar diisi manual.
        if (! isset(self::TARIF[$jenis])) {
            return null;
        }

        if (! $subcon->kualifikasi) {
            return null;
        }

        return self::TARIF[$jenis][$this->golongan($subcon)];
    }

    /** Penjelasan singkat kenapa tarifnya sekian — ditampilkan di sebelah isian. */
    public function alasan(Subcon $subcon): ?string
    {
        if ($this->saran($subcon) === null) {
            return null;
        }

        $sertifikat = $subcon->bersertifikat()
            ? 'punya SBU/SKA yang masih berlaku'
            : 'belum punya SBU/SKA yang berlaku';

        return $subcon->labelJenisJasa().', kualifikasi '.strtolower((string) $subcon->labelKualifikasi()).', '.$sertifikat;
    }

    /** Tarif tersimpan berbeda dari yang disarankan. */
    public function menyimpang(Subcon $subcon): bool
    {
        $saran = $this->saran($subcon);

        if ($saran === null || $subcon->pph_persen === null) {
            return false;
        }

        return abs((float) $subcon->pph_persen - $saran) > 0.001;
    }

    private function golongan(Subcon $subcon): string
    {
        if (! $subcon->bersertifikat()) {
            return 'tanpa_sertifikat';
        }

        return in_array($subcon->kualifikasi, ['menengah', 'besar'], true)
            ? 'bersertifikat_besar'
            : 'bersertifikat_kecil';
    }
}
