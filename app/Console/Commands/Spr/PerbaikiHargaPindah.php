<?php

namespace App\Console\Commands\Spr;

use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kembalikan harga SPR hasil pindah kavling ke harga kesepakatan konsumen.
 *
 * SPR pindahan yang dibuat sebelum perbaikan mengambil harga dari tipe unit
 * tujuan, padahal konsumen sudah sepakat di harga unit lamanya. Akibatnya
 * tagihan berubah tanpa dasar, dan kalau unit tujuan lebih murah sistem sempat
 * menjadwalkan refund atas selisih yang sebenarnya tidak ada.
 *
 * Perbaikannya menyalin balik seluruh blok harga dari SPR asal — sama persis
 * dengan yang sekarang diwariskan otomatis saat pindah kavling.
 *
 * Tanpa --commit hanya melaporkan.
 */
class PerbaikiHargaPindah extends Command
{
    protected $signature = 'spr:perbaiki-harga-pindah
        {--spr=* : Nomor SPR tertentu. Kosongkan untuk memeriksa semua SPR pindahan}
        {--commit : Simpan. Tanpa ini hanya melaporkan}';

    protected $description = 'Kembalikan harga SPR hasil pindah kavling ke harga SPR asalnya';

    /**
     * Kolom yang ikut harga kesepakatan, bukan ikut unit tujuan.
     *
     * Daftarnya sengaja disamakan dengan SprSwitchingService supaya hasil
     * perbaikan identik dengan SPR pindahan yang dibuat setelahnya.
     */
    private const KOLOM_HARGA = [
        'harga_jual', 'diskon', 'biaya_tambahan', 'ppn',
        'kelebihan_tanah_m2', 'harga_per_m2', 'total_harga',
        'nilai_kpr', 'dp_persen', 'dp_nominal', 'sbum', 'um_net',
    ];

    /**
     * Skema pembayaran ikut pindah juga.
     *
     * Tanpa ini SPR bisa berakhir janggal: tertulis KPR padahal nilai KPR-nya nol
     * karena konsumen aslinya membeli tunai. SprSwitchingService mewariskan
     * keduanya, jadi perbaikan ini harus menyamainya.
     */
    private const KOLOM_SKEMA = ['jenis_pembayaran', 'bank_kpr_id'];

    /**
     * Jejak persetujuan ikut pindah — SPR pindahan bukan penjualan baru.
     *
     * `status` sengaja TIDAK ikut disalin. Saat pindah kavling berlangsung, SPR
     * lama masih berstatus approved lalu dibatalkan sesudahnya; kalau disalin
     * sekarang yang terbawa justru "cancelled".
     */
    private const KOLOM_PERSETUJUAN = [
        'approved_by_user_id', 'approved_at',
        'pm_approved_by_user_id', 'pm_approved_at', 'pm_catatan',
        'ttd_sales_path', 'ttd_finance_path', 'ttd_pm_path',
    ];

    public function handle(): int
    {
        $query = Spr::query()->whereNotNull('switched_from_spr_id');

        if ($nomor = array_filter((array) $this->option('spr'))) {
            $query->whereIn('nomor_spr', $nomor);
        }

        $daftar = $query->orderBy('nomor_spr')->get();

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada SPR hasil pindah kavling yang cocok.');

            return self::SUCCESS;
        }

        $perluDiperbaiki = [];

        foreach ($daftar as $spr) {
            $asal = Spr::find($spr->switched_from_spr_id);

            if (! $asal) {
                $this->warn("  {$spr->nomor_spr}: SPR asalnya sudah tidak ada, dilewati.");

                continue;
            }

            if ($alasan = $this->dataAsalMeragukan($asal)) {
                $this->warn("  {$spr->nomor_spr}: SPR asalnya ({$asal->nomor_spr}) $alasan — dilewati, perbaiki manual.");

                continue;
            }

            $beda = [];

            foreach (self::KOLOM_HARGA as $kolom) {
                if ($this->berbeda($spr->{$kolom}, $asal->{$kolom})) {
                    $beda[$kolom] = [$this->rupiah($spr->{$kolom}), $this->rupiah($asal->{$kolom})];
                }
            }

            foreach (self::KOLOM_SKEMA as $kolom) {
                if ($spr->{$kolom} != $asal->{$kolom}) {
                    $beda[$kolom] = [(string) ($spr->{$kolom} ?? '—'), (string) ($asal->{$kolom} ?? '—')];
                }
            }

            // Antrean PM menyaring status=approved yang pm_approved_at-nya kosong.
            // SPR pindahan yang lahir sebelum perbaikan tersangkut di situ.
            $persetujuanKurang = $asal->pm_approved_at !== null && $spr->pm_approved_at === null;

            $refund = SprRealisasiPembayaran::where('spr_id', $spr->id)
                ->where('jenis', 'refund_pindah')
                ->get();

            if ($beda === [] && $refund->isEmpty() && ! $persetujuanKurang) {
                continue;
            }

            $perluDiperbaiki[] = [
                'spr' => $spr, 'asal' => $asal, 'beda' => $beda,
                'refund' => $refund, 'persetujuan' => $persetujuanKurang,
            ];
        }

