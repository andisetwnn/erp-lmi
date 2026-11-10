<?php

namespace App\Console\Commands;

use App\Services\PembelianService;
use Illuminate\Console\Command;

class BatalkanPoGantung extends Command
{
    protected $signature = 'pembelian:batalkan-po-gantung';

    protected $description = 'Batalkan PO yang tidak dibayar sama sekali melewati batas hari di Pengaturan Pembelian (kosong = tidak aktif)';

    public function handle(PembelianService $pembelian): int
    {
        $batas = $pembelian->batasPoGantungHari();
        if ($batas === null) {
            $this->info('Batal otomatis PO gantung tidak aktif.');

            return self::SUCCESS;
        }

        $dibatalkan = $pembelian->batalkanPoGantung();
        $this->info($dibatalkan->isEmpty()
            ? "Tidak ada PO yang lewat {$batas} hari tanpa pembayaran."
            : "Membatalkan {$dibatalkan->count()} PO: ".$dibatalkan->pluck('nomor')->implode(', '));

        return self::SUCCESS;
    }
}
