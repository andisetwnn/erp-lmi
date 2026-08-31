<?php

use App\Models\Master\Booking;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Booking Baru'), Layout('layouts.dbos')] class extends Component
{
    public string $search = '';

    public ?int $proyekFilter = null;

    public function updatedSearch(): void
    {
        // Trigger re-render via $search property change
    }

    public function setProyekFilter(?int $id): void
    {
        $this->proyekFilter = $id;
    }

    public function with(): array
    {
        $searchResults = collect();
        $cooldownByRumahId = collect();

        $search = trim($this->search);

        if (mb_strlen($search) >= 2) {
            // Cari unit (semua status) yang kode-nya mengandung input.
            // Kode unit = "{blok}-{nomor_unit}". Search bisa ke blok atau nomor_unit.
            $searchResults = Rumah::query()
                ->with(['proyek:id,nama_proyek,nama_perumahan', 'tipeRumah:id,tipe,nama_tipe,harga_jual'])
                ->when($this->proyekFilter, fn ($q) => $q->where('proyek_id', $this->proyekFilter))
                ->where(function ($q) use ($search) {
                    $bare = ltrim(str_replace(['-', ' '], '', $search), '0');
                    $q->whereRaw("CONCAT(blok, '-', nomor_unit) LIKE ?", ['%'.$search.'%'])
                        ->orWhereRaw("CONCAT(blok, nomor_unit) LIKE ?", ['%'.$bare.'%'])
                        ->orWhere('blok', 'like', $search.'%')
                        ->orWhere('nomor_unit', 'like', $search.'%');
                })
                ->orderByRaw(\App\Models\Master\Spr::urutanJenisSql(['available', 'booking', 'terjual', 'draft'], 'status'))
                ->orderBy('proyek_id')
                ->orderBy('blok')
                ->orderBy('nomor_unit')
                ->limit(20)
                ->get();

            // Cek cooldown untuk hasil pencarian
            if ($searchResults->isNotEmpty()) {
                $today = Carbon::today();
                $salesId = Auth::guard('sales')->id();

                $cooldownByRumahId = Booking::where('sales_id', $salesId)
                    ->whereIn('rumah_id', $searchResults->pluck('id'))
                    ->whereNotNull('unit_dilepas_at')
                    ->whereIn('status', ['batal', 'aktif'])
                    ->selectRaw('rumah_id, MAX(unit_dilepas_at) as latest_dilepas')
                    ->groupBy('rumah_id')
                    ->get()
                    ->mapWithKeys(function ($row) use ($today) {
                        $dilepas = Carbon::parse($row->latest_dilepas);
                        $allowedAt = $dilepas->copy()->addDays(2);
                        return $today->lt($allowedAt) ? [$row->rumah_id => $allowedAt] : [];
                    });
            }
        }

        return [
            'searchResults' => $searchResults,
            'cooldownByRumahId' => $cooldownByRumahId,
            'proyekList' => Proyek::withCount([
                'rumah',
                'rumah as available_count' => fn ($q) => $q->where('status', 'available'),
            ])->orderBy('nama_proyek')->get(),
        ];
    }
}; ?>

