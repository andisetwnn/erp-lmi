@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center rounded-md bg-white ring-1 ring-zinc-200 dark:ring-zinc-700">
            <x-app-logo-icon class="size-10" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center rounded-md bg-white ring-1 ring-zinc-200 dark:ring-zinc-700">
            <x-app-logo-icon class="size-10" />
        </x-slot>
    </flux:brand>
@endif
