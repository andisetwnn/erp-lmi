@props([
    'title' => null,
])

@php
    // Unique name per instance untuk modal — biar bisa banyak tombol info di 1 halaman
    $modalName = 'info-'.uniqid();
@endphp

<flux:modal.trigger :name="$modalName">
    <button type="button"
            class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 transition hover:bg-blue-100 hover:text-blue-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-blue-950/50 dark:hover:text-blue-400"
            aria-label="{{ __('Info') }}">
        <svg xmlns="http://www.w3.org/2000/svg" class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
        </svg>
    </button>
</flux:modal.trigger>

<flux:modal :name="$modalName" class="md:w-md">
    <div class="space-y-4">
        <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
            </svg>
            <flux:heading size="lg">{{ $title ?? __('Info') }}</flux:heading>
        </div>
        <div class="text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
            {{ $slot }}
        </div>
        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="primary">{{ __('Mengerti') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>
