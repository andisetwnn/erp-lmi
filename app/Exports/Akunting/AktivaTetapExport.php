<?php

namespace App\Exports\Akunting;

use App\Models\Akunting\AktivaTetap;
use App\Models\Master\Perusahaan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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

class AktivaTetapExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    protected Perusahaan $perusahaan;

    protected Collection $rows;

    public function __construct(
        protected string $from,
        protected string $to,
        protected ?string $kategori = null,
        protected ?string $status = null,
    ) {
        $this->perusahaan = Perusahaan::first();

        $q = AktivaTetap::query()->where('perusahaan_id', $this->perusahaan->id);
        if ($kategori) {
            $q->where('kategori', $kategori);
        }
        if ($status) {
            $q->where('status', $status);
        }
        $this->rows = $q->orderBy('kategori')->orderBy('tgl_perolehan')->orderBy('id')->get();
    }

    public function title(): string
    {
        return 'Aktiva Tetap';
    }

    public function array(): array
    {
        $out = [];
        // 4 baris kop kosong
        for ($i = 0; $i < 4; $i++) {
            $out[] = array_fill(0, 13, '');
        }
        // Header row 5 (2 baris)
        $out[] = ['No', 'KODE', 'KETERANGAN', 'TGL BELI', 'MASA (Thn)', 'TARIF (%)', 'HRG BELI', 'SUSUT AWAL', 'BUKU AWAL', 'KURANG', 'Penyusutan Bulan Ini', 'AKUMULASI', 'BUKU AKHIR'];

        $grandHp = 0;
        $grandSusutAwal = 0;
        $grandBukuAwal = 0;
        $grandKurang = 0;
        $grandBulanIni = 0;
        $grandAkum = 0;
        $grandBukuAkhir = 0;

        foreach ($this->rows->groupBy(fn ($r) => $r->kategori ?: 'Lainnya') as $kategori => $items) {
            $kategoriUpper = strtoupper($kategori).' :';
            $out[] = [$kategoriUpper, '', '', '', '', '', '', '', '', '', '', '', ''];

            $subHp = 0;
            $subSusutAwal = 0;
            $subBukuAwal = 0;
            $subKurang = 0;
            $subBulanIni = 0;
            $subAkum = 0;
            $subBukuAkhir = 0;
            $no = 0;

            foreach ($items as $a) {
                $no++;
                $hp = (float) $a->harga_perolehan;
                $umurBulan = (int) $a->umur_ekonomis_bulan;
                $masaThn = $umurBulan > 0 ? $umurBulan / 12 : 0;
                $tarif = $masaThn > 0 ? (100 / $masaThn) : 0;
                $bulanIni = ($a->metode_penyusutan === 'tidak_disusutkan' || $umurBulan <= 0)
                    ? 0
                    : ($hp - (float) $a->nilai_residu) / $umurBulan;
                $akumTotal = (float) $a->akumulasi_penyusutan;
                $susutAwal = max(0, $akumTotal - $bulanIni);
                $bukuAwal = $hp - $susutAwal;
                $kurang = 0;
                $bukuAkhir = $hp - $susutAwal - $kurang - $bulanIni;

                $subHp += $hp;
                $subSusutAwal += $susutAwal;
                $subBukuAwal += $bukuAwal;
                $subKurang += $kurang;
                $subBulanIni += $bulanIni;
                $subAkum += $akumTotal;
                $subBukuAkhir += $bukuAkhir;

                $out[] = [
                    $no,
                    $a->kode ?: '',
                    $a->nama,
                    $a->tgl_perolehan?->format('d M Y') ?? '',
                    $masaThn,
                    round($tarif, 2),
                    $hp,
                    $susutAwal,
                    $bukuAwal,
                    $kurang,
                    $bulanIni,
                    $akumTotal,
                    $bukuAkhir,
                ];
            }

            // Subtotal
            $out[] = [
                '', '', 'SUB TOTAL '.strtoupper($kategori), '', '', '',
                $subHp, $subSusutAwal, $subBukuAwal, $subKurang, $subBulanIni, $subAkum, $subBukuAkhir,
            ];

            $grandHp += $subHp;
            $grandSusutAwal += $subSusutAwal;
            $grandBukuAwal += $subBukuAwal;
            $grandKurang += $subKurang;
            $grandBulanIni += $subBulanIni;
            $grandAkum += $subAkum;
            $grandBukuAkhir += $subBukuAkhir;
        }

        // Grand total
        $out[] = [
            '', '', 'TOTAL SELURUHNYA', '', '', '',
            $grandHp, $grandSusutAwal, $grandBukuAwal, $grandKurang, $grandBulanIni, $grandAkum, $grandBukuAkhir,
        ];

        return $out;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 4, 'B' => 10, 'C' => 40, 'D' => 12, 'E' => 10, 'F' => 10,
            'G' => 16, 'H' => 16, 'I' => 16, 'J' => 12, 'K' => 15, 'L' => 16, 'M' => 16,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

                // Kop
                $sheet->mergeCells('A1:M1');
                $sheet->setCellValue('A1', $this->perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 4],
                ]);
                $sheet->mergeCells('A2:M2');
                $sheet->setCellValue('A2', 'LAMPIRAN AKTIVA TETAP');
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 4],
                ]);
                $sheet->mergeCells('A3:M3');
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

                // Header row (5)
                $sheet->getStyle('A5:M5')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF4']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);
                $sheet->getRowDimension(5)->setRowHeight(30);

                $highest = $sheet->getHighestRow();

                // Format kolom uang
                $sheet->getStyle("G6:M{$highest}")->getNumberFormat()->setFormatCode('#,##0;-#,##0;"-"');
                $sheet->getStyle("E6:F{$highest}")->getNumberFormat()->setFormatCode('#,##0.0;-#,##0.0;"-"');

                // Border semua
                $sheet->getStyle("A5:M{$highest}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
                ]);

                // Highlight rows
                foreach (range(6, $highest) as $r) {
                    $a = trim((string) $sheet->getCell('A'.$r)->getValue());
                    $c = trim((string) $sheet->getCell('C'.$r)->getValue());

                    // Kategori header (kolom A upper case + " :")
                    if (str_ends_with($a, ' :') && trim($sheet->getCell('B'.$r)->getValue() ?: '') === '') {
                        $sheet->mergeCells("A{$r}:M{$r}");
                        $sheet->getStyle("A{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'italic' => true, 'size' => 10],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EDF7']],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
                        ]);
                    }
                    // Subtotal row
                    if (str_starts_with($c, 'SUB TOTAL')) {
                        $sheet->getStyle("A{$r}:M{$r}")->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E8E8']],
                        ]);
                    }
                    // Grand total row
                    if ($c === 'TOTAL SELURUHNYA') {
                        $sheet->getStyle("A{$r}:M{$r}")->applyFromArray([
                            'font' => ['bold' => true, 'size' => 11],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D8FF']],
                        ]);
                    }
                }

                $sheet->freezePane('A6');
            },
        ];
    }
}
