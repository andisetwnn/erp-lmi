<?php

namespace App\Support;

use App\Models\Master\Proyek;

/**
 * Proyek aktif — satu sumber untuk seluruh halaman: picker di sidebar.
 *
 * Halaman yang datanya milik sebuah proyek wajib menunggu proyek dipilih dulu;
 * kalau kosong, ia menampilkan ajakan memilih, bukan data semua proyek.
 *
 * Link yang membawa `?proyek=` (bookmark, tautan dari halaman lain) tidak boleh
 * diam-diam menimpa tampilan sementara picker menunjukkan proyek lain — itu yang
 * membingungkan. Jadi proyek dari link justru mengganti pilihan picker, dan
 * sesudah itu keduanya selalu sama.
 */
class ProyekAktif
{
    public const SESI = 'active_proyek_id';

    /**
     * Proyek yang dipakai halaman saat dibuka.
     *
     * @param  int|null  $dariLink  nilai `?proyek=` di alamat, kalau ada
     */
    public static function sinkron(?int $dariLink = null): ?int
    {
        if ($dariLink && Proyek::whereKey($dariLink)->exists()) {
            session([self::SESI => $dariLink]);

            return $dariLink;
        }

        $sesi = session(self::SESI);

        return $sesi ? (int) $sesi : null;
    }

    public static function id(): ?int
    {
        $sesi = session(self::SESI);

        return $sesi ? (int) $sesi : null;
    }
}
