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

    /**
     * Nomor baris yang perlu diwarnai, dikumpulkan sambil menyusun isinya.
     *
     * Dicatat lewat nomor baris, bukan dicocokkan ulang dari teks di kolom A
     * seperti sebelumnya: begitu ada kelompok akun yang kebetulan bernama sama
     * dengan salah satu judul seksi, pencocokan teks mewarnai baris yang keliru.
     *
     * @var array<string, list<int>>
     */
    protected array $baris = ['seksi_untung' => [], 'seksi_biaya' => [], 'subtotal' => [], 'garis' => [], 'grup' => []];

    public function array(): array
    {
        $u = $this->data['uraian'];
        $dasar = (float) $u['dasar_persen'];

        // Persen disimpan sebagai pecahan, bukan teks: kolomnya diformat persen
        // di Excel, jadi Accounting tetap bisa memakainya untuk hitungan sendiri.
        $rasio = fn (float $nilai) => $dasar == 0.0 ? '' : $nilai / $dasar;

        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['', '', '', ''];
        }
        $rows[] = ['KODE — NAMA AKUN', 'Detail', 'Sub Total', '% Penjualan'];

        $seksi = function (string $judul, array $bagian, int $tanda, string $nada) use (&$rows, $rasio) {
            if (! $bagian['groups']) {
                return;
            }

            $rows[] = [strtoupper($judul), '', '', ''];
            $this->baris[$nada][] = count($rows) + 1;   // +1 karena Excel mulai dari 1

            foreach ($bagian['groups'] as $group) {
                $rows[] = [
                    $group['header']->kode.' — '.$group['header']->nama, '',
                    $tanda * $group['total'], $rasio($tanda * $group['total']),
                ];
                $this->baris['grup'][] = count($rows) + 1;

                foreach ($group['items'] as $item) {
                    $rows[] = [
                        '   '.$item['coa']->kode.' — '.$item['coa']->nama,
                        $tanda * $item['saldo'], '', $rasio($tanda * $item['saldo']),
                    ];
                }
            }

            $rows[] = ['TOTAL '.strtoupper($judul), '', $tanda * $bagian['total'], $rasio($tanda * $bagian['total'])];
            $this->baris['subtotal'][] = count($rows) + 1;
            $rows[] = ['', '', '', ''];
        };

        $seksi('Penjualan', $u['penjualan'], 1, 'seksi_untung');
        $seksi('Harga Pokok Penjualan', $u['hpp'], -1, 'seksi_biaya');

        $rows[] = ['LABA KOTOR (GROSS PROFIT)', '', $u['gross_profit'], $rasio((float) $u['gross_profit'])];
        $this->baris['garis'][] = count($rows) + 1;
        $rows[] = ['', '', '', ''];

        $seksi('Biaya Usaha', $u['biaya'], -1, 'seksi_biaya');
        $seksi('Pendapatan Lain-lain', $u['pendapatan_lain'], 1, 'seksi_untung');
        $seksi('Pajak PPh Final', $u['pajak_final'], -1, 'seksi_biaya');

        $rows[] = [
            $u['net_profit'] >= 0 ? 'LABA BERSIH (NET PROFIT)' : 'RUGI BERSIH (NET PROFIT)',
            '', $u['net_profit'], $rasio((float) $u['net_profit']),
        ];
        $this->baris['garis'][] = count($rows) + 1;

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 55, 'B' => 22, 'C' => 22, 'D' => 14];
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

                $sheet->getStyle('A5:D5')->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF4']],
                ]);

                $highest = $sheet->getHighestRow();
                $sheet->getStyle("B6:C{$highest}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
                // Kolom persen diformat sebagai persen, bukan diisi teks berakhiran
                // "%": nilainya tetap angka, jadi Accounting bisa memakainya untuk
                // hitungan lanjutan di berkas yang sama.
                $sheet->getStyle("D6:D{$highest}")->getNumberFormat()->setFormatCode('0.0%;-0.0%;""');
                $sheet->getStyle("A6:D{$highest}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
                ]);

                $warnai = function (array $baris, array $gaya) use ($sheet) {
                    foreach ($baris as $r) {
                        $sheet->getStyle("A{$r}:D{$r}")->applyFromArray($gaya);
                    }
                };

                $warnai($this->baris['grup'], [
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF3F7']],
                ]);
                $warnai($this->baris['seksi_untung'], [
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '145A32']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D5F5E3']],
                ]);
                $warnai($this->baris['seksi_biaya'], [
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '7D1919']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FADBD8']],
                ]);
                $warnai($this->baris['subtotal'], [
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E8E8']],
                ]);
                $warnai($this->baris['garis'], [
                    'font' => ['bold' => true, 'size' => 12],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D8FF']],
                ]);

                $sheet->freezePane('A6');
            },
        ];
    }
}
