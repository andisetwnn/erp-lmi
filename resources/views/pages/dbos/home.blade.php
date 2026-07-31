<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Sales;
use App\Models\Master\SalesGrup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('DBOS Home'), Layout('layouts.dbos')] class extends Component {
    public function with(): array
    {
        /** @var Sales $sales */
        $sales = Auth::guard('sales')->user();
        $sales->load(['jenisSales', 'grup.pimpinan']);

        // Cek apakah sales ini pimpinan dari grup tertentu
        $leaderOf = SalesGrup::where('pimpinan_id', $sales->id)->first();
        $isLeader = $leaderOf !== null;

        // Greeting berdasarkan jam (WIB)
        $hour = (int) now()->format('H');
        $greeting = match (true) {
            $hour >= 4 && $hour < 11  => __('Selamat Pagi'),
            $hour >= 11 && $hour < 15 => __('Selamat Siang'),
            $hour >= 15 && $hour < 18 => __('Selamat Sore'),
            default                    => __('Selamat Malam'),
        };

        $today = Carbon::today();

        // Stats booking sales sendiri:
        // - Booking = aktif (deadline belum lewat)
        // - SPR     = sukses (SPR sudah terbit, belum akad)
        // - Akad    = akad
        $statBooking = Booking::where('sales_id', $sales->id)
            ->where('status', 'aktif')
            ->where(fn ($q) => $q->whereNull('tanggal_expired')->orWhere('tanggal_expired', '>', $today))
            ->count();
        $statSpr = Booking::where('sales_id', $sales->id)->where('status', 'sukses')->count();
        $statAkad = Booking::where('sales_id', $sales->id)->where('status', 'akad')->count();

        // Stats prospect by status (sales sendiri)
        $prospectCounts = ProspectCustomer::query()
            ->where('sales_id', $sales->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $prospectByStatus = [
            'cold'    => $prospectCounts['cold']    ?? 0,
            'warm'    => $prospectCounts['warm']    ?? 0,
            'hot'     => $prospectCounts['hot']     ?? 0,
            'finish'  => $prospectCounts['finish']  ?? 0,
            'archive' => $prospectCounts['archive'] ?? 0,
        ];
        // Total pipeline aktif (exclude archive — itu parking lot, bukan pipeline)
        $prospectTotal = $prospectByStatus['cold'] + $prospectByStatus['warm'] + $prospectByStatus['hot'] + $prospectByStatus['finish'];

        // Stats tim (kalau leader)
        $tim = null;
        if ($isLeader) {
            $anggota = Sales::where('sales_grup_id', $leaderOf->id)
                ->where('id', '!=', $sales->id)
                ->where('is_aktif', true)
                ->orderBy('nama')
                ->get(['id', 'kode', 'nama']);

            // Prospect tim (semua anggota termasuk leader)
            $allTimSalesIds = Sales::where('sales_grup_id', $leaderOf->id)->pluck('id');
            // Pipeline aktif tim (exclude archive — itu sudah di-parkir)
            $timProspectTotal = ProspectCustomer::whereIn('sales_id', $allTimSalesIds)
                ->where('status', '!=', 'archive')
                ->count();

            $timBookingBase = Booking::whereIn('sales_id', $allTimSalesIds);
            $timBooking = (clone $timBookingBase)
                ->where('status', 'aktif')
                ->where(fn ($q) => $q->whereNull('tanggal_expired')->orWhere('tanggal_expired', '>', $today))
                ->count();
            $timSpr = (clone $timBookingBase)->where('status', 'sukses')->count();
            $timAkad = (clone $timBookingBase)->where('status', 'akad')->count();

            // Stats per anggota (untuk leaderboard, urutkan by total)
            $statsByAnggota = Booking::whereIn('sales_id', $anggota->pluck('id'))
                ->selectRaw('sales_id, status, COUNT(*) as cnt')
                ->groupBy('sales_id', 'status')
                ->get()
                ->groupBy('sales_id')
                ->map(fn ($rows) => $rows->pluck('cnt', 'status')->toArray());

            $anggotaRanked = $anggota->map(function ($a) use ($statsByAnggota) {
                $s = $statsByAnggota->get($a->id, []);
                $a->stat_booking = $s['aktif'] ?? 0;
                $a->stat_spr = $s['sukses'] ?? 0;
                $a->stat_akad = $s['akad'] ?? 0;
                $a->stat_total = $a->stat_booking + $a->stat_spr + $a->stat_akad;
                return $a;
            })->sortByDesc('stat_total')->values();

            $tim = [
                'grup' => $leaderOf,
                'anggota_count' => $anggota->count(),
                'anggota' => $anggotaRanked,
                'total_booking' => $timBooking,
                'total_spr' => $timSpr,
                'total_akad' => $timAkad,
                'total_prospect' => $timProspectTotal,
            ];
        }

        // ============= REMINDER PRIORITAS =============
        $alerts = [];

        $bookingExpiringSoon = Booking::where('sales_id', $sales->id)
            ->where('status', 'aktif')
            ->whereNotNull('tanggal_expired')
            ->whereBetween('tanggal_expired', [$today, $today->copy()->addDay()])
            ->count();
        if ($bookingExpiringSoon > 0) {
            $alerts[] = [
                'level' => 'urgent',
                'icon' => 'clock',
                'text' => $bookingExpiringSoon.' booking akan expired dalam 24 jam.',
                'route' => route('dbos.booking.index'),
            ];
        }

        $hotStuck = ProspectCustomer::where('sales_id', $sales->id)
            ->where('status', 'hot')
            ->whereDoesntHave('statusLog', fn ($q) => $q->where('created_at', '>=', $today->copy()->subDays(3)))
            ->count();
        if ($hotStuck > 0) {
            $alerts[] = [
                'level' => 'warning',
                'icon' => 'fire',
                'text' => $hotStuck.' prospect HOT belum di-follow up dalam 3 hari.',
                'route' => route('dbos.database.index', ['status' => 'hot']),
            ];
        }

        // Cold > 3 minggu tanpa follow-up → suggest pindah Archive (level urgent, prioritas dibandingkan cold > 7 hari di bawah)
        $coldArchiveCandidate = ProspectCustomer::where('sales_id', $sales->id)
            ->where('status', 'cold')
            ->whereDoesntHave('statusLog', fn ($q) => $q->where('created_at', '>=', $today->copy()->subDays(21)))
            ->count();
        if ($coldArchiveCandidate > 0) {
            $alerts[] = [
                'level' => 'warning',
                'icon' => 'archive-box',
                'text' => $coldArchiveCandidate.' prospect COLD 3 minggu tanpa follow-up, pindahkan ke Archive.',
                'route' => route('dbos.database.index', ['status' => 'cold']),
            ];
        }

        // Cold > 7 hari tapi belum 3 minggu → cuma reminder follow-up biasa
        $coldStale = ProspectCustomer::where('sales_id', $sales->id)
            ->where('status', 'cold')
            ->where('created_at', '<', $today->copy()->subDays(7))
            ->where('created_at', '>=', $today->copy()->subDays(21))
            ->count();
        if ($coldStale > 0) {
            $alerts[] = [
                'level' => 'info',
                'icon' => 'snowflake',
                'text' => $coldStale.' prospect COLD lebih dari 7 hari, perlu di-follow up.',
                'route' => route('dbos.database.index', ['status' => 'cold']),
            ];
        }

        // ============= AKTIVITAS TERAKHIR =============
        $activities = ProspectCustomerStatusLog::where('changed_by_sales_id', $sales->id)
            ->with('prospectCustomer:id,nama_lengkap')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        // Inisial untuk avatar
        $initials = collect(explode(' ', $sales->nama))
            ->take(2)
            ->map(fn ($w) => mb_substr($w, 0, 1))
            ->implode('');

        // Tips harian untuk sales — random per render
        $tipsPool = [
            'Follow up dalam 24 jam meningkatkan closing rate hingga 4x lipat.',
            'Customer hot jika tidak di-follow up 3 hari akan dingin kembali.',
            'Catat preferensi customer di field Catatan agar mudah menyambung di follow up berikutnya.',
            'Foto KTP yang jelas + pencahayaan baik = OCR akurat = data lebih cepat lengkap.',
            'Jangan lupa update status prospect setiap ada interaksi baru.',
            'Booking hanya valid 1 hari kerja. Siapkan dokumen SPR dari awal.',
            'BI Checking via SLIK OJK adalah syarat wajib sebelum customer naik FINISH.',
            'Sapa customer dengan nama, jangan generic. Personal touch meningkatkan trust.',
            'Customer yang merasa didengarkan 3x lebih mungkin closing.',
            'Cek tab Database — prospect cold yang lebih dari 7 hari, follow up sekarang.',
            'Sales yang konsisten input data tiap hari menutup 2x lebih banyak deal.',
            'Pertanyaan pertama yang bagus: "Sudah ada gambaran tipe rumah yang dicari?"',
            'Berikan opsi maksimal 3 unit. Terlalu banyak pilihan bikin customer ragu.',
            'Closing terbaik adalah meminta keputusan dengan jelas, bukan sekadar menanti.',
            'Update WhatsApp story dengan unit hot 2x seminggu untuk top of mind.',
        ];
        shuffle($tipsPool);
        $tips = $tipsPool;

        return compact('sales', 'isLeader', 'tim', 'greeting', 'statBooking', 'statSpr', 'statAkad', 'initials', 'prospectByStatus', 'prospectTotal', 'tips', 'alerts', 'activities');
    }
}; ?>

<section class="px-4 pb-8 pt-5">

    {{-- ============== TOP BAR: Greeting + Date + Notif + Logout ============== --}}
    <div class="mb-4 flex items-start justify-between">
        <div>
            <p class="text-sm text-zinc-500">{{ $greeting }},</p>
            <p class="text-xl font-bold text-zinc-900 dark:text-white">{{ $sales->nama }}</p>
            <p class="mt-0.5 text-xs text-zinc-400">{{ now()->translatedFormat('l, d F Y') }}</p>
        </div>

        <div class="flex items-center gap-2">
            {{-- Bell reminder --}}
            @php $notifCount = count($alerts); @endphp
            <flux:modal.trigger name="reminder">
                <button type="button"
                        class="relative inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm transition active:scale-95 hover:text-orange-600 dark:bg-zinc-900 dark:text-zinc-300"
                        title="{{ __('Reminder') }}">
                    <flux:icon.exclamation-circle class="size-5" />
                    @if ($notifCount > 0)
                        <span class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white ring-2 ring-white dark:ring-zinc-950">
                            {{ $notifCount > 9 ? '9+' : $notifCount }}
                        </span>
                    @endif
                </button>
            </flux:modal.trigger>

            <a href="{{ route('dbos.profil') }}" wire:navigate
               class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm transition hover:text-orange-600 active:scale-95 dark:bg-zinc-900 dark:text-zinc-300"
               title="{{ __('Profil') }}">
                <flux:icon.user class="size-5" />
            </a>

            <form method="POST" action="{{ route('dbos.logout') }}">
                @csrf
                <button type="submit"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm transition hover:text-red-600 active:scale-95 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:text-red-400"
                        title="{{ __('Keluar') }}">
                    <flux:icon.arrow-right-start-on-rectangle class="size-5" />
                </button>
            </form>
        </div>
    </div>

    {{-- ============== MODAL REMINDER ============== --}}
    <flux:modal name="reminder" class="md:w-md">
        <div class="space-y-4">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.exclamation-circle class="size-5 text-orange-600" />
                    <flux:heading size="lg">{{ __('Reminder') }}</flux:heading>
                    @if ($notifCount > 0)
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">
                            {{ $notifCount }}
                        </span>
                    @endif
                </div>
                <flux:subheading>{{ __('Hal yang perlu segera ditindaklanjuti.') }}</flux:subheading>
            </div>

            @if (empty($alerts))
                <div class="rounded-xl border-2 border-dashed border-zinc-200 px-4 py-8 text-center dark:border-zinc-700">
                    <flux:icon.check-circle class="mx-auto size-10 text-emerald-400" />
                    <p class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        {{ __('Semua aman') }}
                    </p>
                    <p class="mt-0.5 text-xs text-zinc-500">
                        {{ __('Tidak ada reminder saat ini.') }}
                    </p>
                </div>
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($alerts as $a)
                        @php
                            $alertStyle = match ($a['level']) {
                                'urgent'  => ['iconBg' => 'bg-rose-500',   'text' => 'text-rose-900 dark:text-rose-100',   'label' => 'URGENT',  'labelClr' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'],
                                'warning' => ['iconBg' => 'bg-amber-500', 'text' => 'text-amber-900 dark:text-amber-100', 'label' => 'PENTING', 'labelClr' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'],
                                'info'    => ['iconBg' => 'bg-blue-500',   'text' => 'text-blue-900 dark:text-blue-100',   'label' => 'INFO',    'labelClr' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300'],
                            };
                        @endphp
                        <li>
                            <flux:modal.close>
                                <a href="{{ $a['route'] }}" wire:navigate
                                   class="flex items-start gap-3 py-3 transition active:scale-[0.99]">
                                    <div @class(['mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-white shadow-sm', $alertStyle['iconBg']])>
                                        @switch($a['icon'])
                                            @case('clock')        <flux:icon.clock class="size-4" /> @break
                                            @case('fire')         <flux:icon.fire class="size-4" /> @break
                                            @case('snowflake')    <flux:icon.cloud class="size-4" /> @break
                                            @case('archive-box')  <flux:icon.archive-box class="size-4" /> @break
                                        @endswitch
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <span @class(['inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide', $alertStyle['labelClr']])>
                                            {{ $alertStyle['label'] }}
                                        </span>
                                        <p @class(['mt-1 text-sm font-semibold', $alertStyle['text']])>{{ $a['text'] }}</p>
                                    </div>
                                    <flux:icon.chevron-right class="mt-2 size-4 shrink-0 text-zinc-400" />
                                </a>
                            </flux:modal.close>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </flux:modal>

    {{-- ============== TIPS SALES (carousel auto-rotate) ============== --}}
    <div x-data="{
            tips: @js($tips),
            idx: 0,
            fading: false,
            timer: null,
            start() {
                this.timer = setInterval(() => {
                    this.fading = true;
                    setTimeout(() => {
                        this.idx = (this.idx + 1) % this.tips.length;
                        this.fading = false;
                    }, 300);
                }, 6000);
            },
            next() {
                clearInterval(this.timer);
                this.fading = true;
                setTimeout(() => {
                    this.idx = (this.idx + 1) % this.tips.length;
                    this.fading = false;
                    this.start();
                }, 200);
            },
         }"
         x-init="start()"
         class="mb-4 overflow-hidden rounded-2xl border border-amber-200 bg-linear-to-r from-amber-50 to-orange-50 dark:border-amber-900/40 dark:from-amber-950/30 dark:to-orange-950/30">
        <div class="flex items-start gap-3 px-4 py-3">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-500 text-white shadow-sm">
                <flux:icon.light-bulb class="size-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">
                        {{ __('Tips') }}
                    </div>
                    <button type="button" @click="next()"
                            class="rounded-full p-0.5 text-amber-600 hover:bg-amber-100 active:scale-95 dark:text-amber-400 dark:hover:bg-amber-900/40"
                            title="{{ __('Tip berikutnya') }}">
                        <flux:icon.arrow-path class="size-3.5" />
                    </button>
                </div>
                <p class="mt-0.5 min-h-8 text-xs leading-snug text-zinc-700 transition-opacity duration-300 dark:text-zinc-200"
                   :class="fading ? 'opacity-0' : 'opacity-100'"
                   x-text="tips[idx]"></p>
            </div>
        </div>
    </div>

    {{-- ============== HERO CARD: Avatar + Identity + Badge ============== --}}
    <div class="overflow-hidden rounded-2xl bg-linear-to-br from-orange-600 via-orange-500 to-amber-500 p-5 text-white shadow-lg">
        <div class="flex items-center gap-4">
            {{-- Avatar dengan inisial --}}
            <div class="relative">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-white/20 text-xl font-bold backdrop-blur-sm ring-2 ring-white/40">
                    {{ $initials }}
                </div>
                @if ($isLeader)
                    <div class="absolute -bottom-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full bg-amber-400 ring-2 ring-orange-600" title="{{ __('Pimpinan Grup') }}">
                        <flux:icon.star class="size-3.5 text-amber-900" />
                    </div>
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="font-mono text-xs opacity-80">#{{ $sales->kode }}</span>
                    @if ($isLeader)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-400/90 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-900">
                            <flux:icon.star class="size-3" />
                            {{ __('Pimpinan') }}
                        </span>
                    @endif
                </div>

                <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs opacity-90">
                    @if ($sales->jenisSales)
                        <span class="inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5">
                            {{ $sales->jenisSales->nama }}
                        </span>
                    @endif
                    @if ($sales->grup)
                        <span class="opacity-80">·</span>
                        <span>{{ $sales->grup->nama }}</span>
                    @endif
                </div>

                @if (! $isLeader && $sales->grup?->pimpinan)
                    <div class="mt-1.5 text-[11px] opacity-75">
                        {{ __('Pimpinan:') }} {{ $sales->grup->pimpinan->nama }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Stats personal --}}
        <div class="mt-5 grid grid-cols-3 gap-2 rounded-xl bg-white/10 p-3 backdrop-blur-sm">
            <div class="text-center">
                <div class="text-2xl font-bold leading-tight">{{ $statBooking }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wider opacity-80">{{ __('Booking') }}</div>
            </div>
            <div class="border-x border-white/20 text-center">
                <div class="text-2xl font-bold leading-tight">{{ $statSpr }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wider opacity-80">{{ __('SPR') }}</div>
            </div>
            <div class="text-center">
                <div class="text-2xl font-bold leading-tight">{{ $statAkad }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wider opacity-80">{{ __('Akad') }}</div>
            </div>
        </div>
    </div>

    {{-- ============== PROSPECT BREAKDOWN ============== --}}
    <div class="mt-5 overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
            <div class="flex items-center gap-2">
                <flux:icon.circle-stack class="size-4 text-zinc-500" />
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">
                    {{ __('Prospect Saya') }}
                </span>
            </div>
            <a href="{{ route('dbos.database.index') }}" wire:navigate
               class="inline-flex items-center gap-1 text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">
                {{ __('Lihat semua') }}
                <flux:icon.chevron-right class="size-3.5" />
            </a>
        </div>

        @if ($prospectTotal === 0)
            <div class="px-4 py-6 text-center">
                <flux:icon.user-plus class="mx-auto size-8 text-zinc-300" />
                <p class="mt-2 text-xs text-zinc-500">{{ __('Belum ada prospect.') }}</p>
                <a href="{{ route('dbos.database.create') }}" wire:navigate
                   class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-orange-600">
                    <flux:icon.plus class="size-3.5" />
                    {{ __('Tambah prospect pertama') }}
                </a>
            </div>
        @else
            <div class="grid grid-cols-5 divide-x divide-zinc-100 dark:divide-zinc-800">
                @php
                    $cards = [
                        ['key' => 'cold',    'label' => 'Cold',    'count' => $prospectByStatus['cold'],    'text' => 'text-blue-600 dark:text-blue-400'],
                        ['key' => 'warm',    'label' => 'Warm',    'count' => $prospectByStatus['warm'],    'text' => 'text-amber-600 dark:text-amber-400'],
                        ['key' => 'hot',     'label' => 'Hot',     'count' => $prospectByStatus['hot'],     'text' => 'text-red-600 dark:text-red-400'],
                        ['key' => 'finish',  'label' => 'Finish',  'count' => $prospectByStatus['finish'],  'text' => 'text-green-600 dark:text-green-400'],
                        ['key' => 'archive', 'label' => 'Archive', 'count' => $prospectByStatus['archive'], 'text' => 'text-stone-500 dark:text-stone-400'],
                    ];
                @endphp
                @foreach ($cards as $c)
                    <a href="{{ route('dbos.database.index', ['status' => $c['key']]) }}" wire:navigate
                       class="block px-2 py-4 text-center transition active:scale-95 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <div @class(['text-2xl font-bold leading-tight', $c['text']])>{{ $c['count'] }}</div>
                        <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ $c['label'] }}</div>
                    </a>
                @endforeach
            </div>

            <div class="border-t border-zinc-100 bg-zinc-50/50 px-4 py-2.5 text-center text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/30 dark:text-zinc-400">
                {{ __('Total :n prospect dalam pipeline', ['n' => $prospectTotal]) }}
                @if ($isLeader && $tim && $tim['total_prospect'] > $prospectTotal)
                    <span class="text-zinc-400">·</span>
                    <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $tim['total_prospect'] }}</span>
                    <span>{{ __('di tim') }}</span>
                @endif
            </div>
        @endif
    </div>

    {{-- ============== MENU GRID (3 menu) ============== --}}
    <div class="mt-4">
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <flux:icon.squares-2x2 class="size-4 text-zinc-500" />
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('Menu Utama') }}</span>
            </div>
        </div>

        @php
            $menus = [
                ['route' => 'dbos.booking.index',  'label' => __('Booking'),  'desc' => __('List booking'),       'icon' => 'clipboard-document-list', 'color' => 'amber'],
                ['route' => 'dbos.spr.index',      'label' => __('SPR'),      'desc' => __('Surat pemesanan'),    'icon' => 'document-check',          'color' => 'purple'],
                ['route' => 'dbos.database.index', 'label' => __('Database'), 'desc' => __('Data customer'),      'icon' => 'circle-stack',            'color' => 'cyan'],
                ['route' => 'dbos.cara-kerja',     'label' => __('Cara Kerja'), 'desc' => __('Panduan alur'),     'icon' => 'map',                      'color' => 'orange'],
            ];
        @endphp

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($menus as $m)
                @php
                    $bg = match ($m['color']) {
                        'amber'  => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                        'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300',
                        'cyan'   => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950/50 dark:text-cyan-300',
                        'orange' => 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
                    };
                    $hasRoute = \Illuminate\Support\Facades\Route::has($m['route']);
                @endphp
                <a href="{{ $hasRoute ? route($m['route']) : '#' }}"
                   @if ($hasRoute) wire:navigate @endif
                   @class([
                       'group flex flex-col items-center gap-2.5 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition active:scale-95 dark:border-zinc-700 dark:bg-zinc-900',
                       'opacity-50 pointer-events-none' => ! $hasRoute,
                   ])>
                    <div @class(['flex h-14 w-14 items-center justify-center rounded-2xl transition group-hover:scale-110', $bg])>
                        @switch($m['icon'])
                            @case('clipboard-document-list') <flux:icon.clipboard-document-list class="size-7" /> @break
                            @case('document-check')          <flux:icon.document-check class="size-7" /> @break
                            @case('circle-stack')            <flux:icon.circle-stack class="size-7" /> @break
                            @case('map')                     <flux:icon.map class="size-7" /> @break
                        @endswitch
                    </div>
                    <div class="text-center">
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $m['label'] }}</div>
                        <div class="mt-0.5 text-[10px] text-zinc-500">{{ $m['desc'] }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>

    {{-- ============== AKTIVITAS TERAKHIR ============== --}}
    @if ($activities->isNotEmpty())
        <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon.clock class="size-4 text-zinc-500" />
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ __('Aktivitas Terakhir') }}</span>
                </div>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($activities as $log)
                    @php
                        $statusBadge = match ($log->status_ke) {
                            'cold'    => ['Cold',    'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300'],
                            'warm'    => ['Warm',    'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
                            'hot'     => ['Hot',     'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300'],
                            'finish'  => ['Finish',  'bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300'],
                            'archive' => ['Archive', 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300'],
                            default  => [strtoupper($log->status_ke ?? '—'), 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'],
                        };
                    @endphp
                    <li class="flex items-start gap-3 px-4 py-3">
                        <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                            <flux:icon.arrow-trending-up class="size-3.5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ $log->prospectCustomer?->nama_lengkap ?? '—' }}
                            </p>
                            <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-zinc-500">
                                @if ($log->status_dari)
                                    <span class="uppercase">{{ $log->status_dari }}</span>
                                    <flux:icon.arrow-right class="size-2.5" />
                                @endif
                                <span @class(['inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase', $statusBadge[1]])>
                                    {{ $statusBadge[0] }}
                                </span>
                                <span class="text-zinc-300">·</span>
                                <span>{{ $log->created_at->diffForHumans() }}</span>
                            </div>
                            @if ($log->catatan)
                                <p class="mt-1 truncate text-[11px] text-zinc-500">{{ $log->catatan }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ============== LEADER SECTION: Tim Saya ============== --}}
    @if ($isLeader && $tim)
        <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            {{-- Header sub-section --}}
            <div class="border-b border-zinc-200 bg-linear-to-r from-amber-50 to-yellow-50 px-5 py-4 dark:border-zinc-700 dark:from-amber-950/30 dark:to-yellow-950/30">
                <div class="flex items-center gap-2">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500 text-white">
                        <flux:icon.users class="size-4" />
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Tim Saya') }}</div>
                        <div class="text-xs text-zinc-500">{{ $tim['grup']->nama }} · {{ $tim['anggota_count'] }} {{ __('anggota aktif') }}</div>
                    </div>
                </div>
            </div>

            {{-- Tim stats --}}
            <div class="grid grid-cols-4 divide-x divide-zinc-200 px-2 py-4 dark:divide-zinc-700">
                <div class="text-center">
                    <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">{{ $tim['total_prospect'] }}</div>
                    <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('Prospect') }}</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $tim['total_booking'] }}</div>
                    <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('Booking') }}</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $tim['total_spr'] }}</div>
                    <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('SPR') }}</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $tim['total_akad'] }}</div>
                    <div class="mt-0.5 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('Akad') }}</div>
                </div>
            </div>

            {{-- Leaderboard anggota (sorted by total) --}}
            @if ($tim['anggota']->isNotEmpty())
                <div class="border-t border-zinc-100 px-2 py-2 dark:border-zinc-800">
                    <div class="px-3 pb-1 pt-1 text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                        {{ __('Peringkat berdasarkan total Booking + SPR + Akad') }}
                    </div>
                    @foreach ($tim['anggota'] as $i => $a)
                        @php
                            $anggotaInitials = collect(explode(' ', $a->nama))
                                ->take(2)
                                ->map(fn ($w) => mb_substr($w, 0, 1))
                                ->implode('');
                            $rank = $i + 1;
                            $rankStyle = match (true) {
                                $rank === 1 => ['bg-amber-400 text-amber-900', '🥇'],
                                $rank === 2 => ['bg-zinc-300 text-zinc-800', '🥈'],
                                $rank === 3 => ['bg-orange-300 text-orange-900', '🥉'],
                                default     => ['bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400', $rank],
                            };
                        @endphp
                        <div class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <div @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', $rankStyle[0]])>
                                {{ $rankStyle[1] }}
                            </div>
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ $anggotaInitials }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $a->nama }}</div>
                                <div class="font-mono text-xs text-zinc-500">#{{ $a->kode }}</div>
                            </div>
                            <div class="flex items-center gap-3 text-xs">
                                <div class="text-center">
                                    <div class="font-bold text-amber-600 dark:text-amber-400">{{ $a->stat_booking }}</div>
                                    <div class="text-[9px] uppercase text-zinc-400">B</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-bold text-purple-600 dark:text-purple-400">{{ $a->stat_spr }}</div>
                                    <div class="text-[9px] uppercase text-zinc-400">S</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-bold text-green-600 dark:text-green-400">{{ $a->stat_akad }}</div>
                                    <div class="text-[9px] uppercase text-zinc-400">A</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="border-t border-zinc-100 px-5 py-6 text-center text-xs text-zinc-500 dark:border-zinc-800">
                    {{ __('Belum ada anggota aktif di grup ini.') }}
                </div>
            @endif
        </div>
    @endif

</section>