<section class="px-4 pb-6 pt-4">

    {{-- HEADER --}}
    <div class="mb-3 flex items-center gap-2.5">
        <a href="{{ route('dbos.booking.index') }}" wire:navigate
           class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-4" />
        </a>
        <h1 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Booking Baru') }}</h1>
    </div>

    {{-- SEARCH BAR --}}
    <div class="relative mb-2">
        <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="{{ __('Cari kode unit (A-05, B12)') }}"
               autocomplete="off"
               class="block h-11 w-full rounded-xl border-2 border-orange-200 bg-white pl-10 pr-9 text-sm font-semibold shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/30 dark:border-orange-900/50 dark:bg-zinc-900 dark:text-white" />
        <div wire:loading wire:target="search" class="absolute right-3 top-1/2 -translate-y-1/2">
            <svg class="size-4 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="50.27" stroke-dashoffset="20"/>
            </svg>
        </div>
    </div>

    {{-- FILTER PROYEK (chips) --}}
    @if ($proyekList->isNotEmpty())
        <div class="mb-3 -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
            <button type="button" wire:click="setProyekFilter(null)"
                    @class([
                        'inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-[11px] font-semibold transition',
                        'bg-orange-600 text-white shadow-sm' => $proyekFilter === null,
                        'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300' => $proyekFilter !== null,
                    ])>
                {{ __('Semua Proyek') }}
            </button>
            @foreach ($proyekList as $p)
                <button type="button" wire:click="setProyekFilter({{ $p->id }})"
                        @class([
                            'inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-[11px] font-semibold transition',
                            'bg-orange-600 text-white shadow-sm' => $proyekFilter === $p->id,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300' => $proyekFilter !== $p->id,
                        ])>
                    {{ $p->nama_proyek }}
                </button>
            @endforeach
        </div>
    @endif

    {{-- SEARCH RESULTS --}}
    @if (mb_strlen(trim($search)) >= 2)
        <div class="mb-4">
            <div class="mb-1.5 px-1 text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                {{ __('Hasil Pencarian') }}
                @if ($searchResults->isNotEmpty())
                    <span class="font-normal normal-case text-zinc-400">· {{ $searchResults->count() }} unit</span>
                @endif
            </div>

            @if ($searchResults->isEmpty())
                <div class="rounded-xl border-2 border-dashed border-zinc-200 bg-white px-4 py-6 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon.magnifying-glass class="mx-auto size-6 text-zinc-400" />
                    <p class="mt-1.5 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                        {{ __('Unit tidak ditemukan') }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-zinc-500">
                        {{ __('Coba kata kunci lain, atau browse via proyek di bawah.') }}
                    </p>
                </div>
            @else
                <div class="space-y-1.5">
                    @foreach ($searchResults as $r)
                        @php
                            $cooldownUntil = $cooldownByRumahId[$r->id] ?? null;
                            $tipe = $r->tipeRumah;
                            $tipeLabel = $tipe ? trim(($tipe->tipe ?? '').' '.($tipe->nama_tipe ?? '')) : '—';

                            // Tentukan tampilan berdasarkan status & cooldown
                            if ($cooldownUntil) {
                                $state = [
                                    'clickable' => false,
                                    'border' => 'border-zinc-200 dark:border-zinc-700',
                                    'avatar' => 'bg-zinc-400',
                                    'badgeClr' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                    'badgeIcon' => 'lock-closed',
                                    'badgeLabel' => $cooldownUntil->translatedFormat('d M'),
                                    'opacity' => 'opacity-70',
                                ];
                            } else {
                                $state = match ($r->status) {
                                    'available' => [
                                        'clickable' => true,
                                        'border' => 'border-emerald-200 dark:border-emerald-900/50',
                                        'avatar' => 'bg-emerald-500',
                                        'badgeClr' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
                                        'badgeIcon' => 'check-circle',
                                        'badgeLabel' => 'Tersedia',
                                        'opacity' => '',
                                    ],
                                    'booking' => [
                                        'clickable' => false,
                                        'border' => 'border-zinc-200 dark:border-zinc-700',
                                        'avatar' => 'bg-amber-500',
                                        'badgeClr' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
                                        'badgeIcon' => 'clock',
                                        'badgeLabel' => 'Booked',
                                        'opacity' => 'opacity-70',
                                    ],
                                    'terjual' => [
                                        'clickable' => false,
                                        'border' => 'border-zinc-200 dark:border-zinc-700',
                                        'avatar' => 'bg-blue-500',
                                        'badgeClr' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
                                        'badgeIcon' => 'check-badge',
                                        'badgeLabel' => 'Terjual',
                                        'opacity' => 'opacity-70',
                                    ],
                                    'draft' => [
                                        'clickable' => false,
                                        'border' => 'border-zinc-200 dark:border-zinc-700',
                                        'avatar' => 'bg-zinc-400',
                                        'badgeClr' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                        'badgeIcon' => 'pencil-square',
                                        'badgeLabel' => 'Draft',
                                        'opacity' => 'opacity-70',
                                    ],
                                };
                            }
                        @endphp

                        @php
                            $cardClasses = 'flex items-center gap-2.5 rounded-xl border bg-white px-2.5 py-2 dark:bg-zinc-900 '.$state['border'].' '.$state['opacity'];
                        @endphp

                        @if ($state['clickable'])
                            <a href="{{ route('dbos.booking.form', $r->id) }}" wire:navigate
                               class="{{ $cardClasses }} transition hover:border-emerald-400 active:scale-[0.98]">
                                @include('pages.dbos.booking._search-result-row', ['r' => $r, 'tipeLabel' => $tipeLabel, 'state' => $state, 'clickable' => true])
                            </a>
                        @else
                            <div class="{{ $cardClasses }}">
                                @include('pages.dbos.booking._search-result-row', ['r' => $r, 'tipeLabel' => $tipeLabel, 'state' => $state, 'clickable' => false])
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- BROWSE MANUAL via Proyek --}}
    <div>
        <div class="mb-2 flex items-center justify-between px-1">
            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                {{ mb_strlen(trim($search)) >= 2 ? __('Atau browse via proyek') : __('Browse via proyek') }}
            </span>
        </div>

        @if ($proyekList->isEmpty())
            <div class="rounded-xl border-2 border-dashed border-zinc-200 bg-white px-6 py-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.home-modern class="mx-auto size-8 text-zinc-400" />
                <p class="mt-2 text-xs text-zinc-500">{{ __('Belum ada proyek. Hubungi admin.') }}</p>
            </div>
        @else
            <div class="space-y-1.5">
                @foreach ($proyekList as $p)
                    @php $hasAvailable = $p->available_count > 0; @endphp
                    <a href="{{ route('dbos.booking.blok', $p->id) }}"
                       @if ($hasAvailable) wire:navigate @endif
                       @class([
                           'flex items-center gap-2.5 rounded-xl border bg-white px-2.5 py-2 dark:bg-zinc-900',
                           'border-zinc-200 dark:border-zinc-700' => $hasAvailable,
                           'border-zinc-200 opacity-50 pointer-events-none dark:border-zinc-700' => ! $hasAvailable,
                       ])>
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300">
                            <flux:icon.home-modern class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-xs font-bold text-zinc-900 dark:text-white">{{ $p->nama_proyek }}</div>
                            <div class="flex items-center gap-1 text-[10px]">
                                <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $p->available_count }}</span>
                                <span class="text-zinc-400">{{ __('tersedia') }}</span>
                                <span class="text-zinc-300">·</span>
                                <span class="text-zinc-500">{{ $p->rumah_count }} {{ __('total') }}</span>
                            </div>
                        </div>
                        @if ($hasAvailable)
                            <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400" />
                        @else
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-zinc-800">
                                {{ __('Penuh') }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </div>

</section>
