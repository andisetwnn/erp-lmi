@props([
    // [['label' => 'Per Periode', 'href' => '...', 'active' => true], ...]
    'items' => [],
])

{{-- Tab antar halaman yang masih satu urusan, supaya sidebar tidak beranak. --}}
<div class="mb-4 border-b border-zinc-200 dark:border-zinc-700 print:hidden">
    <nav class="-mb-px flex gap-1 overflow-x-auto">
        @foreach ($items as $item)
            <a href="{{ $item['href'] }}" wire:navigate
               @class([
                   'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition',
                   'border-purple-600 text-purple-700 dark:border-purple-400 dark:text-purple-300' => $item['active'] ?? false,
                   'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => ! ($item['active'] ?? false),
               ])>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
</div>
