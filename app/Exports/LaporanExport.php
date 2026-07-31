<?php

namespace App\Exports;

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Universal export untuk laporan.
 * Terima tab + filter, output array of rows lengkap.
 */
class LaporanExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private string $tab,
        private ?int $proyekId = null,
        private ?int $salesId = null,
        private ?int $tipeId = null,
        private ?string $search = null,
        private ?\DateTimeInterface $from = null,
        private ?\DateTimeInterface $to = null,
    ) {}

    public function title(): string
    {
        return match ($this->tab) {
            'penjualan' => 'Laporan Penjualan',
            'stock' => 'Stock Unit',
            'realisasi' => 'Realisasi Pembayaran',
            'outstanding' => 'Outstanding UM',
            'pembatalan' => 'Pembatalan SPR',
            'performance' => 'Sales Performance',
            default => 'Laporan',
        };
    }

    public function headings(): array
    {
        return match ($this->tab) {
            'penjualan' => ['No SPR', 'Tanggal SPR', 'Customer', 'NIK', 'HP', 'Alamat', 'Blok-Unit', 'Tipe', 'Proyek', 'Sales', 'Harga Jual', 'Biaya Tambahan', 'Diskon', 'PPN', 'Total Harga', 'Jenis Bayar', 'Bank KPR', 'DP %', 'DP Nominal', 'SBUM', 'UM Net', 'Nilai KPR', 'UTJ Nominal', 'Status', 'Approved At', 'PM Approved At'],
            'stock' => ['Blok-Unit', 'Blok', 'No Unit', 'Tipe', 'Nama Tipe', 'Kategori', 'Proyek', 'Luas Bangunan', 'Luas Tanah', 'Harga Standard', 'Harga Jual', 'Biaya Tambahan', 'Discount', 'Status', 'Tanggal Launching'],
            'realisasi' => ['No Kwitansi', 'Tanggal Bayar', 'No SPR', 'Customer', 'NIK', 'Blok-Unit', 'Sales', 'Jenis', 'Metode', 'Jumlah', 'Keterangan', 'Input By'],
            'outstanding' => ['No SPR', 'Tanggal SPR', 'Customer', 'NIK', 'HP', 'Blok-Unit', 'Tipe', 'Sales', 'UM Net', 'UTJ + UM Cair', 'Sisa Kurang', 'Progress %', 'Umur (hari)', 'Age Bucket'],
            'pembatalan' => ['No SPR', 'Tanggal SPR', 'Customer', 'NIK', 'Blok-Unit', 'Tipe', 'Sales', 'Alasan Pembatalan', 'Cancel Keterangan', 'Cancelled At', 'Refund Amount', 'Refund Status', 'Refund At', 'Total Harga'],
            'performance' => ['Rank', 'Kode Sales', 'Nama Sales', 'DBOS Username', 'Jumlah SPR', 'Total Nilai', 'Total KPR', 'Cash In', 'Grup'],
            default => [],
        };
    }

    public function array(): array
    {
        return match ($this->tab) {
            'penjualan' => $this->rowsPenjualan(),
            'stock' => $this->rowsStock(),
            'realisasi' => $this->rowsRealisasi(),
            'outstanding' => $this->rowsOutstanding(),
            'pembatalan' => $this->rowsPembatalan(),
            'performance' => $this->rowsPerformance(),
            default => [],
        };
    }

    private function baseSprQuery(?array $statuses = null)
    {
        $q = Spr::query()->with(['prospectCustomer', 'rumah.tipeRumah', 'rumah.proyek', 'sales', 'bankKpr']);
        if ($statuses) $q->whereIn('spr.status', $statuses);
        if ($this->proyekId) $q->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->proyekId));
        if ($this->salesId) $q->where('spr.sales_id', $this->salesId);
        if ($this->tipeId) $q->whereHas('rumah', fn ($r) => $r->where('tipe_rumah_id', $this->tipeId));
        if ($this->search) {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('spr.nomor_spr', 'like', "%{$s}%")
                    ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%"))
                    ->orWhereHas('rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", ["%{$s}%"]));
            });
        }
        return $q;
    }

    private function rowsPenjualan(): array
    {
        $q = $this->baseSprQuery(['approved', 'akad']);
        if ($this->from && $this->to) $q->whereBetween('spr.tanggal_spr', [$this->from, $this->to]);

        return $q->orderByDesc('spr.tanggal_spr')->get()->map(fn ($s) => [
            $s->nomor_spr,
            $s->tanggal_spr?->format('d/m/Y'),
            $s->prospectCustomer?->nama_lengkap,
            $s->prospectCustomer?->nik,
            $s->prospectCustomer?->hp,
            $s->prospectCustomer?->alamat,
            ($s->rumah?->blok ?? '').'-'.($s->rumah?->nomor_unit ?? ''),
            $s->rumah?->tipeRumah?->tipe,
            $s->rumah?->proyek?->nama_proyek,
            $s->sales?->nama,
            (float) $s->harga_jual,
            (float) $s->biaya_tambahan,
            (float) $s->diskon,
            (float) ($s->ppn ?? 0),
            (float) $s->total_harga,
            $s->jenis_pembayaran,
            $s->bankKpr?->nama,
            (float) $s->dp_persen,
            (float) $s->dp_nominal,
            (float) $s->sbum,
            (float) $s->um_net,
            (float) $s->nilai_kpr,
            (float) $s->utj_nominal,
            $s->status,
            $s->approved_at?->format('d/m/Y H:i'),
            $s->pm_approved_at?->format('d/m/Y H:i'),
        ])->toArray();
    }

    private function rowsStock(): array
    {
        $q = Rumah::query()->with(['tipeRumah', 'proyek']);
        if ($this->proyekId) $q->where('proyek_id', $this->proyekId);
        if ($this->tipeId) $q->where('tipe_rumah_id', $this->tipeId);
        if ($this->search) {
            $s = $this->search;
            $q->where(fn ($qq) => $qq->where('blok', 'like', "%{$s}%")->orWhere('nomor_unit', 'like', "%{$s}%")->orWhereRaw("CONCAT(blok,'-',nomor_unit) like ?", ["%{$s}%"]));
        }

        return $q->orderBy('blok')->orderBy('nomor_unit')->get()->map(fn ($r) => [
            $r->blok.'-'.$r->nomor_unit,
            $r->blok,
            $r->nomor_unit,
            $r->tipeRumah?->tipe,
            $r->tipeRumah?->nama_tipe,
            $r->tipeRumah?->kategori,
            $r->proyek?->nama_proyek,
            $r->tipeRumah?->luas_bangunan,
            $r->tipeRumah?->luas_tanah,
            (float) ($r->tipeRumah?->harga_jual ?? 0),
            (float) ($r->harga_jual ?? 0),
            (float) ($r->biaya_tambahan ?? 0),
            (float) ($r->discount ?? 0),
            $r->status,
            $r->tanggal_launching?->format('d/m/Y'),
        ])->toArray();
    }

    private function rowsRealisasi(): array
    {
        $q = SprRealisasiPembayaran::query()->with(['spr.prospectCustomer', 'spr.sales', 'spr.rumah', 'inputBy']);
        if ($this->from && $this->to) $q->whereBetween('tanggal_bayar', [$this->from, $this->to]);
        if ($this->proyekId) $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->proyekId));
        if ($this->salesId) $q->whereHas('spr', fn ($s) => $s->where('sales_id', $this->salesId));
        if ($this->tipeId) $q->whereHas('spr.rumah', fn ($r) => $r->where('tipe_rumah_id', $this->tipeId));
        if ($this->search) {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('nomor_kwitansi', 'like', "%{$s}%")
                    ->orWhereHas('spr', fn ($sp) => $sp->where('nomor_spr', 'like', "%{$s}%"))
                    ->orWhereHas('spr.prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%"));
            });
        }

        return $q->orderByDesc('tanggal_bayar')->get()->map(fn ($r) => [
            $r->nomor_kwitansi,
            $r->tanggal_bayar?->format('d/m/Y'),
            $r->spr?->nomor_spr,
            $r->spr?->prospectCustomer?->nama_lengkap,
            $r->spr?->prospectCustomer?->nik,
            ($r->spr?->rumah?->blok ?? '').'-'.($r->spr?->rumah?->nomor_unit ?? ''),
            $r->spr?->sales?->nama,
            strtoupper($r->jenis),
            ucfirst($r->metode),
            (float) $r->jumlah,
            $r->keterangan,
            $r->inputBy?->name,
        ])->toArray();
    }

    private function rowsOutstanding(): array
    {
        $sprs = $this->baseSprQuery(['approved'])->get();
        $rows = [];
        foreach ($sprs as $spr) {
            $umNet = (float) $spr->um_net;
            if ($umNet <= 0) continue;
            $dibayar = (float) SprRealisasiPembayaran::where('spr_id', $spr->id)->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
            $sisa = max(0, $umNet - $dibayar);
            if ($sisa <= 0) continue;
            $tglAwal = $spr->utj_tanggal_transaksi ?: $spr->tanggal_spr;
            $ageDays = $tglAwal ? $tglAwal->diffInDays(now()) : 0;
            $ageBucket = match (true) {
                $ageDays <= 30 => '0-30',
                $ageDays <= 60 => '31-60',
                $ageDays <= 90 => '61-90',
                default => '>90',
            };
            $rows[] = [
                $spr->nomor_spr,
                $spr->tanggal_spr?->format('d/m/Y'),
                $spr->prospectCustomer?->nama_lengkap,
                $spr->prospectCustomer?->nik,
                $spr->prospectCustomer?->hp,
                ($spr->rumah?->blok ?? '').'-'.($spr->rumah?->nomor_unit ?? ''),
                $spr->rumah?->tipeRumah?->tipe,
                $spr->sales?->nama,
                $umNet,
                $dibayar,
                $sisa,
                $umNet > 0 ? (int) round(($dibayar / $umNet) * 100) : 0,
                $ageDays,
                $ageBucket,
            ];
        }
        usort($rows, fn ($a, $b) => $b[10] <=> $a[10]);
        return $rows;
    }

    private function rowsPembatalan(): array
    {
        $q = $this->baseSprQuery(['cancelled'])->with('alasanPembatalan');
        if ($this->from && $this->to) $q->whereBetween('spr.tanggal_spr', [$this->from, $this->to]);

        return $q->orderByDesc('spr.cancelled_at')->get()->map(fn ($s) => [
            $s->nomor_spr,
            $s->tanggal_spr?->format('d/m/Y'),
            $s->prospectCustomer?->nama_lengkap,
            $s->prospectCustomer?->nik,
            ($s->rumah?->blok ?? '').'-'.($s->rumah?->nomor_unit ?? ''),
            $s->rumah?->tipeRumah?->tipe,
            $s->sales?->nama,
            $s->alasanPembatalan?->nama,
            $s->cancel_keterangan,
            $s->cancelled_at?->format('d/m/Y H:i'),
            (float) ($s->refund_amount ?? 0),
            $s->refund_status,
            $s->refund_at?->format('d/m/Y'),
            (float) $s->total_harga,
        ])->toArray();
    }

    private function rowsPerformance(): array
    {
        $sprQ = Spr::query()->whereIn('status', ['approved', 'akad']);
        if ($this->from && $this->to) $sprQ->whereBetween('tanggal_spr', [$this->from, $this->to]);
        if ($this->proyekId) $sprQ->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->proyekId));
        if ($this->tipeId) $sprQ->whereHas('rumah', fn ($r) => $r->where('tipe_rumah_id', $this->tipeId));
        $sprIds = $sprQ->pluck('id');

        $ranking = Sales::query()->with('grup')
            ->leftJoin('spr', function ($j) use ($sprIds) {
                $j->on('spr.sales_id', '=', 'sales.id')->whereIn('spr.id', $sprIds);
            })
            ->selectRaw('sales.id, sales.kode, sales.nama, sales.dbos_username, sales.sales_grup_id')
            ->selectRaw('COUNT(spr.id) as spr_count')
            ->selectRaw('COALESCE(SUM(spr.total_harga), 0) as total_nilai')
            ->selectRaw('COALESCE(SUM(spr.nilai_kpr), 0) as total_kpr')
            ->groupBy('sales.id', 'sales.kode', 'sales.nama', 'sales.dbos_username', 'sales.sales_grup_id')
            ->orderByDesc('total_nilai')
            ->get();

        $cashInPerSales = SprRealisasiPembayaran::query()
            ->join('spr', 'spr.id', '=', 'spr_realisasi_pembayaran.spr_id')
            ->when($this->from && $this->to, fn ($q) => $q->whereBetween('spr_realisasi_pembayaran.tanggal_bayar', [$this->from, $this->to]))
            ->when($this->proyekId, fn ($q) => $q->join('rumah', 'rumah.id', '=', 'spr.rumah_id')->where('rumah.proyek_id', $this->proyekId))
            ->selectRaw('spr.sales_id, SUM(spr_realisasi_pembayaran.jumlah) as total_masuk')
            ->groupBy('spr.sales_id')
            ->pluck('total_masuk', 'sales_id');

        return $ranking->map(fn ($s, $idx) => [
            $idx + 1,
            $s->kode,
            $s->nama,
            $s->dbos_username,
            (int) $s->spr_count,
            (float) $s->total_nilai,
            (float) $s->total_kpr,
            (float) ($cashInPerSales[$s->id] ?? 0),
            \App\Models\Master\SalesGrup::find($s->sales_grup_id)?->nama,
        ])->values()->toArray();
    }

    public function styles(Worksheet $sheet): array
    {
        // Bold header row
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->getStyle('1:1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

        // Auto-size columns
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return [];
    }
}
