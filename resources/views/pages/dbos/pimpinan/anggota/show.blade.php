<?php

use App\Models\Master\Booking;
use App\Models\Master\PimpinanActivityLog;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Sales;
use App\Models\Master\SalesTarget;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Detail Anggota'), Layout('layouts.pimpinan')] class extends Component {
    public int $id;

    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    // Target form state
    public int $targetProspect = 0;

    public int $targetBooking = 0;

    public function mount(int $id): void
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $anggota = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->find($id);

        abort_unless($anggota, 404);

        $this->id = $anggota->id;

        // Load target bulan ini ke form
        $target = $anggota->targetForPeriode(SalesTarget::currentPeriode());
        if ($target) {
            $this->targetProspect = $target->target_prospect;
            $this->targetBooking = $target->target_booking;
        }
    }

    public function saveTarget(): void
    {
        $this->validate([
            'targetProspect' => ['integer', 'min:0', 'max:1000'],
            'targetBooking' => ['integer', 'min:0', 'max:1000'],
        ]);

        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        // Defensive: pastikan anggota ini benar di grup pimpinan
        $anggota = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->findOrFail($this->id);

        SalesTarget::updateOrCreate(
            [
                'sales_id' => $anggota->id,
                'periode' => SalesTarget::currentPeriode(),
            ],
            [
                'target_prospect' => $this->targetProspect,
                'target_booking' => $this->targetBooking,
                'set_by_sales_id' => $pimpinan->id,
            ],
        );

        PimpinanActivityLog::log(
            $pimpinan->id,
            'set_target',
            $anggota->nama,
            [
                'sales_id' => $anggota->id,
                'periode' => SalesTarget::currentPeriode(),
                'target_prospect' => $this->targetProspect,
                'target_booking' => $this->targetBooking,
            ],
        );

        Flux::toast(variant: 'success', text: 'Target bulan ini berhasil disimpan.');
    }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $anggota = Sales::with(['jenisSales', 'bank'])
            ->where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->findOrFail($this->id);

        $prospectByStatus = ProspectCustomer::where('sales_id', $anggota->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $statProspect = [
            'cold' => $prospectByStatus['cold'] ?? 0,
            'warm' => $prospectByStatus['warm'] ?? 0,
            'hot' => $prospectByStatus['hot'] ?? 0,
            'finish' => $prospectByStatus['finish'] ?? 0,
            'archive' => $prospectByStatus['archive'] ?? 0,
        ];
        $statProspect['total_aktif'] = $statProspect['cold'] + $statProspect['warm'] + $statProspect['hot'] + $statProspect['finish'];

        $bookingByStatus = Booking::where('sales_id', $anggota->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $statBooking = [
            'aktif' => $bookingByStatus['aktif'] ?? 0,
            'sukses' => $bookingByStatus['sukses'] ?? 0,
            'akad' => $bookingByStatus['akad'] ?? 0,
            'batal' => $bookingByStatus['batal'] ?? 0,
        ];

        // Progress vs target bulan ini
        $monthStart = now()->startOfMonth();
        $prospectBulanIni = ProspectCustomer::where('sales_id', $anggota->id)
            ->where('created_at', '>=', $monthStart)->count();
        $bookingBulanIni = Booking::where('sales_id', $anggota->id)
            ->where('created_at', '>=', $monthStart)->count();

        $target = $anggota->targetForPeriode(SalesTarget::currentPeriode());

        // ============= TIME-TO-CONVERSION (anggota ini) =============
        $ttcRows = Booking::where('sales_id', $anggota->id)
            ->with('prospectCustomer:id,created_at')
            ->get(['id', 'created_at', 'prospect_customer_id'])
            ->map(function ($b) {
                if (! $b->prospectCustomer || ! $b->prospectCustomer->created_at) return null;
                return $b->prospectCustomer->created_at->diffInDays($b->created_at);
            })->filter()->values();
        $ttcAvg = $ttcRows->isNotEmpty() ? round($ttcRows->avg(), 1) : null;
        $ttcMin = $ttcRows->isNotEmpty() ? (int) $ttcRows->min() : null;
        $ttcMax = $ttcRows->isNotEmpty() ? (int) $ttcRows->max() : null;

        // ============= GOAL VS ACTUAL CHART (daily cumulative this month) =============
        $daysInMonth = now()->daysInMonth;
        $todayDay = (int) now()->format('j');

        // Counts harian
        $prospectDaily = ProspectCustomer::where('sales_id', $anggota->id)
            ->where('created_at', '>=', $monthStart)
            ->get(['created_at'])
            ->groupBy(fn ($r) => (int) $r->created_at->format('j'))
            ->map(fn ($g) => $g->count());
        $bookingDaily = Booking::where('sales_id', $anggota->id)
            ->where('created_at', '>=', $monthStart)
            ->get(['created_at'])
            ->groupBy(fn ($r) => (int) $r->created_at->format('j'))
            ->map(fn ($g) => $g->count());

        $prospectCum = [];
        $bookingCum = [];
        $accP = 0;
        $accB = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $accP += $prospectDaily->get($d, 0);
            $accB += $bookingDaily->get($d, 0);
            $prospectCum[$d] = $accP;
            $bookingCum[$d] = $accB;
        }

        $goalChart = [
            'days_in_month' => $daysInMonth,
            'today_day' => $todayDay,
            'prospect_cum' => $prospectCum,
            'booking_cum' => $bookingCum,
            'target_prospect' => $target?->target_prospect ?? 0,
            'target_booking' => $target?->target_booking ?? 0,
        ];

        $prospectList = null;
        $bookingList = null;
        $activityLog = null;
        $heatmap = null;

        if ($this->tab === 'prospect') {
            $prospectList = ProspectCustomer::where('sales_id', $anggota->id)
                ->with('proyek:id,nama_proyek')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        } elseif ($this->tab === 'booking') {
            $bookingList = Booking::where('sales_id', $anggota->id)
                ->with(['proyek:id,nama_proyek', 'rumah.tipeRumah', 'prospectCustomer:id,nama_lengkap'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        } elseif ($this->tab === 'aktivitas') {
            $activityLog = ProspectCustomerStatusLog::where('changed_by_sales_id', $anggota->id)
                ->with('prospectCustomer:id,nama_lengkap')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        } elseif ($this->tab === 'heatmap') {
            $heatmap = $this->buildHeatmap($anggota->id, 30);
        }

        $initials = collect(explode(' ', $anggota->nama))
            ->take(2)
            ->map(fn ($w) => mb_substr($w, 0, 1))
            ->implode('');

        $lastActivity = ProspectCustomerStatusLog::where('changed_by_sales_id', $anggota->id)
            ->orderByDesc('created_at')
            ->value('created_at');
        $lastActivityAt = $lastActivity ? Carbon::parse($lastActivity) : null;

        $waLink = $anggota->telepon
            ? 'https://wa.me/'.preg_replace('/[^0-9]/', '', $anggota->telepon).'?text='.urlencode("Halo {$anggota->nama}, ada hal yang perlu didiskusikan terkait pekerjaan. Bisa di-cek dashboard DBOS kamu ya. Terima kasih.")
            : null;

        return compact(
            'anggota',
            'initials',
            'statProspect',
            'statBooking',
            'prospectList',
            'bookingList',
            'activityLog',
            'heatmap',
            'lastActivityAt',
            'prospectBulanIni',
            'bookingBulanIni',
            'target',
            'waLink',
            'ttcAvg',
            'ttcMin',
            'ttcMax',
            'goalChart',
        );
    }

    /** Build heatmap matrix: array of [date => count] for last N days. */
    private function buildHeatmap(int $salesId, int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        // Pakai prospect_customer.created_at (creation) + status_log.created_at (interaksi)
        $prospectByDay = ProspectCustomer::where('sales_id', $salesId)
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at'])
            ->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))
            ->map(fn ($g) => $g->count());

        $logByDay = ProspectCustomerStatusLog::where('changed_by_sales_id', $salesId)
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at'])
            ->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))
            ->map(fn ($g) => $g->count());

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $result[$date] = (int) ($prospectByDay->get($date, 0) + $logByDay->get($date, 0));
        }

        return $result;
    }
}; ?>

