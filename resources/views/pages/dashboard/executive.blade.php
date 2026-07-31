<?php

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\SprTerminPembayaran;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    #[Url(as: 'p')]
    public string $period = 'ytd';

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    /** Sync dari sidebar picker. Null = agregat semua proyek. */
    public ?int $filterProyek = null;

    public const PERIOD_OPTIONS = [
        'mtd' => 'Bulan Ini',
        'qtd' => '3 Bulan',
        'ytd' => 'YTD',
        'all' => 'Semua',
        'custom' => 'Kustom',
    ];

    public function mount(): void
    {
        $this->filterProyek = session('active_proyek_id');
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->filterProyek = $proyekId;
    }

    public function setPeriod(string $p): void
    {
        if (! array_key_exists($p, self::PERIOD_OPTIONS)) return;
        $this->period = $p;
        // Reset custom dates kalau pindah ke preset
        if ($p !== 'custom') {
            $this->dateFrom = null;
            $this->dateTo = null;
        } elseif (! $this->dateFrom || ! $this->dateTo) {
            // Default kustom = 30 hari terakhir
            $this->dateFrom = now()->subDays(29)->format('Y-m-d');
            $this->dateTo = now()->format('Y-m-d');
        }
    }

    public function updatedDateFrom(): void { $this->period = 'custom'; }
    public function updatedDateTo(): void { $this->period = 'custom'; }

    public function periodLabel(): string
    {
        if ($this->period === 'custom' && $this->dateFrom && $this->dateTo) {
            return Carbon::parse($this->dateFrom)->translatedFormat('d M Y').' — '.Carbon::parse($this->dateTo)->translatedFormat('d M Y');
        }
        return self::PERIOD_OPTIONS[$this->period] ?? 'YTD';
    }

    private function periodRange(): array
    {
        if ($this->period === 'custom' && $this->dateFrom && $this->dateTo) {
            return [Carbon::parse($this->dateFrom)->startOfDay(), Carbon::parse($this->dateTo)->endOfDay()];
        }
        return match ($this->period) {
            'mtd' => [now()->startOfMonth(), now()->endOfMonth()],
            'qtd' => [now()->subMonths(3)->startOfDay(), now()->endOfDay()],
            'ytd' => [now()->startOfYear(), now()->endOfYear()],
            default => [Carbon::create(2000, 1, 1), now()->endOfDay()],
        };
    }

    private function previousPeriodRange(): array
    {
        if ($this->period === 'custom' && $this->dateFrom && $this->dateTo) {
            // Ambil rentang yang sama panjang, mundur
            $from = Carbon::parse($this->dateFrom);
            $to = Carbon::parse($this->dateTo);
            $days = max(1, $from->diffInDays($to) + 1);
            return [$from->copy()->subDays($days)->startOfDay(), $from->copy()->subDay()->endOfDay()];
        }
        return match ($this->period) {
            'mtd' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'qtd' => [now()->subMonths(6)->startOfDay(), now()->subMonths(3)->endOfDay()],
            'ytd' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            default => [Carbon::create(1990, 1, 1), Carbon::create(2000, 1, 1)],
        };
    }

    /** Apply proyek filter jika ada. */
    private function scoped($query, string $prefix = ''): mixed
    {
        if ($this->filterProyek) {
            $col = $prefix ? $prefix.'.proyek_id' : 'proyek_id';
            // Kalau punya rumah relation, filter via relation
            if ($query->getModel() instanceof Spr) {
                return $query->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));
            }
            return $query->where($col, $this->filterProyek);
        }
        return $query;
    }

    public function with(): array
    {
        [$from, $to] = $this->periodRange();
        [$prevFrom, $prevTo] = $this->previousPeriodRange();

        $proyekAktif = $this->filterProyek ? Proyek::find($this->filterProyek) : null;

        // ============ EXECUTIVE KPIs (dengan growth Periode lalu:) ============
        $sprCur = $this->scoped(Spr::query())->whereIn('spr.status', ['approved', 'akad'])->whereBetween('spr.tanggal_spr', [$from, $to]);
        $sprPrev = $this->scoped(Spr::query())->whereIn('spr.status', ['approved', 'akad'])->whereBetween('spr.tanggal_spr', [$prevFrom, $prevTo]);

        $unitCur = (clone $sprCur)->count();
        $unitPrev = (clone $sprPrev)->count();
        $unitGrowth = $unitPrev > 0 ? round((($unitCur - $unitPrev) / $unitPrev) * 100, 1) : ($unitCur > 0 ? 100 : 0);

        $nilaiCur = (float) (clone $sprCur)->sum('spr.total_harga');
        $nilaiPrev = (float) (clone $sprPrev)->sum('spr.total_harga');
        $nilaiGrowth = $nilaiPrev > 0 ? round((($nilaiCur - $nilaiPrev) / $nilaiPrev) * 100, 1) : ($nilaiCur > 0 ? 100 : 0);

        // Cash in current vs previous
        $realisasiCur = SprRealisasiPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereBetween('tanggal_bayar', [$from, $to])->sum('jumlah');
        $realisasiPrev = SprRealisasiPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereBetween('tanggal_bayar', [$prevFrom, $prevTo])->sum('jumlah');
        $cashGrowth = $realisasiPrev > 0 ? round((($realisasiCur - $realisasiPrev) / $realisasiPrev) * 100, 1) : ($realisasiCur > 0 ? 100 : 0);

        // Stock (all-time, tidak per periode)
        $rumahQ = Rumah::query();
        if ($this->filterProyek) $rumahQ->where('proyek_id', $this->filterProyek);
        $totalUnit = (clone $rumahQ)->count();
        $unitTerjual = (clone $rumahQ)->where('status', 'terjual')->count();
        $unitAvailable = (clone $rumahQ)->where('status', 'available')->count();
        $unitBooking = (clone $rumahQ)->where('status', 'booking')->count();
        $sellThrough = $totalUnit > 0 ? round(($unitTerjual / $totalUnit) * 100, 1) : 0;

        // ============ SALES FUNNEL ============
        $prospectCount = ProspectCustomer::query()
            ->when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek))
            ->count();
        $sprAktifCount = $this->scoped(Spr::query())->whereIn('spr.status', ['approved', 'akad'])->count();
        $sprAkadCount = $this->scoped(Spr::query())->where('spr.status', 'akad')->count();

        $funnel = [
            ['label' => 'Prospect', 'count' => $prospectCount, 'color' => 'blue'],
            ['label' => 'SPR Aktif', 'count' => $sprAktifCount, 'color' => 'emerald'],
            ['label' => 'Akad', 'count' => $sprAkadCount, 'color' => 'violet'],
        ];
        $funnelMax = max(1, ...array_column($funnel, 'count'));

        // ============ RISK INDICATORS ============
        $totalSprAll = $this->scoped(Spr::query())->count();
        $sprBatal = $this->scoped(Spr::query())->where('spr.status', 'cancelled')->count();
        $cancellationRate = $totalSprAll > 0 ? round(($sprBatal / $totalSprAll) * 100, 1) : 0;

        // Overdue UM: SPR approved dgn realisasi UM < 50% dan umur > 60 hari
        $overdueUm = 0;
        $sprApprovedList = $this->scoped(Spr::query())->where('spr.status', 'approved')->get(['id', 'um_net', 'utj_tanggal_transaksi', 'tanggal_spr']);
        foreach ($sprApprovedList as $spr) {
            $umNet = (float) $spr->um_net;
            if ($umNet <= 0) continue;
            $paid = (float) SprRealisasiPembayaran::where('spr_id', $spr->id)->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
            $ratio = $paid / $umNet;
            $tglMulai = $spr->utj_tanggal_transaksi ?: $spr->tanggal_spr;
            if ($tglMulai && $ratio < 0.5 && $tglMulai->diffInDays(now()) > 60) {
                $overdueUm++;
            }
        }
        $overdueRate = $sprApprovedList->count() > 0 ? round(($overdueUm / $sprApprovedList->count()) * 100, 1) : 0;

        // Avg days to akad (dari tanggal_spr ke pm_approved_at atau approved_at untuk akad SPR)
        $akadSprs = $this->scoped(Spr::query())->where('spr.status', 'akad')->whereNotNull('spr.tanggal_spr')->get(['tanggal_spr', 'pm_approved_at', 'approved_at']);
        $totalDays = 0; $countAkad = 0;
        foreach ($akadSprs as $s) {
            $tglAkad = $s->pm_approved_at ?: $s->approved_at;
            if ($tglAkad) {
                $totalDays += $s->tanggal_spr->diffInDays($tglAkad);
                $countAkad++;
            }
        }
        $avgDaysToAkad = $countAkad > 0 ? round($totalDays / $countAkad) : 0;

        // ============ OUTSTANDING UM (total sisa) ============
        $totalUmNet = (float) $this->scoped(Spr::query())->where('spr.status', 'approved')->sum('spr.um_net');
        $totalUmPaid = (float) SprRealisasiPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereHas('spr', fn ($s) => $s->where('spr.status', 'approved'))
            ->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
        $outstandingUm = max(0, $totalUmNet - $totalUmPaid);

        // ============ FORECAST CASH IN 30 HARI ============
        $forecastFrom = now()->startOfDay();
        $forecastTo = now()->addDays(30)->endOfDay();
        $forecast30d = (float) SprTerminPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereHas('spr', fn ($s) => $s->where('spr.status', 'approved'))
            ->whereNull('tanggal_realisasi')
            ->whereBetween('tanggal_jadwal', [$forecastFrom, $forecastTo])
            ->sum('jumlah_jadwal');

        // ============ TREND CASH IN 30 HARI HARIAN ============
        $trendCash = [];
        $trendCashQ = SprRealisasiPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->where('tanggal_bayar', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(tanggal_bayar) as tgl, SUM(jumlah) as total')
            ->groupBy('tgl')
            ->pluck('total', 'tgl');

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->format('Y-m-d');
            $trendCash[] = [
                'date' => $date,
                'total' => (float) ($trendCashQ[$key] ?? 0),
            ];
        }
        $trendCashMax = max(1, ...array_column($trendCash, 'total'));
        $trendCashTotal = array_sum(array_column($trendCash, 'total'));

        // ============ PIPELINE SPR PER STAGE ============
        $pipelineStages = [
            'utj_verify' => $this->scoped(Spr::query())->where('status', 'submitted')->count(),
            'pm_approve' => $this->scoped(Spr::query())->where('status', 'approved')->whereNull('pm_approved_at')->count(),
            'konsumen_ttd' => $this->scoped(Spr::query())->where('status', 'approved')->whereNotNull('pm_approved_at')->whereNull('konsumen_signed_at')->count(),
            'materai' => $this->scoped(Spr::query())->where('status', 'approved')->whereNotNull('konsumen_signed_at')->whereNull('materai_stamped_at')->count(),
            'final' => $this->scoped(Spr::query())->where('status', 'approved')->whereNotNull('materai_stamped_at')->count(),
        ];
        $pipelineTotal = array_sum($pipelineStages);

        // ============ TREND 12 BULAN ============
        $trendQ = Spr::query()
            ->whereIn('status', ['approved', 'akad'])
            ->whereNotNull('tanggal_spr')
            ->where('tanggal_spr', '>=', now()->subMonths(11)->startOfMonth());
        if ($this->filterProyek) $trendQ->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));

        $trendData = $trendQ
            ->selectRaw('DATE_FORMAT(tanggal_spr, "%Y-%m") as bulan, COUNT(*) as cnt, SUM(total_harga) as nilai')
            ->groupBy('bulan')
            ->orderBy('bulan')
            ->get()->keyBy('bulan');

        $trend = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key = $month->format('Y-m');
            $row = $trendData->get($key);
            $trend[] = [
                'label' => $month->translatedFormat('M'),
                'fullLabel' => $month->translatedFormat('M Y'),
                'count' => (int) ($row->cnt ?? 0),
                'nilai' => (float) ($row->nilai ?? 0),
            ];
        }
        $trendMax = max(array_column($trend, 'count')) ?: 1;
        $trendTotalCount = array_sum(array_column($trend, 'count'));
        $trendTotalNilai = array_sum(array_column($trend, 'nilai'));

        // ============ TOP 5 SALES ============
        $topSales = Sales::query()
            ->select('sales.id', 'sales.kode', 'sales.nama')
            ->selectRaw('COUNT(spr.id) as spr_count')
            ->selectRaw('COALESCE(SUM(spr.total_harga), 0) as spr_nilai')
            ->leftJoin('spr', function ($j) use ($from, $to) {
                $j->on('spr.sales_id', '=', 'sales.id')
                    ->whereIn('spr.status', ['approved', 'akad'])
                    ->whereBetween('spr.tanggal_spr', [$from, $to]);
                if ($this->filterProyek) {
                    $j->leftJoin('rumah', 'rumah.id', '=', 'spr.rumah_id')
                        ->where('rumah.proyek_id', $this->filterProyek);
                }
            })
            ->groupBy('sales.id', 'sales.kode', 'sales.nama')
            ->orderByDesc('spr_nilai')
            ->limit(5)->get();
        $topSalesMax = (float) ($topSales->max('spr_nilai') ?: 1);

        // ============ STOCK PER PROYEK (kalau tidak pilih proyek) ============
        $stockPerProyek = Proyek::query()
            ->when($this->filterProyek, fn ($q) => $q->where('id', $this->filterProyek))
            ->withCount([
                'rumah',
                'rumah as terjual_count' => fn ($q) => $q->where('status', 'terjual'),
                'rumah as available_count' => fn ($q) => $q->where('status', 'available'),
                'rumah as booking_count' => fn ($q) => $q->where('status', 'booking'),
            ])
            ->orderByDesc('rumah_count')->get();

        return compact(
            'proyekAktif',
            'unitCur', 'unitPrev', 'unitGrowth',
            'nilaiCur', 'nilaiPrev', 'nilaiGrowth',
            'realisasiCur', 'realisasiPrev', 'cashGrowth',
            'totalUnit', 'unitTerjual', 'unitAvailable', 'unitBooking', 'sellThrough',
            'funnel', 'funnelMax',
            'cancellationRate', 'sprBatal', 'totalSprAll',
            'overdueUm', 'overdueRate',
            'avgDaysToAkad', 'countAkad',
            'totalUmNet', 'totalUmPaid', 'outstandingUm',
            'forecast30d',
            'trend', 'trendMax', 'trendTotalCount', 'trendTotalNilai',
            'topSales', 'topSalesMax',
            'stockPerProyek',
            'trendCash', 'trendCashMax', 'trendCashTotal',
            'pipelineStages', 'pipelineTotal',
        );
    }
}; ?>

