<?php

namespace App\Support;

use App\Models\Master\Bank;
use App\Models\Master\Notaris;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Sales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Daftar pihak yang boleh ditunjuk sebagai rekanan di baris jurnal.
 *
 * Rekanan tidak punya tabel sendiri — ia menunjuk master yang sudah ada lewat kolom
 * polymorphic jurnal_detail.rekanan_type/rekanan_id. Jadi konsumen di jurnal adalah
 * konsumen yang sama dengan yang dipakai SPR, bukan salinannya.
 *
 * Kontraktor/supplier dan karyawan belum punya master, jadi belum bisa ditunjuk.
 * Menambahkannya nanti cukup dengan menambah baris di KATEGORI dan satu case di
 * barisDari() — sisanya (picker, simpan, tampilan) ikut sendiri.
 */
class RekananPilihan
{
    /** Model yang boleh jadi rekanan → label kategorinya di layar. */
    public const KATEGORI = [
        ProspectCustomer::class => 'Konsumen',
        Bank::class => 'Bank',
        Sales::class => 'Sales',
        Notaris::class => 'Notaris',
    ];

    /**
     * Semua rekanan yang bisa dipilih, diratakan jadi satu daftar untuk picker.
     *
     * @return Collection<int, array{nilai:string, kode:string, nama:string, kategori:string}>
     */
    public static function daftar(): Collection
    {
        // Urut nama, bukan kategori. Kalau dikelompokkan per kategori, halaman pertama
        // habis dipakai satu kategori saja dan tidak mewakili isinya — sementara untuk
        // menyaring per kategori sudah ada tombolnya sendiri.
        return collect(self::KATEGORI)
            ->flatMap(fn (string $kategori, string $kelas) => self::barisDari($kelas, $kategori))
            ->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Ambil baris pilihan dari satu master, sudah diseragamkan bentuknya.
     *
     * @param  class-string<Model>  $kelas
     * @return Collection<int, array{nilai:string, kode:string, nama:string, kategori:string}>
     */
    private static function barisDari(string $kelas, string $kategori): Collection
    {
        [$kolomNama, $kolomKode] = match ($kelas) {
            ProspectCustomer::class => ['nama_lengkap', 'nik'],
            Sales::class => ['nama', 'kode'],
            default => ['nama', null],
        };

        $kolom = array_values(array_filter(['id', $kolomNama, $kolomKode]));

        return $kelas::query()
            ->orderBy($kolomNama)
            ->get($kolom)
            ->map(fn (Model $m) => [
                'nilai' => self::nilai($kelas, (int) $m->getKey()),
                'kode' => $kolomKode ? (string) $m->{$kolomKode} : '',
                'nama' => (string) $m->{$kolomNama},
                'kategori' => $kategori,
            ]);
    }

    /**
     * Peta "type:id" → label siap tampil.
     *
     * Untuk tabel yang barisnya bukan Eloquent (mis. hasil DB::table di export PDF),
     * supaya pelabelan tidak jadi satu query per baris.
     *
     * @return Collection<string, string>
     */
    public static function peta(): Collection
    {
        return self::daftar()->mapWithKeys(
            fn (array $r) => [$r['nilai'] => $r['kategori'].' · '.$r['nama']]
        );
    }

    /** Bentuk nilai yang dipakai di form: "App\Models\...\Sales:7". */
    public static function nilai(?string $type, ?int $id): string
    {
        return $type && $id ? $type.':'.$id : '';
    }

    /**
     * Pecah nilai form kembali jadi pasangan type/id.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public static function pecah(?string $nilai): array
    {
        if (! $nilai || ! str_contains($nilai, ':')) {
            return [null, null];
        }

        [$type, $id] = explode(':', $nilai, 2);

        return self::diizinkan($type, (int) $id) ? [$type, (int) $id] : [null, null];
    }

    /** Type ada di daftar yang diizinkan dan barisnya benar-benar ada. */
    public static function diizinkan(?string $type, ?int $id): bool
    {
        if (! $type || ! $id || ! array_key_exists($type, self::KATEGORI)) {
            return false;
        }

        return $type::query()->whereKey($id)->exists();
    }

    /** Teks untuk ditampilkan, mis. "Konsumen · BUDI SANTOSO". Null kalau tidak ada. */
    public static function label(?string $type, ?int $id): ?string
    {
        if (! $type || ! $id || ! array_key_exists($type, self::KATEGORI)) {
            return null;
        }

        return self::labelDari($type::query()->find($id));
    }

    /**
     * Label dari model yang relasinya sudah dimuat — tanpa query tambahan.
     * Dipakai di tabel supaya tidak jadi satu query per baris.
     */
    public static function labelDari(?Model $baris): ?string
    {
        if (! $baris) {
            return null;
        }

        $type = $baris::class;

        if (! array_key_exists($type, self::KATEGORI)) {
            return null;
        }

        $nama = $type === ProspectCustomer::class ? $baris->nama_lengkap : $baris->nama;

        return self::KATEGORI[$type].' · '.$nama;
    }
}
