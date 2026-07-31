<?php

namespace App\Exports;

use App\Models\Master\Booking;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PimpinanBookingExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(protected array $bawahanIds, protected array $filters = []) {}

    public function query(): Builder
    {
        $q = Booking::query()
            ->whereIn('sales_id', $this->bawahanIds)
            ->with([
                'sales:id,kode,nama',
                'proyek:id,nama_proyek',
                'rumah:id,blok,nomor_unit,tipe_rumah_id',
                'rumah.tipeRumah:id,tipe,nama_tipe',
                'prospectCustomer:id,nama_lengkap,hp,nik',
            ]);

        $f = $this->filters;
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['sales_id'])) {
            $q->where('sales_id', $f['sales_id']);
        }

        return $q->orderByDesc('created_at');
    }

    public function headings(): array
    {
        return [
            'Kode Booking', 'Tanggal Booking', 'Tanggal Expired', 'Status',
            'Customer', 'HP', 'NIK',
            'Sales', 'Kode Sales',
            'Proyek', 'Blok-Unit', 'Tipe',
            'Keterangan Batal',
        ];
    }

    public function map($b): array
    {
        $tipeKode = trim((string) ($b->rumah?->tipeRumah?->tipe ?? ''));
        $tipeNama = trim((string) ($b->rumah?->tipeRumah?->nama_tipe ?? ''));
        $tipeLabel = $tipeNama !== '' && $tipeKode !== '' && ! str_contains(mb_strtolower($tipeKode), mb_strtolower($tipeNama))
            ? $tipeKode.' '.$tipeNama
            : ($tipeKode !== '' ? $tipeKode : $tipeNama);

        return [
            'BK-'.str_pad((string) $b->id, 5, '0', STR_PAD_LEFT),
            $b->tanggal_booking?->format('Y-m-d') ?? '—',
            $b->tanggal_expired?->format('Y-m-d') ?? '—',
            strtoupper($b->status),
            $b->prospectCustomer?->nama_lengkap ?? '—',
            $b->prospectCustomer?->hp ?? '—',
            $b->prospectCustomer?->nik ?? '—',
            $b->sales?->nama ?? '—',
            $b->sales?->kode ?? '—',
            $b->proyek?->nama_proyek ?? '—',
            $b->rumah ? $b->rumah->blok.'-'.$b->rumah->nomor_unit : '—',
            $tipeLabel ?: '—',
            $b->keterangan_batal ?? '',
        ];
    }

    public function title(): string
    {
        return 'Booking Grup';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F59E0B']]],
        ];
    }
}
