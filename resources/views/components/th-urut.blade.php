@props([
    'field',
    'sortBy' => '',
    'sortDir' => 'asc',
])

{{-- Judul kolom tabel HTML biasa yang bisa diklik untuk mengurutkan (pasangan trait Sortable).
     Gaya teks (huruf besar, ukuran) diwarisi dari baris judulnya. --}}
@php
    $aktif = $sortBy === $field;
@endphp

<th {{ $attributes->class(['px-3 py-2.5']) }}>
    <button type="button" wire:click="sort('{{ $field }}')"
            @class([
                '-mx-1 -my-0.5 inline-flex items-center gap-1 rounded px-1 py-0.5 text-left uppercase transition hover:bg-zinc-200/70 dark:hover:bg-zinc-700',
                'text-zinc-900 dark:text-white' => $aktif,
            ])>
        <span>{{ $slot }}</span>
        @if ($aktif)
            @if ($sortDir === 'asc')
                <flux:icon.chevron-up class="size-3 shrink-0" />
            @else
                <flux:icon.chevron-down class="size-3 shrink-0" />
            @endif
        @else
            <flux:icon.chevron-up-down class="size-3 shrink-0 opacity-40" />
        @endif
    </button>
</th>
