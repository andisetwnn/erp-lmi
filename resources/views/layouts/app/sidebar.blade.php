<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        {{-- Sidebar item: izinkan wrap multi-baris untuk label panjang --}}
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

            /* Print — hide semua UI chrome, keep konten saja */
            @media print {
                @page { margin: 10mm; }
                body { background: white !important; }
                [data-flux-sidebar],
                [data-flux-header],
                [data-flux-toast-group],
                [data-flux-modal],
                [data-flux-dropdown-menu] { display: none !important; }
                /* Notif bell + user menu fixed top-right */
                .fixed.right-4.top-3,
                .fixed.top-3.right-4 { display: none !important; }
                /* Tombol print sendiri jangan ikut ter-print */
                .print\:hidden { display: none !important; }
                /* Konten full width saat print (main slot) */
                body > main,
                body > div:not([data-flux-sidebar]):not([data-flux-header]) {
                    margin-left: 0 !important;
                    max-width: 100% !important;
                }
                /* Container Tailwind — remove padding */
                .max-w-screen-xl, .max-w-screen-2xl {
                    max-width: 100% !important;
                    padding-left: 0 !important;
                    padding-right: 0 !important;
                }
            }
        </style>
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            @auth
                @canany(['master.kelola', 'master.proyek.kelola', 'spr.lihat', 'pembayaran.kelola', 'spr.approve'])
                    <livewire:active-proyek />
                @endcanany
            @endauth

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @canany([
                    'master.kelola', 'master.perusahaan.kelola', 'master.proyek.kelola',
                    'master.tipe.kelola', 'master.rumah.kelola', 'master.va.kelola',
                    'master.sales.kelola', 'master.notaris.kelola', 'master.customer.kelola',
                ])
                    <flux:sidebar.group :heading="__('Master Data')" icon="folder" expandable
                                        :expanded="request()->routeIs('master.*')">
                        @canany(['master.kelola', 'master.perusahaan.kelola'])
                            <flux:sidebar.item icon="building-office-2" :href="route('master.perusahaan.index')"
                                               :current="request()->routeIs('master.perusahaan.*')" wire:navigate>
                                {{ __('Perusahaan') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="banknotes" :href="route('master.bank.index')"
                                               :current="request()->routeIs('master.bank.*')" wire:navigate>
                                {{ __('Bank') }}
                            </flux:sidebar.item>
                        @endcanany
                        @canany(['master.kelola', 'master.proyek.kelola'])
                            <flux:sidebar.item icon="home-modern" :href="route('master.proyek.index')"
                                               :current="request()->routeIs('master.proyek.*')" wire:navigate>
                                {{ __('Proyek') }}
                            </flux:sidebar.item>
                        @endcanany
                        @canany(['master.kelola', 'master.tipe.kelola'])
                            <flux:sidebar.item icon="cube" :href="route('master.tipe-rumah.index')"
                                               :current="request()->routeIs('master.tipe-rumah.*')" wire:navigate>
                                {{ __('Tipe Rumah') }}
                            </flux:sidebar.item>
                        @endcanany
                        @canany(['master.kelola', 'master.rumah.kelola'])
                            <flux:sidebar.item icon="key" :href="route('master.rumah.index')"
                                               :current="request()->routeIs('master.rumah.*')" wire:navigate>
                                {{ __('Rumah') }}
                            </flux:sidebar.item>
                        @endcanany
                        @canany(['master.kelola', 'master.va.kelola'])
                            <flux:sidebar.item icon="credit-card" :href="route('master.virtual-account.index')"
                                               :current="request()->routeIs('master.virtual-account.*')" wire:navigate>
                                {{ __('Virtual Account') }}
                            </flux:sidebar.item>
                        @endcanany
                        @canany(['master.kelola', 'master.sales.kelola'])
                            <flux:sidebar.item icon="user-group" :href="route('master.sales.index')"
                                               :current="request()->routeIs('master.sales.*')" wire:navigate>
                                {{ __('Sales') }}
                            </flux:sidebar.item>
                        @endcanany
                        @canany(['master.kelola', 'master.notaris.kelola'])
                            <flux:sidebar.item icon="identification" :href="route('master.notaris.index')"
                                               :current="request()->routeIs('master.notaris.*')" wire:navigate>
                                {{ __('Notaris') }}
                            </flux:sidebar.item>
                        @endcanany
                        @canany(['master.kelola', 'master.customer.kelola'])
                            <flux:sidebar.item icon="users" :href="route('master.customer.index')"
                                               :current="request()->routeIs('master.customer.*')" wire:navigate>
                                {{ __('Customer') }}
                            </flux:sidebar.item>
                        @endcanany
                    </flux:sidebar.group>
                @endcanany

                @canany(['spr.lihat', 'master.kelola', 'master.customer.kelola', 'pembayaran.kelola', 'target.kelola'])
                    <flux:sidebar.group :heading="__('Marketing')" icon="megaphone" expandable
                                        :expanded="request()->routeIs('marketing.*') || request()->routeIs('master.prospect-customer.*') || request()->routeIs('finance.tempel-materai.*')">
                        @canany(['master.kelola', 'master.customer.kelola'])
                            <flux:sidebar.item icon="funnel" :href="route('master.prospect-customer.index')"
                                               :current="request()->routeIs('master.prospect-customer.*')" wire:navigate>
                                {{ __('Prospect Customer') }}
                            </flux:sidebar.item>
                        @endcanany
                        @can('target.kelola')
                            <flux:sidebar.item icon="flag" :href="route('marketing.target.index')"
                                               :current="request()->routeIs('marketing.target.*')" wire:navigate>
                                {{ __('Target / RAB') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('spr.lihat')
                            <flux:sidebar.item icon="document-text" :href="route('marketing.spr.index')"
                                               :current="request()->routeIs('marketing.spr.*')" wire:navigate>
                                {{ __('SPR') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('pembayaran.kelola')
                            @php
                                $countMaterai = \App\Models\Master\Spr::query()
                                    ->where('status', 'approved')
                                    ->whereNotNull('pm_approved_at')
                                    ->whereNotNull('konsumen_signed_at')
                                    ->whereNull('materai_stamped_at')
                                    ->count();
                            @endphp
                            <flux:sidebar.item icon="document-check" :href="route('finance.tempel-materai.index')"
                                               :current="request()->routeIs('finance.tempel-materai.*')"
                                               :badge="$countMaterai > 0 ? $countMaterai : null"
                                               badge-color="rose"
                                               wire:navigate>
                                {{ __('e-Materai') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif

                @can('spr.approve')
                    @php
                        $countApprovePending = \App\Models\Master\Spr::where('status', 'approved')
                            ->whereNull('pm_approved_at')
                            ->count();
                    @endphp
                    <flux:sidebar.group :heading="__('Approval')" icon="check-badge" expandable
                                        :expanded="request()->routeIs('approval.*')">
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('approval.spr.index')"
                                           :current="request()->routeIs('approval.spr.*')"
                                           :badge="$countApprovePending > 0 ? $countApprovePending : null"
                                           badge-color="rose"
                                           wire:navigate>
                            {{ __('Persetujuan SPR') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @canany(['pemberkasan.kelola', 'pemberkasan.lihat'])
                    <flux:sidebar.group :heading="__('Pemberkasan')" icon="folder-open" expandable
                                        :expanded="request()->routeIs('pemberkasan.*')">
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('pemberkasan.input.index')"
                                           :current="request()->routeIs('pemberkasan.input.*')" wire:navigate>
                            {{ __('Input Pemberkasan') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcanany

                @can('teknik.rumah.lihat')
                    <flux:sidebar.group :heading="__('Teknik')" icon="wrench-screwdriver" expandable
                                        :expanded="request()->routeIs('teknik.*')">
                        <flux:sidebar.item icon="home-modern" :href="route('teknik.rumah.index')"
                                           :current="request()->routeIs('teknik.rumah.*')" wire:navigate>
                            {{ __('Data Rumah') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @can('pembayaran.kelola')
                    @php
                        $countUtj = \App\Models\Master\Spr::where('status', 'submitted')->count();
                    @endphp
                    <flux:sidebar.group :heading="__('Transaksi Penerimaan')" icon="banknotes" expandable
                                        :expanded="request()->routeIs('finance.penerimaan-konsumen.*')">
                        <flux:sidebar.item icon="inbox-arrow-down" :href="route('finance.penerimaan-konsumen.index')"
                                           :current="request()->routeIs('finance.penerimaan-konsumen.*')"
                                           :badge="$countUtj > 0 ? $countUtj : null"
                                           badge-color="rose"
                                           wire:navigate>
                            {{ __('Penerimaan Konsumen') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @if (auth()->user()?->can('jurnal.umum.kelola')
                    || auth()->user()?->can('jurnal.bank.kelola')
                    || auth()->user()?->can('jurnal.kaskecil.kelola')
                    || auth()->user()?->can('bukubesar.lihat')
                    || auth()->user()?->can('kasbank.lihat')
                    || auth()->user()?->can('labarugi.lihat')
                    || auth()->user()?->can('neraca.lihat')
                    || auth()->user()?->can('neracasaldo.lihat')
                    || auth()->user()?->can('neracalajur.lihat')
                    || auth()->user()?->can('aruskas.lihat')
                    || auth()->user()?->can('aktivatetap.lihat')
                    || auth()->user()?->can('master.kelola')
                    || auth()->user()?->can('master.coa.kelola'))
                    <flux:sidebar.group :heading="__('Akunting')" icon="calculator" expandable
                                        :expanded="request()->routeIs('akunting.*') || request()->routeIs('master.coa.*')">
                        @canany(['master.kelola', 'master.coa.kelola'])
                            <flux:sidebar.item icon="calculator" :href="route('master.coa.index')"
                                               :current="request()->routeIs('master.coa.*')" wire:navigate>
                                {{ __('COA') }}
                            </flux:sidebar.item>
                        @endcanany
                        @if (auth()->user()?->can('jurnal.umum.kelola')
                            || auth()->user()?->can('jurnal.bank.kelola')
                            || auth()->user()?->can('jurnal.kaskecil.kelola'))
                            <flux:sidebar.item icon="pencil-square" :href="route('akunting.input-jurnal.index')"
                                               :current="request()->routeIs('akunting.input-jurnal.*') || request()->routeIs('akunting.jurnal-umum.*') || request()->routeIs('akunting.jurnal-bank.*') || request()->routeIs('akunting.jurnal-kaskecil.*')"
                                               wire:navigate>
                                {{ __('Input Jurnal') }}
                            </flux:sidebar.item>
                        @endif
                        @can('bukubesar.lihat')
                            <flux:sidebar.item icon="book-open" :href="route('akunting.buku-besar.index')"
                                               :current="request()->routeIs('akunting.buku-besar.*')" wire:navigate>
                                {{ __('Buku Besar') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('kasbank.lihat')
                            <flux:sidebar.item icon="banknotes" :href="route('akunting.kas-bank.index')"
                                               :current="request()->routeIs('akunting.kas-bank.*')" wire:navigate>
                                {{ __('Kas & Bank') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('labarugi.lihat')
                            <flux:sidebar.item icon="chart-bar-square" :href="route('akunting.laba-rugi.index')"
                                               :current="request()->routeIs('akunting.laba-rugi.*')" wire:navigate>
                                {{ __('Laba Rugi') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('neraca.lihat')
                            <flux:sidebar.item icon="scale" :href="route('akunting.neraca.index')"
                                               :current="request()->routeIs('akunting.neraca.*')" wire:navigate>
                                {{ __('Neraca') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('neracasaldo.lihat')
                            <flux:sidebar.item icon="table-cells" :href="route('akunting.neraca-saldo.index')"
                                               :current="request()->routeIs('akunting.neraca-saldo.*')" wire:navigate>
                                {{ __('Neraca Saldo') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('neracalajur.lihat')
                            <flux:sidebar.item icon="squares-2x2" :href="route('akunting.neraca-lajur.index')"
                                               :current="request()->routeIs('akunting.neraca-lajur.*')" wire:navigate>
                                {{ __('Neraca Lajur') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('aruskas.lihat')
                            <flux:sidebar.item icon="banknotes" :href="route('akunting.arus-kas.index')"
                                               :current="request()->routeIs('akunting.arus-kas.*')" wire:navigate>
                                {{ __('Arus Kas') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('aktivatetap.lihat')
                            <flux:sidebar.item icon="building-office" :href="route('akunting.aktiva-tetap.index')"
                                               :current="request()->routeIs('akunting.aktiva-tetap.*')" wire:navigate>
                                {{ __('Aktiva Tetap') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif

                @can('laporan.lihat')
                    @php $activeCat = request()->query('c', 'penjualan'); @endphp
                    <flux:sidebar.group :heading="__('Laporan')" icon="chart-bar" expandable
                                        :expanded="request()->routeIs('laporan.*')">
                        <flux:sidebar.item icon="chart-bar" :href="route('laporan.index', ['c' => 'penjualan'])"
                                           :current="request()->routeIs('laporan.*') && $activeCat === 'penjualan'"
                                           wire:navigate>
                            {{ __('Penjualan') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="banknotes" :href="route('laporan.index', ['c' => 'keuangan'])"
                                           :current="request()->routeIs('laporan.*') && $activeCat === 'keuangan'"
                                           wire:navigate>
                            {{ __('Keuangan') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                @endcan

                @can('monitoring.lihat')
                    <flux:sidebar.item icon="radio" :href="route('monitoring.index')"
                                       :current="request()->routeIs('monitoring.*')" wire:navigate>
                        {{ __('Monitoring') }}
                    </flux:sidebar.item>
                @endcan

                @can('log.lihat')
                    <flux:sidebar.item icon="clipboard-document-list" :href="route('log-user.index')"
                                       :current="request()->routeIs('log-user.*')" wire:navigate>
                        {{ __('Log User') }}
                    </flux:sidebar.item>
                @endcan

                @can('user.kelola')
                    <flux:sidebar.item icon="shield-check" :href="route('user-akses.index')"
                                       :current="request()->routeIs('user-akses.*')" wire:navigate>
                        {{ __('User Akses') }}
                    </flux:sidebar.item>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        {{-- Notif bell fixed di top-right (desktop) — PM/Direktur (semua event) atau Keuangan (event khusus) --}}
        @if (auth()->user()?->can('monitoring.lihat') || auth()->user()?->can('notifikasi.keuangan'))
            <div class="pointer-events-none fixed right-4 top-3 z-40 hidden lg:block">
                <div class="pointer-events-auto rounded-full border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <livewire:notification-bell />
                </div>
            </div>
        @endif

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            @if (auth()->user()?->can('monitoring.lihat') || auth()->user()?->can('notifikasi.keuangan'))
                <livewire:notification-bell />
            @endif

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