@php
    $fmtJt = function ($v) {
        $v = (float) $v;
        if ($v >= 1_000_000_000) return number_format($v / 1_000_000_000, 2, ',', '.').' M';
        if ($v >= 1_000_000) return number_format($v / 1_000_000, 1, ',', '.').' jt';
        if ($v >= 1_000) return number_format($v / 1_000, 0, ',', '.').' rb';
        return number_format($v, 0, ',', '.');
    };
    $growthBadge = function ($pct) {
        if ($pct > 0) return ['emerald', '▲', '+'.$pct];
        if ($pct < 0) return ['rose', '▼', ''.$pct];
        return ['zinc', '—', '0'];
    };
@endphp

<section class="w-full">
    <style>
        @keyframes dash-fade-up { 0% { opacity: 0; transform: translateY(8px); } 100% { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: no-preference) {
            .dash-card { animation: dash-fade-up 0.35s ease-out both; }
        }
    </style>

    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                <flux:subheading>
                    @if ($proyekAktif)
                        {{ $proyekAktif->nama_proyek }} · {{ $this->periodLabel() }}
                    @else
                        {{ __('Seluruh Proyek') }} · {{ $this->periodLabel() }}
                    @endif
                </flux:subheading>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex items-center rounded-lg border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($this::PERIOD_OPTIONS as $k => $lbl)
                        @php $active = $period === $k; @endphp
                        <button type="button" wire:click="setPeriod('{{ $k }}')"
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition',
                                    'bg-emerald-600 text-white shadow' => $active,
                                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $active,
                                ])>{{ $lbl }}</button>
                    @endforeach
                </div>

                {{-- Date range picker (muncul kalau period = custom) --}}
                @if ($period === 'custom')
                    <div class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 p-1.5 dark:border-emerald-700 dark:bg-emerald-950/30">
                        <input type="date" wire:model.live="dateFrom" max="{{ now()->format('Y-m-d') }}"
                               class="h-7 rounded border border-zinc-200 bg-white px-2 text-xs shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900" />
                        <span class="text-xs text-emerald-700">—</span>
                        <input type="date" wire:model.live="dateTo" max="{{ now()->format('Y-m-d') }}"
                               class="h-7 rounded border border-zinc-200 bg-white px-2 text-xs shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900" />
                    </div>
                @endif
            </div>
        </div>

        {{-- ============ EXECUTIVE KPI (4 cards dengan growth) ============ --}}
        <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Unit Terjual + Growth --}}
            <div class="dash-card relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-emerald-500"></div>
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Unit Terjual</div>
                        <div class="mt-2 text-3xl font-bold tabular-nums">{{ number_format($unitCur) }}</div>
                    </div>
                    @php [$c, $arrow, $val] = $growthBadge($unitGrowth); @endphp
                    <div class="rounded-md bg-{{ $c }}-100 px-2 py-1 text-xs font-bold text-{{ $c }}-700 dark:bg-{{ $c }}-950/50 dark:text-{{ $c }}-400">
                        <span class="mr-0.5">{{ $arrow }}</span>{{ $val }}%
                    </div>
                </div>
                <div class="mt-3 text-[10px] text-zinc-500">Periode lalu: {{ number_format($unitPrev) }} unit</div>
            </div>

            {{-- Total Penjualan --}}
            <div class="dash-card relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-amber-500"></div>
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Penjualan</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums"><span class="text-sm">Rp</span> {{ $fmtJt($nilaiCur) }}</div>
                    </div>
                    @php [$c, $arrow, $val] = $growthBadge($nilaiGrowth); @endphp
                    <div class="rounded-md bg-{{ $c }}-100 px-2 py-1 text-xs font-bold text-{{ $c }}-700 dark:bg-{{ $c }}-950/50 dark:text-{{ $c }}-400">
                        <span class="mr-0.5">{{ $arrow }}</span>{{ $val }}%
                    </div>
                </div>
                <div class="mt-3 text-[10px] text-zinc-500">Periode lalu: Rp {{ $fmtJt($nilaiPrev) }}</div>
            </div>

            {{-- Kas Masuk --}}
            <div class="dash-card relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-purple-500"></div>
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Kas Masuk</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums"><span class="text-sm">Rp</span> {{ $fmtJt($realisasiCur) }}</div>
                    </div>
                    @php [$c, $arrow, $val] = $growthBadge($cashGrowth); @endphp
                    <div class="rounded-md bg-{{ $c }}-100 px-2 py-1 text-xs font-bold text-{{ $c }}-700 dark:bg-{{ $c }}-950/50 dark:text-{{ $c }}-400">
                        <span class="mr-0.5">{{ $arrow }}</span>{{ $val }}%
                    </div>
                </div>
                <div class="mt-3 text-[10px] text-zinc-500">Total kwitansi diterima periode ini</div>
            </div>

            {{-- Persentase Terjual --}}
            <div class="dash-card relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-blue-500"></div>
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Unit Terjual (Total)</div>
                    <div class="mt-2 flex items-baseline gap-1.5">
                        <div class="text-3xl font-bold tabular-nums">{{ $sellThrough }}<span class="text-lg text-zinc-500">%</span></div>
                    </div>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div class="h-full bg-blue-500 transition-all" style="width: {{ $sellThrough }}%"></div>
                </div>
                <div class="mt-1 text-[10px] text-zinc-500">{{ $unitTerjual }} dari total {{ $totalUnit }} unit</div>
            </div>
        </div>

        {{-- ============ SALES FUNNEL + RISK INDICATORS ============ --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Sales Funnel --}}
            <div class="dash-card rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.funnel class="size-5 text-indigo-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Alur Konversi Sales') }}</flux:heading>
                            <x-info-button title="Alur Konversi Sales">
                                <p>Perjalanan customer dari <strong>prospek → beli (SPR aktif) → akad kredit</strong>.</p>
                                <ul class="mt-3 space-y-1.5 list-disc pl-5">
                                    <li><strong>Prospect</strong>: calon customer yang terdaftar di database sales</li>
                                    <li><strong>SPR Aktif</strong>: prospect yang sudah booking + submit SPR + UTJ diverifikasi</li>
                                    <li><strong>Akad</strong>: SPR yang sudah akad kredit di notaris</li>
                                </ul>
                                <p class="mt-3"><strong>Cara baca:</strong> makin kecil dropoff antar stage, makin sehat funnel-nya. Dropoff besar = ada bottleneck di tahap itu.</p>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('Perjalanan customer: dari daftar prospek → beli (SPR aktif) → akad kredit.') }}</flux:subheading>
                    </div>
                </div>
                <div class="space-y-3">
                    @foreach ($funnel as $idx => $stage)
                        @php
                            $pct = $funnelMax > 0 ? round(($stage['count'] / $funnelMax) * 100, 1) : 0;
                            $convFromPrev = null;
                            $dropoff = null;
                            if ($idx > 0 && $funnel[$idx - 1]['count'] > 0) {
                                $convFromPrev = round(($stage['count'] / $funnel[$idx - 1]['count']) * 100, 1);
                                $dropoff = 100 - $convFromPrev;
                            }
                        @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-{{ $stage['color'] }}-100 text-[10px] font-bold text-{{ $stage['color'] }}-700">{{ $idx + 1 }}</span>
                                    <span class="font-semibold">{{ $stage['label'] }}</span>
                                    @if ($convFromPrev !== null)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-{{ $convFromPrev >= 50 ? 'emerald' : ($convFromPrev >= 20 ? 'amber' : 'rose') }}-100 px-2 py-0.5 text-[10px] font-bold text-{{ $convFromPrev >= 50 ? 'emerald' : ($convFromPrev >= 20 ? 'amber' : 'rose') }}-700">
                                            {{ $convFromPrev }}% lanjut
                                        </span>
                                        @if ($dropoff > 0)
                                            <span class="text-[10px] text-zinc-500">({{ $dropoff }}% drop-off)</span>
                                        @endif
                                    @endif
                                </div>
                                <span class="font-mono text-sm font-bold tabular-nums">{{ number_format($stage['count']) }}</span>
                            </div>
                            <div class="h-4 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full bg-{{ $stage['color'] }}-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Indikator Risiko --}}
            <div class="dash-card rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.shield-exclamation class="size-5 text-rose-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Indikator Risiko') }}</flux:heading>
                            <x-info-button title="Indikator Risiko">
                                <p>Sinyal awal masalah operasional. Warna warning:</p>
                                <ul class="mt-3 space-y-1 list-disc pl-5">
                                    <li><span class="text-emerald-700 font-bold">Hijau</span> = sehat</li>
                                    <li><span class="text-amber-700 font-bold">Kuning</span> = perlu diperhatikan</li>
                                    <li><span class="text-rose-700 font-bold">Merah</span> = perlu action segera</li>
                                </ul>
                                <div class="mt-4 space-y-3">
                                    <div>
                                        <div class="font-bold">% SPR Dibatalkan</div>
                                        <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">Persentase SPR yang berhenti di tengah jalan (customer mundur, tolak bank, dll). Target &lt;5%.</p>
                                    </div>
                                    <div>
                                        <div class="font-bold">Customer Terlambat Bayar UM</div>
                                        <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">Customer yang sudah &gt;60 hari sejak UTJ tapi bayar UM &lt;50%. Berisiko cancel.</p>
                                    </div>
                                    <div>
                                        <div class="font-bold">Rata-rata SPR → Akad</div>
                                        <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">Lama waktu proses dari SPR dibuat sampai akad kredit di notaris. Makin cepat makin baik.</p>
                                    </div>
                                </div>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('Sinyal awal masalah operasional.') }}</flux:subheading>
                    </div>
                </div>
                <div class="space-y-3">
                    {{-- Pembatalan --}}
                    @php $cancelColor = $cancellationRate >= 10 ? 'rose' : ($cancellationRate >= 5 ? 'amber' : 'emerald'); @endphp
                    <div class="rounded-lg bg-{{ $cancelColor }}-50 p-3 dark:bg-{{ $cancelColor }}-950/30">
                        <div class="flex items-center justify-between">
                            <div class="text-[10px] font-bold uppercase text-{{ $cancelColor }}-700">% SPR Dibatalkan</div>
                            <div class="text-xl font-bold tabular-nums text-{{ $cancelColor }}-800">{{ $cancellationRate }}%</div>
                        </div>
                        <div class="mt-1 text-[10px] text-{{ $cancelColor }}-700/80">{{ $sprBatal }} batal dari total {{ $totalSprAll }} SPR</div>
                    </div>

                    {{-- Terlambat Bayar --}}
                    @php $overdueColor = $overdueRate >= 20 ? 'rose' : ($overdueRate >= 10 ? 'amber' : 'emerald'); @endphp
                    <div class="rounded-lg bg-{{ $overdueColor }}-50 p-3 dark:bg-{{ $overdueColor }}-950/30">
                        <div class="flex items-center justify-between">
                            <div class="text-[10px] font-bold uppercase text-{{ $overdueColor }}-700">Customer Terlambat Bayar UM</div>
                            <div class="text-xl font-bold tabular-nums text-{{ $overdueColor }}-800">{{ $overdueRate }}%</div>
                        </div>
                        <div class="mt-1 text-[10px] text-{{ $overdueColor }}-700/80">{{ $overdueUm }} customer belum bayar &gt;50% dalam 60 hari</div>
                    </div>

                    {{-- Rata-rata hari sampai Akad --}}
                    @if ($avgDaysToAkad > 0)
                        <div class="rounded-lg bg-blue-50 p-3 dark:bg-blue-950/30">
                            <div class="flex items-center justify-between">
                                <div class="text-[10px] font-bold uppercase text-blue-700">Rata-rata SPR → Akad</div>
                                <div class="text-xl font-bold tabular-nums text-blue-800">{{ $avgDaysToAkad }}<span class="text-sm"> hari</span></div>
                            </div>
                            <div class="mt-1 text-[10px] text-blue-700/80">Berdasarkan {{ $countAkad }} SPR yang sudah akad</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ============ CASH FLOW SNAPSHOT ============ --}}
        <div class="dash-card mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start gap-3">
                <flux:icon.banknotes class="size-5 text-amber-600" />
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="lg">{{ __('Kesehatan Keuangan') }}</flux:heading>
                        <x-info-button title="Kesehatan Keuangan">
                            <p>Ringkasan posisi piutang UM dari customer + perkiraan kas masuk 30 hari ke depan.</p>
                            <div class="mt-4 space-y-3">
                                <div>
                                    <div class="font-bold">Total Piutang UM</div>
                                    <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">Total kewajiban uang muka dari semua SPR aktif.</p>
                                </div>
                                <div>
                                    <div class="font-bold">Sudah Dibayar</div>
                                    <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">Total UTJ + UM yang sudah cair ke rekening perusahaan.</p>
                                </div>
                                <div>
                                    <div class="font-bold">Belum Tertagih</div>
                                    <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">Sisa piutang aktif — customer wajib bayar sesuai jadwal termin.</p>
                                </div>
                                <div>
                                    <div class="font-bold">Perkiraan 30 Hari</div>
                                    <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">Jumlah termin yang jatuh tempo dalam 30 hari ke depan (potensi kas masuk).</p>
                                </div>
                            </div>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Total uang muka dari customer aktif & perkiraan kas masuk 30 hari ke depan.') }}</flux:subheading>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                    <div class="text-[10px] font-bold uppercase text-zinc-600">Total Piutang UM</div>
                    <div class="mt-1 text-xl font-bold tabular-nums">Rp {{ $fmtJt($totalUmNet) }}</div>
                    <div class="mt-0.5 text-[10px] text-zinc-500">Kewajiban customer aktif</div>
                </div>
                <div class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/30">
                    <div class="text-[10px] font-bold uppercase text-emerald-700">Sudah Dibayar</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300">Rp {{ $fmtJt($totalUmPaid) }}</div>
                    <div class="mt-0.5 text-[10px] text-emerald-700/70">Total UTJ + UM yang cair</div>
                </div>
                <div class="rounded-lg bg-rose-50 p-3 dark:bg-rose-950/30">
                    <div class="text-[10px] font-bold uppercase text-rose-700">Belum Tertagih</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-rose-700 dark:text-rose-300">Rp {{ $fmtJt($outstandingUm) }}</div>
                    <div class="mt-0.5 text-[10px] text-rose-700/70">Sisa piutang aktif</div>
                </div>
                <div class="rounded-lg bg-purple-50 p-3 dark:bg-purple-950/30">
                    <div class="text-[10px] font-bold uppercase text-purple-700">Perkiraan 30 Hari</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-purple-700 dark:text-purple-300">Rp {{ $fmtJt($forecast30d) }}</div>
                    <div class="mt-0.5 text-[10px] text-purple-700/70">Termin akan jatuh tempo</div>
                </div>
            </div>
        </div>

        {{-- ============ TREND KAS MASUK 30 HARI (BARU) ============ --}}
        <div class="dash-card mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <flux:icon.chart-bar class="size-5 text-teal-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Kas Masuk Harian — 30 Hari') }}</flux:heading>
                            <x-info-button title="Kas Masuk Harian">
                                <p>Grafik batang menunjukkan total kas masuk (UTJ + UM) per hari dalam 30 hari terakhir.</p>
                                <ul class="mt-3 space-y-1 list-disc pl-5">
                                    <li><strong>Bar teal gelap</strong> = hari ini</li>
                                    <li><strong>Bar teal terang</strong> = hari sebelumnya</li>
                                </ul>
                                <p class="mt-3"><strong>Cara pakai:</strong> hover bar untuk lihat tanggal + nominal detail. Gunakan untuk analisa pola kas masuk (hari mana yang biasanya sepi, kapan puncak realisasi).</p>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('Realisasi pembayaran per hari. Hover bar untuk lihat detail.') }}</flux:subheading>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total 30 Hari</div>
                    <div class="text-lg font-bold tabular-nums text-teal-700">Rp {{ $fmtJt($trendCashTotal) }}</div>
                </div>
            </div>

            @if ($trendCashTotal === 0)
                <div class="py-12 text-center text-sm text-zinc-500">{{ __('Belum ada kas masuk dalam 30 hari terakhir.') }}</div>
            @else
                @php
                    $svgW = 1000; $svgH = 200; $padBottom = 26; $chartH = $svgH - $padBottom;
                    $n = count($trendCash); $gap = 3;
                    $barW = ($svgW - ($gap * ($n - 1))) / $n;
                @endphp
                <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" class="w-full" style="height: 200px;" preserveAspectRatio="none">
                    @for ($g = 1; $g <= 4; $g++)
                        <line x1="0" y1="{{ ($chartH / 4) * $g }}" x2="{{ $svgW }}" y2="{{ ($chartH / 4) * $g }}"
                              stroke="currentColor" stroke-opacity="0.08" stroke-width="1" class="text-zinc-900" />
                    @endfor
                    @foreach ($trendCash as $idx => $t)
                        @php
                            $h = $trendCashMax > 0 ? max(2, round(($t['total'] / $trendCashMax) * ($chartH - 4))) : 0;
                            $x = $idx * ($barW + $gap);
                            $y = $chartH - $h;
                            $isToday = $t['date']->isToday();
                            $fill = $isToday ? '#0d9488' : '#5eead4';
                        @endphp
                        <g>
                            <title>{{ $t['date']->translatedFormat('l, d M Y') }} — Rp {{ $fmtJt($t['total']) }}</title>
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $h }}" fill="{{ $fill }}" rx="2" ry="2" />
                        </g>
                        @if ($idx % 5 === 0 || $isToday)
                            <text x="{{ $x + $barW / 2 }}" y="{{ $svgH - 6 }}" text-anchor="middle" font-size="10"
                                  font-weight="{{ $isToday ? '700' : '400' }}"
                                  fill="currentColor" fill-opacity="{{ $isToday ? '1' : '0.5' }}"
                                  class="{{ $isToday ? 'text-teal-700' : 'text-zinc-500' }}">
                                {{ $t['date']->format('d/m') }}
                            </text>
                        @endif
                    @endforeach
                </svg>
            @endif
        </div>

        {{-- ============ PIPELINE SPR — 5 STAGE (BARU) ============ --}}
        <div class="dash-card mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <flux:icon.arrow-trending-up class="size-5 text-indigo-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Alur Proses SPR') }}</flux:heading>
                            <x-info-button title="Alur Proses SPR">
                                <p>Distribusi SPR aktif berdasarkan tahap yang sedang berjalan.</p>
                                <div class="mt-3">
                                    <div class="mb-1 font-bold">5 Tahap SPR:</div>
                                    <ol class="space-y-1 list-decimal pl-5">
                                        <li>Verifikasi UTJ oleh Keuangan</li>
                                        <li>Approval oleh Project Manager</li>
                                        <li>Konsumen tanda tangan digital</li>
                                        <li>Keuangan tempel e-Materai</li>
                                        <li>Selesai (arsip)</li>
                                    </ol>
                                </div>
                                <p class="mt-3"><strong>Cara baca:</strong> kalau bar di satu tahap terlalu panjang = ada bottleneck di sana. Fokuskan action ke tahap tersebut.</p>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('Distribusi SPR aktif — di tahap mana mereka sekarang.') }}</flux:subheading>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total SPR Aktif</div>
                    <div class="text-lg font-bold tabular-nums">{{ number_format($pipelineTotal) }}</div>
                </div>
            </div>

            @php
                $stages = [
                    ['key' => 'utj_verify', 'label' => 'Menunggu Verifikasi UTJ (Keuangan)', 'color' => 'amber', 'icon' => 'banknotes'],
                    ['key' => 'pm_approve', 'label' => 'Menunggu Persetujuan Project Manager', 'color' => 'violet', 'icon' => 'shield-check'],
                    ['key' => 'konsumen_ttd', 'label' => 'Menunggu Tanda Tangan Konsumen', 'color' => 'teal', 'icon' => 'pencil-square'],
                    ['key' => 'materai', 'label' => 'Menunggu e-Materai (Keuangan)', 'color' => 'purple', 'icon' => 'document-check'],
                    ['key' => 'final', 'label' => 'Selesai (Sudah Ber-e-Materai)', 'color' => 'emerald', 'icon' => 'trophy'],
                ];
                $stageMax = max(1, ...array_values($pipelineStages));
            @endphp
            <div class="space-y-2.5">
                @foreach ($stages as $s)
                    @php
                        $cnt = $pipelineStages[$s['key']];
                        $pct = $pipelineTotal > 0 ? round(($cnt / $pipelineTotal) * 100, 1) : 0;
                        $barW = $stageMax > 0 ? round(($cnt / $stageMax) * 100, 1) : 0;
                    @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2">
                                <flux:icon :name="$s['icon']" class="size-3.5 text-{{ $s['color'] }}-600" />
                                <span class="font-semibold text-{{ $s['color'] }}-700 dark:text-{{ $s['color'] }}-400">{{ $s['label'] }}</span>
                            </div>
                            <span class="tabular-nums">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ number_format($cnt) }}</span>
                                <span class="ms-1 text-[10px] text-zinc-500">({{ $pct }}%)</span>
                            </span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full bg-{{ $s['color'] }}-500 transition-all" style="width: {{ $barW }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ============ TREND 12 BULAN ============ --}}
        <div class="dash-card mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <flux:icon.chart-bar class="size-5 text-emerald-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Trend Penjualan — 12 Bulan Terakhir') }}</flux:heading>
                            <x-info-button title="Trend Penjualan">
                                <p>Jumlah unit terjual per bulan dalam 12 bulan terakhir.</p>
                                <ul class="mt-3 space-y-1 list-disc pl-5">
                                    <li><strong>Bar hijau tua</strong> = bulan berjalan</li>
                                    <li><strong>Bar hijau muda</strong> = bulan sebelumnya</li>
                                </ul>
                                <p class="mt-3">Data yang dihitung: SPR dengan status <em>Disetujui</em> atau <em>Akad</em>.</p>
                                <p class="mt-3"><strong>Cara pakai:</strong> hover bar untuk lihat detail bulan + jumlah + nilai. Bandingkan bulan ke bulan untuk lihat tren naik/turun penjualan.</p>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('Jumlah unit terjual per bulan (SPR sudah disetujui atau sudah akad).') }}</flux:subheading>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total 12 bln</div>
                    <div class="text-lg font-bold tabular-nums">{{ number_format($trendTotalCount) }} unit</div>
                    <div class="text-[10px] tabular-nums text-zinc-500">Rp {{ $fmtJt($trendTotalNilai) }}</div>
                </div>
            </div>

            @if ($trendTotalCount === 0)
                <div class="py-12 text-center text-sm text-zinc-500">{{ __('Belum ada penjualan dalam 12 bulan terakhir.') }}</div>
            @else
                @php
                    $svgW = 1000; $svgH = 220; $padBottom = 28;
                    $chartH = $svgH - $padBottom;
                    $n = count($trend); $gap = 8;
                    $barW = ($svgW - ($gap * ($n - 1))) / $n;
                @endphp
                <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" class="w-full" style="height: 240px;" preserveAspectRatio="none">
                    @for ($g = 1; $g <= 4; $g++)
                        <line x1="0" y1="{{ ($chartH / 4) * $g }}" x2="{{ $svgW }}" y2="{{ ($chartH / 4) * $g }}"
                              stroke="currentColor" stroke-opacity="0.08" stroke-width="1" class="text-zinc-900 dark:text-zinc-100" />
                    @endfor
                    @foreach ($trend as $idx => $t)
                        @php
                            $h = $trendMax > 0 ? max(3, round(($t['count'] / $trendMax) * ($chartH - 6))) : 0;
                            $x = $idx * ($barW + $gap);
                            $y = $chartH - $h;
                            $isCurrent = $loop->last;
                            $fill = $isCurrent ? '#059669' : '#34d399';
                        @endphp
                        <g>
                            <title>{{ $t['fullLabel'] }} — {{ $t['count'] }} unit · Rp {{ $fmtJt($t['nilai']) }}</title>
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $h }}" fill="{{ $fill }}" rx="4" ry="4" />
                        </g>
                        <text x="{{ $x + $barW / 2 }}" y="{{ $svgH - 8 }}" text-anchor="middle" font-size="14"
                              font-weight="{{ $isCurrent ? '700' : '400' }}" fill="currentColor"
                              fill-opacity="{{ $isCurrent ? '1' : '0.55' }}"
                              class="{{ $isCurrent ? 'text-emerald-700' : 'text-zinc-500' }}">
                            {{ $t['label'] }}
                        </text>
                    @endforeach
                </svg>
            @endif
        </div>

        {{-- ============ STOCK PER PROYEK + TOP SALES ============ --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="dash-card rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.building-office-2 class="size-5 text-blue-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Stock per Proyek') }}</flux:heading>
                            <x-info-button title="Stock per Proyek">
                                <p>Distribusi unit rumah di setiap proyek berdasarkan status:</p>
                                <ul class="mt-3 space-y-1.5 list-none pl-0">
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1.5 inline-block size-2.5 shrink-0 rounded-sm bg-emerald-500"></span>
                                        <span><strong>Terjual</strong> — unit yang sudah SPR aktif atau akad</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1.5 inline-block size-2.5 shrink-0 rounded-sm bg-amber-400"></span>
                                        <span><strong>Booking</strong> — unit dalam masa booking (belum SPR)</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1.5 inline-block size-2.5 shrink-0 rounded-sm bg-blue-400"></span>
                                        <span><strong>Available</strong> — unit siap dijual</span>
                                    </li>
                                </ul>
                                <p class="mt-3">Persentase = jumlah / total unit di proyek. Digunakan untuk lihat proyek mana yang <em>hot</em> (banyak terjual) vs perlu dorong marketing.</p>
                            </x-info-button>
                        </div>
                    </div>
                </div>

                @forelse ($stockPerProyek as $p)
                    @php
                        $total = max(1, $p->rumah_count);
                        $segs = [
                            ['count' => $p->terjual_count,   'label' => 'Terjual',   'color' => 'bg-emerald-500'],
                            ['count' => $p->booking_count,   'label' => 'Booking',   'color' => 'bg-amber-400'],
                            ['count' => $p->available_count, 'label' => 'Available', 'color' => 'bg-blue-400'],
                        ];
                    @endphp
                    <div class="mb-4 last:mb-0">
                        <div class="mb-2 flex items-center justify-between">
                            <div class="text-sm font-semibold">{{ $p->nama_proyek }}</div>
                            <div class="text-xs text-zinc-500">
                                <span class="font-bold text-zinc-900 dark:text-white tabular-nums">{{ $p->rumah_count }}</span> unit
                            </div>
                        </div>
                        <div class="flex h-6 overflow-hidden rounded-md border border-zinc-200">
                            @foreach ($segs as $s)
                                @if ($s['count'] > 0)
                                    <div class="flex items-center justify-center {{ $s['color'] }} text-[10px] font-bold text-white"
                                         style="width: {{ round(($s['count'] / $total) * 100, 2) }}%"
                                         title="{{ $s['label'] }}: {{ $s['count'] }}">
                                        @if (($s['count'] / $total) >= 0.08){{ $s['count'] }}@endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 text-[10px] text-zinc-500">
                            @foreach ($segs as $s)
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="inline-block size-2.5 rounded-sm {{ $s['color'] }}"></span>
                                    {{ $s['label'] }}: <span class="tabular-nums font-semibold text-zinc-700 dark:text-zinc-300">{{ $s['count'] }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="py-6 text-center text-sm text-zinc-500">{{ __('Belum ada data proyek.') }}</div>
                @endforelse
            </div>

            <div class="dash-card rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.trophy class="size-5 text-amber-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Top Sales') }}</flux:heading>
                            <x-info-button title="Top Sales">
                                <p>5 sales dengan penjualan tertinggi periode ini (berdasar total nilai SPR yang <em>Disetujui</em> atau <em>Akad</em>).</p>
                                <div class="mt-3">
                                    <div class="mb-1 font-bold">Rangking 1-3 dapat medali warna:</div>
                                    <ul class="space-y-1 list-none pl-0">
                                        <li>🥇 Emas — juara 1</li>
                                        <li>🥈 Perak — juara 2</li>
                                        <li>🥉 Perunggu — juara 3</li>
                                    </ul>
                                </div>
                                <p class="mt-3">Bar horizontal = perbandingan nilai vs juara 1. Digunakan untuk apresiasi &amp; benchmark performa tim.</p>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('By total nilai penjualan.') }}</flux:subheading>
                    </div>
                </div>
                <div class="space-y-3">
                    @forelse ($topSales as $idx => $s)
                        @php
                            $pct = $topSalesMax > 0 ? round(($s->spr_nilai / $topSalesMax) * 100, 1) : 0;
                            $rankBg = match ($idx) {
                                0 => 'bg-amber-500 text-white',
                                1 => 'bg-zinc-400 text-white',
                                2 => 'bg-orange-500 text-white',
                                default => 'bg-zinc-100 text-zinc-500',
                            };
                        @endphp
                        <div>
                            <div class="mb-1 flex items-center gap-2.5">
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold {{ $rankBg }}">{{ $idx + 1 }}</div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold truncate">{{ $s->nama }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-bold tabular-nums">Rp {{ $fmtJt($s->spr_nilai) }}</div>
                                    <div class="text-[10px] text-zinc-500 tabular-nums">{{ $s->spr_count }} unit</div>
                                </div>
                            </div>
                            <div class="ms-8 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full bg-amber-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-500">{{ __('Belum ada penjualan.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</section>
