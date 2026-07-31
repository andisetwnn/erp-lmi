<?php

namespace App\Exports;

use App\Models\Master\ProspectCustomer;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProspectCustomerExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        $q = ProspectCustomer::query()
            ->with([
                'proyek:id,nama_proyek',
                'sales:id,kode,nama',
                'tempatKerja:id,nama',
                'bank:id,nama',
                'kontakDarurat:id,prospect_customer_id,nama,hubungan,nomor_telepon',
            ]);

        $f = $this->filters;

        if (! empty($f['sales_in']) && is_array($f['sales_in'])) {
            $q->whereIn('sales_id', $f['sales_in']);
        }
        if (! empty($f['filterSales'])) {
            $q->where('sales_id', $f['filterSales']);
        }
        if (! empty($f['filterProyek'])) {
            $q->where('proyek_id', $f['filterProyek']);
        }
        if (! empty($f['filterStatus'])) {
            $q->where('status', $f['filterStatus']);
        }
        if (! empty($f['filterSumber'])) {
            $q->where('sumber', $f['filterSumber']);
        }
        if (! empty($f['filterTanggalFrom'])) {
            $q->whereDate('created_at', '>=', $f['filterTanggalFrom']);
        }
        if (! empty($f['filterTanggalTo'])) {
            $q->whereDate('created_at', '<=', $f['filterTanggalTo']);
        }
        if (! empty($f['search'])) {
            $term = '%'.$f['search'].'%';
            $q->where(function ($qq) use ($term) {
                $qq->where('nama_lengkap', 'like', $term)
                    ->orWhere('hp', 'like', $term)
                    ->orWhere('hp_2', 'like', $term)
                    ->orWhere('nik', 'like', $term);
            });
        }

        [$sortCol, $sortDir] = match ($f['sort'] ?? '') {
            'terlama' => ['created_at', 'asc'],
            'nama_asc' => ['nama_lengkap', 'asc'],
            'nama_desc' => ['nama_lengkap', 'desc'],
            default => ['created_at', 'desc'],
        };

        return $q->orderBy($sortCol, $sortDir);
    }

    public function headings(): array
    {
        return [
            'No',
            'Nama Lengkap',
            'NIK',
            'NPWP',
            'HP Utama',
            'HP Cadangan',
            'Status',
            'Sumber Info',
            'Proyek',
            'Sales (Kode)',
            'Sales (Nama)',
            'Perusahaan',
            'Bank',
            'No. Rekening',
            'Atas Nama Rekening',
            'Foto KTP',
            'BI KOL',
            'BI DBR (%)',
            'BI Keterangan',
            'Alamat KTP',
            'Provinsi',
            'Kota / Kabupaten',
            'Kecamatan',
            'Kelurahan',
            'Kontak Darurat',
            'Catatan',
            'Tanggal Dibuat',
        ];
    }

    public function map($row): array
    {
        static $i = 0;
        $i++;

        $kolLabel = match ($row->bi_kol) {
            '1' => 'KOL 1 — Lancar',
            '2' => 'KOL 2 — DPK',
            '3' => 'KOL 3 — Kurang Lancar',
            '4' => 'KOL 4 — Diragukan',
            '5' => 'KOL 5 — Macet',
            default => null,
        };

        // Kontak darurat dirangkum jadi 1 cell: "Nama (Hubungan) +62xxx; Nama2 (Hubungan2) +62xxx"
        $kontakRingkas = $row->kontakDarurat->map(function ($k) {
            $hub = \App\Models\Master\ProspectCustomerKontakDarurat::HUBUNGAN_OPTIONS[$k->hubungan] ?? $k->hubungan;
            return $k->nama.' ('.$hub.') '.$k->nomor_telepon;
        })->implode(' ; ');

        return [
            $i,
            $row->nama_lengkap,
            $row->nik,
            $row->npwp,
            $row->hp,
            $row->hp_2,
            strtoupper($row->status),
            $row->sumber,
            $row->proyek?->nama_proyek,
            $row->sales?->kode,
            $row->sales?->nama,
            $row->tempatKerja?->nama,
            $row->bank?->nama,
            $row->nomor_rekening,
            $row->rekening_atas_nama,
            $row->foto_ktp ? 'Ada' : '-',
            $kolLabel,
            $row->bi_dbr !== null ? (float) $row->bi_dbr : null,
            $row->bi_keterangan,
            $row->alamat,
            $row->provinsi_nama,
            $row->kota_nama,
            $row->kecamatan_nama,
            $row->kelurahan_nama,
            $kontakRingkas ?: null,
            $row->catatan,
            $row->created_at?->translatedFormat('d M Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EA580C']],
            ],
        ];
    }

    public function title(): string
    {
        return 'Prospect Customer';
    }
}
