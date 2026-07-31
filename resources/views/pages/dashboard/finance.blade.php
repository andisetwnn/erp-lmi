<?php

use App\Models\Master\Proyek;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\SprTerminPembayaran;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard Keuangan')] class extends Component
{
    #[Url(as: 'p')]
    public string $period = 'mtd';

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    public ?int $filterProyek = null;

    public const PERIOD_OPTIONS = [
        'today' => 'Hari Ini',
        'mtd' => 'Bulan Ini',
        'qtd' => '3 Bulan',
        'ytd' => 'YTD',
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
        if ($p !== 'custom') {
            $this->dateFrom = null;
            $this->dateTo = null;
        } elseif (! $this->dateFrom || ! $this->dateTo) {
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
        return self::PERIOD_OPTIONS[$this->period] ?? 'Bulan Ini';
    }

    private function periodRange(): array
    {
        if ($this->period === 'custom' && $this->dateFrom && $this->dateTo) {
            return [Carbon::parse($this->dateFrom)->startOfDay(), Carbon::parse($this->dateTo)->endOfDay()];
        }
        return match ($this->period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'mtd' => [now()->startOfMonth(), now()->endOfMonth()],
            'qtd' => [now()->subMonths(3)->startOfDay(), now()->endOfDay()],
            'ytd' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function previousPeriodRange(): array
    {
        if ($this->period === 'custom' && $this->dateFrom && $this->dateTo) {
            $from = Carbon::parse($this->dateFrom);
            $to = Carbon::parse($this->dateTo);
            $days = max(1, $from->diffInDays($to) + 1);
            return [$from->copy()->subDays($days)->startOfDay(), $from->copy()->subDay()->endOfDay()];
        }
        return match ($this->period) {
            'today' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'mtd' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'qtd' => [now()->subMonths(6)->startOfDay(), now()->subMonths(3)->endOfDay()],
            'ytd' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            default => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
        };
    }

    private function scopedRealisasi($query)
    {
        if ($this->filterProyek) {
            return $query->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));
        }
        return $query;
    }

    private function scopedSpr($query)
    {
        if ($this->filterProyek) {
            return $query->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));
        }
        return $query;
    }

    public function with(): array
    {
        [$from, $to] = $this->periodRange();
        [$prevFrom, $prevTo] = $this->previousPeriodRange();
        $proyekAktif = $this->filterProyek ? Proyek::find($this->filterProyek) : null;

        // ============ ACTION ITEMS ============
        $utjPending = $this->scopedSpr(Spr::query())->where('status', 'submitted')->count();
        $materaiPending = $this->scopedSpr(Spr::query())
            ->where('status', 'approved')
            ->whereNotNull('pm_approved_at')
            ->whereNotNull('konsumen_signed_at')
            ->whereNull('materai_stamped_at')
            ->count();

        // ============ CASH IN ============
        $cashInPeriod = (float) $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->whereBetween('tanggal_bayar', [$from, $to])->sum('jumlah');
        $cashInPrev = (float) $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->whereBetween('tanggal_bayar', [$prevFrom, $prevTo])->sum('jumlah');
        $cashGrowth = $cashInPrev > 0 ? round((($cashInPeriod - $cashInPrev) / $cashInPrev) * 100, 1) : ($cashInPeriod > 0 ? 100 : 0);

        $cashInToday = (float) $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->whereDate('tanggal_bayar', now()->toDateString())->sum('jumlah');
        $cashInMonth = (float) $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->whereBetween('tanggal_bayar', [now()->startOfMonth(), now()->endOfMonth()])->sum('jumlah');

        $cashByJenis = $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->whereBetween('tanggal_bayar', [$from, $to])
            ->selectRaw('jenis, SUM(jumlah) as total, COUNT(*) as cnt')
            ->groupBy('jenis')
            ->get()
            ->keyBy('jenis');
        $cashByJenisTotal = (float) $cashByJenis->sum('total');

        // ============ TREND 30 HARI CASH IN ============
        $trendCash = [];
        $trendCashQ = $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->where('tanggal_bayar', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(tanggal_bayar) as tgl, SUM(jumlah) as total, COUNT(*) as cnt')
            ->groupBy('tgl')
            ->get()
            ->keyBy('tgl');

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->format('Y-m-d');
            $row = $trendCashQ->get($key);
            $trendCash[] = [
                'date' => $date,
                'total' => (float) ($row->total ?? 0),
                'cnt' => (int) ($row->cnt ?? 0),
            ];
        }
        $trendCashMax = max(1, ...array_column($trendCash, 'total'));
        $trendCashTotal = array_sum(array_column($trendCash, 'total'));

        // ============ OUTSTANDING & FORECAST ============
        $totalUmNet = (float) $this->scopedSpr(Spr::query())->where('status', 'approved')->sum('um_net');
        $totalUmPaid = (float) $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->whereHas('spr', fn ($s) => $s->where('status', 'approved'))
            ->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
        $outstandingUm = max(0, $totalUmNet - $totalUmPaid);
        $paidPct = $totalUmNet > 0 ? round(($totalUmPaid / $totalUmNet) * 100, 1) : 0;

        $forecast7d = (float) SprTerminPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereHas('spr', fn ($s) => $s->where('status', 'approved'))
            ->whereNull('tanggal_realisasi')
            ->whereBetween('tanggal_jadwal', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->sum('jumlah_jadwal');
        $forecast30d = (float) SprTerminPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereHas('spr', fn ($s) => $s->where('status', 'approved'))
            ->whereNull('tanggal_realisasi')
            ->whereBetween('tanggal_jadwal', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->sum('jumlah_jadwal');

        // ============ OVERDUE ============
        $overdueTermins = SprTerminPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereHas('spr', fn ($s) => $s->where('status', 'approved'))
            ->whereNull('tanggal_realisasi')
            ->where('tanggal_jadwal', '<', now())
            ->count();
        $overdueAmount = (float) SprTerminPembayaran::query()
            ->when($this->filterProyek, fn ($q) => $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
            ->whereHas('spr', fn ($s) => $s->where('status', 'approved'))
            ->whereNull('tanggal_realisasi')
            ->where('tanggal_jadwal', '<', now())
            ->sum('jumlah_jadwal');

        // ============ RECENT REALISASI ============
        $recentRealisasi = $this->scopedRealisasi(SprRealisasiPembayaran::query())
            ->with(['spr:id,nomor_spr,prospect_customer_id', 'spr.prospectCustomer:id,nama_lengkap', 'inputBy:id,name'])
            ->orderByDesc('tanggal_bayar')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        // ============ REFUND PENDING ============
        $refundPending = $this->scopedSpr(Spr::query())
            ->where('status', 'cancelled')
            ->where('refund_status', 'pending')
            ->count();

        // ============ OUTSTANDING PER PROYEK (comparison) ============
        $outstandingPerProyek = Proyek::query()
            ->when($this->filterProyek, fn ($q) => $q->where('id', $this->filterProyek))
            ->get(['id', 'nama_proyek'])
            ->map(function ($p) {
                $um = (float) Spr::whereHas('rumah', fn ($r) => $r->where('proyek_id', $p->id))
                    ->where('status', 'approved')->sum('um_net');
                $paid = (float) SprRealisasiPembayaran::whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $p->id))
                    ->whereHas('spr', fn ($s) => $s->where('status', 'approved'))
                    ->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
                return [
                    'nama' => $p->nama_proyek,
                    'um_net' => $um,
                    'paid' => $paid,
                    'outstanding' => max(0, $um - $paid),
                    'paid_pct' => $um > 0 ? round(($paid / $um) * 100, 1) : 0,
                ];
            })
            ->filter(fn ($r) => $r['um_net'] > 0)
            ->sortByDesc('outstanding')
            ->values();
        $outstandingProyekMax = max(1, ...$outstandingPerProyek->pluck('um_net')->toArray() ?: [1]);

        return compact(
            'proyekAktif',
            'utjPending', 'materaiPending',
            'cashInPeriod', 'cashInPrev', 'cashGrowth',
            'cashInToday', 'cashInMonth',
            'cashByJenis', 'cashByJenisTotal',
            'trendCash', 'trendCashMax', 'trendCashTotal',
            'totalUmNet', 'totalUmPaid', 'outstandingUm', 'paidPct',
            'forecast7d', 'forecast30d',
            'overdueTermins', 'overdueAmount',
            'recentRealisasi', 'refundPending',
            'outstandingPerProyek', 'outstandingProyekMax',
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
    $fmtRp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $growthBadge = function ($pct) {
        if ($pct > 0) return ['emerald', '▲', '+'.$pct];
        if ($pct < 0) return ['rose', '▼', ''.$pct];
        return ['zinc', '—', '0'];
    };
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER + FILTER PERIODE --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Dashboard Keuangan') }}</flux:heading>
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

        {{-- ============ ACTION ITEMS ============ --}}
        <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
            @if ($utjPending > 0)
                <a href="{{ route('finance.penerimaan-konsumen.index') }}" wire:navigate
                   class="flex items-center justify-between gap-4 rounded-2xl border-2 border-emerald-400 bg-linear-to-r from-emerald-500 to-teal-600 p-5 text-white shadow-lg transition hover:from-emerald-600 hover:to-teal-700">
                    <div class="flex items-center gap-3">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white/20">
                            <flux:icon.inbox-arrow-down class="size-6" />
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-90">{{ __('Butuh Verifikasi') }}</div>
                            <div class="text-xl font-bold leading-tight">{{ $utjPending }} UTJ {{ __('baru') }}</div>
                            <div class="text-[11px] opacity-90">{{ __('Cek bukti transfer & konfirmasi') }}</div>
                        </div>
                    </div>
                    <flux:icon.arrow-right class="size-5 shrink-0" />
                </a>
            @else
                <div class="flex items-center gap-3 rounded-2xl border-2 border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-300 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">
                        <flux:icon.check class="size-6" />
                    </div>
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ __('UTJ') }}</div>
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Semua UTJ sudah diverifikasi') }}</div>
                    </div>
                </div>
            @endif

            @if ($materaiPending > 0)
                <a href="{{ route('finance.tempel-materai.index') }}" wire:navigate
                   class="flex items-center justify-between gap-4 rounded-2xl border-2 border-purple-400 bg-linear-to-r from-purple-500 to-indigo-600 p-5 text-white shadow-lg transition hover:from-purple-600 hover:to-indigo-700">
                    <div class="flex items-center gap-3">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white/20">
                            <flux:icon.document-check class="size-6" />
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider opacity-90">{{ __('Siap Materai') }}</div>
                            <div class="text-xl font-bold leading-tight">{{ $materaiPending }} SPR {{ __('siap ditempel') }}</div>
                            <div class="text-[11px] opacity-90">{{ __('Konsumen sudah TTD — tinggal e-Materai') }}</div>
                        </div>
                    </div>
                    <flux:icon.arrow-right class="size-5 shrink-0" />
                </a>
            @else
                <div class="flex items-center gap-3 rounded-2xl border-2 border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-300 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">
                        <flux:icon.check class="size-6" />
                    </div>
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ __('e-Materai') }}</div>
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Tidak ada SPR menunggu materai') }}</div>
                    </div>
                </div>
            @endif
        </div>

        {{-- ============ CASH IN KPI (4 cards dengan growth) ============ --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-blue-500"></div>
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Cash In Hari Ini') }}</div>
                <div class="mt-2 text-2xl font-bold tabular-nums"><span class="text-sm text-zinc-500">Rp</span> {{ $fmtJt($cashInToday) }}</div>
                <div class="mt-1 text-[10px] text-zinc-500">{{ now()->translatedFormat('l, d M') }}</div>
            </div>

            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-indigo-500"></div>
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Cash In Bulan Ini') }}</div>
                <div class="mt-2 text-2xl font-bold tabular-nums"><span class="text-sm text-zinc-500">Rp</span> {{ $fmtJt($cashInMonth) }}</div>
                <div class="mt-1 text-[10px] text-zinc-500">{{ now()->translatedFormat('F Y') }}</div>
            </div>

            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-emerald-500"></div>
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Cash In') }} · {{ $this->periodLabel() }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-700"><span class="text-sm text-zinc-500">Rp</span> {{ $fmtJt($cashInPeriod) }}</div>
                    </div>
                    @php [$c, $arrow, $val] = $growthBadge($cashGrowth); @endphp
                    <div class="rounded-md bg-{{ $c }}-100 px-2 py-1 text-xs font-bold text-{{ $c }}-700 dark:bg-{{ $c }}-950/50 dark:text-{{ $c }}-400">
                        <span class="mr-0.5">{{ $arrow }}</span>{{ $val }}%
                    </div>
                </div>
                <div class="mt-3 text-[10px] text-zinc-500">vs periode lalu (Rp {{ $fmtJt($cashInPrev) }})</div>
            </div>

            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-purple-500"></div>
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Piutang Tertagih') }}</div>
                    <div class="mt-2 flex items-baseline gap-1.5">
                        <div class="text-3xl font-bold tabular-nums">{{ $paidPct }}<span class="text-lg text-zinc-500">%</span></div>
                    </div>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div class="h-full bg-purple-500 transition-all" style="width: {{ min(100, $paidPct) }}%"></div>
                </div>
                <div class="mt-1 text-[10px] text-zinc-500">{{ $fmtJt($totalUmPaid) }} dari {{ $fmtJt($totalUmNet) }}</div>
            </div>
        </div>

        {{-- ============ TREND 30 HARI CASH IN — BAR CHART ============ --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <flux:icon.chart-bar class="size-5 text-emerald-600" />
                    <div>
                        <flux:heading size="lg">{{ __('Trend Cash In — 30 Hari') }}</flux:heading>
                        <flux:subheading>{{ __('Realisasi pembayaran harian. Hover bar untuk detail.') }}</flux:subheading>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total 30 Hari</div>
                    <div class="text-lg font-bold tabular-nums text-emerald-700">Rp {{ $fmtJt($trendCashTotal) }}</div>
                </div>
            </div>

            @if ($trendCashTotal === 0)
                <div class="py-12 text-center text-sm text-zinc-500">{{ __('Belum ada realisasi dalam 30 hari terakhir.') }}</div>
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
                            $fill = $isToday ? '#059669' : '#34d399';
                        @endphp
                        <g>
                            <title>{{ $t['date']->translatedFormat('l, d M Y') }} — {{ $t['cnt'] }} transaksi · Rp {{ $fmtJt($t['total']) }}</title>
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $h }}" fill="{{ $fill }}" rx="2" ry="2" />
                        </g>
                        @if ($idx % 5 === 0 || $isToday)
                            <text x="{{ $x + $barW / 2 }}" y="{{ $svgH - 6 }}" text-anchor="middle" font-size="10"
                                  font-weight="{{ $isToday ? '700' : '400' }}"
                                  fill="currentColor" fill-opacity="{{ $isToday ? '1' : '0.5' }}"
                                  class="{{ $isToday ? 'text-emerald-700' : 'text-zinc-500' }}">
                                {{ $t['date']->format('d/m') }}
                            </text>
                        @endif
                    @endforeach
                </svg>
            @endif
        </div>

        {{-- ============ BREAKDOWN PER JENIS + PIUTANG & FORECAST ============ --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Breakdown per jenis (horizontal bar) --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.chart-pie class="size-5 text-blue-600" />
                    <div>
                        <flux:heading size="lg">{{ __('Cash In per Jenis') }}</flux:heading>
                        <flux:subheading>{{ __('Distribusi realisasi selama') }} {{ $this->periodLabel() }}.</flux:subheading>
                    </div>
                </div>

                @php
                    $jenisMap = [
                        'bf' => ['label' => 'BF (UTJ)', 'color' => 'purple'],
                        'um' => ['label' => 'UM (Uang Muka)', 'color' => 'emerald'],
                        'sbum' => ['label' => 'SBUM (Subsidi)', 'color' => 'amber'],
                    ];
                @endphp
                @if ($cashByJenisTotal > 0)
                    <div class="space-y-3">
                        @foreach ($jenisMap as $key => $meta)
                            @php
                                $data = $cashByJenis->get($key);
                                $total = (float) ($data->total ?? 0);
                                $cnt = (int) ($data->cnt ?? 0);
                                $pct = $cashByJenisTotal > 0 ? round(($total / $cashByJenisTotal) * 100, 1) : 0;
                            @endphp
                            @if ($total > 0)
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="font-semibold text-{{ $meta['color'] }}-700">{{ $meta['label'] }}</span>
                                        <span class="tabular-nums">
                                            <span class="font-bold">{{ $fmtJt($total) }}</span>
                                            <span class="ms-1 text-[10px] text-zinc-500">({{ $pct }}% · {{ $cnt }}×)</span>
                                        </span>
                                    </div>
                                    <div class="h-3 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                                        <div class="h-full bg-{{ $meta['color'] }}-500 transition-all" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center text-sm text-zinc-500">{{ __('Belum ada realisasi periode ini.') }}</div>
                @endif
            </div>

            {{-- Piutang & Forecast --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.banknotes class="size-5 text-amber-600" />
                    <div>
                        <flux:heading size="lg">{{ __('Piutang & Forecast') }}</flux:heading>
                        <flux:subheading>{{ __('Outstanding + jadwal termin akan jatuh tempo.') }}</flux:subheading>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                        <div class="text-[10px] font-bold uppercase text-zinc-600">{{ __('Total UM Aktif') }}</div>
                        <div class="mt-1 text-lg font-bold tabular-nums">{{ $fmtRp($totalUmNet) }}</div>
                    </div>
                    <div class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/30">
                        <div class="text-[10px] font-bold uppercase text-emerald-700">{{ __('Sudah Dibayar') }}</div>
                        <div class="mt-1 text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ $fmtRp($totalUmPaid) }}</div>
                    </div>
                    <div class="rounded-lg bg-rose-50 p-3 dark:bg-rose-950/30">
                        <div class="text-[10px] font-bold uppercase text-rose-700">{{ __('Outstanding') }}</div>
                        <div class="mt-1 text-lg font-bold tabular-nums text-rose-700 dark:text-rose-300">{{ $fmtRp($outstandingUm) }}</div>
                    </div>
                    <div class="rounded-lg bg-purple-50 p-3 dark:bg-purple-950/30">
                        <div class="text-[10px] font-bold uppercase text-purple-700">{{ __('Forecast 30 Hari') }}</div>
                        <div class="mt-1 text-lg font-bold tabular-nums text-purple-700 dark:text-purple-300">{{ $fmtRp($forecast30d) }}</div>
                        <div class="mt-0.5 text-[10px] text-purple-700/70">7 hr: {{ $fmtJt($forecast7d) }}</div>
                    </div>
                </div>

                @if ($overdueTermins > 0)
                    <div class="mt-3 flex items-start gap-2 rounded-lg border border-rose-300 bg-rose-50 p-3 dark:border-rose-800 dark:bg-rose-950/30">
                        <flux:icon.exclamation-triangle class="size-4 shrink-0 text-rose-600" />
                        <div class="flex-1 text-xs">
                            <div class="font-bold text-rose-900 dark:text-rose-200">{{ $overdueTermins }} termin lewat jatuh tempo</div>
                            <div class="text-rose-700 dark:text-rose-300">Total: <span class="font-bold">{{ $fmtRp($overdueAmount) }}</span></div>
                        </div>
                    </div>
                @endif

                @if ($refundPending > 0)
                    <div class="mt-2 flex items-start gap-2 rounded-lg border border-orange-300 bg-orange-50 p-3 dark:border-orange-800 dark:bg-orange-950/30">
                        <flux:icon.arrow-uturn-left class="size-4 shrink-0 text-orange-600" />
                        <div class="flex-1 text-xs">
                            <div class="font-bold text-orange-900 dark:text-orange-200">{{ $refundPending }} refund menunggu proses</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- ============ OUTSTANDING PER PROYEK — comparison bar ============ --}}
        @if ($outstandingPerProyek->isNotEmpty())
            <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.building-office-2 class="size-5 text-blue-600" />
                    <div>
                        <flux:heading size="lg">{{ __('Piutang per Proyek') }}</flux:heading>
                        <flux:subheading>{{ __('Total UM Net vs Sudah Dibayar (SPR aktif).') }}</flux:subheading>
                    </div>
                </div>

                <div class="space-y-4">
                    @foreach ($outstandingPerProyek as $p)
                        @php
                            $umBarW = $outstandingProyekMax > 0 ? round(($p['um_net'] / $outstandingProyekMax) * 100, 1) : 0;
                            $paidBarW = $p['um_net'] > 0 ? round(($p['paid'] / $p['um_net']) * 100, 1) : 0;
                        @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold">{{ $p['nama'] }}</span>
                                    <span class="rounded-full bg-{{ $p['paid_pct'] >= 80 ? 'emerald' : ($p['paid_pct'] >= 50 ? 'amber' : 'rose') }}-100 px-1.5 py-0.5 text-[10px] font-bold text-{{ $p['paid_pct'] >= 80 ? 'emerald' : ($p['paid_pct'] >= 50 ? 'amber' : 'rose') }}-700">
                                        {{ $p['paid_pct'] }}% tertagih
                                    </span>
                                </div>
                                <span class="tabular-nums text-[11px]">
                                    <span class="font-bold text-emerald-700">{{ $fmtJt($p['paid']) }}</span>
                                    <span class="text-zinc-400"> / </span>
                                    <span class="font-bold">{{ $fmtJt($p['um_net']) }}</span>
                                </span>
                            </div>
                            <div class="relative h-6 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                                {{-- Bar full UM (background) --}}
                                <div class="absolute inset-y-0 left-0 bg-zinc-300/60 dark:bg-zinc-700" style="width: {{ $umBarW }}%"></div>
                                {{-- Bar paid (foreground) --}}
                                <div class="absolute inset-y-0 left-0 bg-emerald-500 transition-all" style="width: {{ round($umBarW * $paidBarW / 100, 1) }}%"></div>
                            </div>
                            <div class="mt-1 flex justify-between text-[10px] text-zinc-500">
                                <span>Outstanding: <span class="font-bold text-rose-700">{{ $fmtRp($p['outstanding']) }}</span></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============ RECENT REALISASI ============ --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start gap-3">
                <flux:icon.receipt-percent class="size-5 text-emerald-600" />
                <div>
                    <flux:heading size="lg">{{ __('Realisasi Terbaru') }}</flux:heading>
                    <flux:subheading>{{ __('8 pembayaran terakhir yang tercatat.') }}</flux:subheading>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="border-b border-zinc-200 text-left uppercase text-[10px] tracking-wider text-zinc-500 dark:border-zinc-700">
                        <tr>
                            <th class="pb-2">Tanggal</th>
                            <th class="pb-2">Kuitansi</th>
                            <th class="pb-2">Jenis</th>
                            <th class="pb-2">Customer / SPR</th>
                            <th class="pb-2 text-right">Nominal</th>
                            <th class="pb-2">Input By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($recentRealisasi as $r)
                            <tr>
                                <td class="py-2 whitespace-nowrap">{{ $r->tanggal_bayar?->format('d/m/Y') }}</td>
                                <td class="py-2 font-mono text-[11px]">{{ $r->nomor_kwitansi ?? '—' }}</td>
                                <td class="py-2">
                                    <span @class([
                                        'rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase',
                                        'bg-purple-100 text-purple-700' => $r->jenis === 'bf',
                                        'bg-emerald-100 text-emerald-700' => $r->jenis === 'um',
                                        'bg-blue-100 text-blue-700' => ! in_array($r->jenis, ['bf', 'um']),
                                    ])>{{ strtoupper($r->jenis) }}</span>
                                </td>
                                <td class="py-2">
                                    <div class="text-[11px] font-semibold">{{ $r->spr?->prospectCustomer?->nama_lengkap ?? '—' }}</div>
                                    <div class="font-mono text-[10px] text-zinc-500">#{{ $r->spr?->nomor_display ?? '—' }}</div>
                                </td>
                                <td class="py-2 text-right font-mono font-bold tabular-nums">{{ $fmtRp($r->jumlah) }}</td>
                                <td class="py-2 text-[11px] text-zinc-500">{{ $r->inputBy?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-zinc-500">{{ __('Belum ada realisasi.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>
