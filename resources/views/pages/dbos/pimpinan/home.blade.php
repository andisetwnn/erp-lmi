<?php

use App\Models\Master\Booking;
use App\Models\Master\DismissedNotif;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use App\Models\Master\SalesTarget;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard Pimpinan'), Layout('layouts.pimpinan')] class extends Component {
    #[Url(as: 'p', except: 'bulan')]
    public string $periode = 'bulan';

    #[Url(as: 'fokus', except: 0)]
    public int $fokusSalesId = 0;

    public const PERIODE_OPTIONS = [
        'hari' => 'Hari ini',
        'minggu' => 'Minggu ini',
        'bulan' => 'Bulan ini',
        'q' => '3 Bulan',
    ];

    public function setPeriode(string $p): void
    {
        if (array_key_exists($p, self::PERIODE_OPTIONS)) {
            $this->periode = $p;
        }
    }

    public function dismissNotif(string $key): void
    {
        $pimpinan = Auth::guard('sales')->user();
        if (! $pimpinan) return;

        // Dismiss sampai besok pagi (auto-reappear besok)
        DismissedNotif::dismiss($pimpinan->id, $key, now()->addDay()->startOfDay());
        Flux::toast(variant: 'success', text: 'Notifikasi disembunyikan sampai besok.');
    }

    private function periodeRange(): array
    {
        return match ($this->periode) {
            'hari' => [now()->startOfDay(), now()->endOfDay()],
            'minggu' => [now()->startOfWeek(), now()->endOfWeek()],
            'q' => [now()->subMonths(3)->startOfDay(), now()->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        [$from, $to] = $this->periodeRange();
        $today = Carbon::today();

        $anggotaSemua = Sales::where('sales_grup_id', $grup->id)->get();
        $anggotaBawahan = $anggotaSemua->where('id', '!=', $pimpinan->id);
        $anggotaBawahanAktif = $anggotaBawahan->where('is_aktif', true);
        $allBawahanIds = $anggotaBawahan->pluck('id');

        // Fokus filter: kalau di-set ke ID anggota tertentu, scope query ke 1 orang saja
        $fokusSales = $this->fokusSalesId > 0
            ? $anggotaBawahanAktif->firstWhere('id', $this->fokusSalesId)
            : null;
        $bawahanIds = $fokusSales
            ? collect([$fokusSales->id])
            : $allBawahanIds;

        // ============= KPI (filtered by period for "baru" counts) =============
        $totalProspectAktif = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->where('status', '!=', 'archive')
            ->count();

        $prospectPeriode = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $bookingAktif = Booking::whereIn('sales_id', $bawahanIds)
            ->where('status', 'aktif')
            ->where(fn ($q) => $q->whereNull('tanggal_expired')->orWhere('tanggal_expired', '>', $today))
            ->count();

        $bookingPeriode = Booking::whereIn('sales_id', $bawahanIds)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $sukses = Booking::whereIn('sales_id', $bawahanIds)->where('status', 'sukses')->count();
        $akad = Booking::whereIn('sales_id', $bawahanIds)->where('status', 'akad')->count();

        $konversiPct = $prospectPeriode > 0
            ? round(($bookingPeriode / $prospectPeriode) * 100, 1)
            : 0;

        // ============= SPARKLINE 7 HARI TERAKHIR =============
        $sparkProspect = $this->dailyCounts(ProspectCustomer::class, $bawahanIds, 7);
        $sparkBooking = $this->dailyCounts(Booking::class, $bawahanIds, 7);

        // Delta vs 7 hari sebelumnya
        $deltaProspect = $this->weekOverWeekDelta(ProspectCustomer::class, $bawahanIds);
        $deltaBooking = $this->weekOverWeekDelta(Booking::class, $bawahanIds);

        // ============= FUNNEL =============
        $funnelProspect = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->where('status', '!=', 'archive')
            ->count();
        $funnelBooking = Booking::whereIn('sales_id', $bawahanIds)->count();
        $funnelSpr = Booking::whereIn('sales_id', $bawahanIds)->whereIn('status', ['sukses', 'akad'])->count();
        $funnelAkad = Booking::whereIn('sales_id', $bawahanIds)->where('status', 'akad')->count();

        $convProspectBooking = $funnelProspect > 0 ? round(($funnelBooking / $funnelProspect) * 100, 1) : 0;
        $convBookingSpr = $funnelBooking > 0 ? round(($funnelSpr / $funnelBooking) * 100, 1) : 0;
        $convSprAkad = $funnelSpr > 0 ? round(($funnelAkad / $funnelSpr) * 100, 1) : 0;

        // ============= LEADERBOARD =============
        $statsProspect = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->where('status', '!=', 'archive')
            ->selectRaw('sales_id, COUNT(*) as cnt')
            ->groupBy('sales_id')
            ->pluck('cnt', 'sales_id')
            ->toArray();

        $statsBooking = Booking::whereIn('sales_id', $bawahanIds)
            ->selectRaw('sales_id, status, COUNT(*) as cnt')
            ->groupBy('sales_id', 'status')
            ->get()
            ->groupBy('sales_id')
            ->map(fn ($rows) => $rows->pluck('cnt', 'status')->toArray());

        $lastActivity = ProspectCustomerStatusLog::whereIn('changed_by_sales_id', $bawahanIds)
            ->selectRaw('changed_by_sales_id, MAX(created_at) as last_at')
            ->groupBy('changed_by_sales_id')
            ->pluck('last_at', 'changed_by_sales_id')
            ->toArray();

        $anggotaRanked = $anggotaBawahanAktif->map(function ($a) use ($statsProspect, $statsBooking, $lastActivity) {
            $b = $statsBooking->get($a->id, []);
            $a->stat_prospect = $statsProspect[$a->id] ?? 0;
            $a->stat_booking = $b['aktif'] ?? 0;
            $a->stat_sukses = $b['sukses'] ?? 0;
            $a->stat_akad = $b['akad'] ?? 0;
            $a->stat_total = $a->stat_booking + $a->stat_sukses + $a->stat_akad;
            $a->last_activity_at = isset($lastActivity[$a->id]) ? Carbon::parse($lastActivity[$a->id]) : null;
            return $a;
        })->sortByDesc('stat_total')->values();

        // ============= COUNTS untuk checklist =============
        $bookingExpiringSoon = Booking::whereIn('sales_id', $bawahanIds)
            ->where('status', 'aktif')
            ->whereNotNull('tanggal_expired')
            ->whereBetween('tanggal_expired', [$today, $today->copy()->addDay()])
            ->count();

        $hotStuck = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->where('status', 'hot')
            ->whereDoesntHave('statusLog', fn ($q) => $q->where('created_at', '>=', $today->copy()->subDays(3)))
            ->count();

        $stagnanIds = $anggotaBawahanAktif->filter(function ($a) use ($lastActivity, $today) {
            $last = $lastActivity[$a->id] ?? null;
            if (! $last) {
                return true;
            }
            return Carbon::parse($last)->lt($today->copy()->subDays(7));
        });

        $aktivitasTerakhir = ProspectCustomerStatusLog::whereIn('changed_by_sales_id', $bawahanIds)
            ->with(['prospectCustomer:id,nama_lengkap', 'changedBy:id,nama,kode'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        // ============= DAILY CHECKLIST =============
        // Filter berdasarkan dismissed_notif state
        $dismissedKeys = DismissedNotif::where('sales_id', $pimpinan->id)
            ->where(fn ($q) => $q->whereNull('dismissed_until')->orWhere('dismissed_until', '>', now()))
            ->pluck('notif_key')
            ->all();
        $checklist = [];

        if ($hotStuck > 0 && ! in_array('hot-stagnan', $dismissedKeys)) {
            $checklist[] = [
                'key' => 'hot-stagnan',
                'icon' => 'fire',
                'color' => 'red',
                'text' => 'Review '.$hotStuck.' prospect HOT belum di-follow up >3 hari',
                'route' => route('dbos.pimpinan.prospect.index', ['status' => 'hot', 'stagnan' => 1]),
            ];
        }

        if ($bookingExpiringSoon > 0 && ! in_array('booking-expiring', $dismissedKeys)) {
            $checklist[] = [
                'key' => 'booking-expiring',
                'icon' => 'clock',
                'color' => 'amber',
                'text' => 'Cek '.$bookingExpiringSoon.' booking expired dalam 24 jam',
                'route' => route('dbos.pimpinan.booking.index', ['tab' => 'aktif']),
            ];
        }

        if ($stagnanIds->count() > 0 && ! in_array('anggota-stagnan', $dismissedKeys)) {
            $checklist[] = [
                'key' => 'anggota-stagnan',
                'icon' => 'user',
                'color' => 'blue',
                'text' => 'Tegur '.$stagnanIds->count().' anggota yang tidak aktif >7 hari',
                'route' => route('dbos.pimpinan.anggota.index', ['stagnan' => 1]),
            ];
        }

        // Cek anggota yang belum di-set target untuk bulan ini
        $periodeBulan = SalesTarget::currentPeriode();
        $anggotaWithTarget = SalesTarget::whereIn('sales_id', $allBawahanIds)
            ->where('periode', $periodeBulan)
            ->where(fn ($q) => $q->where('target_prospect', '>', 0)->orWhere('target_booking', '>', 0))
            ->pluck('sales_id');
        $noTargetCount = $anggotaBawahanAktif->whereNotIn('id', $anggotaWithTarget)->count();
        if ($noTargetCount > 0 && now()->day <= 7 && ! in_array('target-belum-set', $dismissedKeys)) {
            // Reminder hanya di awal bulan (tanggal 1-7)
            $checklist[] = [
                'key' => 'target-belum-set',
                'icon' => 'target',
                'color' => 'purple',
                'text' => 'Set target bulan ini untuk '.$noTargetCount.' anggota',
                'route' => route('dbos.pimpinan.anggota.index'),
            ];
        }

        // ============= CONVERSION PER SUMBER =============
        $perSumber = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->where('status', '!=', 'archive')
            ->selectRaw('sumber, COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('finish') THEN 1 ELSE 0 END) as finish_count")
            ->groupBy('sumber')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(function ($r) use ($bawahanIds) {
                // Jumlah booking dari prospect sumber ini
                $bookingCount = Booking::whereIn('sales_id', $bawahanIds)
                    ->whereHas('prospectCustomer', fn ($q) => $q->where('sumber', $r->sumber))
                    ->count();
                $r->booking_count = $bookingCount;
                $r->conv_pct = $r->total > 0 ? round(($bookingCount / $r->total) * 100, 1) : 0;
                return $r;
            });

        // ============= WORKLOAD DISTRIBUTION =============
        // Pakai $allBawahanIds (lihat semua walau fokus 1 sales)
        $workloadRaw = ProspectCustomer::whereIn('sales_id', $allBawahanIds)
            ->where('status', '!=', 'archive')
            ->selectRaw('sales_id, COUNT(*) as cnt')
            ->groupBy('sales_id')
            ->pluck('cnt', 'sales_id')
            ->toArray();
        $workload = $anggotaBawahanAktif->map(function ($a) use ($workloadRaw) {
            $a->workload_count = $workloadRaw[$a->id] ?? 0;
            return $a;
        })->sortByDesc('workload_count')->values();
        $workloadTotal = array_sum($workloadRaw);

        // ============= TIME-TO-CONVERSION (rata-rata hari Cold/Warm/Hot → Booking) =============
        $bookingsForTtc = Booking::whereIn('sales_id', $bawahanIds)
            ->with('prospectCustomer:id,created_at')
            ->get(['id', 'created_at', 'prospect_customer_id']);
        $ttcDays = $bookingsForTtc->map(function ($b) {
            if (! $b->prospectCustomer || ! $b->prospectCustomer->created_at) {
                return null;
            }
            return $b->prospectCustomer->created_at->diffInDays($b->created_at);
        })->filter()->values();
        $avgTtc = $ttcDays->isNotEmpty() ? round($ttcDays->avg(), 1) : null;

        // ============= PER-PROYEK BREAKDOWN =============
        $perProyek = Proyek::query()
            ->select('id', 'nama_proyek')
            ->withCount([
                'prospectCustomer as prospect_count' => fn ($q) => $q->whereIn('sales_id', $bawahanIds)->where('status', '!=', 'archive'),
                'booking as booking_count' => fn ($q) => $q->whereIn('sales_id', $bawahanIds),
            ])
            ->get()
            ->filter(fn ($p) => $p->prospect_count > 0 || $p->booking_count > 0)
            ->sortByDesc(fn ($p) => $p->prospect_count + $p->booking_count)
            ->values();

        // ============= AKTIVITAS LOGIN ANGGOTA =============
        // Klasifikasi anggota berdasarkan kapan terakhir login DBOS
        $loginBuckets = [
            'today' => 0,         // login hari ini
            'week' => 0,          // login 2-7 hari lalu
            'stale' => 0,         // login >7 hari lalu
            'never' => 0,         // belum pernah login
        ];
        $loginRows = [];
        foreach ($anggotaBawahanAktif as $a) {
            $entry = (object) [
                'id' => $a->id,
                'nama' => $a->nama,
                'kode' => $a->kode,
                'last_login_at' => $a->last_login_at,
                'telepon' => $a->telepon,
            ];

            if (! $a->last_login_at) {
                $loginBuckets['never']++;
                $entry->bucket = 'never';
                $entry->days_ago = null;
            } else {
                $daysAgo = (int) $a->last_login_at->diffInDays(now());
                $entry->days_ago = $daysAgo;
                if ($daysAgo === 0) {
                    $loginBuckets['today']++;
                    $entry->bucket = 'today';
                } elseif ($daysAgo <= 7) {
                    $loginBuckets['week']++;
                    $entry->bucket = 'week';
                } else {
                    $loginBuckets['stale']++;
                    $entry->bucket = 'stale';
                }
            }
            $loginRows[] = $entry;
        }
        // List anggota yang perlu perhatian (never / stale) — sorted by "lama tidak login"
        $loginPerluPerhatian = collect($loginRows)
            ->filter(fn ($r) => in_array($r->bucket, ['stale', 'never'], true))
            ->sortBy(function ($r) {
                // never di paling atas, lalu stale dari yang paling lama
                return $r->bucket === 'never' ? PHP_INT_MAX : ($r->days_ago ?? 0);
            })
            ->values()
            ->reverse()
            ->values();

        // Tambah ke checklist kalau ada
        if ($loginPerluPerhatian->count() > 0 && ! in_array('login-stagnan', $dismissedKeys)) {
            $checklist[] = [
                'key' => 'login-stagnan',
                'icon' => 'computer-desktop',
                'color' => 'rose',
                'text' => 'Cek '.$loginPerluPerhatian->count().' anggota yang jarang login DBOS',
                'route' => route('dbos.pimpinan.anggota.index'),
            ];
        }

        // ============= ANGGOTA OPTIONS untuk fokus filter =============
        $fokusOptions = $anggotaBawahanAktif->sortBy('nama')->values();

        return compact(
            'pimpinan',
            'grup',
            'anggotaBawahanAktif',
            'anggotaRanked',
            'totalProspectAktif',
            'prospectPeriode',
            'bookingAktif',
            'bookingPeriode',
            'sukses',
            'akad',
            'konversiPct',
            'sparkProspect',
            'sparkBooking',
            'deltaProspect',
            'deltaBooking',
            'funnelProspect',
            'funnelBooking',
            'funnelSpr',
            'funnelAkad',
            'convProspectBooking',
            'convBookingSpr',
            'convSprAkad',
            'aktivitasTerakhir',
            'checklist',
            'perSumber',
            'workload',
            'workloadTotal',
            'avgTtc',
            'perProyek',
            'fokusOptions',
            'fokusSales',
            'loginBuckets',
            'loginPerluPerhatian',
        );
    }

    /** Hitung jumlah row per hari untuk N hari terakhir. Return array of [date => count]. */
    private function dailyCounts(string $modelClass, $bawahanIds, int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $rows = $modelClass::whereIn('sales_id', $bawahanIds)
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at'])
            ->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))
            ->map(fn ($g) => $g->count());

        // Fill missing days dengan 0
        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $result[$date] = $rows->get($date, 0);
        }

        return array_values($result);
    }

    /** Delta % minggu ini vs minggu lalu (7 hari terakhir vs 7 hari sebelumnya). */
    private function weekOverWeekDelta(string $modelClass, $bawahanIds): ?float
    {
        $now = now();
        $thisWeek = $modelClass::whereIn('sales_id', $bawahanIds)
            ->whereBetween('created_at', [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()])
            ->count();

        $lastWeek = $modelClass::whereIn('sales_id', $bawahanIds)
            ->whereBetween('created_at', [$now->copy()->subDays(13)->startOfDay(), $now->copy()->subDays(7)->endOfDay()])
            ->count();

        if ($lastWeek === 0) {
            return $thisWeek > 0 ? 100.0 : null;
        }

        return round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1);
    }
}; ?>

