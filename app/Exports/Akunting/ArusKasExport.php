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

class ArusKasExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    protected array $data;

    protected Perusahaan $perusahaan;

    public function __construct(
        protected string $from,
        protected string $to,
    ) {
        $this->perusahaan = Perusahaan::first();
        $this->data = app(LaporanAkuntingService::class)->arusKas($this->perusahaan->id, $from, $to);
    }

    public function title(): string
    {
        return 'Arus Kas';
    }

    public function array(): array
    {
        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $out[] = array_fill(0, 5, '');
        }
        $out[] = ['Kode', 'Nama Akun (lawan vs kas)', 'Kas Masuk', 'Kas Keluar', 'Net'];

        foreach ([
            'operasi' => 'AKTIVITAS OPERASI',
            'investasi' => 'AKTIVITAS INVESTASI',
            'pendanaan' => 'AKTIVITAS PENDANAAN',
        ] as $key => $title) {
            $out[] = [$title, '', '', '', ''];
            $s = $this->data[$key];
            if (empty($s['items'])) {
                $out[] = ['', '   (tidak ada mutasi)', '', '', ''];
            } else {
                foreach ($s['items'] as $item) {
                    $out[] = [
                        $item['lawan_coa']->kode,
                        '   '.$item['lawan_coa']->nama,
                        $item['masuk'] > 0 ? $item['masuk'] : '',
                        $item['keluar'] > 0 ? $item['keluar'] : '',
                        $item['net'],
                    ];
                }
            }
            $out[] = ['', 'Net '.$title, '', '', $s['net']];
            $out[] = ['', '', '', '', ''];
        }

        $out[] = ['', 'KENAIKAN / (PENURUNAN) KAS BERSIH', '', '', $this->data['kenaikan_bersih']];
        $out[] = ['', 'Kas & Bank Awal Periode', '', '', $this->data['kas_awal']];
        $out[] = ['', 'KAS & BANK AKHIR PERIODE', '', '', $this->data['kas_akhir']];

        return $out;
    }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 50, 'C' => 20, 'D' => 20, 'E' => 20];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:E1');
                $sheet->setCellValue('A1', $this->perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);
                $sheet->mergeCells('A2:E2');
                $sheet->setCellValue('A2', 'LAPORAN ARUS KAS');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);
                $sheet->mergeCells('A3:E3');
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

                $sheet->getStyle('A5:E5')->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF4']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);

                $highest = $sheet->getHighestRow();
                $sheet->getStyle("C6:E{$highest}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
                $sheet->getStyle("A6:E{$highest}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
                ]);

                // Highlight section header + subtotal + grand total
                foreach (range(6, $highest) as $r) {
                    $a = trim((string) $sheet->getCell('A'.$r)->getValue());
                    $b = trim((string) $sheet->getCell('B'.$r)->getValue());

                    if (in_array($a, ['AKTIVITAS OPERASI', 'AKTIVITAS INVESTASI', 'AKTIVITAS PENDANAAN'])) {
                        $bg = match ($a) {
                            'AKTIVITAS OPERASI' => 'DCEFFB',
                            'AKTIVITAS INVESTASI' => 'EBDEF0',
                            'AKTIVITAS PENDANAAN' => 'D4EFDF',
                        };
                        $sheet->mergeCells("A{$r}:E{$r}");
                        $sheet->getStyle("A{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                        ]);
                    }
                    if (str_starts_with($b, 'Net AKTIVITAS')) {
                        $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E8E8']],
                        ]);
                    }
                    if (in_array($b, ['KENAIKAN / (PENURUNAN) KAS BERSIH', 'KAS & BANK AKHIR PERIODE'])) {
                        $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D8FF']],
                        ]);
                    }
                }
            },
        ];
    }
}
