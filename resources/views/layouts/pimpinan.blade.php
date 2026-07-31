<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @php
            /** @var \App\Models\Master\Sales $pimpinanUser */
            $pimpinanUser = auth('sales')->user();
            $pimpinanGrup = $pimpinanUser?->grupYangDipimpin();
            $pimpinanInitials = $pimpinanUser
                ? collect(explode(' ', $pimpinanUser->nama))
                    ->take(2)
                    ->map(fn ($w) => mb_substr($w, 0, 1))
                    ->implode('')
                : '?';

            // ===== HITUNG BADGE COUNT UNTUK SIDEBAR =====
            $sidebarCounts = ['anggota' => 0, 'prospect' => 0, 'booking' => 0, 'bookingUrgent' => false, 'spr' => 0];
            if ($pimpinanGrup) {
                $bawahanIds = \App\Models\Master\Sales::where('sales_grup_id', $pimpinanGrup->id)
                    ->where('id', '!=', $pimpinanUser->id)
                    ->pluck('id');

                $sidebarCounts['anggota'] = \App\Models\Master\Sales::where('sales_grup_id', $pimpinanGrup->id)
                    ->where('id', '!=', $pimpinanUser->id)
                    ->where('is_aktif', true)
                    ->count();

                $sidebarCounts['prospect'] = \App\Models\Master\ProspectCustomer::whereIn('sales_id', $bawahanIds)
                    ->where('status', '!=', 'archive')
                    ->count();

                $today = \Illuminate\Support\Carbon::today();
                $sidebarCounts['booking'] = \App\Models\Master\Booking::whereIn('sales_id', $bawahanIds)
                    ->where('status', 'aktif')
                    ->where(fn ($q) => $q->whereNull('tanggal_expired')->orWhere('tanggal_expired', '>', $today))
                    ->count();

                $sidebarCounts['spr'] = \App\Models\Master\Spr::whereIn('sales_id', $bawahanIds)
                    ->whereIn('status', ['submitted', 'approved'])
                    ->count();

                // Urgent kalau ada booking expired dalam 24 jam
                $sidebarCounts['bookingUrgent'] = \App\Models\Master\Booking::whereIn('sales_id', $bawahanIds)
                    ->where('status', 'aktif')
                    ->whereNotNull('tanggal_expired')
                    ->whereBetween('tanggal_expired', [$today, $today->copy()->addDay()])
                    ->exists();
            }
        @endphp

        <style>
            [data-flux-sidebar-item] {
                height: auto !important;
                min-height: 2rem !important;
                padding-top: 0.4rem !important;
                padding-bottom: 0.4rem !important;
            }
            [data-flux-sidebar-item] [data-content] {
                white-space: normal !important;
                text-overflow: clip !important;
                overflow: visible !important;
                line-height: 1.25 !important;
            }
        </style>

        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <flux:sidebar.brand
                    :href="route('dbos.pimpinan.home')"
                    wire:navigate
                    :name="__('DBOS Pimpinan')"
                    :description="$pimpinanGrup?->nama ?? '—'"
                >
                    <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center rounded-md bg-amber-500 text-white shadow">
                        <flux:icon.star class="size-5" />
                    </x-slot>
                </flux:sidebar.brand>
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Overview')" class="grid">
                    <flux:sidebar.item icon="home"
                                       :href="route('dbos.pimpinan.home')"
                                       :current="request()->routeIs('dbos.pimpinan.home')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Tim Saya')" class="grid">
                    <flux:sidebar.item icon="users"
                                       :href="route('dbos.pimpinan.anggota.index')"
                                       :current="request()->routeIs('dbos.pimpinan.anggota.*')"
                                       :badge="$sidebarCounts['anggota'] > 0 ? (string) $sidebarCounts['anggota'] : null"
                                       wire:navigate>
                        {{ __('Anggota') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="circle-stack"
                                       :href="route('dbos.pimpinan.prospect.index')"
                                       :current="request()->routeIs('dbos.pimpinan.prospect.*')"
                                       :badge="$sidebarCounts['prospect'] > 0 ? (string) $sidebarCounts['prospect'] : null"
                                       wire:navigate>
                        {{ __('Prospect') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="clipboard-document-list"
                                       :href="route('dbos.pimpinan.booking.index')"
                                       :current="request()->routeIs('dbos.pimpinan.booking.*')"
                                       :badge="$sidebarCounts['booking'] > 0 ? (string) $sidebarCounts['booking'] : null"
                                       :badgeColor="$sidebarCounts['bookingUrgent'] ? 'red' : null"
                                       wire:navigate>
                        {{ __('Booking') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="document-check"
                                       :href="route('dbos.pimpinan.spr.index')"
                                       :current="request()->routeIs('dbos.pimpinan.spr.*')"
                                       :badge="$sidebarCounts['spr'] > 0 ? (string) $sidebarCounts['spr'] : null"
                                       wire:navigate>
                        {{ __('SPR') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Lainnya')" class="grid">
                    <flux:sidebar.item icon="bolt"
                                       :href="route('dbos.pimpinan.activity')"
                                       :current="request()->routeIs('dbos.pimpinan.activity')" wire:navigate>
                        {{ __('Aktivitas Saya') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            {{-- Desktop user profile (sidebar bottom) --}}
            <flux:dropdown position="bottom" align="start" class="hidden lg:block">
                <flux:sidebar.profile
                    :name="$pimpinanUser?->nama ?? '—'"
                    :initials="$pimpinanInitials"
                    icon:trailing="chevrons-up-down"
                />

                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar :name="$pimpinanUser?->nama ?? '—'" :initials="$pimpinanInitials" />
                        <div class="grid flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ $pimpinanUser?->nama ?? '—' }}</flux:heading>
                            <flux:text class="truncate font-mono text-xs">#{{ $pimpinanUser?->kode ?? '—' }}</flux:text>
                        </div>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item icon="user-circle" :href="route('dbos.pimpinan.profil')" wire:navigate>
                        {{ __('Profil') }}
                    </flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.radio.group>
                        <form method="POST" action="{{ route('dbos.logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item
                                as="button"
                                type="submit"
                                icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer"
                            >
                                {{ __('Keluar') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu.radio.group>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        {{-- Top header (sticky, with global search + mobile sidebar toggle) --}}
        <flux:header class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            {{-- Global search (Ctrl+K) — pojok kanan supaya gak bentrok dengan sidebar toggle --}}
            <livewire:pimpinan-global-search />

            <flux:dropdown position="top" align="end" class="lg:hidden">
                <flux:profile
                    :initials="$pimpinanInitials"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar :name="$pimpinanUser?->nama ?? '—'" :initials="$pimpinanInitials" />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ $pimpinanUser?->nama ?? '—' }}</flux:heading>
                                    <flux:text class="truncate font-mono text-xs">#{{ $pimpinanUser?->kode ?? '—' }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('dbos.logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                        >
                            {{ __('Keluar') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{-- Global loading bar (Livewire request indicator) --}}
        <div wire:loading.delay.long.flex
             class="fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden bg-amber-100">
            <div class="h-full w-1/3 animate-[pulse_1.5s_linear_infinite] bg-amber-500"></div>
        </div>

        <flux:main>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
