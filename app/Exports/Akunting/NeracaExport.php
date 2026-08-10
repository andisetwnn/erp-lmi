<?php

namespace App\Exports\Akunting;

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class NeracaExport implements FromArray, WithColumnWidths, WithEvents, WithStyles, WithTitle
{
    protected array $data;

    protected Perusahaan $perusahaan;

    public function __construct(
        protected string $tanggal,
        protected ?string $from = null,
    ) {
        $this->perusahaan = Perusahaan::first();
        $this->data = app(LaporanAkuntingService::class)->neraca($this->perusahaan->id, $tanggal, $from);
    }

    public function title(): string
    {
        return 'Neraca';
    }

    public function array(): array
    {
        $rows = [];
        // Baris 1-3: kosong (dipakai kop via events)
        $rows[] = ['', '', ''];
        $rows[] = ['', '', ''];
        $rows[] = ['', '', ''];
        $rows[] = ['', '', ''];
        // Baris 5: header table
        $rows[] = ['KODE — NAMA AKUN', 'Detail', 'Sub Total'];

        // ASET
        $rows[] = ['ASET', '', ''];
        foreach ($this->data['aset']['groups'] as $group) {
            $rows[] = [$group['header']->kode.' — '.$group['header']->nama, '', $group['total']];
            foreach ($group['items'] as $item) {
                $rows[] = ['   '.$item['coa']->kode.' — '.$item['coa']->nama, $item['saldo'], ''];
            }
        }
        $rows[] = ['TOTAL ASET', '', $this->data['aset']['total']];
        $rows[] = ['', '', ''];

        // KEWAJIBAN
        $rows[] = ['KEWAJIBAN', '', ''];
        foreach ($this->data['kewajiban']['groups'] as $group) {
            $rows[] = [$group['header']->kode.' — '.$group['header']->nama, '', $group['total']];
            foreach ($group['items'] as $item) {
                $rows[] = ['   '.$item['coa']->kode.' — '.$item['coa']->nama, $item['saldo'], ''];
            }
        }
        $rows[] = ['TOTAL KEWAJIBAN', '', $this->data['kewajiban']['total']];
        $rows[] = ['', '', ''];

        // MODAL & LABA
        $rows[] = ['MODAL & LABA', '', ''];
        foreach ($this->data['modal']['groups'] as $group) {
            $rows[] = [$group['header']->kode.' — '.$group['header']->nama, '', $group['total']];
            foreach ($group['items'] as $item) {
                $rows[] = ['   '.$item['coa']->kode.' — '.$item['coa']->nama, $item['saldo'], ''];
            }
        }
        $labaLabel = ($this->data['laba_periode'] >= 0 ? 'Laba' : 'Rugi').' Periode Berjalan';
        $rows[] = ['   '.$labaLabel, $this->data['laba_periode'], ''];
        $rows[] = ['TOTAL MODAL & LABA', '', $this->data['modal']['total'] + $this->data['laba_periode']];
        $rows[] = ['TOTAL PASIVA', '', $this->data['total_pasiva']];

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 55, 'B' => 22, 'C' => 22];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Header table row (5)
            5 => ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF4']]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Kop: logo + nama perusahaan + judul + periode
                $sheet->mergeCells('A1:C1');
                $sheet->setCellValue('A1', $this->perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);

                $sheet->mergeCells('A2:C2');
                $sheet->setCellValue('A2', 'NERACA');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);

                $sheet->mergeCells('A3:C3');
                $sheet->setCellValue('A3', 'Per: '.Carbon::parse($this->tanggal)->translatedFormat('d F Y'));
                $sheet->getStyle('A3')->applyFromArray([
                    'font' => ['italic' => true, 'size' => 10],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 8],
                ]);

                // Logo
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

                // Format kolom angka
                $highest = $sheet->getHighestRow();
                $sheet->getStyle("B6:C{$highest}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
                $sheet->getStyle("A6:C{$highest}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
                ]);

                // Highlight baris section header + total
                foreach (range(1, $highest) as $r) {
                    $v = trim((string) $sheet->getCell('A'.$r)->getValue());
                    if (in_array($v, ['ASET', 'KEWAJIBAN', 'MODAL & LABA'])) {
                        $bg = match ($v) {
                            'ASET' => 'DCEFFB',
                            'KEWAJIBAN' => 'FDEBD0',
                            'MODAL & LABA' => 'D4EFDF',
                        };
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                        ]);
                    }
                    if (in_array($v, ['TOTAL ASET', 'TOTAL PASIVA'])) {
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D8FF']],
                        ]);
                    }
                    if (in_array($v, ['TOTAL KEWAJIBAN', 'TOTAL MODAL & LABA'])) {
                        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E8E8']],
                        ]);
                    }
                    // Group header (baris dgn kolom C ada nilai tapi bukan section header)
                    $cVal = $sheet->getCell('C'.$r)->getValue();
                    if ($cVal && ! in_array($v, ['ASET', 'KEWAJIBAN', 'MODAL & LABA', 'TOTAL ASET', 'TOTAL KEWAJIBAN', 'TOTAL MODAL & LABA', 'TOTAL PASIVA'])) {
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