<div wire:poll.30s>
    {{-- HEADER + PERIODE FILTER + FOKUS ANGGOTA --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Dashboard Pimpinan') }}</flux:heading>
            <flux:subheading>
                {{ $grup->nama }} · {{ $anggotaBawahanAktif->count() }} {{ __('anggota aktif') }} · {{ now()->translatedFormat('l, d F Y · H:i') }}
            </flux:subheading>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- Focus filter --}}
            <flux:select wire:model.live="fokusSalesId" class="w-48" size="sm">
                <flux:select.option value="0">{{ __('Semua anggota') }}</flux:select.option>
                @foreach ($fokusOptions as $opt)
                    <flux:select.option value="{{ $opt->id }}">{{ __('Fokus:') }} {{ $opt->nama }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="inline-flex overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                @php
                    $periodeOptions = [
                        'hari' => 'Hari ini',
                        'minggu' => 'Minggu ini',
                        'bulan' => 'Bulan ini',
                        'q' => '3 Bulan',
                    ];
                @endphp
                @foreach ($periodeOptions as $key => $label)
                    @php $active = $periode === $key; @endphp
                    <button type="button" wire:click="setPeriode('{{ $key }}')"
                            @class([
                                'px-3 py-1.5 text-xs font-semibold transition',
                                'bg-amber-600 text-white' => $active,
                                'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $active,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- INDIKATOR FOKUS AKTIF --}}
    @if ($fokusSales)
        <div class="mb-4 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs dark:border-amber-900/40 dark:bg-amber-950/30">
            <span class="flex items-center gap-1.5 text-amber-800 dark:text-amber-300">
                <flux:icon.user class="size-3.5" />
                {{ __('Fokus pada:') }}
                <strong>{{ $fokusSales->nama }}</strong>
                <span class="text-amber-600">#{{ $fokusSales->kode }}</span>
            </span>
            <button type="button" wire:click="$set('fokusSalesId', 0)"
                    class="font-semibold text-amber-700 underline hover:text-amber-900">
                {{ __('Lihat semua') }}
            </button>
        </div>
    @endif

    {{-- DAILY CHECKLIST --}}
    @if (! empty($checklist))
        <div class="mb-6 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                <flux:icon.check-badge class="size-4 text-emerald-500" />
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Yang perlu dikerjakan hari ini') }}</span>
                <span class="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-zinc-100 px-1.5 text-[10px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {{ count($checklist) }}
                </span>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($checklist as $item)
                    @php
                        $iconBg = match ($item['color']) {
                            'red' => 'bg-rose-100 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300',
                            'rose' => 'bg-rose-100 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300',
                            'amber' => 'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300',
                            'blue' => 'bg-blue-100 text-blue-600 dark:bg-blue-950/50 dark:text-blue-300',
                            'purple' => 'bg-purple-100 text-purple-600 dark:bg-purple-950/50 dark:text-purple-300',
                        };
                    @endphp
                    <li class="group flex items-center gap-3 px-5 py-3 transition hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                        <a href="{{ $item['route'] }}" wire:navigate
                           class="flex flex-1 items-center gap-3">
                            <div @class(['flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', $iconBg])>
                                @switch($item['icon'])
                                    @case('fire') <flux:icon.fire class="size-4" /> @break
                                    @case('clock') <flux:icon.clock class="size-4" /> @break
                                    @case('user') <flux:icon.user class="size-4" /> @break
                                    @case('target') <flux:icon.cursor-arrow-rays class="size-4" /> @break
                                    @case('computer-desktop') <flux:icon.computer-desktop class="size-4" /> @break
                                @endswitch
                            </div>
                            <span class="flex-1 text-sm text-zinc-700 dark:text-zinc-200">{{ $item['text'] }}</span>
                            <flux:icon.chevron-right class="size-4 text-zinc-400" />
                        </a>
                        <button type="button" wire:click="dismissNotif('{{ $item['key'] }}')"
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-white opacity-0 group-hover:opacity-100"
                                title="{{ __('Sembunyikan sampai besok') }}">
                            <flux:icon.x-mark class="size-4" />
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- KPI GRID + SPARKLINE --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Prospect --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('Prospect Aktif') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-100 text-orange-600 dark:bg-orange-950/50 dark:text-orange-400">
                    <flux:icon.circle-stack class="size-4" />
                </div>
            </div>
            <div class="mt-3 text-3xl font-bold text-zinc-900 dark:text-white">{{ $totalProspectAktif }}</div>
            <div class="mt-1 flex items-center justify-between text-xs text-zinc-500">
                <span>{{ $prospectPeriode }} {{ __('baru') }}</span>
                @if ($deltaProspect !== null)
                    @php $isUp = $deltaProspect >= 0; @endphp
                    <span @class([
                        'inline-flex items-center gap-0.5 font-bold',
                        'text-emerald-600 dark:text-emerald-400' => $isUp,
                        'text-rose-600 dark:text-rose-400' => ! $isUp,
                    ])>
                        @if ($isUp) ↑ @else ↓ @endif {{ abs($deltaProspect) }}%
                    </span>
                @endif
            </div>
            {{-- SPARKLINE --}}
            <x-sparkline :data="$sparkProspect" color="#ea580c" />
        </div>

        {{-- Booking --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('Booking Aktif') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                    <flux:icon.clipboard-document-list class="size-4" />
                </div>
            </div>
            <div class="mt-3 text-3xl font-bold text-zinc-900 dark:text-white">{{ $bookingAktif }}</div>
            <div class="mt-1 flex items-center justify-between text-xs text-zinc-500">
                <span>{{ $bookingPeriode }} {{ __('baru') }}</span>
                @if ($deltaBooking !== null)
                    @php $isUp = $deltaBooking >= 0; @endphp
                    <span @class([
                        'inline-flex items-center gap-0.5 font-bold',
                        'text-emerald-600 dark:text-emerald-400' => $isUp,
                        'text-rose-600 dark:text-rose-400' => ! $isUp,
                    ])>
                        @if ($isUp) ↑ @else ↓ @endif {{ abs($deltaBooking) }}%
                    </span>
                @endif
            </div>
            <x-sparkline :data="$sparkBooking" color="#d97706" />
        </div>

        {{-- SPR + Akad --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('SPR & Akad') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                    <flux:icon.check-badge class="size-4" />
                </div>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $sukses + $akad }}</span>
            </div>
            <div class="mt-1 text-xs text-zinc-500">
                {{ $sukses }} SPR · {{ $akad }} Akad
            </div>
            <div class="mt-8"></div>
        </div>

        {{-- Konversi --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('Konversi') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400">
                    <flux:icon.arrow-trending-up class="size-4" />
                </div>
            </div>
            <div class="mt-3 text-3xl font-bold text-zinc-900 dark:text-white">{{ $konversiPct }}%</div>
            <div class="mt-1 text-xs text-zinc-500">{{ __('Prospect → Booking (periode)') }}</div>
            <div class="mt-8"></div>
        </div>
    </div>

    {{-- 3-COLUMN GRID: funnel + leaderboard + activity --}}
    <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- FUNNEL PIPELINE --}}
        <div class="lg:col-span-1">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon.funnel class="size-4 text-blue-500" />
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Funnel Pipeline') }}</span>
                    </div>
                </div>
                <div class="p-5">
                    @php
                        $stages = [
                            ['Prospect', $funnelProspect, 'bg-orange-500', null],
                            ['Booking', $funnelBooking, 'bg-amber-500', $convProspectBooking],
                            ['SPR', $funnelSpr, 'bg-purple-500', $convBookingSpr],
                            ['Akad', $funnelAkad, 'bg-emerald-500', $convSprAkad],
                        ];
                        $maxStage = max($funnelProspect, 1);
                    @endphp
                    <div class="space-y-3">
                        @foreach ($stages as $i => [$label, $count, $color, $conv])
                            @php
                                $widthPct = ($maxStage > 0 && $count > 0)
                                    ? max(8, round(($count / $maxStage) * 100))
                                    : 0;
                            @endphp
                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $label }}</span>
                                        @if ($conv !== null)
                                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                {{ $conv }}% konversi
                                            </span>
                                        @endif
                                    </div>
                                    <span class="font-bold text-zinc-900 dark:text-white">{{ $count }}</span>
                                </div>
                                <div class="h-6 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                                    <div @class(['h-full transition-all', $color])
                                         style="width: {{ $widthPct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- LEADERBOARD --}}
        <div class="lg:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon.trophy class="size-4 text-amber-500" />
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Leaderboard Anggota') }}</span>
                    </div>
                    <a href="{{ route('dbos.pimpinan.anggota.index') }}" wire:navigate
                       class="inline-flex items-center gap-1 text-xs font-semibold text-amber-600 hover:text-amber-700">
                        {{ __('Lihat semua') }}
                        <flux:icon.chevron-right class="size-3.5" />
                    </a>
                </div>

                @if ($anggotaRanked->isEmpty())
                    <div class="px-5 py-10 text-center text-sm text-zinc-500">
                        {{ __('Belum ada anggota aktif di grup ini.') }}
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-xs uppercase tracking-wider text-zinc-500">
                                <th class="px-4 py-2.5 text-left font-semibold">#</th>
                                <th class="px-4 py-2.5 text-left font-semibold">{{ __('Nama') }}</th>
                                <th class="px-4 py-2.5 text-right font-semibold">{{ __('Prospect') }}</th>
                                <th class="px-4 py-2.5 text-right font-semibold">{{ __('Booking') }}</th>
                                <th class="px-4 py-2.5 text-right font-semibold">{{ __('SPR') }}</th>
                                <th class="px-4 py-2.5 text-right font-semibold">{{ __('Akad') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($anggotaRanked->take(8) as $i => $a)
                                @php
                                    $rank = $i + 1;
                                    $rankIcon = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => $rank };
                                    $rankBg = match ($rank) {
                                        1 => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                                        2 => 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200',
                                        3 => 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
                                        default => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
                                    };
                                @endphp
                                <tr class="transition hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                    <td class="px-4 py-3">
                                        <span @class(['inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold', $rankBg])>
                                            {{ $rankIcon }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('dbos.pimpinan.anggota.show', $a->id) }}" wire:navigate
                                           class="hover:underline">
                                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $a->nama }}</div>
                                            <div class="font-mono text-[10px] text-zinc-500">#{{ $a->kode }}</div>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-orange-600 dark:text-orange-400">{{ $a->stat_prospect }}</td>
                                    <td class="px-4 py-3 text-right font-bold text-amber-600 dark:text-amber-400">{{ $a->stat_booking }}</td>
                                    <td class="px-4 py-3 text-right font-bold text-purple-600 dark:text-purple-400">{{ $a->stat_sukses }}</td>
                                    <td class="px-4 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400">{{ $a->stat_akad }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    {{-- INSIGHTS: Conversion per Sumber + Workload Distribution --}}
    <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Conversion per sumber --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon.megaphone class="size-4 text-purple-500" />
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Konversi per Sumber') }}</span>
                </div>
                @if ($avgTtc !== null)
                    <span class="text-[10px] text-zinc-500">
                        {{ __('Rata-rata closing:') }} <span class="font-bold text-zinc-700 dark:text-zinc-300">{{ $avgTtc }} {{ __('hari') }}</span>
                    </span>
                @endif
            </div>

            @if ($perSumber->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('Belum ada data prospect.') }}</div>
            @else
                @php $maxSumber = (int) $perSumber->max('total'); @endphp
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($perSumber as $s)
                        @php
                            $widthPct = $maxSumber > 0 ? round(($s->total / $maxSumber) * 100) : 0;
                            $convColor = match (true) {
                                $s->conv_pct >= 30 => 'text-emerald-600 dark:text-emerald-400',
                                $s->conv_pct >= 10 => 'text-amber-600 dark:text-amber-400',
                                default => 'text-rose-500 dark:text-rose-400',
                            };
                        @endphp
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $s->sumber }}</span>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500">{{ $s->total }} prospect → {{ $s->booking_count }} booking</span>
                                    <span @class(['font-bold', $convColor])>{{ $s->conv_pct }}%</span>
                                </div>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full bg-purple-500" style="width: {{ $widthPct }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Pemegang Prospect Terbanyak --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon.trophy class="size-4 text-amber-500" />
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Pemegang Prospect Terbanyak') }}</span>
                </div>
                <span class="text-[10px] text-zinc-500">{{ $workloadTotal }} {{ __('total prospect aktif') }}</span>
            </div>

            @if ($workload->isEmpty() || $workloadTotal === 0)
                <div class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('Belum ada prospect aktif.') }}</div>
            @else
                @php $maxWorkload = (int) $workload->max('workload_count'); @endphp
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($workload as $i => $a)
                        @php
                            $widthPct = $maxWorkload > 0 ? round(($a->workload_count / $maxWorkload) * 100) : 0;
                            $pctShare = $workloadTotal > 0 ? round(($a->workload_count / $workloadTotal) * 100, 1) : 0;
                            $rank = $i + 1;
                            $isTop3 = $rank <= 3;
                            $barColor = match (true) {
                                $rank === 1 => 'bg-amber-500',
                                $rank === 2 => 'bg-amber-400',
                                $rank === 3 => 'bg-orange-400',
                                default => 'bg-blue-500',
                            };
                        @endphp
                        <li class="px-5 py-2.5">
                            <div class="flex items-center justify-between text-xs">
                                <a href="{{ route('dbos.pimpinan.anggota.show', $a->id) }}" wire:navigate
                                   class="inline-flex items-center gap-1.5 font-semibold text-zinc-700 hover:text-amber-700 dark:text-zinc-200 dark:hover:text-amber-300">
                                    @if ($rank === 1)
                                        <span title="{{ __('Pemegang prospect terbanyak') }}">🥇</span>
                                    @elseif ($rank === 2)
                                        <span>🥈</span>
                                    @elseif ($rank === 3)
                                        <span>🥉</span>
                                    @endif
                                    {{ $a->nama }}
                                </a>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500">{{ $a->workload_count }}</span>
                                    <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $pctShare }}%</span>
                                </div>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div @class(['h-full', $barColor]) style="width: {{ $widthPct }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- PER-PROYEK BREAKDOWN --}}
    @if ($perProyek->isNotEmpty() && $perProyek->count() > 1)
        <div class="mb-6 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                <flux:icon.home-modern class="size-4 text-emerald-500" />
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Breakdown per Proyek') }}</span>
            </div>
            <div class="grid grid-cols-2 divide-x divide-zinc-100 sm:grid-cols-3 lg:grid-cols-4 dark:divide-zinc-800">
                @foreach ($perProyek as $p)
                    <div class="px-4 py-3">
                        <div class="truncate text-xs font-semibold text-zinc-700 dark:text-zinc-200" title="{{ $p->nama_proyek }}">
                            {{ $p->nama_proyek }}
                        </div>
                        <div class="mt-1.5 flex items-baseline gap-3">
                            <div>
                                <div class="text-lg font-bold text-orange-600 dark:text-orange-400">{{ $p->prospect_count }}</div>
                                <div class="text-[9px] uppercase tracking-wider text-zinc-500">{{ __('Prospect') }}</div>
                            </div>
                            <div>
                                <div class="text-lg font-bold text-amber-600 dark:text-amber-400">{{ $p->booking_count }}</div>
                                <div class="text-[9px] uppercase tracking-wider text-zinc-500">{{ __('Booking') }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- LOGIN ACTIVITY MONITORING --}}
    <div class="mb-6 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <div class="flex items-center gap-2">
                <flux:icon.computer-desktop class="size-4 text-indigo-500" />
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Aktivitas Login Anggota') }}</span>
            </div>
            <span class="text-[10px] text-zinc-500">{{ __('Pantau siapa yang jarang buka DBOS') }}</span>
        </div>

        {{-- Bucket stats --}}
        <div class="grid grid-cols-2 divide-x divide-zinc-100 sm:grid-cols-4 dark:divide-zinc-800">
            <div class="px-4 py-4 text-center">
                <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $loginBuckets['today'] }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('Login hari ini') }}</div>
            </div>
            <div class="px-4 py-4 text-center">
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $loginBuckets['week'] }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('2-7 hari lalu') }}</div>
            </div>
            <div class="px-4 py-4 text-center">
                <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $loginBuckets['stale'] }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('Lewat 7 hari') }}</div>
            </div>
            <div class="px-4 py-4 text-center">
                <div class="text-2xl font-bold text-rose-600 dark:text-rose-400">{{ $loginBuckets['never'] }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('Belum pernah') }}</div>
            </div>
        </div>

        {{-- List anggota perlu perhatian --}}
        @if ($loginPerluPerhatian->isNotEmpty())
            <div class="border-t border-zinc-100 dark:border-zinc-800">
                <div class="bg-rose-50/50 px-5 py-2 text-[10px] font-bold uppercase tracking-wider text-rose-700 dark:bg-rose-950/20 dark:text-rose-300">
                    {{ __('Perlu ditegur') }}
                </div>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($loginPerluPerhatian->take(8) as $r)
                        @php
                            $bucketLabel = match ($r->bucket) {
                                'never' => ['Belum pernah login', 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'],
                                'stale' => [$r->days_ago.' hari lalu', 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
                            };
                            $waLink = $r->telepon
                                ? 'https://wa.me/'.preg_replace('/[^0-9]/', '', $r->telepon).'?text='.urlencode("Halo {$r->nama}, mohon cek aplikasi DBOS — sudah cukup lama tidak ada aktivitas. Ada update prospect/booking yang perlu ditindaklanjuti.")
                                : null;
                        @endphp
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            <a href="{{ route('dbos.pimpinan.anggota.show', $r->id) }}" wire:navigate
                               class="min-w-0 flex-1 hover:underline">
                                <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $r->nama }}</div>
                                <div class="font-mono text-[10px] text-zinc-500">#{{ $r->kode }}</div>
                            </a>
                            <span @class(['rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider', $bucketLabel[1]])>
                                {{ $bucketLabel[0] }}
                            </span>
                            @if ($waLink)
                                <a href="{{ $waLink }}" target="_blank"
                                   class="inline-flex h-7 items-center gap-1 rounded-md bg-green-600 px-2 text-[11px] font-semibold text-white transition hover:bg-green-700 active:scale-95"
                                   title="{{ __('Tegur via WA') }}">
                                    <flux:icon.phone class="size-3" />
                                    {{ __('Tegur') }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($loginPerluPerhatian->count() > 8)
                    <div class="border-t border-zinc-100 px-5 py-2 text-center text-[10px] text-zinc-500 dark:border-zinc-800">
                        {{ __(':n anggota lainnya — lihat di tab Anggota', ['n' => $loginPerluPerhatian->count() - 8]) }}
                    </div>
                @endif
            </div>
        @else
            <div class="border-t border-zinc-100 px-5 py-4 text-center text-xs text-emerald-600 dark:border-zinc-800 dark:text-emerald-400">
                <flux:icon.check-circle class="inline size-4" />
                {{ __('Semua anggota aktif login DBOS.') }}
            </div>
        @endif
    </div>

    {{-- AKTIVITAS TERAKHIR (full width) --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <div class="flex items-center gap-2">
                <flux:icon.clock class="size-4 text-zinc-500" />
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Aktivitas Terakhir') }}</span>
                <span class="ml-auto text-[10px] text-zinc-400">{{ __('auto-refresh tiap 30 detik') }}</span>
            </div>
        </div>

        @if ($aktivitasTerakhir->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-zinc-500">{{ __('Belum ada aktivitas.') }}</div>
        @else
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($aktivitasTerakhir as $log)
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
                    <li class="flex items-center gap-4 px-5 py-3 hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                        <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase shrink-0', $statusBadge])>
                            {{ strtoupper($log->status_ke ?? '—') }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ $log->prospectCustomer?->nama_lengkap ?? '—' }}
                            </p>
                            <p class="text-xs text-zinc-500">
                                {{ __('oleh') }}
                                <span class="font-semibold text-amber-700 dark:text-amber-300">{{ $log->changedBy?->nama ?? '—' }}</span>
                            </p>
                        </div>
                        <span class="shrink-0 text-xs text-zinc-500">{{ $log->created_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
