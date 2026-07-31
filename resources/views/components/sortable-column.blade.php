@props([
    'field' => null,
    'sortBy' => '',
    'sortDir' => 'asc',
    'align' => null,
])

@php
    $isActive = $field && $sortBy === $field;
@endphp

<flux:table.column :align="$align">
    @if ($field)
        <button type="button" wire:click="sort('{{ $field }}')"
                @class([
                    '-mx-1 -my-0.5 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-inherit transition',
                    'hover:bg-zinc-100 dark:hover:bg-zinc-800' => true,
                    'text-zinc-900 dark:text-white' => $isActive,
                ])>
            <span>{{ $slot }}</span>
            @if ($isActive)
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
</flux:table.column>
