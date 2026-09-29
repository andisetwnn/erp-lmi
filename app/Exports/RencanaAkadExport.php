<?php

namespace App\Exports;

use App\Models\Master\RencanaAkad;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export daftar unit dari 1 Rencana Akad.
 * Kolomnya mengikuti kolom yang dipakai bank & notaris di lembar rencana Excel
 * legacy — supaya berkas XLSX ini bisa langsung dilampirkan tanpa reformat.
 */
class RencanaAkadExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private RencanaAkad $rencana) {}

    public function title(): string
    {
        return 'Rencana '.substr($this->rencana->nomor, -20);
    }

    public function headings(): array
    {
        return [
            'No', 'Gol', 'SPR', 'Blok', 'Lot', 'Tipe', 'HGB', 'Nama Konsumen',
            'NIK', 'HP', 'NPWP',
            'Harga Jual', 'Diskon', 'PPN', 'Harga Net', 'Nilai KPR',
            'BP2BT', 'UM Seharusnya', 'Setoran UM', 'Setoran AJB', 'Setoran BPHTB',
            'Promo UM', 'Promo AJB', 'Promo BPHTB',
            'Kurang UM', 'Lebih UM',
            'Bayar PS4a2', 'Bayar BI Notaris', 'Bayar BPHTB', 'Nilai PPJB',
            'Marketing', 'Tanggal PPJB', 'Tanggal AJB', 'NTPN', 'Status Unit',
        ];
    }

    public function array(): array
    {
        $rows = [];
        $i = 0;

        foreach ($this->rencana->unit as $u) {
            $i++;
            $spr = $u->spr;
            $rumah = $spr?->rumah;
            $prospect = $spr?->prospectCustomer;
            $tipe = $rumah?->tipeRumah;

            $setoranUm = (float) ($spr?->realisasiPembayaran->where('jenis', 'um')->sum('jumlah') ?? 0);
            $umSeharusnya = max(0, (float) ($spr?->total_harga ?? 0) - (float) ($spr?->nilai_kpr ?? 0));
            $kurangUm = max(0, $umSeharusnya - $setoranUm - (float) $u->promo_um);
            $lebihUm = max(0, $setoranUm + (float) $u->promo_um - $umSeharusnya);

            $rows[] = [
                $i,
                strtoupper($u->jenis_akad),
                $spr?->nomor_display,
                $rumah?->blok,
                $rumah?->lot,
                $tipe?->nama_tipe,
                $u->hgb,
                $prospect?->nama_lengkap,
                $prospect?->nik,
                $prospect?->hp,
                $prospect?->npwp,
                (float) ($spr?->harga_jual ?? 0),
                (float) ($spr?->diskon ?? 0),
                (float) ($spr?->ppn ?? 0),
                (float) ($spr?->total_harga ?? 0),
                (float) ($spr?->nilai_kpr ?? 0),
                (float) $u->bp2bt,
                $umSeharusnya,
                $setoranUm,
                (float) $u->setoran_ajb,
                (float) $u->setoran_bphtb,
                (float) $u->promo_um,
                (float) $u->promo_ajb,
                (float) $u->promo_bphtb,
                $kurangUm,
                $lebihUm,
                (float) $u->bayar_ps4a2,
                (float) $u->bayar_bi_notaris,
                (float) $u->bayar_bphtb,
                (float) $u->nominal_ppjb,
                $spr?->sales?->nama,
                $u->tanggal_ppjb?->format('d/m/Y'),
                $u->tanggal_ajb?->format('d/m/Y'),
                $u->ntpn,
                ucfirst($u->status),
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:AI1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFEA580C'], // orange-600 sesuai brand
            ],
        ]);

        // Freeze header + kolom kiri (No, Gol, SPR, Blok)
        $sheet->freezePane('E2');

        // Auto width kolom kolom kecil
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return [];
    }
}
