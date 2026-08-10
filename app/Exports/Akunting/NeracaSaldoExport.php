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

class NeracaSaldoExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    protected array $data;

    protected Perusahaan $perusahaan;

    public function __construct(
        protected string $from,
        protected string $to,
    ) {
        $this->perusahaan = Perusahaan::first();
        $this->data = app(LaporanAkuntingService::class)->neracaSaldo($this->perusahaan->id, $from, $to);
    }

    public function title(): string
    {
        return 'Neraca Saldo';
    }

    public function array(): array
    {
        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $out[] = array_fill(0, 7, '');
        }
        // Row 5-6: header
        $out[] = ['KODE', 'NAMA AKUN', 'TIPE', 'MUTASI PERIODE', '', 'SALDO AKHIR', ''];
        $out[] = ['', '', '', 'Debet', 'Kredit', 'Debet', 'Kredit'];

        foreach ($this->data['rows'] as $r) {
            $out[] = [
                $r['coa']->kode,
                $r['coa']->nama,
                ucfirst($r['coa']->tipe),
                $r['debet'] > 0 ? $r['debet'] : '',
                $r['kredit'] > 0 ? $r['kredit'] : '',
                $r['saldo_debet'] > 0 ? $r['saldo_debet'] : '',
                $r['saldo_kredit'] > 0 ? $r['saldo_kredit'] : '',
            ];
        }

        $out[] = [
            '', '', 'TOTAL',
            $this->data['total_debet'],
            $this->data['total_kredit'],
            $this->data['total_saldo_debet'],
            $this->data['total_saldo_kredit'],
        ];

        return $out;
    }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 48, 'C' => 12, 'D' => 20, 'E' => 20, 'F' => 20, 'G' => 20];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Kop
                $sheet->mergeCells('A1:G1');
                $sheet->setCellValue('A1', $this->perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);
                $sheet->mergeCells('A2:G2');
                $sheet->setCellValue('A2', 'NERACA SALDO (TRIAL BALANCE)');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);
                $sheet->mergeCells('A3:G3');
                $sheet->setCellValue('A3', 'Periode: '
                    .Carbon::parse($this->from)->translatedFormat('d F Y')
                    .' — '.Carbon::parse($this->to)->translatedFormat('d F Y'));
                $sheet->getStyle('A3')->applyFromArray([
                    'font' => ['italic' => true, 'size' => 10],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
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
                $sheet->mergeCells('C5:C6');
                $sheet->mergeCells('D5:E5');
                $sheet->mergeCells('F5:G5');
                $sheet->getStyle('A5:G6')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF4']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);

                $highest = $sheet->getHighestRow();
                $sheet->getStyle("D7:G{$highest}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
                $sheet->getStyle("A7:G{$highest}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
                ]);

                // Total row: highlight biru
                $sheet->getStyle("A{$highest}:G{$highest}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D8FF']],
                ]);

                $sheet->freezePane('A7');
            },
        ];
    }
}
