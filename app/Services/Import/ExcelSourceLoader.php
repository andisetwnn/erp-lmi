<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Helper load & iterate file Excel legacy.
 *
 * Design:
 * - Cache spreadsheet instance per file
 * - Provide iterator per sheet dgn header row awareness
 * - Row generator return array assoc ['NAMA' => ..., 'NIK' => ...] berdasarkan header
 */
class ExcelSourceLoader
{
    protected array $spreadsheets = [];

    /**
     * Bersihkan cache spreadsheet untuk free memory.
     * Panggil di antara fase kalau load banyak file besar.
     */
    public function flush(): void
    {
        foreach ($this->spreadsheets as $sp) {
            $sp->disconnectWorksheets();
        }
        $this->spreadsheets = [];
        gc_collect_cycles();
    }

    /** Load file Excel (cached). Path relatif ke storage/app/. */
    public function loadFile(string $filename): Spreadsheet
    {
        $path = storage_path('app/'.$filename);
        if (! isset($this->spreadsheets[$path])) {
            if (! file_exists($path)) {
                throw new \RuntimeException("File Excel tidak ditemukan: $path");
            }
            $this->spreadsheets[$path] = IOFactory::load($path);
        }

        return $this->spreadsheets[$path];
    }

    /** Ambil sheet by name. */
    public function sheet(string $filename, string $sheetName): Worksheet
    {
        $sp = $this->loadFile($filename);
        $sheet = $sp->getSheetByName($sheetName);
        if (! $sheet) {
            throw new \RuntimeException("Sheet '$sheetName' tidak ada di $filename");
        }

        return $sheet;
    }

    /**
     * Iterate baris data dgn header awareness.
     *
     * @param  int  $headerRow  Row nomor header (misal 3)
     * @param  array<string,string>  $columns  Mapping ['col_key' => 'A', 'nama' => 'C', ...]
     * @return \Generator<int, array<string,mixed>> Yield [rowNumber, assoc data]
     */
    public function rows(Worksheet $sheet, int $headerRow, array $columns, ?callable $filter = null): \Generator
    {
        $totalRows = $sheet->getHighestDataRow();
        for ($r = $headerRow + 1; $r <= $totalRows; $r++) {
            $data = [];
            foreach ($columns as $key => $col) {
                $cell = $sheet->getCell($col.$r);
                // Pakai getCalculatedValue() supaya formula (=K276+L276) di-evaluate ke numeric,
                // bukan return raw formula string yg bikin parseNumber salah.
                try {
                    $value = $cell->getCalculatedValue();
                } catch (\Throwable $e) {
                    $value = $cell->getValue();
                }
                $data[$key] = trim((string) ($value ?? ''));
                $data[$key.'_formatted'] = trim((string) ($cell->getFormattedValue() ?? ''));
            }

            if ($filter && ! $filter($data)) {
                continue;
            }

            yield $r => $data;
        }
    }

    /**
     * Parse nominal Rupiah dari cell Excel.
     * Handle format Indonesia (15.000.000), US (15,000,000), raw (15000000).
     * Rupiah selalu bulat → strip semua non-digit (aman).
     */
    public function parseNumber(string|int|float|null $value): float
    {
        if ($value === null || $value === '') {
            return 0;
        }
        // Kalau cell disimpan sebagai number di Excel, langsung float
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        // String dengan separator (thousand: . atau ,) — strip semua non-digit (Rupiah bulat)
        $clean = preg_replace('/[^0-9\-]/', '', (string) $value);

        return (float) ($clean ?: 0);
    }

    /** Parse tanggal dari cell Excel (handle format "18-Feb-26", "18/02/2026", serial number). */
    public function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel serial number (numeric)
        if (is_numeric($value)) {
            try {
                $dt = Date::excelToDateTimeObject((float) $value);

                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                // fallthrough
            }
        }

        // String — coba beberapa format umum
        $formats = ['d-M-y', 'd-M-Y', 'd-m-Y', 'd/m/Y', 'd/m/y', 'Y-m-d', 'd F Y'];
        foreach ($formats as $fmt) {
            try {
                $dt = \DateTime::createFromFormat($fmt, (string) $value);
                if ($dt) {
                    return $dt->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                // continue
            }
        }

        // strtotime fallback
        $ts = strtotime((string) $value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    }
}
