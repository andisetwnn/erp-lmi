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

class LabaRugiExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    protected array $data;

    protected Perusahaan $perusahaan;

    public function __construct(
        protected string $from,
        protected string $to,
    ) {
        $this->perusahaan = Perusahaan::first();
        $this->data = app(LaporanAkuntingService::class)->labaRugi($this->perusahaan->id, $from, $to);
    }

    public function title(): string
    {
        return 'Laba Rugi';
    }

    public function array(): array
    {
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['', '', ''];
        }
        $rows[] = ['KODE — NAMA AKUN', 'Detail', 'Sub Total'];

        // PENDAPATAN
        $rows[] = ['PENDAPATAN', '', ''];
        foreach ($this->data['pendapatan']['groups'] as $group) {
            $rows[] = [$group['header']->kode.' — '.$group['header']->nama, '', $group['total']];
            foreach ($group['items'] as $item) {
                $rows[] = ['   '.$item['coa']->kode.' — '.$item['coa']->nama, $item['saldo'], ''];
            }
        }
        $rows[] = ['TOTAL PENDAPATAN', '', $this->data['pendapatan']['total']];
        $rows[] = ['', '', ''];

        // BEBAN
        $rows[] = ['BEBAN / HPP', '', ''];
        foreach ($this->data['beban']['groups'] as $group) {
            $rows[] = [$group['header']->kode.' — '.$group['header']->nama, '', $group['total']];
            foreach ($group['items'] as $item) {
                $rows[] = ['   '.$item['coa']->kode.' — '.$item['coa']->nama, $item['saldo'], ''];
            }
        }
        $rows[] = ['TOTAL BEBAN', '', $this->data['beban']['total']];
        $rows[] = ['', '', ''];

        $rows[] = [
            $this->data['laba_rugi'] >= 0 ? 'LABA BERSIH PERIODE BERJALAN' : 'RUGI BERSIH PERIODE BERJALAN',
            '',
            $this->data['laba_rugi'],
        ];

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 55, 'B' => 22, 'C' => 22];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:C1');
                $sheet->setCellValue('A1', $this->perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);

                $sheet->mergeCells('A2:C2');
                $sheet->setCellValue('A2', 'LAPORAN LABA RUGI');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);

                $sheet->mergeCells('A3:C3');
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

                $sheet->getStyle('A5:C5')->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF4']],
                ]);

                $highest = $sheet->getHighestRow();
                $sheet->getStyle("B6:C{$highest}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
                $sheet->getStyle("A6:C{$highest}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
                ]);

                foreach (range(1, $highest) as $r) {
                    $v = trim((string) $sheet->getCell('A'.$r)->getValue());
                    if ($v === 'PENDAPATAN') {
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '145A32']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D5F5E3']],
                        ]);
                    }
                    if ($v === 'BEBAN / HPP') {
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '7D1919']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FADBD8']],
                        ]);
                    }
                    if (in_array($v, ['TOTAL PENDAPATAN', 'TOTAL BEBAN'])) {
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E8E8']],
                        ]);
                    }
                    if (in_array($v, ['LABA BERSIH PERIODE BERJALAN', 'RUGI BERSIH PERIODE BERJALAN'])) {
                        $color = $v === 'LABA BERSIH PERIODE BERJALAN' ? '145A32' : '7D1919';
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $color]],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D8FF']],
                        ]);
                    }
                    $cVal = $sheet->getCell('C'.$r)->getValue();
                    if ($cVal !== '' && ! in_array($v, ['PENDAPATAN', 'BEBAN / HPP', 'TOTAL PENDAPATAN', 'TOTAL BEBAN', 'LABA BERSIH PERIODE BERJALAN', 'RUGI BERSIH PERIODE BERJALAN'])) {
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF3F7']],
                        ]);
                    }
                }

                $sheet->freezePane('A6');
            },
        ];
    }
}
