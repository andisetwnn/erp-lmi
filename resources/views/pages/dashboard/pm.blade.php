<?php

use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard PM')] class extends Component
{
    #[Url(as: 'p')]
    public string $period = 'mtd';

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

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
            'mtd' => [now()->startOfMonth(), now()->endOfMonth()],
            'qtd' => [now()->subMonths(3)->startOfDay(), now()->endOfDay()],
            'ytd' => [now()->startOfYear(), now()->endOfYear()],
            default => [Carbon::create(2000, 1, 1), now()->endOfDay()],
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
            'mtd' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'qtd' => [now()->subMonths(6)->startOfDay(), now()->subMonths(3)->endOfDay()],
            'ytd' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            default => [Carbon::create(1990, 1, 1), Carbon::create(2000, 1, 1)],
        };
    }

    private function scoped($query)
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
        $pendingApproval = $this->scoped(Spr::query())
            ->where('status', 'approved')
            ->whereNull('pm_approved_at')
            ->count();

        // ============ APPROVALS METRIC PERIODE INI vs LALU ============
        $approvedCur = $this->scoped(Spr::query())
            ->whereNotNull('pm_approved_at')
            ->whereBetween('pm_approved_at', [$from, $to])
            ->count();
        $approvedPrev = $this->scoped(Spr::query())
            ->whereNotNull('pm_approved_at')
            ->whereBetween('pm_approved_at', [$prevFrom, $prevTo])
            ->count();
        $approvedGrowth = $approvedPrev > 0 ? round((($approvedCur - $approvedPrev) / $approvedPrev) * 100, 1) : ($approvedCur > 0 ? 100 : 0);

        $rejectedCur = $this->scoped(Spr::query())
            ->where('status', 'rejected')
            ->whereBetween('updated_at', [$from, $to])
            ->count();
        $rejectedPrev = $this->scoped(Spr::query())
            ->where('status', 'rejected')
            ->whereBetween('updated_at', [$prevFrom, $prevTo])
            ->count();
        $rejectedGrowth = $rejectedPrev > 0 ? round((($rejectedCur - $rejectedPrev) / $rejectedPrev) * 100, 1) : ($rejectedCur > 0 ? 100 : 0);

        // Rejection Rate (rejected / total processed)
        $totalProcessed = $approvedCur + $rejectedCur;
        $rejectionRate = $totalProcessed > 0 ? round(($rejectedCur / $totalProcessed) * 100, 1) : 0;

        // Avg time to approve (dari status='approved' sampai pm_approved_at)
        $approvedList = $this->scoped(Spr::query())
            ->whereNotNull('pm_approved_at')
            ->whereBetween('pm_approved_at', [$from, $to])
            ->whereNotNull('approved_at')
            ->get(['approved_at', 'pm_approved_at']);
        $totalHours = 0;
        $countApproved = $approvedList->count();
        foreach ($approvedList as $s) {
            $totalHours += $s->approved_at->diffInHours($s->pm_approved_at);
        }
        $avgApproveHours = $countApproved > 0 ? round($totalHours / $countApproved, 1) : 0;

        // ============ PIPELINE PER STAGE ============
        $stageCounts = [
            'utj_verify' => $this->scoped(Spr::query())->where('status', 'submitted')->count(),
            'pm_approve' => $this->scoped(Spr::query())->where('status', 'approved')->whereNull('pm_approved_at')->count(),
            'konsumen_ttd' => $this->scoped(Spr::query())->where('status', 'approved')->whereNotNull('pm_approved_at')->whereNull('konsumen_signed_at')->count(),
            'materai' => $this->scoped(Spr::query())->where('status', 'approved')->whereNotNull('konsumen_signed_at')->whereNull('materai_stamped_at')->count(),
            'final' => $this->scoped(Spr::query())->where('status', 'approved')->whereNotNull('materai_stamped_at')->count(),
        ];
        $pipelineTotal = array_sum($stageCounts);

        // ============ TREND 30 HARI (approval per hari) ============
        $trend30d = [];
        $trend30dQ = $this->scoped(Spr::query())
            ->whereNotNull('pm_approved_at')
            ->where('pm_approved_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(pm_approved_at) as tgl, COUNT(*) as cnt')
            ->groupBy('tgl')
            ->pluck('cnt', 'tgl');

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->format('Y-m-d');
            $trend30d[] = [
                'date' => $date,
                'count' => (int) ($trend30dQ[$key] ?? 0),
            ];
        }
        $trend30dMax = max(1, ...array_column($trend30d, 'count'));
        $trend30dTotal = array_sum(array_column($trend30d, 'count'));

        // ============ STOCK PER PROYEK ============
        $stockPerProyek = Proyek::query()
            ->when($this->filterProyek, fn ($q) => $q->where('id', $this->filterProyek))
            ->withCount([
                'rumah',
                'rumah as terjual_count' => fn ($q) => $q->where('status', 'terjual'),
                'rumah as available_count' => fn ($q) => $q->where('status', 'available'),
                'rumah as booking_count' => fn ($q) => $q->where('status', 'booking'),
            ])
            ->orderByDesc('rumah_count')->get();

        // ============ RECENT PENDING APPROVAL ============
        $recentPending = $this->scoped(Spr::query())
            ->with(['prospectCustomer:id,nama_lengkap', 'sales:id,nama', 'rumah:id,blok,nomor_unit,proyek_id', 'rumah.proyek:id,nama_proyek'])
            ->where('status', 'approved')
            ->whereNull('pm_approved_at')
            ->orderByDesc('approved_at')
            ->limit(5)
            ->get();

        // ============ TOP SALES PERIODE ============
        $topSales = Sales::query()
            ->select('sales.id', 'sales.kode', 'sales.nama')
            ->selectRaw('COUNT(spr.id) as spr_count')
            ->selectRaw('COALESCE(SUM(spr.total_harga), 0) as spr_nilai')
            ->leftJoin('spr', function ($j) use ($from, $to) {
                $j->on('spr.sales_id', '=', 'sales.id')
                    ->whereNotNull('spr.pm_approved_at')
                    ->whereBetween('spr.pm_approved_at', [$from, $to]);
                if ($this->filterProyek) {
                    $j->leftJoin('rumah', 'rumah.id', '=', 'spr.rumah_id')
                        ->where('rumah.proyek_id', $this->filterProyek);
                }
            })
            ->groupBy('sales.id', 'sales.kode', 'sales.nama')
            ->orderByDesc('spr_nilai')
            ->limit(5)->get();
        $topSalesMax = (float) ($topSales->max('spr_nilai') ?: 1);

        return compact(
            'proyekAktif',
            'pendingApproval',
            'approvedCur', 'approvedPrev', 'approvedGrowth',
            'rejectedCur', 'rejectedPrev', 'rejectedGrowth',
            'rejectionRate', 'avgApproveHours', 'countApproved',
            'stageCounts', 'pipelineTotal',
            'trend30d', 'trend30dMax', 'trend30dTotal',
            'stockPerProyek',
            'recentPending',
            'topSales', 'topSalesMax',
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
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER + FILTER PERIODE --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Dashboard Project Manager') }}</flux:heading>
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
                                    'bg-violet-600 text-white shadow' => $active,
                                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $active,
                                ])>{{ $lbl }}</button>
                    @endforeach
                </div>

                @if ($period === 'custom')
                    <div class="inline-flex items-center gap-1.5 rounded-lg border border-violet-300 bg-violet-50 p-1.5 dark:border-violet-700 dark:bg-violet-950/30">
                        <input type="date" wire:model.live="dateFrom" max="{{ now()->format('Y-m-d') }}"
                               class="h-7 rounded border border-zinc-200 bg-white px-2 text-xs shadow-sm focus:border-violet-500 focus:outline-none focus:ring-1 focus:ring-violet-500 dark:border-zinc-700 dark:bg-zinc-900" />
                        <span class="text-xs text-violet-700">—</span>
                        <input type="date" wire:model.live="dateTo" max="{{ now()->format('Y-m-d') }}"
                               class="h-7 rounded border border-zinc-200 bg-white px-2 text-xs shadow-sm focus:border-violet-500 focus:outline-none focus:ring-1 focus:ring-violet-500 dark:border-zinc-700 dark:bg-zinc-900" />
                    </div>
                @endif
            </div>
        </div>

        {{-- ============ ACTION CARD ============ --}}
        @if ($pendingApproval > 0)
            <a href="{{ route('approval.spr.index') }}" wire:navigate
               class="mb-4 flex items-center justify-between gap-4 rounded-2xl border-2 border-violet-400 bg-linear-to-r from-violet-500 to-purple-600 p-5 text-white shadow-lg transition hover:from-violet-600 hover:to-purple-700">
                <div class="flex items-center gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white/20">
                        <flux:icon.clipboard-document-check class="size-6" />
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider opacity-90">{{ __('Butuh Aksi Anda') }}</div>
                        <div class="text-2xl font-bold">{{ $pendingApproval }} SPR {{ __('menunggu persetujuan') }}</div>
                        <div class="mt-0.5 text-xs opacity-90">{{ __('Buka menu Persetujuan SPR untuk approve/reject') }}</div>
                    </div>
                </div>
                <flux:icon.arrow-right class="size-6 shrink-0" />
            </a>
        @else
            <div class="mb-4 flex items-center gap-4 rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-5 dark:border-emerald-800 dark:bg-emerald-950/30">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-white">
                    <flux:icon.check-badge class="size-6" />
                </div>
                <div>
                    <div class="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">{{ __('Bersih') }}</div>
                    <div class="text-lg font-bold text-emerald-900 dark:text-emerald-200">{{ __('Tidak ada SPR menunggu persetujuan Anda') }}</div>
                </div>
            </div>
        @endif

        {{-- ============ KPI CARDS (4) — dengan growth comparison ============ --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            {{-- Approved Cur --}}
            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-violet-500"></div>
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Disetujui') }}</div>
                        <div class="mt-2 text-3xl font-bold tabular-nums">{{ number_format($approvedCur) }}</div>
                    </div>
                    @php [$c, $arrow, $val] = $growthBadge($approvedGrowth); @endphp
                    <div class="rounded-md bg-{{ $c }}-100 px-2 py-1 text-xs font-bold text-{{ $c }}-700 dark:bg-{{ $c }}-950/50 dark:text-{{ $c }}-400">
                        <span class="mr-0.5">{{ $arrow }}</span>{{ $val }}%
                    </div>
                </div>
                <div class="mt-3 text-[10px] text-zinc-500">vs periode lalu ({{ number_format($approvedPrev) }})</div>
            </div>

            {{-- Rejected Cur --}}
            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-rose-500"></div>
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Ditolak') }}</div>
                        <div class="mt-2 text-3xl font-bold tabular-nums">{{ number_format($rejectedCur) }}</div>
                    </div>
                    @php
                        // Untuk rejection: growth NAIK = jelek, jadi warna dibalik
                        [$c, $arrow, $val] = $growthBadge($rejectedGrowth);
                        $c = $rejectedGrowth > 0 ? 'rose' : ($rejectedGrowth < 0 ? 'emerald' : 'zinc');
                    @endphp
                    <div class="rounded-md bg-{{ $c }}-100 px-2 py-1 text-xs font-bold text-{{ $c }}-700 dark:bg-{{ $c }}-950/50 dark:text-{{ $c }}-400">
                        <span class="mr-0.5">{{ $arrow }}</span>{{ $val }}%
                    </div>
                </div>
                <div class="mt-3 text-[10px] text-zinc-500">vs periode lalu ({{ number_format($rejectedPrev) }})</div>
            </div>

            {{-- Rejection Rate --}}
            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-amber-500"></div>
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Rejection Rate') }}</div>
                    <div class="mt-2 flex items-baseline gap-1.5">
                        <div class="text-3xl font-bold tabular-nums">{{ $rejectionRate }}<span class="text-lg text-zinc-500">%</span></div>
                    </div>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div class="h-full {{ $rejectionRate >= 10 ? 'bg-rose-500' : ($rejectionRate >= 5 ? 'bg-amber-500' : 'bg-emerald-500') }} transition-all" style="width: {{ min(100, $rejectionRate) }}%"></div>
                </div>
                <div class="mt-1 text-[10px] text-zinc-500">{{ $rejectedCur }} ditolak dari {{ $approvedCur + $rejectedCur }} diproses</div>
            </div>

            {{-- Avg Time to Approve --}}
            <div class="relative overflow-hidden rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="absolute inset-x-0 top-0 h-1 bg-blue-500"></div>
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Avg Time to Approve') }}</div>
                    <div class="mt-2 flex items-baseline gap-1.5">
                        <div class="text-3xl font-bold tabular-nums">{{ $avgApproveHours }}<span class="text-lg text-zinc-500">h</span></div>
                    </div>
                </div>
                <div class="mt-3 text-[10px] text-zinc-500">Dari {{ $countApproved }} SPR yg diapprove periode ini</div>
            </div>
        </div>

        {{-- ============ TREND 30 HARI BAR CHART ============ --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <flux:icon.chart-bar class="size-5 text-violet-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Aktivitas Approval — 30 Hari') }}</flux:heading>
                            <x-info-button title="Aktivitas Approval">
                                <p>Grafik batang menunjukkan berapa SPR yang Anda approve per hari dalam 30 hari terakhir.</p>
                                <ul class="mt-3 space-y-1 list-disc pl-5">
                                    <li><strong>Bar ungu tua</strong> = hari ini</li>
                                    <li><strong>Bar ungu muda</strong> = hari sebelumnya</li>
                                </ul>
                                <p class="mt-3"><strong>Cara pakai:</strong> hover bar untuk detail. Gunakan untuk lihat konsistensi kerja Anda + identifikasi hari-hari padat approval.</p>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('SPR yang Anda approve per hari.') }}</flux:subheading>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total 30 Hari</div>
                    <div class="text-lg font-bold tabular-nums text-violet-700">{{ number_format($trend30dTotal) }} SPR</div>
                </div>
            </div>

            @if ($trend30dTotal === 0)
                <div class="py-12 text-center text-sm text-zinc-500">{{ __('Belum ada approval dalam 30 hari terakhir.') }}</div>
            @else
                @php
                    $svgW = 1000; $svgH = 180; $padBottom = 26; $chartH = $svgH - $padBottom;
                    $n = count($trend30d); $gap = 3;
                    $barW = ($svgW - ($gap * ($n - 1))) / $n;
                @endphp
                <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" class="w-full" style="height: 180px;" preserveAspectRatio="none">
                    @for ($g = 1; $g <= 4; $g++)
                        <line x1="0" y1="{{ ($chartH / 4) * $g }}" x2="{{ $svgW }}" y2="{{ ($chartH / 4) * $g }}"
                              stroke="currentColor" stroke-opacity="0.08" stroke-width="1" class="text-zinc-900" />
                    @endfor
                    @foreach ($trend30d as $idx => $t)
                        @php
                            $h = $trend30dMax > 0 ? max(2, round(($t['count'] / $trend30dMax) * ($chartH - 4))) : 0;
                            $x = $idx * ($barW + $gap);
                            $y = $chartH - $h;
                            $isToday = $t['date']->isToday();
                            $fill = $isToday ? '#7c3aed' : '#a78bfa';
                        @endphp
                        <g>
                            <title>{{ $t['date']->translatedFormat('l, d M Y') }} — {{ $t['count'] }} SPR approved</title>
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $h }}" fill="{{ $fill }}" rx="2" ry="2" />
                        </g>
                        @if ($idx % 5 === 0 || $isToday)
                            <text x="{{ $x + $barW / 2 }}" y="{{ $svgH - 6 }}" text-anchor="middle" font-size="10"
                                  font-weight="{{ $isToday ? '700' : '400' }}"
                                  fill="currentColor" fill-opacity="{{ $isToday ? '1' : '0.5' }}"
                                  class="{{ $isToday ? 'text-violet-700' : 'text-zinc-500' }}">
                                {{ $t['date']->format('d/m') }}
                            </text>
                        @endif
                    @endforeach
                </svg>
            @endif
        </div>

        {{-- ============ PIPELINE SPR — 5 STAGE dengan progress bar horizontal ============ --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start justify-between">
                <div class="flex items-start gap-3">
                    <flux:icon.arrow-trending-up class="size-5 text-indigo-600" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Pipeline SPR — Distribusi per Tahap') }}</flux:heading>
                            <x-info-button title="Pipeline SPR">
                                <p>Semua SPR aktif dikelompokkan sesuai tahap prosesnya.</p>
                                <div class="mt-3">
                                    <div class="mb-1 font-bold">5 Tahap:</div>
                                    <ol class="space-y-1 list-decimal pl-5">
                                        <li>Verifikasi UTJ oleh Keuangan</li>
                                        <li>Approval oleh PM (Anda)</li>
                                        <li>Konsumen tanda tangan digital</li>
                                        <li>Keuangan tempel e-Materai</li>
                                        <li>Selesai</li>
                                    </ol>
                                </div>
                                <p class="mt-3">Bar panjang di tahap 2 = SPR menunggu Anda approve. Bar panjang di tahap lain = bottleneck di role lain.</p>
                            </x-info-button>
                        </div>
                        <flux:subheading>{{ __('SPR aktif di seluruh sistem, dikelompokkan per stage.') }}</flux:subheading>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total Aktif</div>
                    <div class="text-lg font-bold tabular-nums">{{ number_format($pipelineTotal) }}</div>
                </div>
            </div>

            @php
                $stages = [
                    ['key' => 'utj_verify', 'label' => 'Verifikasi UTJ', 'color' => 'amber', 'icon' => 'banknotes'],
                    ['key' => 'pm_approve', 'label' => 'Approval PM', 'color' => 'violet', 'icon' => 'shield-check'],
                    ['key' => 'konsumen_ttd', 'label' => 'TTD Konsumen', 'color' => 'teal', 'icon' => 'pencil-square'],
                    ['key' => 'materai', 'label' => 'e-Materai', 'color' => 'purple', 'icon' => 'document-check'],
                    ['key' => 'final', 'label' => 'Selesai', 'color' => 'emerald', 'icon' => 'trophy'],
                ];
                $stageMax = max(1, ...array_values($stageCounts));
            @endphp
            <div class="space-y-2.5">
                @foreach ($stages as $s)
                    @php
                        $cnt = $stageCounts[$s['key']];
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

        {{-- ============ RECENT PENDING APPROVAL + TOP SALES ============ --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <flux:icon.clipboard-document-list class="size-5 text-violet-600" />
                        <div>
                            <flux:heading size="lg">{{ __('Menunggu Approval') }}</flux:heading>
                            <flux:subheading>{{ __('5 SPR terbaru siap Anda review.') }}</flux:subheading>
                        </div>
                    </div>
                    @if ($pendingApproval > 5)
                        <a href="{{ route('approval.spr.index') }}" wire:navigate
                           class="text-xs font-semibold text-violet-600 hover:text-violet-700">
                            {{ __('Lihat semua') }} ({{ $pendingApproval }}) →
                        </a>
                    @endif
                </div>

                @forelse ($recentPending as $spr)
                    <a href="{{ route('approval.spr.show', $spr->id) }}" wire:navigate
                       class="mb-2 flex items-center gap-3 rounded-lg border border-zinc-100 bg-zinc-50 p-3 transition hover:border-violet-300 hover:bg-violet-50 dark:border-zinc-800 dark:bg-zinc-800/50 dark:hover:border-violet-800 dark:hover:bg-violet-950/20">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs font-bold text-violet-700 dark:text-violet-400">#{{ $spr->nomor_display }}</span>
                                <span class="truncate text-sm font-semibold">{{ $spr->prospectCustomer?->nama_lengkap ?? '—' }}</span>
                            </div>
                            <div class="mt-0.5 text-[11px] text-zinc-500">
                                {{ $spr->rumah?->proyek?->nama_proyek ?? '—' }} · {{ $spr->rumah?->blok }}-{{ $spr->rumah?->nomor_unit }} · Sales: {{ $spr->sales?->nama }}
                            </div>
                        </div>
                        <flux:icon.arrow-right class="size-4 shrink-0 text-zinc-400" />
                    </a>
                @empty
                    <div class="py-8 text-center text-sm text-zinc-500">{{ __('Tidak ada SPR menunggu approval.') }}</div>
                @endforelse
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start gap-3">
                    <flux:icon.trophy class="size-5 text-amber-600" />
                    <div>
                        <flux:heading size="lg">{{ __('Top Sales') }} · {{ $this->periodLabel() }}</flux:heading>
                        <flux:subheading>{{ __('By total nilai SPR disetujui.') }}</flux:subheading>
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
                                <div class="flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold {{ $rankBg }}">{{ $idx + 1 }}</div>
                                <div class="min-w-0 flex-1 truncate text-sm font-semibold">{{ $s->nama }}</div>
                                <div class="text-right">
                                    <div class="text-sm font-bold tabular-nums">Rp {{ $fmtJt($s->spr_nilai) }}</div>
                                    <div class="text-[10px] tabular-nums text-zinc-500">{{ $s->spr_count }} unit</div>
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

        {{-- ============ STOCK PER PROYEK ============ --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-start gap-3">
                <flux:icon.building-office-2 class="size-5 text-blue-600" />
                <div>
                    <flux:heading size="lg">{{ __('Stock per Proyek') }}</flux:heading>
                </div>
            </div>

            @forelse ($stockPerProyek as $p)
                @php
                    $total = max(1, $p->rumah_count);
                    $segs = [
                        ['count' => $p->terjual_count, 'label' => 'Terjual', 'color' => 'bg-emerald-500'],
                        ['count' => $p->booking_count, 'label' => 'Booking', 'color' => 'bg-amber-400'],
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
                                     style="width: {{ round(($s['count'] / $total) * 100, 2) }}%">
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

    </div>
</section>