<div>
    {{-- BREADCRUMB --}}
    <div class="mb-4 flex items-center gap-2 text-sm">
        <a href="{{ route('dbos.pimpinan.anggota.index') }}" wire:navigate
           class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
            {{ __('Anggota Grup') }}
        </a>
        <flux:icon.chevron-right class="size-3.5 text-zinc-400" />
        <span class="font-semibold text-zinc-900 dark:text-white">{{ $anggota->nama }}</span>
    </div>

    {{-- HEADER --}}
    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-xl font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
            {{ $initials }}
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $anggota->nama }}</flux:heading>
                @if (! $anggota->is_aktif)
                    <span class="rounded-full bg-zinc-200 px-2 py-0.5 text-[10px] font-bold uppercase text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                        {{ __('Nonaktif') }}
                    </span>
                @endif
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-zinc-500">
                <span class="font-mono">#{{ $anggota->kode }}</span>
                @if ($anggota->jenisSales)
                    <span class="text-zinc-300">·</span>
                    <span>{{ $anggota->jenisSales->nama }}</span>
                @endif
                @if ($anggota->telepon)
                    <span class="text-zinc-300">·</span>
                    <a href="tel:{{ $anggota->telepon }}" class="text-blue-600 hover:underline dark:text-blue-400">{{ $anggota->telepon }}</a>
                @endif
            </div>
        </div>
        @if ($waLink)
            <a href="{{ $waLink }}" target="_blank"
               class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-green-600 px-4 text-sm font-semibold text-white shadow transition hover:bg-green-700 active:scale-95">
                <flux:icon.phone class="size-4" />
                {{ __('Tegur via WA') }}
            </a>
        @endif
    </div>

    {{-- KPI STRIP --}}
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Prospect') }}</div>
            <div class="mt-1 text-2xl font-bold text-orange-600 dark:text-orange-400">{{ $statProspect['total_aktif'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Booking') }}</div>
            <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $statBooking['aktif'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs uppercase tracking-wider text-zinc-500">{{ __('SPR') }}</div>
            <div class="mt-1 text-2xl font-bold text-purple-600 dark:text-purple-400">{{ $statBooking['sukses'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Akad') }}</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $statBooking['akad'] }}</div>
        </div>
    </div>

    {{-- TARGET PROGRESS (kalau ada) --}}
    @if ($target && ($target->target_prospect > 0 || $target->target_booking > 0))
        @php
            $pPct = $target->target_prospect > 0 ? min(100, round(($prospectBulanIni / $target->target_prospect) * 100)) : 0;
            $bPct = $target->target_booking > 0 ? min(100, round(($bookingBulanIni / $target->target_booking) * 100)) : 0;
        @endphp
        <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">
                {{ __('Target') }} {{ now()->translatedFormat('F Y') }}
            </h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($target->target_prospect > 0)
                    <div>
                        <div class="flex items-end justify-between">
                            <div>
                                <span class="text-xs text-zinc-500">{{ __('Prospect') }}</span>
                                <div class="text-2xl font-bold text-zinc-900 dark:text-white">
                                    {{ $prospectBulanIni }}<span class="text-base text-zinc-400">/{{ $target->target_prospect }}</span>
                                </div>
                            </div>
                            <span @class([
                                'text-lg font-bold',
                                'text-emerald-600 dark:text-emerald-400' => $pPct >= 100,
                                'text-amber-600 dark:text-amber-400' => $pPct >= 50 && $pPct < 100,
                                'text-rose-500 dark:text-rose-400' => $pPct < 50,
                            ])>{{ $pPct }}%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div @class([
                                'h-full transition-all',
                                'bg-emerald-500' => $pPct >= 100,
                                'bg-amber-500' => $pPct >= 50 && $pPct < 100,
                                'bg-rose-400' => $pPct < 50,
                            ]) style="width: {{ $pPct }}%"></div>
                        </div>
                    </div>
                @endif
                @if ($target->target_booking > 0)
                    <div>
                        <div class="flex items-end justify-between">
                            <div>
                                <span class="text-xs text-zinc-500">{{ __('Booking') }}</span>
                                <div class="text-2xl font-bold text-zinc-900 dark:text-white">
                                    {{ $bookingBulanIni }}<span class="text-base text-zinc-400">/{{ $target->target_booking }}</span>
                                </div>
                            </div>
                            <span @class([
                                'text-lg font-bold',
                                'text-emerald-600 dark:text-emerald-400' => $bPct >= 100,
                                'text-amber-600 dark:text-amber-400' => $bPct >= 50 && $bPct < 100,
                                'text-rose-500 dark:text-rose-400' => $bPct < 50,
                            ])>{{ $bPct }}%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div @class([
                                'h-full transition-all',
                                'bg-emerald-500' => $bPct >= 100,
                                'bg-amber-500' => $bPct >= 50 && $bPct < 100,
                                'bg-rose-400' => $bPct < 50,
                            ]) style="width: {{ $bPct }}%"></div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- TABS --}}
    @php
        $tabs = [
            'overview' => __('Ringkasan'),
            'target' => __('Set Target'),
            'heatmap' => __('Heatmap'),
            'prospect' => __('Prospect'),
            'booking' => __('Booking'),
            'aktivitas' => __('Aktivitas'),
        ];
    @endphp
    <div class="mb-4 border-b border-zinc-200 dark:border-zinc-700">
        <div class="flex gap-1 overflow-x-auto">
            @foreach ($tabs as $key => $label)
                @php $active = $tab === $key; @endphp
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                        @class([
                            '-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-semibold transition',
                            'border-amber-600 text-amber-700 dark:text-amber-300' => $active,
                            'border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-white' => ! $active,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($tab === 'overview')
        {{-- TIME TO CONVERSION CARD --}}
        @if ($ttcAvg !== null)
            <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2">
                    <flux:icon.bolt class="size-4 text-blue-500" />
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Kecepatan Closing') }}</span>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $ttcAvg }}</div>
                        <div class="text-[10px] uppercase tracking-wider text-zinc-500">{{ __('rata-rata hari') }}</div>
                    </div>
                    <div class="border-x border-zinc-100 dark:border-zinc-800">
                        <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $ttcMin }}</div>
                        <div class="text-[10px] uppercase tracking-wider text-zinc-500">{{ __('tercepat') }}</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-rose-500 dark:text-rose-400">{{ $ttcMax }}</div>
                        <div class="text-[10px] uppercase tracking-wider text-zinc-500">{{ __('terlama') }}</div>
                    </div>
                </div>
                <p class="mt-3 text-[11px] text-zinc-500">
                    {{ __('Diukur dari saat prospect dibuat sampai booking pertama.') }}
                </p>
            </div>
        @endif

        {{-- GOAL VS ACTUAL CHART --}}
        @if ($goalChart['target_prospect'] > 0 || $goalChart['target_booking'] > 0)
            @php
                $chartW = 600;
                $chartH = 180;
                $padL = 30;
                $padR = 20;
                $padT = 15;
                $padB = 25;
                $plotW = $chartW - $padL - $padR;
                $plotH = $chartH - $padT - $padB;
                $D = $goalChart['days_in_month'];
                $today = $goalChart['today_day'];

                $maxY = max(
                    $goalChart['target_prospect'],
                    $goalChart['target_booking'],
                    max($goalChart['prospect_cum']),
                    max($goalChart['booking_cum']),
                    1,
                );

                $xFor = fn ($day) => $padL + (($day - 1) / max(1, $D - 1)) * $plotW;
                $yFor = fn ($val) => $padT + $plotH - (($val / $maxY) * $plotH);

                $buildPath = function ($cumByDay) use ($D, $today, $xFor, $yFor) {
                    $points = [];
                    for ($d = 1; $d <= $today; $d++) {
                        $points[] = round($xFor($d), 2).','.round($yFor($cumByDay[$d] ?? 0), 2);
                    }
                    return implode(' L ', $points);
                };

                $prosPath = $buildPath($goalChart['prospect_cum']);
                $bookPath = $buildPath($goalChart['booking_cum']);

                $targetPLineY = $goalChart['target_prospect'] > 0 ? $yFor($goalChart['target_prospect']) : null;
                $targetBLineY = $goalChart['target_booking'] > 0 ? $yFor($goalChart['target_booking']) : null;
            @endphp
            <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <flux:icon.chart-bar class="size-4 text-amber-500" />
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Goal vs Actual') }} — {{ now()->translatedFormat('F Y') }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-[10px]">
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-0.5 w-3 bg-orange-500"></span>{{ __('Prospect') }}</span>
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-0.5 w-3 bg-amber-500"></span>{{ __('Booking') }}</span>
                        <span class="inline-flex items-center gap-1"><span class="inline-block h-0.5 w-3 border-t border-dashed border-zinc-400"></span>{{ __('Target') }}</span>
                    </div>
                </div>
                <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" class="w-full" preserveAspectRatio="none" style="max-height: 220px">
                    {{-- Grid baseline --}}
                    <line x1="{{ $padL }}" y1="{{ $padT + $plotH }}" x2="{{ $chartW - $padR }}" y2="{{ $padT + $plotH }}" stroke="#e5e7eb" stroke-width="1" />
                    <line x1="{{ $padL }}" y1="{{ $padT }}" x2="{{ $padL }}" y2="{{ $padT + $plotH }}" stroke="#e5e7eb" stroke-width="1" />

                    {{-- Target lines --}}
                    @if ($targetPLineY)
                        <line x1="{{ $padL }}" y1="{{ $targetPLineY }}" x2="{{ $chartW - $padR }}" y2="{{ $targetPLineY }}"
                              stroke="#ea580c" stroke-width="1" stroke-dasharray="4,3" opacity="0.5" />
                        <text x="{{ $chartW - $padR - 4 }}" y="{{ $targetPLineY - 4 }}" font-size="9" fill="#ea580c" text-anchor="end">P:{{ $goalChart['target_prospect'] }}</text>
                    @endif
                    @if ($targetBLineY)
                        <line x1="{{ $padL }}" y1="{{ $targetBLineY }}" x2="{{ $chartW - $padR }}" y2="{{ $targetBLineY }}"
                              stroke="#d97706" stroke-width="1" stroke-dasharray="4,3" opacity="0.5" />
                        <text x="{{ $chartW - $padR - 4 }}" y="{{ $targetBLineY - 4 }}" font-size="9" fill="#d97706" text-anchor="end">B:{{ $goalChart['target_booking'] }}</text>
                    @endif

                    {{-- Actual line: Prospect --}}
                    @if ($prosPath && $today > 0)
                        <path d="M {{ $prosPath }}" fill="none" stroke="#ea580c" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    @endif
                    {{-- Actual line: Booking --}}
                    @if ($bookPath && $today > 0)
                        <path d="M {{ $bookPath }}" fill="none" stroke="#d97706" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    @endif

                    {{-- Today marker --}}
                    <line x1="{{ $xFor($today) }}" y1="{{ $padT }}" x2="{{ $xFor($today) }}" y2="{{ $padT + $plotH }}"
                          stroke="#22c55e" stroke-width="1" stroke-dasharray="2,2" opacity="0.7" />

                    {{-- X-axis labels --}}
                    <text x="{{ $padL }}" y="{{ $chartH - 8 }}" font-size="9" fill="#9ca3af">1</text>
                    <text x="{{ $xFor((int) ($D / 2)) }}" y="{{ $chartH - 8 }}" font-size="9" fill="#9ca3af" text-anchor="middle">{{ (int) ($D / 2) }}</text>
                    <text x="{{ $chartW - $padR }}" y="{{ $chartH - 8 }}" font-size="9" fill="#9ca3af" text-anchor="end">{{ $D }}</text>
                </svg>
                <p class="mt-2 text-[11px] text-zinc-500">
                    {{ __('Garis solid = aktual kumulatif sampai hari ke-:hari. Garis putus-putus = target bulanan.', ['hari' => $today]) }}
                </p>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-1">
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Profil') }}</h3>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Login DBOS terakhir') }}</dt>
                            <dd class="font-semibold text-zinc-900 dark:text-white">
                                @if ($anggota->last_login_at)
                                    {{ $anggota->last_login_at->translatedFormat('d M Y · H:i') }}
                                    <span class="text-xs font-normal text-zinc-500">· {{ $anggota->last_login_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-zinc-400">{{ __('Belum pernah login') }}</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Aktivitas input terakhir') }}</dt>
                            <dd class="font-semibold text-zinc-900 dark:text-white">
                                {{ $lastActivityAt?->translatedFormat('d M Y · H:i') ?? __('Belum ada aktivitas') }}
                                @if ($lastActivityAt)
                                    <span class="text-xs font-normal text-zinc-500">· {{ $lastActivityAt->diffForHumans() }}</span>
                                @endif
                            </dd>
                        </div>
                        @if ($anggota->bank)
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('Bank') }}</dt>
                                <dd class="font-semibold text-zinc-900 dark:text-white">{{ $anggota->bank->nama }}</dd>
                                <dd class="font-mono text-xs text-zinc-500">{{ $anggota->nomor_rekening ?? '—' }}</dd>
                            </div>
                        @endif
                        @if ($anggota->alamat)
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('Alamat') }}</dt>
                                <dd class="text-zinc-700 dark:text-zinc-300">{{ $anggota->alamat }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="space-y-4 lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Prospect per status') }}</span>
                    </div>
                    <div class="grid grid-cols-5 divide-x divide-zinc-100 dark:divide-zinc-800">
                        @foreach ([
                            ['Cold', $statProspect['cold'], 'text-blue-600 dark:text-blue-400'],
                            ['Warm', $statProspect['warm'], 'text-amber-600 dark:text-amber-400'],
                            ['Hot', $statProspect['hot'], 'text-red-600 dark:text-red-400'],
                            ['Finish', $statProspect['finish'], 'text-green-600 dark:text-green-400'],
                            ['Archive', $statProspect['archive'], 'text-stone-500 dark:text-stone-400'],
                        ] as [$lbl, $n, $clr])
                            <div class="px-2 py-5 text-center">
                                <div @class(['text-2xl font-bold', $clr])>{{ $n }}</div>
                                <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ $lbl }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Booking per status') }}</span>
                    </div>
                    <div class="grid grid-cols-4 divide-x divide-zinc-100 dark:divide-zinc-800">
                        @foreach ([
                            ['Aktif', $statBooking['aktif'], 'text-amber-600 dark:text-amber-400'],
                            ['Sukses', $statBooking['sukses'], 'text-purple-600 dark:text-purple-400'],
                            ['Akad', $statBooking['akad'], 'text-emerald-600 dark:text-emerald-400'],
                            ['Batal', $statBooking['batal'], 'text-rose-500 dark:text-rose-400'],
                        ] as [$lbl, $n, $clr])
                            <div class="px-2 py-5 text-center">
                                <div @class(['text-2xl font-bold', $clr])>{{ $n }}</div>
                                <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ $lbl }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

    @elseif ($tab === 'target')
        <div class="max-w-xl rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Set Target Bulan Ini') }}</h3>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __('Periode:') }} <span class="font-semibold">{{ now()->translatedFormat('F Y') }}</span>.
                {{ __('Set 0 untuk menonaktifkan target tertentu.') }}
            </p>

            <form wire:submit="saveTarget" class="mt-6 space-y-4">
                <flux:field>
                    <flux:label>{{ __('Target Prospect') }}</flux:label>
                    <flux:input wire:model="targetProspect" type="number" min="0" max="1000" />
                    <flux:description>{{ __('Jumlah prospect baru yang diharapkan bulan ini.') }}</flux:description>
                    <flux:error name="targetProspect" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Target Booking') }}</flux:label>
                    <flux:input wire:model="targetBooking" type="number" min="0" max="1000" />
                    <flux:description>{{ __('Jumlah booking baru yang diharapkan bulan ini.') }}</flux:description>
                    <flux:error name="targetBooking" />
                </flux:field>

                <div class="flex justify-end pt-2">
                    <flux:button type="submit" variant="primary" class="bg-amber-600! hover:bg-amber-700!">
                        {{ __('Simpan Target') }}
                    </flux:button>
                </div>
            </form>
        </div>

    @elseif ($tab === 'heatmap')
        @php
            $maxCount = max(array_values($heatmap)) ?: 1;
        @endphp
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">
                {{ __('Heatmap Aktivitas — 30 hari terakhir') }}
            </h3>
            <p class="mb-4 text-xs text-zinc-500">
                {{ __('Setiap kotak = jumlah aktivitas pada hari itu (input prospect + perubahan status).') }}
            </p>

            <div class="overflow-x-auto">
                <div class="grid grid-cols-30 gap-1.5 min-w-150">
                    @foreach ($heatmap as $date => $count)
                        @php
                            $intensity = $count === 0 ? 0 : (int) min(4, max(1, ceil(($count / $maxCount) * 4)));
                            $colorClass = match ($intensity) {
                                0 => 'bg-zinc-100 dark:bg-zinc-800',
                                1 => 'bg-amber-200 dark:bg-amber-900/50',
                                2 => 'bg-amber-400 dark:bg-amber-700',
                                3 => 'bg-amber-500 dark:bg-amber-600',
                                4 => 'bg-amber-600 dark:bg-amber-500',
                            };
                            $d = Carbon::parse($date);
                        @endphp
                        <div @class(['aspect-square rounded', $colorClass])
                             title="{{ $d->translatedFormat('d M Y') }}: {{ $count }} aktivitas">
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between text-[10px] text-zinc-500">
                <span>{{ Carbon::parse(array_key_first($heatmap))->translatedFormat('d M') }}</span>
                <div class="flex items-center gap-1.5">
                    <span>{{ __('sedikit') }}</span>
                    <div class="size-3 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                    <div class="size-3 rounded bg-amber-200 dark:bg-amber-900/50"></div>
                    <div class="size-3 rounded bg-amber-400 dark:bg-amber-700"></div>
                    <div class="size-3 rounded bg-amber-500 dark:bg-amber-600"></div>
                    <div class="size-3 rounded bg-amber-600 dark:bg-amber-500"></div>
                    <span>{{ __('banyak') }}</span>
                </div>
                <span>{{ Carbon::parse(array_key_last($heatmap))->translatedFormat('d M') }}</span>
            </div>
        </div>

    @elseif ($tab === 'prospect')
        @if ($prospectList->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.circle-stack class="mx-auto size-10 text-zinc-400" />
                <p class="mt-3 text-sm text-zinc-500">{{ __('Belum ada prospect.') }}</p>
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-xs uppercase tracking-wider text-zinc-500">
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Nama') }}</th>
                                <th class="px-4 py-3 text-left font-semibold">{{ __('HP') }}</th>
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Proyek') }}</th>
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Tanggal') }}</th>
                                <th class="px-4 py-3 text-right font-semibold">{{ __('Aksi') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($prospectList as $p)
                                @php
                                    $statusBadge = match ($p->status) {
                                        'cold' => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300', 'COLD'],
                                        'warm' => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                                        'hot' => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300', 'HOT'],
                                        'finish' => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                                        'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', 'ARCHIVE'],
                                    };
                                @endphp
                                <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                    <td class="px-4 py-3 font-semibold text-zinc-900 dark:text-white">{{ $p->nama_lengkap }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-green-600 dark:text-green-400">{{ $p->hp }}</td>
                                    <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $p->proyek?->nama_proyek ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $statusBadge[0]])>{{ $statusBadge[1] }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-zinc-500">{{ $p->created_at?->translatedFormat('d M Y') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('dbos.pimpinan.prospect.show', $p->id) }}" wire:navigate
                                           class="text-xs font-semibold text-amber-600 hover:underline">{{ __('Detail') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    @elseif ($tab === 'booking')
        @if ($bookingList->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.clipboard-document-list class="mx-auto size-10 text-zinc-400" />
                <p class="mt-3 text-sm text-zinc-500">{{ __('Belum ada booking.') }}</p>
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-xs uppercase tracking-wider text-zinc-500">
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Customer') }}</th>
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Proyek / Unit') }}</th>
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-left font-semibold">{{ __('Tanggal') }}</th>
                                <th class="px-4 py-3 text-right font-semibold">{{ __('Aksi') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($bookingList as $b)
                                @php
                                    $stBadge = match ($b->status) {
                                        'aktif' => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300', 'AKTIF'],
                                        'sukses' => ['bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300', 'SUKSES'],
                                        'akad' => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', 'AKAD'],
                                        'batal' => ['bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300', 'BATAL'],
                                    };
                                @endphp
                                <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                    <td class="px-4 py-3 font-semibold text-zinc-900 dark:text-white">{{ $b->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                    <td class="px-4 py-3 text-xs">
                                        <div class="text-zinc-700 dark:text-zinc-300">{{ $b->proyek?->nama_proyek ?? '—' }}</div>
                                        @if ($b->rumah)
                                            <div class="font-mono text-zinc-500">{{ $b->rumah->kode_unit }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $stBadge[0]])>{{ $stBadge[1] }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-zinc-500">{{ $b->tanggal_booking?->translatedFormat('d M Y') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('dbos.pimpinan.booking.show', $b->id) }}" wire:navigate
                                           class="text-xs font-semibold text-amber-600 hover:underline">{{ __('Detail') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    @elseif ($tab === 'aktivitas')
        @if ($activityLog->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.clock class="mx-auto size-10 text-zinc-400" />
                <p class="mt-3 text-sm text-zinc-500">{{ __('Belum ada aktivitas.') }}</p>
            </div>
        @else
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <ol class="relative ms-2 space-y-4 border-s-2 border-zinc-200 ps-5 dark:border-zinc-700">
                    @foreach ($activityLog as $log)
                        @php
                            $statusBadge = match ($log->status_ke) {
                                'cold' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
                                'warm' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                                'hot' => 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
                                'finish' => 'bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300',
                                'archive' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                                default => 'bg-zinc-100 text-zinc-700',
                            };
                        @endphp
                        <li class="relative">
                            <span class="absolute -inset-s-6.75 mt-1.5 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-amber-500 ring-4 ring-white dark:ring-zinc-950"></span>
                            <div class="text-xs text-zinc-500">{{ $log->created_at?->translatedFormat('d M Y · H:i') }}</div>
                            <div class="mt-1 flex items-center gap-2 text-sm">
                                <span class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $log->prospectCustomer?->nama_lengkap ?? '—' }}
                                </span>
                                <span @class(['rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase', $statusBadge])>
                                    {{ strtoupper($log->status_ke ?? '—') }}
                                </span>
                            </div>
                            @if ($log->catatan)
                                <p class="mt-1 rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                                    {{ $log->catatan }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    @endif
</div>
