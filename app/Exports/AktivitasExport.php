<?php

namespace App\Exports;

use App\Support\BusinessActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;

/**
 * Aktivitas dari halaman Monitoring, persis sesuai saringan yang sedang dipakai
 * (kategori, event, pencarian, dan periode).
 */
class AktivitasExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    /**
     * @param  Builder<Activity>  $aktivitas
     * @param  array<string, string>  $labelKategori  log_name => label kategori
     */
    public function __construct(
        protected Builder $aktivitas,
        protected array $labelKategori = [],
    ) {}

    public function query(): Builder
    {
        return $this->aktivitas;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['Waktu', 'Oleh', 'Kategori', 'Aktivitas', 'Deskripsi', 'Sales', 'Nilai', 'Detail'];
    }

    /**
     * @param  Activity  $act
     * @return list<mixed>
     */
    public function map($act): array
    {
        $props = $act->properties ?? collect();
        $oleh = $act->event === 'konsumen.signed' && ! $act->causer
            ? 'Konsumen '.($props['customer'] ?? '')
            : ($act->causer?->name ?? $act->causer?->nama ?? 'Sistem');

        return [
            $act->created_at?->format('Y-m-d H:i:s'),
            trim($oleh),
            $this->labelKategori[$act->log_name] ?? (string) $act->log_name,
            BusinessActivityLogger::labelFor($act->event),
            BusinessActivityLogger::shortenDesc($act->description),
            $props['sales'] ?? '',
            isset($props['nilai']) && $props['nilai'] > 0 ? (float) $props['nilai'] : '',
            $props->isNotEmpty() ? json_encode($props, JSON_UNESCAPED_UNICODE) : '',
        ];
    }

    public function title(): string
    {
        return 'Aktivitas';
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '059669']]],
        ];
    }
}
