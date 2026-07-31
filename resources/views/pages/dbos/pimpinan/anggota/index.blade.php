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

new #[Title('Anggota Grup'), Layout('layouts.pimpinan')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: 'total')]
    public string $sort = 'total';

    #[Url(as: 'status', except: 'aktif')]
    public string $filterStatus = 'aktif';

    #[Url(as: 'stagnan', except: false)]
    public bool $onlyStagnan = false;

    #[Url(as: 'preset', except: '')]
    public string $preset = '';

    // Inline target edit
    public ?int $editTargetSalesId = null;

    public int $editTargetProspect = 0;

    public int $editTargetBooking = 0;

    // Comparison selection
    public array $selectedIds = [];

    public function setPreset(string $p): void
    {
        $this->preset = $p;
        // Apply preset behavior
        match ($p) {
            'stagnan' => $this->onlyStagnan = true,
            'no-target' => null,
            '' => null,
            default => null,
        };
    }

    public function clearPreset(): void
    {
        $this->preset = '';
        $this->onlyStagnan = false;
        $this->reset(['search']);
    }

    public function openEditTarget(int $salesId): void
    {
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $anggota = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->find($salesId);
        if (! $anggota) return;

        $target = $anggota->targetForPeriode(SalesTarget::currentPeriode());
        $this->editTargetSalesId = $salesId;
        $this->editTargetProspect = $target?->target_prospect ?? 0;
        $this->editTargetBooking = $target?->target_booking ?? 0;
        $this->resetErrorBag();
        Flux::modal('edit-target')->show();
    }

    public function saveInlineTarget(): void
    {
        $this->validate([
            'editTargetProspect' => ['integer', 'min:0', 'max:1000'],
            'editTargetBooking' => ['integer', 'min:0', 'max:1000'],
        ]);

        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $anggota = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->findOrFail($this->editTargetSalesId);

        SalesTarget::updateOrCreate(
            ['sales_id' => $anggota->id, 'periode' => SalesTarget::currentPeriode()],
            [
                'target_prospect' => $this->editTargetProspect,
                'target_booking' => $this->editTargetBooking,
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
                'target_prospect' => $this->editTargetProspect,
                'target_booking' => $this->editTargetBooking,
            ],
        );

        Flux::modal('edit-target')->close();
        $this->editTargetSalesId = null;
        Flux::toast(variant: 'success', text: 'Target '.$anggota->nama.' tersimpan.');
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function exportExcel()
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();
        $filename = 'anggota-grup-'.now()->format('Y-m-d').'.xlsx';
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PimpinanAnggotaExport($grup->id, $pimpinan->id),
            $filename
        );
    }

    public function exportPdf()
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        // Re-render data anggota via with() supaya konsisten dengan tampilan tabel
        $data = $this->with();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.pimpinan-anggota-pdf', [
            'anggota' => $data['anggota'],
            'grup' => $grup,
            'pimpinanNama' => $pimpinan->nama,
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'anggota-grup-'.now()->format('Y-m-d').'.pdf',
        );
    }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $query = Sales::with(['jenisSales', 'bank'])
            ->where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id);

        if ($this->filterStatus === 'aktif') {
            $query->where('is_aktif', true);
        } elseif ($this->filterStatus === 'nonaktif') {
            $query->where('is_aktif', false);
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('nama', 'like', $term)
                    ->orWhere('kode', 'like', $term);
            });
        }

        $anggota = $query->get();
        $anggotaIds = $anggota->pluck('id');

        // Statistik agregat (all-time)
        $statsProspectAll = ProspectCustomer::whereIn('sales_id', $anggotaIds)
            ->where('status', '!=', 'archive')
            ->selectRaw('sales_id, COUNT(*) as cnt')
            ->groupBy('sales_id')
            ->pluck('cnt', 'sales_id')
            ->toArray();

        $statsBooking = Booking::whereIn('sales_id', $anggotaIds)
            ->selectRaw('sales_id, status, COUNT(*) as cnt')
            ->groupBy('sales_id', 'status')
            ->get()
            ->groupBy('sales_id')
            ->map(fn ($rows) => $rows->pluck('cnt', 'status')->toArray());

        // Statistik bulan ini (untuk progress target)
        $monthStart = now()->startOfMonth();
        $statsProspectBulan = ProspectCustomer::whereIn('sales_id', $anggotaIds)
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('sales_id, COUNT(*) as cnt')
            ->groupBy('sales_id')
            ->pluck('cnt', 'sales_id')
            ->toArray();

        $statsBookingBulan = Booking::whereIn('sales_id', $anggotaIds)
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('sales_id, COUNT(*) as cnt')
            ->groupBy('sales_id')
            ->pluck('cnt', 'sales_id')
            ->toArray();

        // Target bulan ini
        $periodeBulan = SalesTarget::currentPeriode();
        $targets = SalesTarget::whereIn('sales_id', $anggotaIds)
            ->where('periode', $periodeBulan)
            ->get()
            ->keyBy('sales_id');

        $lastActivity = ProspectCustomerStatusLog::whereIn('changed_by_sales_id', $anggotaIds)
            ->selectRaw('changed_by_sales_id, MAX(created_at) as last_at')
            ->groupBy('changed_by_sales_id')
            ->pluck('last_at', 'changed_by_sales_id')
            ->toArray();

        $anggota = $anggota->map(function ($a) use ($statsProspectAll, $statsBooking, $statsProspectBulan, $statsBookingBulan, $targets, $lastActivity) {
            $b = $statsBooking->get($a->id, []);
            $a->stat_prospect = $statsProspectAll[$a->id] ?? 0;
            $a->stat_booking = $b['aktif'] ?? 0;
            $a->stat_sukses = $b['sukses'] ?? 0;
            $a->stat_akad = $b['akad'] ?? 0;
            $a->stat_total = $a->stat_booking + $a->stat_sukses + $a->stat_akad;

            // Progress vs target bulan ini
            $a->prospect_bulan_ini = $statsProspectBulan[$a->id] ?? 0;
            $a->booking_bulan_ini = $statsBookingBulan[$a->id] ?? 0;
            $target = $targets->get($a->id);
            $a->target_prospect = $target?->target_prospect ?? 0;
            $a->target_booking = $target?->target_booking ?? 0;
            $a->progress_prospect_pct = $a->target_prospect > 0
                ? min(100, round(($a->prospect_bulan_ini / $a->target_prospect) * 100))
                : null;
            $a->progress_booking_pct = $a->target_booking > 0
                ? min(100, round(($a->booking_bulan_ini / $a->target_booking) * 100))
                : null;

            $a->last_activity_at = isset($lastActivity[$a->id]) ? Carbon::parse($lastActivity[$a->id]) : null;
            $a->is_stagnan = $a->is_aktif && (! $a->last_activity_at || $a->last_activity_at->lt(now()->subDays(7)));
            return $a;
        });

        // Filter stagnan (kalau toggle on)
        if ($this->onlyStagnan) {
            $anggota = $anggota->filter(fn ($a) => $a->is_stagnan);
        }

        // Filter preset "no-target" — anggota yang belum di-set target bulan ini
        if ($this->preset === 'no-target') {
            $anggota = $anggota->filter(fn ($a) => $a->target_prospect === 0 && $a->target_booking === 0);
        }
        // Filter preset "overload" — anggota dengan workload terbesar (>= 80% dari max)
        if ($this->preset === 'overload') {
            $maxWorkload = $anggota->max('stat_prospect') ?: 0;
            if ($maxWorkload > 0) {
                $threshold = max(1, (int) round($maxWorkload * 0.8));
                $anggota = $anggota->filter(fn ($a) => $a->stat_prospect >= $threshold);
            }
        }
        // Filter preset "below-target" — anggota progress target <50%
        if ($this->preset === 'below-target') {
            $anggota = $anggota->filter(function ($a) {
                $hasTarget = $a->target_prospect > 0 || $a->target_booking > 0;
                if (! $hasTarget) return false;
                $progPros = $a->progress_prospect_pct ?? 100;
                $progBook = $a->progress_booking_pct ?? 100;
                return min($progPros, $progBook) < 50;
            });
        }

        $anggota = match ($this->sort) {
            'nama_asc' => $anggota->sortBy('nama')->values(),
            'nama_desc' => $anggota->sortByDesc('nama')->values(),
            'prospect' => $anggota->sortByDesc('stat_prospect')->values(),
            'aktivitas' => $anggota->sortByDesc(fn ($a) => $a->last_activity_at?->timestamp ?? 0)->values(),
            'target' => $anggota->sortByDesc(fn ($a) => $a->progress_prospect_pct ?? -1)->values(),
            default => $anggota->sortByDesc('stat_total')->values(),
        };

        return compact('grup', 'anggota');
    }

    public function setStatus(string $s): void
    {
        $this->filterStatus = in_array($s, ['aktif', 'nonaktif', 'semua'], true) ? $s : 'aktif';
    }
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Anggota Grup') }}</flux:heading>
            <flux:subheading>
                {{ $grup->nama }} · {{ __('Target progress bulan :bulan', ['bulan' => now()->translatedFormat('F Y')]) }}
            </flux:subheading>
        </div>
        <flux:dropdown align="end">
            <flux:button icon="arrow-down-tray" variant="filled" size="sm">
                <span wire:loading.remove wire:target="exportExcel,exportPdf">{{ __('Export') }}</span>
                <span wire:loading wire:target="exportExcel,exportPdf">{{ __('Memproses...') }}</span>
            </flux:button>
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

    {{-- QUICK FILTER CHIPS --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $presets = [
                '' => ['Semua', 'zinc', null],
                'stagnan' => ['Stagnan >7 hari', 'rose', 'exclamation-triangle'],
                'no-target' => ['Belum di-target', 'purple', 'cursor-arrow-rays'],
                'below-target' => ['Di bawah target', 'amber', 'arrow-trending-down'],
                'overload' => ['Overload', 'red', 'fire'],
            ];
        @endphp
        @foreach ($presets as $key => [$label, $color, $icon])
            @php
                $active = $preset === $key;
                $activeCls = match ($color) {
                    'zinc' => 'bg-zinc-700 text-white border-zinc-700',
                    'rose' => 'bg-rose-600 text-white border-rose-600',
                    'purple' => 'bg-purple-600 text-white border-purple-600',
                    'amber' => 'bg-amber-600 text-white border-amber-600',
                    'red' => 'bg-rose-600 text-white border-rose-600',
                };
            @endphp
            <button type="button" wire:click="setPreset('{{ $key }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition',
                        $activeCls => $active,
                        'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => ! $active,
                    ])>
                @if ($icon)
                    @switch($icon)
                        @case('exclamation-triangle') <flux:icon.exclamation-triangle class="size-3" /> @break
                        @case('cursor-arrow-rays') <flux:icon.cursor-arrow-rays class="size-3" /> @break
                        @case('arrow-trending-down') <flux:icon.arrow-trending-down class="size-3" /> @break
                        @case('fire') <flux:icon.fire class="size-3" /> @break
                    @endswitch
                @endif
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- FILTER BAR --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @php
            $tabs = [
                'aktif' => __('Aktif'),
                'nonaktif' => __('Nonaktif'),
                'semua' => __('Semua'),
            ];
        @endphp
        <div class="inline-flex overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            @foreach ($tabs as $key => $label)
                @php $active = $filterStatus === $key; @endphp
                <button type="button" wire:click="setStatus('{{ $key }}')"
                        @class([
                            'px-3 py-1.5 text-xs font-semibold transition',
                            'bg-amber-600 text-white' => $active,
                            'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $active,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex-1 min-w-60">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama atau kode...')" />
        </div>

        <flux:select wire:model.live="sort" class="w-52">
            <flux:select.option value="total">{{ __('Transaksi terbanyak') }}</flux:select.option>
            <flux:select.option value="prospect">{{ __('Prospect terbanyak') }}</flux:select.option>
            <flux:select.option value="target">{{ __('Progress target') }}</flux:select.option>
            <flux:select.option value="aktivitas">{{ __('Aktivitas terbaru') }}</flux:select.option>
            <flux:select.option value="nama_asc">{{ __('Nama A-Z') }}</flux:select.option>
            <flux:select.option value="nama_desc">{{ __('Nama Z-A') }}</flux:select.option>
        </flux:select>

        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-900">
            <input type="checkbox" wire:model.live="onlyStagnan"
                   class="size-4 cursor-pointer rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-600 dark:bg-zinc-800" />
            <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Hanya stagnan (>7 hari)') }}</span>
        </label>
    </div>

    {{-- TABLE --}}
    @if ($anggota->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.users class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Tidak ada anggota') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wider text-zinc-500">
                            <th class="px-3 py-3 text-left font-semibold"><span class="sr-only">{{ __('Pilih') }}</span></th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Anggota') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Target Bulan Ini') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Prospect') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Booking') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('SPR') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Akad') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Aktivitas') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($anggota as $a)
                            @php
                                $initials = collect(explode(' ', $a->nama))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
                                $waLink = $a->telepon ? 'https://wa.me/'.preg_replace('/[^0-9]/', '', $a->telepon).'?text='.urlencode("Halo {$a->nama}, mohon dicek dashboard DBOS-mu. Ada prospect/booking yang perlu di-follow up. Terima kasih.") : null;
                            @endphp
                            <tr @class([
                                'transition hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30',
                                'bg-amber-50/50 dark:bg-amber-950/20' => in_array($a->id, $selectedIds),
                            ])>
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $a->id }}" wire:model.live="selectedIds"
                                           class="size-4 cursor-pointer rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-600 dark:bg-zinc-800" />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                            {{ $initials }}
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $a->nama }}</span>
                                                @if (! $a->is_aktif)
                                                    <span class="rounded-full bg-zinc-200 px-1.5 py-0.5 text-[9px] font-bold uppercase text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                                        {{ __('Nonaktif') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="font-mono text-[10px] text-zinc-500">#{{ $a->kode }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="group relative">
                                        @if ($a->target_prospect > 0 || $a->target_booking > 0)
                                            <div class="space-y-1.5">
                                                @if ($a->target_prospect > 0)
                                                    @php
                                                        $pPct = $a->progress_prospect_pct ?? 0;
                                                        $pColor = $pPct >= 100 ? 'bg-emerald-500' : ($pPct >= 50 ? 'bg-amber-500' : 'bg-rose-400');
                                                    @endphp
                                                    <div>
                                                        <div class="flex items-center justify-between text-[10px]">
                                                            <span class="font-semibold text-zinc-500">P: {{ $a->prospect_bulan_ini }}/{{ $a->target_prospect }}</span>
                                                            <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $pPct }}%</span>
                                                        </div>
                                                        <div class="mt-0.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                            <div @class(['h-full', $pColor]) style="width: {{ $pPct }}%"></div>
                                                        </div>
                                                    </div>
                                                @endif
                                                @if ($a->target_booking > 0)
                                                    @php
                                                        $bPct = $a->progress_booking_pct ?? 0;
                                                        $bColor = $bPct >= 100 ? 'bg-emerald-500' : ($bPct >= 50 ? 'bg-amber-500' : 'bg-rose-400');
                                                    @endphp
                                                    <div>
                                                        <div class="flex items-center justify-between text-[10px]">
                                                            <span class="font-semibold text-zinc-500">B: {{ $a->booking_bulan_ini }}/{{ $a->target_booking }}</span>
                                                            <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $bPct }}%</span>
                                                        </div>
                                                        <div class="mt-0.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                            <div @class(['h-full', $bColor]) style="width: {{ $bPct }}%"></div>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                            <button type="button" wire:click="openEditTarget({{ $a->id }})"
                                                    class="absolute -right-1 -top-1 hidden h-6 w-6 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 transition hover:bg-amber-500 hover:text-white group-hover:flex dark:bg-zinc-800"
                                                    title="{{ __('Edit target') }}">
                                                <flux:icon.pencil-square class="size-3" />
                                            </button>
                                        @else
                                            <button type="button" wire:click="openEditTarget({{ $a->id }})"
                                                    class="inline-flex items-center gap-1 text-xs text-zinc-400 hover:text-amber-600 hover:underline">
                                                <flux:icon.plus-circle class="size-3" />
                                                {{ __('Set target') }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-orange-600 dark:text-orange-400">{{ $a->stat_prospect }}</td>
                                <td class="px-4 py-3 text-right font-bold text-amber-600 dark:text-amber-400">{{ $a->stat_booking }}</td>
                                <td class="px-4 py-3 text-right font-bold text-purple-600 dark:text-purple-400">{{ $a->stat_sukses }}</td>
                                <td class="px-4 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400">{{ $a->stat_akad }}</td>
                                <td class="px-4 py-3 text-xs">
                                    <span @class([
                                        'inline-flex items-center gap-1',
                                        'text-rose-600 dark:text-rose-400 font-semibold' => $a->is_stagnan,
                                        'text-zinc-500' => ! $a->is_stagnan,
                                    ])>
                                        @if ($a->is_stagnan)
                                            <flux:icon.exclamation-triangle class="size-3" />
                                        @else
                                            <flux:icon.clock class="size-3" />
                                        @endif
                                        {{ $a->last_activity_at?->diffForHumans() ?? __('belum pernah') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex gap-1">
                                        @if ($waLink && $a->is_stagnan)
                                            <a href="{{ $waLink }}" target="_blank"
                                               class="inline-flex h-8 items-center gap-1 rounded-lg bg-green-600 px-2 text-xs font-semibold text-white transition hover:bg-green-700 active:scale-95"
                                               title="{{ __('Tegur via WA') }}">
                                                <flux:icon.phone class="size-3.5" />
                                                {{ __('Tegur') }}
                                            </a>
                                        @endif
                                        <a href="{{ route('dbos.pimpinan.anggota.show', $a->id) }}" wire:navigate
                                           class="inline-flex h-8 items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                            <flux:icon.eye class="size-3.5" />
                                            {{ __('Detail') }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- FAB BANDINGKAN (kalau ada selection ≥ 2) --}}
    @if (count($selectedIds) >= 2)
        <div class="fixed bottom-6 right-6 z-40 flex items-center gap-2 rounded-2xl bg-zinc-900 px-4 py-3 text-white shadow-2xl">
            <flux:icon.scale class="size-5 text-amber-400" />
            <span class="text-sm font-semibold">{{ count($selectedIds) }} {{ __('anggota dipilih') }}</span>
            <a href="{{ route('dbos.pimpinan.anggota.compare', ['ids' => implode(',', $selectedIds)]) }}" wire:navigate
               class="inline-flex h-9 items-center gap-1 rounded-lg bg-amber-500 px-3 text-xs font-bold text-amber-950 transition hover:bg-amber-400">
                <flux:icon.arrows-right-left class="size-4" />
                {{ __('Bandingkan') }}
            </a>
            <button type="button" wire:click="clearSelection"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-zinc-800 text-zinc-300 hover:bg-zinc-700"
                    title="{{ __('Batal pilih') }}">
                <flux:icon.x-mark class="size-4" />
            </button>
        </div>
    @endif

    {{-- MODAL EDIT TARGET --}}
    <flux:modal name="edit-target" class="md:w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Set Target Bulan Ini') }}</flux:heading>
                <flux:subheading>{{ __('Periode:') }} {{ now()->translatedFormat('F Y') }}</flux:subheading>
            </div>
            <flux:field>
                <flux:label>{{ __('Target Prospect') }}</flux:label>
                <flux:input wire:model="editTargetProspect" type="number" min="0" max="1000" />
                <flux:error name="editTargetProspect" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Target Booking') }}</flux:label>
                <flux:input wire:model="editTargetBooking" type="number" min="0" max="1000" />
                <flux:error name="editTargetBooking" />
            </flux:field>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="button" wire:click="saveInlineTarget"
                             class="bg-amber-600! hover:bg-amber-700!">
                    {{ __('Simpan') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
