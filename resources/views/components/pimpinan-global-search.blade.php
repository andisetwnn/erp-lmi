<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Sales;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $query = '';

    public bool $open = false;

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
        if (! $this->open) {
            $this->query = '';
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
    }

    public function with(): array
    {
        $results = [
            'anggota' => collect(),
            'prospect' => collect(),
            'booking' => collect(),
        ];

        if (! $this->open || strlen(trim($this->query)) < 2) {
            return ['results' => $results];
        }

        /** @var Sales|null $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        if (! $pimpinan || ! $pimpinan->isPimpinan()) {
            return ['results' => $results];
        }

        $grup = $pimpinan->grupYangDipimpin();
        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $term = '%'.trim($this->query).'%';

        $results['anggota'] = Sales::whereIn('id', $bawahanIds)
            ->where(function ($q) use ($term) {
                $q->where('nama', 'like', $term)->orWhere('kode', 'like', $term);
            })
            ->limit(5)
            ->get(['id', 'nama', 'kode']);

        $results['prospect'] = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->where(function ($q) use ($term) {
                $q->where('nama_lengkap', 'like', $term)
                    ->orWhere('hp', 'like', $term)
                    ->orWhere('nik', 'like', $term);
            })
            ->with('sales:id,nama')
            ->limit(5)
            ->get(['id', 'nama_lengkap', 'hp', 'status', 'sales_id']);

        $results['booking'] = Booking::whereIn('sales_id', $bawahanIds)
            ->whereHas('prospectCustomer', function ($q) use ($term) {
                $q->where('nama_lengkap', 'like', $term)
                    ->orWhere('hp', 'like', $term)
                    ->orWhere('nik', 'like', $term);
            })
            ->with(['prospectCustomer:id,nama_lengkap', 'rumah:id,blok,nomor_unit', 'sales:id,nama'])
            ->limit(5)
            ->get(['id', 'sales_id', 'prospect_customer_id', 'rumah_id', 'status']);

        return ['results' => $results];
    }
}; ?>

<div x-data="{ open: @entangle('open').live }" @keydown.window.ctrl.k.prevent="open = !open" @keydown.window.meta.k.prevent="open = !open">
    {{-- Trigger button --}}
    <button type="button" @click="open = !open"
            class="inline-flex h-9 items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-500 transition hover:border-zinc-300 hover:text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
        <flux:icon.magnifying-glass class="size-4" />
        <span class="hidden sm:inline">{{ __('Cari...') }}</span>
        <kbd class="hidden rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 sm:inline dark:border-zinc-700 dark:bg-zinc-800">Ctrl+K</kbd>
    </button>

    {{-- Backdrop + Panel --}}
    <div x-show="open" x-cloak
         @click.self="open = false; $wire.close()"
         @keydown.escape.window="open = false; $wire.close()"
         class="fixed inset-0 z-50 flex items-start justify-center bg-zinc-900/60 px-4 pt-20 backdrop-blur-sm">
        <div x-show="open"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             @click.stop
             class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900">

            {{-- Input --}}
            <div class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:icon.magnifying-glass class="size-5 text-zinc-400" />
                <input type="text" wire:model.live.debounce.250ms="query"
                       x-ref="searchInput"
                       x-init="$watch('open', value => { if (value) $nextTick(() => $refs.searchInput.focus()) })"
                       placeholder="{{ __('Cari nama anggota, customer, HP, atau NIK...') }}"
                       class="flex-1 border-0 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white" />
                <button type="button" @click="open = false; $wire.close()"
                        class="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800">
                    <flux:icon.x-mark class="size-4" />
                </button>
            </div>

            {{-- Results --}}
            <div class="max-h-96 overflow-y-auto">
                @php
                    $total = $results['anggota']->count() + $results['prospect']->count() + $results['booking']->count();
                @endphp

                @if (strlen(trim($query)) < 2)
                    <div class="px-4 py-8 text-center text-sm text-zinc-500">
                        {{ __('Ketik minimal 2 karakter untuk mulai mencari.') }}
                    </div>
                @elseif ($total === 0)
                    <div class="px-4 py-8 text-center text-sm text-zinc-500">
                        <flux:icon.face-frown class="mx-auto mb-2 size-8 text-zinc-300" />
                        {{ __('Tidak ada hasil untuk') }} "<span class="font-semibold">{{ $query }}</span>".
                    </div>
                @else
                    {{-- Anggota --}}
                    @if ($results['anggota']->isNotEmpty())
                        <div class="border-b border-zinc-100 dark:border-zinc-800">
                            <div class="bg-zinc-50 px-4 py-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-zinc-800/50">
                                {{ __('Anggota') }} ({{ $results['anggota']->count() }})
                            </div>
                            @foreach ($results['anggota'] as $a)
                                <a href="{{ route('dbos.pimpinan.anggota.show', $a->id) }}" wire:navigate
                                   @click="open = false"
                                   class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-amber-50 dark:hover:bg-amber-950/20">
                                    <flux:icon.user-circle class="size-5 text-amber-600" />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $a->nama }}</div>
                                        <div class="font-mono text-[10px] text-zinc-500">#{{ $a->kode }}</div>
                                    </div>
                                    <flux:icon.arrow-right class="size-3.5 text-zinc-400" />
                                </a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Prospect --}}
                    @if ($results['prospect']->isNotEmpty())
                        <div class="border-b border-zinc-100 dark:border-zinc-800">
                            <div class="bg-zinc-50 px-4 py-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-zinc-800/50">
                                {{ __('Prospect') }} ({{ $results['prospect']->count() }})
                            </div>
                            @foreach ($results['prospect'] as $p)
                                <a href="{{ route('dbos.pimpinan.prospect.show', $p->id) }}" wire:navigate
                                   @click="open = false"
                                   class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-orange-50 dark:hover:bg-orange-950/20">
                                    <flux:icon.circle-stack class="size-5 text-orange-600" />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $p->nama_lengkap }}</div>
                                        <div class="text-[11px] text-zinc-500">
                                            {{ $p->hp }}
                                            @if ($p->sales)
                                                · {{ $p->sales->nama }}
                                            @endif
                                        </div>
                                    </div>
                                    <span @class([
                                        'rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase',
                                        match ($p->status) {
                                            'cold' => 'bg-blue-100 text-blue-700',
                                            'warm' => 'bg-amber-100 text-amber-700',
                                            'hot' => 'bg-red-100 text-red-700',
                                            'finish' => 'bg-green-100 text-green-700',
                                            'archive' => 'bg-zinc-100 text-zinc-700',
                                        }
                                    ])>{{ strtoupper($p->status) }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Booking --}}
                    @if ($results['booking']->isNotEmpty())
                        <div>
                            <div class="bg-zinc-50 px-4 py-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-zinc-800/50">
                                {{ __('Booking') }} ({{ $results['booking']->count() }})
                            </div>
                            @foreach ($results['booking'] as $b)
                                <a href="{{ route('dbos.pimpinan.booking.show', $b->id) }}" wire:navigate
                                   @click="open = false"
                                   class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-blue-50 dark:hover:bg-blue-950/20">
                                    <flux:icon.clipboard-document-list class="size-5 text-blue-600" />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">
                                            {{ $b->prospectCustomer?->nama_lengkap ?? '—' }}
                                        </div>
                                        <div class="text-[11px] text-zinc-500">
                                            @if ($b->rumah)
                                                {{ $b->rumah->blok }}-{{ $b->rumah->nomor_unit }} ·
                                            @endif
                                            {{ $b->sales?->nama ?? '—' }}
                                        </div>
                                    </div>
                                    <span class="rounded-full bg-zinc-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ strtoupper($b->status) }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            {{-- Footer hint --}}
            <div class="border-t border-zinc-200 bg-zinc-50/50 px-4 py-2 text-[10px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/30">
                <kbd class="rounded border border-zinc-200 bg-white px-1 py-0.5 font-mono dark:border-zinc-700 dark:bg-zinc-900">Esc</kbd>
                {{ __('untuk tutup') }} ·
                <kbd class="rounded border border-zinc-200 bg-white px-1 py-0.5 font-mono dark:border-zinc-700 dark:bg-zinc-900">Ctrl+K</kbd>
                {{ __('untuk buka') }}
            </div>
        </div>
    </div>
</div>
