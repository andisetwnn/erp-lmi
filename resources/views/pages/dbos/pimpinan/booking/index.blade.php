<?php

use App\Models\Master\Booking;
use App\Models\Master\Sales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Booking Grup'), Layout('layouts.pimpinan')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab', except: 'aktif')]
    public string $tab = 'aktif';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sales', except: '')]
    public string $filterSales = '';

    #[Url(as: 'view', except: 'tabel')]
    public string $viewMode = 'tabel';

    public function setViewMode(string $m): void
    {
        $this->viewMode = in_array($m, ['tabel', 'kalender'], true) ? $m : 'tabel';
    }

    public function exportExcel()
    {
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();
        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id')->all();

        $filters = [];
        if ($this->filterSales) $filters['sales_id'] = (int) $this->filterSales;

        $filename = 'booking-grup-'.now()->format('Y-m-d').'.xlsx';
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PimpinanBookingExport($bawahanIds, $filters),
            $filename
        );
    }

    public function exportPdf()
    {
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();
        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $bookings = Booking::whereIn('sales_id', $bawahanIds)
            ->with(['sales:id,nama,kode', 'proyek:id,nama_proyek', 'rumah:id,blok,nomor_unit', 'prospectCustomer:id,nama_lengkap,hp'])
            ->when($this->filterSales, fn ($q) => $q->where('sales_id', $this->filterSales))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.pimpinan-booking-pdf', [
            'bookings' => $bookings,
            'grup' => $grup,
            'pimpinanNama' => $pimpinan->nama,
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'booking-grup-'.now()->format('Y-m-d').'.pdf',
        );
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFilterSales(): void { $this->resetPage(); }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['aktif', 'sukses', 'akad', 'batal', 'expired'], true) ? $tab : 'aktif';
        $this->resetPage();
    }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $salesList = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->orderBy('nama')
            ->get(['id', 'nama', 'kode']);

        $today = Carbon::today();
        $base = fn () => Booking::query()->whereIn('sales_id', $bawahanIds);

        $aktifFilter = fn ($q) => $q->where('status', 'aktif')
            ->where(fn ($x) => $x->whereNull('tanggal_expired')->orWhere('tanggal_expired', '>', $today));

        $expiredFilter = fn ($q) => $q->where('status', 'aktif')
            ->whereNotNull('tanggal_expired')
            ->where('tanggal_expired', '<=', $today);

        $counts = [
            'aktif' => $base()->tap($aktifFilter)->count(),
            'sukses' => $base()->where('status', 'sukses')->count(),
            'akad' => $base()->where('status', 'akad')->count(),
            'batal' => $base()->where('status', 'batal')->count(),
            'expired' => $base()->tap($expiredFilter)->count(),
        ];

        $query = $base()->with([
            'rumah.tipeRumah',
            'proyek:id,nama_proyek',
            'prospectCustomer:id,nama_lengkap,hp,nik',
            'sales:id,nama,kode',
        ]);

        match ($this->tab) {
            'aktif' => $aktifFilter($query),
            'sukses' => $query->where('status', 'sukses'),
            'akad' => $query->where('status', 'akad'),
            'batal' => $query->where('status', 'batal'),
            'expired' => $expiredFilter($query),
        };

        if ($this->filterSales) {
            $query->where('sales_id', $this->filterSales);
        }

        if ($this->search !== '') {
            $s = $this->search;
            $query->whereHas('prospectCustomer', function ($q) use ($s) {
                $q->where('nama_lengkap', 'like', "%{$s}%")
                    ->orWhere('nik', 'like', "%{$s}%")
                    ->orWhere('hp', 'like', "%{$s}%");
            });
        }

        $bookings = $query->orderByDesc('created_at')->paginate(15);

        // ===== KALENDER VIEW (current month) =====
        $calendar = null;
        if ($this->viewMode === 'kalender') {
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();

            // Bookings yang relevan utk kalender — tanggal_booking ATAU tanggal_expired ada di bulan ini
            $calBookings = Booking::query()
                ->whereIn('sales_id', $bawahanIds)
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('tanggal_booking', [$start, $end])
                        ->orWhereBetween('tanggal_expired', [$start, $end]);
                })
                ->with(['prospectCustomer:id,nama_lengkap', 'sales:id,nama,kode', 'rumah:id,blok,nomor_unit'])
                ->get();

            // Map tanggal → events
            $events = [];
            foreach ($calBookings as $b) {
                if ($b->tanggal_booking && $b->tanggal_booking->between($start, $end)) {
                    $key = $b->tanggal_booking->format('Y-m-d');
                    $events[$key][] = ['type' => 'booking', 'booking' => $b];
                }
                if ($b->tanggal_expired && $b->tanggal_expired->between($start, $end) && $b->status === 'aktif') {
                    $key = $b->tanggal_expired->format('Y-m-d');
                    $events[$key][] = ['type' => 'expired', 'booking' => $b];
                }
            }

            // Build matrix per minggu — start dari Sunday
            // Pakai Carbon mutable karena $cursor di-mutate di loop (now() return CarbonImmutable di project ini)
            $firstDay = \Illuminate\Support\Carbon::instance($start->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->toDateTime());
            $lastDay = \Illuminate\Support\Carbon::instance($end->endOfWeek(\Carbon\CarbonInterface::SATURDAY)->toDateTime());

            $weeks = [];
            $cursor = $firstDay->copy();
            while ($cursor->lte($lastDay)) {
                $week = [];
                for ($i = 0; $i < 7; $i++) {
                    $key = $cursor->format('Y-m-d');
                    $week[] = [
                        'date' => $cursor->copy(),
                        'in_month' => $cursor->month === $start->month,
                        'is_today' => $cursor->isToday(),
                        'events' => $events[$key] ?? [],
                    ];
                    $cursor->addDay();
                }
                $weeks[] = $week;
            }

            $calendar = [
                'month_label' => $start->translatedFormat('F Y'),
                'weeks' => $weeks,
            ];
        }

        return compact('grup', 'bookings', 'counts', 'salesList', 'calendar');
    }
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Booking Grup') }}</flux:heading>
            <flux:subheading>{{ $grup->nama }}</flux:subheading>
        </div>
        <flux:dropdown align="end">
            <flux:button icon="arrow-down-tray" variant="filled" size="sm">{{ __('Export') }}</flux:button>
            <flux:menu>
                <flux:menu.item icon="document-arrow-down" wire:click="exportExcel">
                    {{ __('Excel (.xlsx)') }}
                </flux:menu.item>
                <flux:menu.item icon="document-text" wire:click="exportPdf">
                    {{ __('PDF') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- TABS --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $tabs = [
                'aktif' => ['Aktif', 'blue'],
                'sukses' => ['Sukses', 'purple'],
                'akad' => ['Akad', 'emerald'],
                'expired' => ['Expired', 'zinc'],
                'batal' => ['Batal', 'rose'],
            ];
        @endphp
        @foreach ($tabs as $key => [$label, $color])
            @php
                $active = $tab === $key;
                $activeClasses = match ($color) {
                    'blue' => 'bg-blue-600 text-white',
                    'purple' => 'bg-purple-600 text-white',
                    'emerald' => 'bg-emerald-600 text-white',
                    'rose' => 'bg-rose-600 text-white',
                    'zinc' => 'bg-zinc-700 text-white',
                };
            @endphp
            <button type="button" wire:click="setTab('{{ $key }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition',
                        $activeClasses.' border-transparent' => $active,
                        'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => ! $active,
                    ])>
                <span>{{ $label }}</span>
                <span @class([
                    'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px] font-bold',
                    'bg-white/25 text-white' => $active,
                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! $active,
                ])>{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    {{-- FILTER BAR --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex-1 min-w-60">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama, NIK, atau HP customer...')" />
        </div>
        <flux:select wire:model.live="filterSales" placeholder="{{ __('Sales') }}" class="w-56">
            <flux:select.option value="">{{ __('Semua sales') }}</flux:select.option>
            @foreach ($salesList as $s)
                <flux:select.option value="{{ $s->id }}">{{ $s->nama }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="inline-flex overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <button type="button" wire:click="setViewMode('tabel')"
                    @class([
                        'inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold transition',
                        'bg-amber-600 text-white' => $viewMode === 'tabel',
                        'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800' => $viewMode !== 'tabel',
                    ])>
                <flux:icon.table-cells class="size-4" />
                {{ __('Tabel') }}
            </button>
            <button type="button" wire:click="setViewMode('kalender')"
                    @class([
                        'inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold transition',
                        'bg-amber-600 text-white' => $viewMode === 'kalender',
                        'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800' => $viewMode !== 'kalender',
                    ])>
                <flux:icon.calendar class="size-4" />
                {{ __('Kalender') }}
            </button>
        </div>
    </div>

    {{-- KALENDER VIEW --}}
    @if ($viewMode === 'kalender' && $calendar)
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon.calendar class="size-4 text-amber-500" />
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ $calendar['month_label'] }}</span>
                    <span class="ml-auto text-[10px] text-zinc-500">
                        <span class="inline-block size-2 rounded-full bg-blue-500"></span> {{ __('Booking baru') }}
                        ·
                        <span class="inline-block size-2 rounded-full bg-rose-500"></span> {{ __('Expired') }}
                    </span>
                </div>
            </div>

            {{-- Day header --}}
            <div class="grid grid-cols-7 border-b border-zinc-100 bg-zinc-50/50 text-center text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/30">
                @foreach (['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'] as $d)
                    <div class="py-2">{{ $d }}</div>
                @endforeach
            </div>

            @foreach ($calendar['weeks'] as $week)
                <div class="grid grid-cols-7 border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                    @foreach ($week as $day)
                        <div @class([
                            'min-h-24 border-r border-zinc-100 p-1.5 last:border-0 dark:border-zinc-800',
                            'bg-zinc-50/40 dark:bg-zinc-800/20' => ! $day['in_month'],
                            'ring-2 ring-inset ring-amber-400' => $day['is_today'],
                        ])>
                            <div @class([
                                'mb-1 text-[10px] font-semibold',
                                'text-zinc-400' => ! $day['in_month'],
                                'text-zinc-700 dark:text-zinc-300' => $day['in_month'] && ! $day['is_today'],
                                'text-amber-700 dark:text-amber-300' => $day['is_today'],
                            ])>
                                {{ $day['date']->day }}
                            </div>
                            @if (! empty($day['events']))
                                <div class="space-y-0.5">
                                    @foreach (array_slice($day['events'], 0, 3) as $ev)
                                        @php
                                            $b = $ev['booking'];
                                            $isExpired = $ev['type'] === 'expired';
                                            $cls = $isExpired
                                                ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-200'
                                                : 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-200';
                                        @endphp
                                        <a href="{{ route('dbos.pimpinan.booking.show', $b->id) }}" wire:navigate
                                           class="block truncate rounded px-1 py-0.5 text-[9px] font-semibold {{ $cls }}"
                                           title="{{ $b->prospectCustomer?->nama_lengkap }} ({{ $b->sales?->nama }})">
                                            {{ $isExpired ? '⌛' : '●' }} {{ $b->prospectCustomer?->nama_lengkap ?? '—' }}
                                        </a>
                                    @endforeach
                                    @if (count($day['events']) > 3)
                                        <div class="text-[9px] text-zinc-500">+{{ count($day['events']) - 3 }} {{ __('lagi') }}</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @elseif ($bookings->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.clipboard-document-list class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Tidak ada booking') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wider text-zinc-500">
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Customer') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Sales') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Proyek / Unit') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Tipe') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Tgl Booking') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Expired') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($bookings as $b)
                            @php
                                $rumah = $b->rumah;
                                $tipe = $rumah?->tipeRumah;
                                $prospect = $b->prospectCustomer;
                                $stBadge = match ($b->status) {
                                    'aktif' => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300', 'AKTIF'],
                                    'sukses' => ['bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300', 'SUKSES'],
                                    'akad' => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', 'AKAD'],
                                    'batal' => ['bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300', 'BATAL'],
                                };
                                $isExpired = $b->status === 'aktif' && $b->tanggal_expired && $b->tanggal_expired->lte(now());
                            @endphp
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $prospect?->nama_lengkap ?? '—' }}</div>
                                    @if ($prospect?->hp)
                                        <div class="font-mono text-[10px] text-zinc-500">{{ $prospect->hp }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs font-semibold text-amber-700 dark:text-amber-300">{{ $b->sales?->nama ?? '—' }}</div>
                                    <div class="font-mono text-[10px] text-zinc-400">#{{ $b->sales?->kode ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                                    <div>{{ $b->proyek?->nama_proyek ?? '—' }}</div>
                                    @if ($rumah)
                                        <div class="font-mono text-zinc-500">{{ $rumah->kode_unit }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                                    @php
                                        $tipeKode = trim((string) ($tipe->tipe ?? ''));
                                        $tipeNama = trim((string) ($tipe->nama_tipe ?? ''));
                                        $tipeLabel = $tipeNama !== '' && $tipeKode !== '' && ! str_contains(mb_strtolower($tipeKode), mb_strtolower($tipeNama))
                                            ? $tipeKode.' '.$tipeNama
                                            : ($tipeKode !== '' ? $tipeKode : $tipeNama);
                                    @endphp
                                    {{ $tipeLabel ?: '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                                    {{ $b->tanggal_booking?->translatedFormat('d M Y') }}
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($b->tanggal_expired)
                                        <div @class([
                                            'font-semibold' => $isExpired,
                                            'text-rose-600 dark:text-rose-400' => $isExpired,
                                            'text-zinc-600 dark:text-zinc-300' => ! $isExpired,
                                        ])>
                                            {{ $b->tanggal_expired->translatedFormat('d M Y') }}
                                        </div>
                                        <div class="text-[10px] text-zinc-500">{{ $b->tanggal_expired->diffForHumans() }}</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($isExpired)
                                        <span class="rounded-full bg-zinc-200 px-2 py-0.5 text-[10px] font-bold uppercase text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">
                                            EXPIRED
                                        </span>
                                    @else
                                        <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $stBadge[0]])>{{ $stBadge[1] }}</span>
                                    @endif
                                </td>
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

        <div class="mt-4">
            <flux:pagination :paginator="$bookings" />
        </div>
    @endif
</div>
