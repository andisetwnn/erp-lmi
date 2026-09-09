@props([
    'field' => null,   // null = kolom tidak bisa diurutkan
    'kanan' => false,  // kolom angka rata kanan
])

{{-- sortBy & sortDir diambil dari x-matrix-tabel supaya tidak perlu dioper
     satu per satu ke tiga puluhan kolom. --}}
@aware(['sortBy' => '', 'sortDir' => 'asc'])

@php
    $aktif = $field !== null && $sortBy === $field;
@endphp

<th @class([
        'whitespace-nowrap px-2 py-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500',
        'text-right' => $kanan,
        'text-left' => ! $kanan,
    ])>
    @if ($field)
        <button type="button" wire:click="sort('{{ $field }}')"
                @class([
                    '-mx-1 -my-0.5 inline-flex items-center gap-1 rounded px-1.5 py-0.5 transition hover:bg-zinc-200/70 dark:hover:bg-zinc-700',
                    'flex-row-reverse' => $kanan,
                    'text-zinc-900 dark:text-white' => $aktif,
                ])>
            <span>{{ $slot }}</span>
            @if ($aktif)
                @if ($sortDir === 'asc')
                    <flux:icon.chevron-up class="size-3" />
                @else
                    <flux:icon.chevron-down class="size-3" />
                @endif
            @else
                <flux:icon.chevron-up-down class="size-3 opacity-40" />
            @endif
        </button>
    @else
        {{ $slot }}
    @endif
</th>