        $this->laporkan($daftar->count(), $perluDiperbaiki);

        if ($perluDiperbaiki === []) {
            return self::SUCCESS;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('Belum ada yang disimpan. Tambahkan --commit kalau laporan di atas sudah sesuai.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($perluDiperbaiki) {
            foreach ($perluDiperbaiki as $item) {
                $nilai = [];

                foreach ([...self::KOLOM_HARGA, ...self::KOLOM_SKEMA] as $kolom) {
                    $nilai[$kolom] = $item['asal']->{$kolom};
                }

                if ($item['persetujuan']) {
                    foreach (self::KOLOM_PERSETUJUAN as $kolom) {
                        $nilai[$kolom] = $item['asal']->{$kolom};
                    }
                }

                $item['spr']->update($nilai);

                // Refund yang lahir dari selisih harga tidak pernah benar-benar ada
                // dasarnya. Yang sudah terlanjur dibayarkan sengaja tidak disentuh —
                // itu uang yang sungguh berpindah dan harus diselesaikan manual.
                foreach ($item['refund'] as $r) {
                    // Kwitansi baru terbit saat keuangan benar-benar mencairkan. Yang sudah
                    // punya nomor berarti uangnya sungguh berpindah — biarkan, selesaikan manual.
                    if ($r->nomor_kwitansi !== null) {
                        continue;
                    }

                    $r->delete();
                }
            }
        });

        $this->newLine();
        $this->info(count($perluDiperbaiki).' SPR diperbaiki.');

        return self::SUCCESS;
    }

    /**
     * SPR asal yang blok pembayarannya belum lengkap tidak boleh jadi acuan.
     *
     * Di produksi ada SPR bertanda KPR tapi nilai KPR-nya nol dan seluruh harga
     * ditaruh sebagai uang muka — isian yang tidak pernah dirampungkan. Menyalinnya
     * justru menimpa angka SPR pindahan yang sudah benar dengan angka yang bolong.
     */
    private function dataAsalMeragukan(Spr $asal): ?string
    {
        if ($asal->jenis_pembayaran === 'kpr' && (float) $asal->nilai_kpr <= 0.0) {
            return 'bertanda KPR tapi nilai KPR-nya nol';
        }

        if ((float) $asal->total_harga <= 0.0) {
            return 'total harganya nol';
        }

        return null;
    }

    private function rupiah(mixed $v): string
    {
        return number_format((float) $v, 0, ',', '.');
    }

    /** Kolom uang DECIMAL(15,2) — dibandingkan dalam satuan sen supaya tidak kena galat pembulatan. */
    private function berbeda(mixed $a, mixed $b): bool
    {
        return (int) round(((float) $a) * 100) !== (int) round(((float) $b) * 100);
    }

    /**
     * @param  list<array{spr: Spr, asal: Spr, beda: array<string, array{float, float}>, refund: mixed}>  $perlu
     */
    private function laporkan(int $jumlahDiperiksa, array $perlu): void
    {
        $this->newLine();
        $this->line("  SPR pindahan diperiksa : $jumlahDiperiksa");
        $this->line('  Perlu diperbaiki       : '.count($perlu));

        if ($perlu === []) {
            $this->newLine();
            $this->info('Semua SPR pindahan sudah memakai harga SPR asalnya.');

            return;
        }

        foreach ($perlu as $item) {
            $this->newLine();
            $this->line(sprintf(
                '  %s  (dari %s)  unit %s',
                $item['spr']->nomor_spr,
                $item['asal']->nomor_spr,
                $item['spr']->rumah?->kode_unit ?? '-',
            ));

            foreach ($item['beda'] as $kolom => [$sekarang, $seharusnya]) {
                $this->line(sprintf('     %-20s %18s  ->  %18s', $kolom, $sekarang, $seharusnya));
            }

            if ($item['persetujuan']) {
                $this->line(sprintf(
                    '     %-20s %18s  ->  %18s',
                    'persetujuan PM',
                    'belum ada',
                    $item['asal']->pm_approved_at->format('d/m/Y'),
                ));
            }

            foreach ($item['refund'] as $r) {
                $sudahCair = $r->nomor_kwitansi !== null;
                $this->line(sprintf(
                    '     refund_pindah %s %s',
                    $this->rupiah($r->jumlah),
                    $sudahCair
                        ? '— SUDAH dibayarkan, tidak dihapus. Selesaikan manual.'
                        : '— belum cair, akan dihapus.',
                ));
            }
        }
    }
}
