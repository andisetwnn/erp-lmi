<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col overflow-hidden border-e border-zinc-200 bg-zinc-100 p-10 text-zinc-900 lg:flex">
                <div class="absolute inset-0 bg-linear-to-br from-zinc-100 via-zinc-50 to-zinc-200"></div>

                <div class="absolute inset-0 opacity-60"
                     style="background-image:
                            linear-gradient(to right, rgba(24,24,27,0.06) 1px, transparent 1px),
                            linear-gradient(to bottom, rgba(24,24,27,0.06) 1px, transparent 1px);
                            background-size: 48px 48px;"></div>

                <div class="absolute inset-0 opacity-40"
                     style="background-image: radial-gradient(circle at 1px 1px, rgba(24,24,27,0.18) 1px, transparent 0);
                            background-size: 24px 24px;
                            background-position: 0 0;"></div>

                <div class="absolute -top-32 -right-32 h-96 w-96 rounded-full bg-sky-200/40 blur-3xl"></div>
                <div class="absolute -bottom-40 -left-40 h-112 w-md rounded-full bg-amber-100/40 blur-3xl"></div>

                <svg class="absolute top-10 right-10 size-32 text-zinc-300/60" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="0.5">
                    <circle cx="50" cy="50" r="40" />
                    <circle cx="50" cy="50" r="30" />
                    <circle cx="50" cy="50" r="20" />
                </svg>
                <svg class="absolute bottom-16 right-16 size-24 text-zinc-300/60" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="0.5">
                    <polygon points="50,10 90,90 10,90" />
                </svg>

                <div class="relative z-20 flex flex-1 flex-col items-center justify-center text-center">
                    <div class="mb-6 flex h-35 w-35 items-center justify-center rounded-3xl bg-white ring-1 ring-zinc-200 shadow-md">
                        <x-app-logo-icon class="h-35 w-35" />
                    </div>
                    <h2 class="text-5xl font-bold tracking-tight text-zinc-900">{{ config('app.name', 'Laravel') }}</h2>
                    <p class="mt-3 text-xs uppercase tracking-[0.3em] text-zinc-500">
                        PT Langit Membangun Indonesia
                    </p>
                </div>

                <div class="relative z-20 text-center text-xs text-zinc-400">
                    &copy; {{ date('Y') }} PT Langit Membangun Indonesia
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="flex h-9 w-9 items-center justify-center rounded-md">
                            <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                        </span>

                        <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                    </a>
                    {{ $slot }}
                </div>
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
