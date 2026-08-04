<?php

use App\Models\Master\SprSwitching;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detail Pindah Kavling')] class extends Component
{
    public SprSwitching $switching;

    public function mount(int $id): void
    {
        $this->switching = SprSwitching::with([
            'sprLamaA.rumah', 'sprLamaA.prospectCustomer',
            'sprBaruA.rumah',
            'sprLamaB.rumah', 'sprLamaB.prospectCustomer',
            'sprBaruB.rumah',
            'processedBy:id,name',
            'realisasi.spr',
        ])->findOrFail($id);
    }
}; ?>

@php
    $sw = $switching;
    $fmt = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $isPindah = $sw->tipe === 'pindah';
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <a href="{{ route('marketing.spr-pindah.list') }}" wire:navigate
                   class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                   title="{{ __('Kembali') }}">
                    <flux:icon.arrow-left class="size-4" />
                </a>
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-emerald-500 to-emerald-700 text-white shadow-sm">
                        <flux:icon.arrows-right-left class="size-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">
                                {{ $isPindah ? __('Pindah Unit') : __('Tukar Unit') }}
                            </flux:heading>
                            <span @class([
                                'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300' => $isPindah,
                                'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300' => ! $isPindah,
                            ])>{{ $isPindah ? 'PINDAH' : 'TUKAR' }}</span>
                        </div>
                        <flux:subheading class="font-mono">{{ $sw->nomor_switching }}</flux:subheading>
                    </div>
                </div>
            </div>
        </div>

        {{-- METADATA --}}
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Diproses') }}</div>
                <div class="mt-0.5 text-sm font-semibold">{{ $sw->processed_at?->translatedFormat('d M Y H:i') ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Oleh') }}</div>
                <div class="mt-0.5 text-sm font-semibold">{{ $sw->processedBy?->name ?? '—' }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Alasan') }}</div>
                <div class="mt-0.5 text-sm">{{ $sw->alasan }}</div>
            </div>
        </div>

        {{-- SISI A --}}
        @php
            $lamaA = $sw->sprLamaA;
            $baruA = $sw->sprBaruA;
            $selisihA = (float) $sw->selisih_a;
        @endphp
        <div class="mb-4 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-2 text-xs font-bold uppercase tracking-wider text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                {{ __('Sisi A') }} — {{ $lamaA?->prospectCustomer?->nama_lengkap }}
            </div>
            <div class="grid grid-cols-1 divide-y divide-zinc-100 md:grid-cols-2 md:divide-x md:divide-y-0 dark:divide-zinc-800">
                {{-- Lama --}}
                <div class="p-4">
                    <div class="mb-2 flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:text-orange-400">
                        <flux:icon.archive-box class="size-3" />
                        {{ __('SPR Lama (dibatalkan)') }}
                    </div>
                    <a href="{{ route('marketing.spr.show', $lamaA->id) }}" wire:navigate
                       class="font-mono text-sm font-bold text-orange-700 hover:underline dark:text-orange-400">
                        {{ $lamaA?->nomor_display }}
                    </a>
                    <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                        {{ __('Unit') }}: <b>{{ $lamaA?->rumah?->kode_unit }}</b> · {{ $lamaA?->kategori }}
                    </div>
                    <div class="mt-2 text-xs">
                        <span class="text-zinc-500">{{ __('Total harga') }}</span>
                        <div class="font-mono font-bold">{{ $fmt($lamaA?->total_harga) }}</div>
                    </div>
                </div>
                {{-- Baru --}}
                <div class="p-4">
                    <div class="mb-2 flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                        <flux:icon.sparkles class="size-3" />
                        {{ __('SPR Baru') }}
                    </div>
                    <a href="{{ route('marketing.spr.show', $baruA->id) }}" wire:navigate
                       class="font-mono text-sm font-bold text-emerald-700 hover:underline dark:text-emerald-400">
                        {{ $baruA?->nomor_display }}
                    </a>
                    <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                        {{ __('Unit') }}: <b>{{ $baruA?->rumah?->kode_unit }}</b> · {{ $baruA?->kategori }}
                    </div>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-zinc-500">{{ __('Total harga') }}</span>
                            <div class="font-mono font-bold">{{ $fmt($baruA?->total_harga) }}</div>
                        </div>
                        <div>
                            <span class="text-zinc-500">{{ __('Selisih') }}</span>
                            <div @class([
                                'font-mono font-bold',
                                'text-amber-700 dark:text-amber-400' => $selisihA > 0,
                                'text-emerald-700 dark:text-emerald-400' => $selisihA < 0,
                                'text-zinc-500' => $selisihA == 0,
                            ])>
                                {{ $selisihA > 0 ? '+' : '' }}{{ $fmt($selisihA) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- SISI B (hanya untuk Tukar Unit) --}}
        @if (! $isPindah && $sw->sprLamaB)
            @php
                $lamaB = $sw->sprLamaB;
                $baruB = $sw->sprBaruB;
                $selisihB = (float) $sw->selisih_b;
            @endphp
            <div class="mb-4 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-2 text-xs font-bold uppercase tracking-wider text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    {{ __('Sisi B') }} — {{ $lamaB?->prospectCustomer?->nama_lengkap }}
                </div>
                <div class="grid grid-cols-1 divide-y divide-zinc-100 md:grid-cols-2 md:divide-x md:divide-y-0 dark:divide-zinc-800">
                    <div class="p-4">
                        <div class="mb-2 flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:text-orange-400">
                            <flux:icon.archive-box class="size-3" />
                            {{ __('SPR Lama (dibatalkan)') }}
                        </div>
                        <a href="{{ route('marketing.spr.show', $lamaB->id) }}" wire:navigate
                           class="font-mono text-sm font-bold text-orange-700 hover:underline dark:text-orange-400">
                            {{ $lamaB?->nomor_display }}
                        </a>
                        <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('Unit') }}: <b>{{ $lamaB?->rumah?->kode_unit }}</b> · {{ $lamaB?->kategori }}
                        </div>
                        <div class="mt-2 text-xs">
                            <span class="text-zinc-500">{{ __('Total harga') }}</span>
                            <div class="font-mono font-bold">{{ $fmt($lamaB?->total_harga) }}</div>
                        </div>
                    </div>
                    <div class="p-4">
                        <div class="mb-2 flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                            <flux:icon.sparkles class="size-3" />
                            {{ __('SPR Baru') }}
                        </div>
                        <a href="{{ route('marketing.spr.show', $baruB->id) }}" wire:navigate
                           class="font-mono text-sm font-bold text-emerald-700 hover:underline dark:text-emerald-400">
                            {{ $baruB?->nomor_display }}
                        </a>
                        <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('Unit') }}: <b>{{ $baruB?->rumah?->kode_unit }}</b> · {{ $baruB?->kategori }}
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-zinc-500">{{ __('Total harga') }}</span>
                                <div class="font-mono font-bold">{{ $fmt($baruB?->total_harga) }}</div>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Selisih') }}</span>
                                <div @class([
                                    'font-mono font-bold',
                                    'text-amber-700 dark:text-amber-400' => $selisihB > 0,
                                    'text-emerald-700 dark:text-emerald-400' => $selisihB < 0,
                                    'text-zinc-500' => $selisihB == 0,
                                ])>
                                    {{ $selisihB > 0 ? '+' : '' }}{{ $fmt($selisihB) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- REALISASI TERPENGARUH --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-2 text-xs font-bold uppercase tracking-wider text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                {{ __('Realisasi Terpengaruh') }}
                <span class="ml-1 text-zinc-400">({{ $sw->realisasi->count() }})</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                            <th class="px-3 py-2">Kwitansi</th>
                            <th class="px-3 py-2">Tanggal</th>
                            <th class="px-3 py-2">Jenis</th>
                            <th class="px-3 py-2">SPR sekarang</th>
                            <th class="px-3 py-2 text-right">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sw->realisasi->sortBy('tanggal_bayar') as $r)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="px-3 py-2 font-mono">{{ $r->nomor_kwitansi ?: '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-500">{{ $r->tanggal_bayar?->format('d/m/Y') }}</td>
                                <td class="px-3 py-2">
                                    @php $badge = match($r->jenis) {
                                        'bf' => ['UTJ','bg-purple-100 text-purple-700'],
                                        'um' => ['UM','bg-orange-100 text-orange-700'],
                                        'sbum' => ['SBUM','bg-indigo-100 text-indigo-700'],
                                        'refund_pindah' => ['REFUND','bg-rose-100 text-rose-700'],
                                        default => [strtoupper($r->jenis),'bg-zinc-100 text-zinc-700'],
                                    }; @endphp
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {{ $badge[1] }}">
                                        {{ $badge[0] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('marketing.spr.show', $r->spr_id) }}" wire:navigate
                                       class="font-mono text-emerald-700 hover:underline dark:text-emerald-400">
                                        {{ $r->spr?->nomor_display ?? '—' }}
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-right font-mono font-bold tabular-nums">{{ $fmt($r->jumlah) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center italic text-zinc-400">
                                    {{ __('Tidak ada realisasi yang terpengaruh (belum ada UTJ atau UM yang cair sebelum pindah kavling).') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
