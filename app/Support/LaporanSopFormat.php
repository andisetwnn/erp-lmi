<?php

namespace App\Support;

use App\Models\Master\Spr;
use Illuminate\Support\Collection;

/**
 * Susunan kolom laporan mengikuti sheet SOP buku manual (DATA MASTER GRHA ARYANA.xlsx),
 * supaya hasil sistem bisa disandingkan langsung dengan file aslinya.
 *
 * Dipakai bersama oleh tampilan layar dan export Excel — kalau dipisah, dua tempat itu
 * pasti akan lepas sinkron begitu ada kolom yang berubah.
 *
 * Beda yang disengaja dari sheet asli:
 *   - Biaya Pinggir + Depan + Menambah Ruang digabung jadi satu kolom "Biaya Tambahan".
 *     Sistem memang menyimpannya sebagai satu nilai di rumah.biaya_tambahan.
 *   - Kolom yang kosong di sheet asli (Samping Gang, PDAM, Diskon UM, UM8–UM17,
 *     Keterangan, Nomor Rekening) tidak diikutkan.
 *   - "Tgl Serah Terima Kunci" tidak ada padanannya di database, jadi dilewati.
 */
class LaporanSopFormat
{
    /** Jumlah slot UM yang ditampilkan — sheet asli menyediakan 17, yang terpakai 7. */
    public const SLOT_UM = 7;

    /** Kolom paling kiri yang dibekukan saat tabel di-scroll menyamping. */
    public const KOLOM_BEKU = 3;

    /**
     * @return array<int, string>
     */
    public static function headers(): array
    {
        $h = [
            'NO', 'NO SPR', 'NAMA',
            'TGL JUAL', 'LOT', 'SALES', 'BLOK', 'UNIT', 'LB', 'LT',
            'ALAMAT', 'NO. TELEPON', 'NO NPWP', 'NO KTP',
            'HARGA JUAL AWAL', 'BIAYA TAMBAHAN', 'BIAYA LAIN2', 'TOTAL HARGA JUAL',
            'PERMOHONAN KPR', 'ACC KPR', 'TOTAL U.M', 'SBUM', 'UM SETELAH SBUM',
            'NO. KWT', 'BF/UTJ', 'TGL. SETOR',
        ];

        for ($i = 1; $i <= self::SLOT_UM; $i++) {
            $h[] = 'NO. KWT';
            $h[] = "UM$i";
            $h[] = 'TGL. SETOR';
        }

        return array_merge($h, [
            'AKUMULASI UANG MUKA', 'SISA UANG MUKA', '% UM',
            'SP3K', 'TGL ACC SP3K', 'TGL EXPIRED SP3K',
            'CASH/KPR', 'TANGGAL AKAD', 'STATUS',
        ]);
    }

    /**
     * Relasi yang wajib di-eager load supaya tidak N+1 saat menyusun baris.
     *
     * @return array<int, string>
     */
    public static function relasi(): array
    {
        return ['prospectCustomer', 'rumah.tipeRumah', 'sales', 'pemberkasan', 'realisasiPembayaran'];
    }

    /**
     * @param  Collection<int, Spr>  $sprs
     * @return array<int, array<int, mixed>>
     */
    public static function rows(Collection $sprs, int $nomorAwal = 1): array
    {
        $no = $nomorAwal;

        return $sprs->map(function (Spr $s) use (&$no) {
            $pc = $s->prospectCustomer;
            $r = $s->rumah;
            $tp = $r?->tipeRumah;
            $pb = $s->pemberkasan;

            $bayar = $s->realisasiPembayaran->sortBy([
                ['tanggal_bayar', 'asc'],
                ['id', 'asc'],
            ])->values();

            $bf = $bayar->firstWhere('jenis', 'bf');
            $um = $bayar->where('jenis', '!=', 'bf')->values();

            $totalUm = (float) $um->sum('jumlah');
            $umNet = (float) $s->um_net;
            $sisa = max(0, $umNet - $totalUm);
            $persen = $umNet > 0 ? round($totalUm / $umNet * 100, 1) : 0;

            $baris = [
                $no++,
                $s->nomor_display ?? $s->nomor_spr,
                $pc?->nama_lengkap,
                self::tgl($s->tanggal_spr),
                $r?->lot,
                $s->sales?->nama,
                $r?->blok,
                $r?->nomor_unit,
                $tp?->luas_bangunan,
                $tp?->luas_tanah,
                $pc?->alamat,
                $pc?->hp,
                $pc?->npwp,
                $pc?->nik,
                (float) $s->harga_jual,
                (float) ($r?->biaya_tambahan ?? 0),
                (float) $s->biaya_tambahan,
                (float) $s->total_harga,
                (float) $s->nilai_kpr,
                (float) ($pb?->sp3k_nominal ?? 0),
                $umNet + (float) $s->sbum,
                (float) $s->sbum,
                $umNet,
                $bf?->nomor_kwitansi,
                $bf ? (float) $bf->jumlah : null,
                self::tgl($bf?->tanggal_bayar),
            ];

            for ($i = 0; $i < self::SLOT_UM; $i++) {
                $slot = $um[$i] ?? null;
                $baris[] = $slot?->nomor_kwitansi;
                $baris[] = $slot ? (float) $slot->jumlah : null;
                $baris[] = self::tgl($slot?->tanggal_bayar);
            }

            return array_merge($baris, [
                $totalUm,
                $sisa,
                $persen,
                $pb?->sp3k_tanggal ? 'ACC' : null,
                self::tgl($pb?->sp3k_tanggal),
                self::tgl($pb?->sp3k_expired),
                strtoupper((string) $s->jenis_pembayaran),
                self::tgl($s->tgl_akad),
                strtoupper((string) $s->status),
            ]);
        })->all();
    }

    private static function tgl($v): ?string
    {
        return $v?->format('d/m/Y');
    }
}
