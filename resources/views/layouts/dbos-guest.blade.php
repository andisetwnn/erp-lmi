<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="relative min-h-screen overflow-x-hidden bg-linear-to-br from-orange-600 via-orange-500 to-amber-500">
        {{-- Decorative blobs --}}
        <div class="pointer-events-none absolute -left-32 -top-32 h-80 w-80 rounded-full bg-white/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-40 -right-32 h-96 w-96 rounded-full bg-amber-200/20 blur-3xl"></div>
        <div class="pointer-events-none absolute right-10 top-20 h-32 w-32 rounded-full bg-white/5 blur-2xl"></div>

        <div class="relative flex min-h-screen items-center justify-center px-4 py-8">
            <div class="w-full max-w-md">
                {{ $slot }}
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
