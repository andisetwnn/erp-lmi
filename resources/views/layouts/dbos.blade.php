<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-100 pb-20 dark:bg-zinc-950">

        {{-- Main content slot --}}
        <main class="mx-auto max-w-2xl">
            {{ $slot }}
        </main>

        {{-- Bottom Navigation (mobile-first, jempol-reachable) --}}
        @auth('sales')
            @php
                $navItems = [
                    ['route' => 'dbos.sales-home',      'label' => __('Home'),     'icon' => 'home',                    'match' => 'dbos.sales-home'],
                    ['route' => 'dbos.booking.create',  'label' => __('Booking'),  'icon' => 'clipboard-document-list', 'match' => 'dbos.booking.*'],
                    ['route' => 'dbos.spr.index',       'label' => __('SPR'),      'icon' => 'document-check',          'match' => 'dbos.spr.*'],
                    ['route' => 'dbos.database.create', 'label' => __('Database'), 'icon' => 'circle-stack',            'match' => 'dbos.database.*'],
                ];
            @endphp

            <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mx-auto grid max-w-2xl grid-cols-4">
                    @foreach ($navItems as $item)
                        @php
                            $isActive = request()->routeIs($item['match']);
                            $hasRoute = \Illuminate\Support\Facades\Route::has($item['route']);
                        @endphp
                        <a href="{{ $hasRoute ? route($item['route']) : '#' }}"
                           @if ($hasRoute) wire:navigate @endif
                           @class([
                               'flex flex-col items-center gap-1 py-3 text-xs font-medium transition',
                               'text-orange-600 dark:text-orange-400' => $isActive,
                               'text-zinc-400 dark:text-zinc-600' => ! $hasRoute,
                               'text-zinc-500 hover:text-zinc-900 dark:hover:text-white' => $hasRoute && ! $isActive,
                           ])>
                            @switch($item['icon'])
                                @case('home')                       <flux:icon.home class="size-5" /> @break
                                @case('clipboard-document-list')    <flux:icon.clipboard-document-list class="size-5" /> @break
                                @case('document-check')             <flux:icon.document-check class="size-5" /> @break
                                @case('circle-stack')               <flux:icon.circle-stack class="size-5" /> @break
                            @endswitch
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>
        @endauth

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
