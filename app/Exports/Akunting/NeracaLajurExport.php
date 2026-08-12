<?php

namespace App\Exports\Akunting;

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class NeracaLajurExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    protected array $data;

    protected Perusahaan $perusahaan;

    public function __construct(
        protected string $from,
        protected string $to,
    ) {
        $this->perusahaan = Perusahaan::first();
        $this->data = app(LaporanAkuntingService::class)->neracaLajur($this->perusahaan->id, $from, $to);
    }

    public function title(): string
    {
        return 'Neraca Lajur';
    }

    public function array(): array
    {
        $out = [];
        // 4 baris kop kosong
        for ($i = 0; $i < 4; $i++) {
            $out[] = array_fill(0, 12, '');
        }
        // Header 2 baris (row 5 & 6)
        $out[] = ['KODE', 'NAMA AKUN', 'NERACA SALDO', '', 'AJP', '', 'DISESUAIKAN', '', 'RUGI/LABA', '', 'NERACA', ''];
        $out[] = ['', '', 'Debet', 'Kredit', 'Debet', 'Kredit', 'Debet', 'Kredit', 'Debet', 'Kredit', 'Debet', 'Kredit'];

        foreach ($this->data['rows'] as $r) {
            $out[] = [
                $r['coa']->kode,
                $r['coa']->nama,
                $r['ns_debet'] > 0 ? $r['ns_debet'] : '',
                $r['ns_kredit'] > 0 ? $r['ns_kredit'] : '',
                $r['ajp_debet'] > 0 ? $r['ajp_debet'] : '',
                $r['ajp_kredit'] > 0 ? $r['ajp_kredit'] : '',
                $r['adj_debet'] > 0 ? $r['adj_debet'] : '',
                $r['adj_kredit'] > 0 ? $r['adj_kredit'] : '',
                $r['lr_debet'] > 0 ? $r['lr_debet'] : '',
                $r['lr_kredit'] > 0 ? $r['lr_kredit'] : '',
                $r['nr_debet'] > 0 ? $r['nr_debet'] : '',
                $r['nr_kredit'] > 0 ? $r['nr_kredit'] : '',
            ];
        }

        // TOTAL row
        $out[] = [
            '', 'TOTAL',
            $this->data['total_ns_debet'], $this->data['total_ns_kredit'],
            $this->data['total_ajp_debet'], $this->data['total_ajp_kredit'],
            $this->data['total_adj_debet'], $this->data['total_adj_kredit'],
            $this->data['total_lr_debet'], $this->data['total_lr_kredit'],
            $this->data['total_nr_debet'], $this->data['total_nr_kredit'],
        ];

        // Laba/Rugi balancing row
        $lr = $this->data['laba_rugi'];
        $out[] = [
            '', ($lr >= 0 ? 'LABA' : 'RUGI').' Bersih Periode',
            '', '', '', '', '', '',
            $lr >= 0 ? $lr : '',
            $lr < 0 ? -$lr : '',
            $lr < 0 ? -$lr : '',
            $lr >= 0 ? $lr : '',
        ];

        return $out;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10, 'B' => 40,
            'C' => 15, 'D' => 15, 'E' => 15, 'F' => 15,
            'G' => 15, 'H' => 15, 'I' => 15, 'J' => 15,
            'K' => 15, 'L' => 15,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

                // Kop
                $sheet->mergeCells('A1:L1');
                $sheet->setCellValue('A1', $this->perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 4],
                ]);
                $sheet->mergeCells('A2:L2');
                $sheet->setCellValue('A2', 'NERACA LAJUR (WORKSHEET)');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 4],
                ]);
                $sheet->mergeCells('A3:L3');
                $sheet->setCellValue('A3', 'Periode: '
                    .Carbon::parse($this->from)->translatedFormat('d F Y')
                    .' — '.Carbon::parse($this->to)->translatedFormat('d F Y'));
                $sheet->getStyle('A3')->applyFromArray([
                    'font' => ['italic' => true, 'size' => 10],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 4],
                ]);

                $logoPath = public_path('images/logo.png');
                if (is_file($logoPath)) {
                    $drawing = new Drawing;
                    $drawing->setName('Logo');
                    $drawing->setPath($logoPath);
                    $drawing->setHeight(50);
                    $drawing->setCoordinates('A1');
                    $drawing->setWorksheet($sheet);
                }
                $sheet->getRowDimension(1)->setRowHeight(20);
                $sheet->getRowDimension(2)->setRowHeight(18);
                $sheet->getRowDimension(3)->setRowHeight(15);

                // Header row 5-6 merged
                $sheet->mergeCells('A5:A6');
                $sheet->mergeCells('B5:B6');
                $sheet->mergeCells('C5:D5'); // NS
                $sheet->mergeCells('E5:F5'); // AJP
                $sheet->mergeCells('G5:H5'); // Adj
                $sheet->mergeCells('I5:J5'); // LR
                $sheet->mergeCells('K5:L5'); // NR

                $sheet->getStyle('A5:L6')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);
                // Warna per group header
                $sheet->getStyle('C5:D5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
                $sheet->getStyle('E5:F5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
                $sheet->getStyle('G5:H5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCEFFB');
                $sheet->getStyle('I5:J5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FADBD8');
                $sheet->getStyle('K5:L5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D5F5E3');

                $highest = $sheet->getHighestRow();
                $sheet->getStyle("C7:L{$highest}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
                $sheet->getStyle("A7:L{$highest}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
                ]);

                // TOTAL row (highest - 1) & LABA/RUGI row (highest)
                $totalRow = $highest - 1;
                $laRow = $highest;
                $sheet->getStyle("A{$totalRow}:L{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D8FF']],
                ]);
                $sheet->getStyle("A{$laRow}:L{$laRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8F4D8']],
                ]);

                $sheet->freezePane('C7');
            },
        ];
    }
}
