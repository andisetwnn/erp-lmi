<?php

namespace App\Exports;

use App\Models\Master\Sales;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

class LogUserExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        $q = Activity::query()->with(['causer', 'subject']);

        $f = $this->filters;

        if (! empty($f['log_name'])) {
            $q->where('log_name', $f['log_name']);
        }
        if (! empty($f['event'])) {
            $q->where('event', $f['event']);
        }
        if (! empty($f['causer'])) {
            [$type, $id] = explode('|', $f['causer']) + [null, null];
            if ($type === 'user') {
                $q->where('causer_type', User::class)->where('causer_id', $id);
            } elseif ($type === 'sales') {
                $q->where('causer_type', Sales::class)->where('causer_id', $id);
            }
        }
        if (! empty($f['date_from'])) {
            $q->where('created_at', '>=', Carbon::parse($f['date_from'])->startOfDay());
        }
        if (! empty($f['date_to'])) {
            $q->where('created_at', '<=', Carbon::parse($f['date_to'])->endOfDay());
        }
        if (! empty($f['search'])) {
            $q->where('description', 'like', '%'.$f['search'].'%');
        }

        return $q->orderByDesc('created_at');
    }

    public function headings(): array
    {
        return [
            'Waktu',
            'User',
            'Tipe User',
            'Kategori',
            'Event',
            'Kode Event',
            'Deskripsi',
            'Subject',
            'Subject ID',
            'Properties',
        ];
    }

    public function map($act): array
    {
        $kategoriLabel = match ($act->log_name) {
            'penjualan' => 'Penjualan',
            'keuangan'  => 'Keuangan',
            'unit'      => 'Unit',
            default     => (string) ($act->log_name ?? '—'),
        };

        if ($act->event === 'konsumen.signed' && ! $act->causer) {
            $causerLabel = $act->properties['customer'] ?? 'Konsumen';
            $causerType = 'Konsumen';
        } else {
            $causerLabel = $act->causer?->name ?? $act->causer?->nama ?? '—';
            $causerType = $act->causer_type ? class_basename($act->causer_type) : 'Sistem';
        }
        $subjectType = $act->subject_type ? class_basename($act->subject_type) : '—';

        return [
            $act->created_at?->format('Y-m-d H:i:s'),
            $causerLabel,
            $causerType,
            $kategoriLabel,
            \App\Support\BusinessActivityLogger::labelFor($act->event),
            $act->event ?? '—',
            $act->description,
            $subjectType,
            $act->subject_id ? '#'.$act->subject_id : '',
            $act->properties ? json_encode($act->properties, JSON_UNESCAPED_UNICODE) : '',
        ];
    }

    public function title(): string
    {
        return 'Log User';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '059669']]],
        ];
    }
}
