<?php

namespace App\Exports;

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Sales;
use App\Models\Master\SalesTarget;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PimpinanAnggotaExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(protected int $grupId, protected int $pimpinanId) {}

    public function collection()
    {
        $anggota = Sales::with('jenisSales')
            ->where('sales_grup_id', $this->grupId)
            ->where('id', '!=', $this->pimpinanId)
            ->orderBy('nama')
            ->get();

        $ids = $anggota->pluck('id');
        $monthStart = now()->startOfMonth();
        $periode = SalesTarget::currentPeriode();

        $statsP = ProspectCustomer::whereIn('sales_id', $ids)
            ->where('status', '!=', 'archive')
            ->selectRaw('sales_id, COUNT(*) as cnt')->groupBy('sales_id')->pluck('cnt', 'sales_id')->toArray();
        $statsB = Booking::whereIn('sales_id', $ids)
            ->selectRaw('sales_id, status, COUNT(*) as cnt')->groupBy('sales_id', 'status')->get()
            ->groupBy('sales_id')->map(fn ($r) => $r->pluck('cnt', 'status')->toArray());
        $bulanP = ProspectCustomer::whereIn('sales_id', $ids)->where('created_at', '>=', $monthStart)
            ->selectRaw('sales_id, COUNT(*) as cnt')->groupBy('sales_id')->pluck('cnt', 'sales_id')->toArray();
        $bulanB = Booking::whereIn('sales_id', $ids)->where('created_at', '>=', $monthStart)
            ->selectRaw('sales_id, COUNT(*) as cnt')->groupBy('sales_id')->pluck('cnt', 'sales_id')->toArray();
        $lastAct = ProspectCustomerStatusLog::whereIn('changed_by_sales_id', $ids)
            ->selectRaw('changed_by_sales_id, MAX(created_at) as last_at')->groupBy('changed_by_sales_id')
            ->pluck('last_at', 'changed_by_sales_id')->toArray();
        $targets = SalesTarget::whereIn('sales_id', $ids)->where('periode', $periode)->get()->keyBy('sales_id');

        return $anggota->map(function ($a) use ($statsP, $statsB, $bulanP, $bulanB, $lastAct, $targets) {
            $b = $statsB->get($a->id, []);
            $t = $targets->get($a->id);
            $a->_p_aktif = $statsP[$a->id] ?? 0;
            $a->_b_aktif = $b['aktif'] ?? 0;
            $a->_b_sukses = $b['sukses'] ?? 0;
            $a->_b_akad = $b['akad'] ?? 0;
            $a->_p_bulan = $bulanP[$a->id] ?? 0;
            $a->_b_bulan = $bulanB[$a->id] ?? 0;
            $a->_target_p = $t?->target_prospect ?? 0;
            $a->_target_b = $t?->target_booking ?? 0;
            $a->_last_at = $lastAct[$a->id] ?? null;
            return $a;
        });
    }

    public function headings(): array
    {
        return [
            'Kode', 'Nama', 'Jenis', 'Telepon', 'Status',
            'Prospect Aktif', 'Booking Aktif', 'SPR', 'Akad',
            'Prospect Bulan Ini', 'Booking Bulan Ini',
            'Target Prospect', 'Target Booking',
            'Aktivitas Terakhir',
        ];
    }

    public function map($a): array
    {
        return [
            $a->kode,
            $a->nama,
            $a->jenisSales?->nama ?? '—',
            $a->telepon ?? '—',
            $a->is_aktif ? 'Aktif' : 'Nonaktif',
            $a->_p_aktif,
            $a->_b_aktif,
            $a->_b_sukses,
            $a->_b_akad,
            $a->_p_bulan,
            $a->_b_bulan,
            $a->_target_p,
            $a->_target_b,
            $a->_last_at ? \Illuminate\Support\Carbon::parse($a->_last_at)->translatedFormat('d M Y H:i') : 'Belum ada',
        ];
    }

    public function title(): string
    {
        return 'Anggota Grup';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F59E0B']]],
        ];
    }
}
