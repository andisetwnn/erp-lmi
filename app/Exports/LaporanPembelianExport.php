<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Satu tab Laporan Pembelian, isinya disiapkan LaporanPembelianService::excel()
 * dengan saringan yang sedang dipakai di layar.
 */
class LaporanPembelianExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $kolom
     * @param  list<list<mixed>>  $baris
     */
    public function __construct(
        protected string $judul,
        protected array $kolom,
        protected array $baris,
    ) {}

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return $this->baris;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->kolom;
    }

    public function title(): string
    {
        return $this->judul;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EA580C']]],
        ];
    }
}
