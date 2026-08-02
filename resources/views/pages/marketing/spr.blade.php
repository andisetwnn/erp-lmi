<?php

use App\Models\Master\Spr;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('SPR')] class extends Component
{
    public function with(): array
    {
        return [
            'stats' => [
                'total' => Spr::count(),
                // Diproses = submitted + approved yang belum final (belum ber-e-Materai)
                'diproses' => Spr::where(function ($q) {
                    $q->where('status', 'submitted')
                        ->orWhere(function ($qq) {
                            $qq->where('status', 'approved')->whereNull('spr_finalized_at');
                        });
                })->count(),
                // Selesai = approved + sudah ber-e-Materai (final, siap kirim ke konsumen)
                'selesai' => Spr::where('status', 'approved')->whereNotNull('spr_finalized_at')->count(),
                'akad' => Spr::where('status', 'akad')->count(),
                'rejected' => Spr::where('status', 'rejected')->count(),
                'cancelled' => Spr::where('status', 'cancelled')->count(),
                // Total nilai penjualan yang telah terkontrak (Selesai + Akad).
                'total_nilai' => (float) Spr::where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('status', 'approved')->whereNotNull('spr_finalized_at');
                    })->orWhere('status', 'akad');
                })->sum('total_harga'),
            ],
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-emerald-500 to-emerald-700 text-white shadow-sm">
                    <flux:icon.document-text class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('SPR') }}</flux:heading>
                    <flux:subheading>{{ __('Surat Pesanan & Konfirmasi Pembelian Rumah') }}</flux:subheading>
                </div>
            </div>

            {{-- QUICK SEARCH: ketik nomor SPR / nama / blok → jump ke list dgn filter --}}
            <form method="GET" action="{{ route('marketing.spr.list') }}"
                  class="w-full sm:w-72">
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-400">
                        <flux:icon.magnifying-glass class="size-4" />
                    </span>
                    <input type="text" name="q"
                           placeholder="{{ __('Cari SPR: nomor, nama, blok...') }}"
                           class="block w-full rounded-lg border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 shadow-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                           aria-label="{{ __('Cari SPR') }}" />
                </div>
            </form>
        </div>

        {{-- QUICK STATS STRIP (clickable → jump ke list dengan tab yang sesuai) --}}
        <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
            {{-- Diproses --}}
            @php $diproses = (int) $stats['diproses']; @endphp
            <div @class([
                'relative rounded-xl border p-3 transition',
                'border-emerald-300 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-950/30' => $diproses > 0,
                'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => $diproses === 0,
            ])>
                <div class="absolute right-2 top-2 z-10">
                    <x-info-button title="Diproses">
                        SPR yang sedang berjalan dan belum final. Mencakup tahapan verifikasi UTJ oleh Keuangan, persetujuan Project Manager, penandatanganan konsumen, dan penempelan e-Materai.
                    </x-info-button>
                </div>
                <a href="{{ route('marketing.spr.list', ['tab' => 'diproses']) }}" wire:navigate
                   class="block hover:opacity-80">
                    <div class="flex items-center gap-2 pr-6">
                        <flux:icon.arrow-path @class([
                            'size-4',
                            'text-emerald-600 dark:text-emerald-400' => $diproses > 0,
                            'text-zinc-400' => $diproses === 0,
                        ]) />
                        <p @class([
                            'text-[10px] font-semibold uppercase tracking-wider',
                            'text-emerald-700 dark:text-emerald-400' => $diproses > 0,
                            'text-zinc-500' => $diproses === 0,
                        ])>{{ __('Diproses') }}</p>
                    </div>
                    <p @class([
                        'mt-1 text-2xl font-bold tabular-nums',
                        'text-emerald-700 dark:text-emerald-300' => $diproses > 0,
                        'text-zinc-900 dark:text-white' => $diproses === 0,
                    ])>{{ number_format($diproses) }}</p>
                    <p class="text-[10px] text-zinc-500">{{ __('Menunggu penyelesaian tahapan') }}</p>
                </a>
            </div>

            {{-- Selesai --}}
            <div class="relative rounded-xl border border-violet-200 bg-violet-50 p-3 transition dark:border-violet-900/50 dark:bg-violet-950/30">
                <div class="absolute right-2 top-2 z-10">
                    <x-info-button title="Selesai">
                        SPR yang seluruh tahapannya telah tuntas: disetujui Keuangan &amp; Project Manager, ditandatangani konsumen, dan telah ber-e-Materai. Dokumen final siap dikirimkan kepada konsumen.
                    </x-info-button>
                </div>
                <a href="{{ route('marketing.spr.list', ['tab' => 'selesai']) }}" wire:navigate
                   class="block hover:opacity-80">
                    <div class="flex items-center gap-2 pr-6">
                        <flux:icon.check-badge class="size-4 text-violet-600 dark:text-violet-400" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-violet-700 dark:text-violet-400">{{ __('Selesai') }}</p>
                    </div>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-violet-700 dark:text-violet-300">{{ number_format($stats['selesai']) }}</p>
                    <p class="text-[10px] text-zinc-500">{{ __('Dokumen final, siap dikirim') }}</p>
                </a>
            </div>

            {{-- Akad --}}
            <div class="relative rounded-xl border border-indigo-200 bg-indigo-50 p-3 transition dark:border-indigo-900/50 dark:bg-indigo-950/30">
                <div class="absolute right-2 top-2 z-10">
                    <x-info-button title="Akad">
                        SPR yang telah menjalani proses akad kredit di bank. Pendapatan penjualan diakui pada tahap ini dan status ini merepresentasikan penjualan yang telah tuntas.
                    </x-info-button>
                </div>
                <a href="{{ route('marketing.spr.list', ['tab' => 'akad']) }}" wire:navigate
                   class="block hover:opacity-80">
                    <div class="flex items-center gap-2 pr-6">
                        <flux:icon.building-library class="size-4 text-indigo-600 dark:text-indigo-400" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-indigo-700 dark:text-indigo-400">{{ __('Akad') }}</p>
                    </div>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-indigo-700 dark:text-indigo-300">{{ number_format($stats['akad']) }}</p>
                    <p class="text-[10px] text-zinc-500">{{ __('Akad kredit tuntas') }}</p>
                </a>
            </div>

            {{-- Dibatalkan --}}
            <div class="relative rounded-xl border border-orange-200 bg-orange-50 p-3 transition dark:border-orange-900/50 dark:bg-orange-950/30">
                <div class="absolute right-2 top-2 z-10">
                    <x-info-button title="Dibatalkan">
                        SPR yang dibatalkan setelah diterbitkan, dengan berbagai alasan (permintaan konsumen, penolakan KPR, dan lain-lain). Unit kembali tersedia. Rincian pembatalan dan status pengembalian dana dapat dilihat pada menu Pembatalan SPR.
                    </x-info-button>
                </div>
                <a href="{{ route('marketing.spr.list', ['tab' => 'cancelled']) }}" wire:navigate
                   class="block hover:opacity-80">
                    <div class="flex items-center gap-2 pr-6">
                        <flux:icon.x-circle class="size-4 text-orange-600 dark:text-orange-400" />
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-orange-700 dark:text-orange-400">{{ __('Dibatalkan') }}</p>
                    </div>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-orange-700 dark:text-orange-300">{{ number_format($stats['cancelled']) }}</p>
                    <p class="text-[10px] text-zinc-500">{{ __('SPR yang dibatalkan') }}</p>
                </a>
            </div>

            {{-- Total Nilai Terjual (info-only) --}}
            <div class="relative col-span-2 rounded-xl border border-blue-200 bg-blue-50 p-3 dark:border-blue-900/50 dark:bg-blue-950/30 md:col-span-1">
                <div class="absolute right-2 top-2 z-10">
                    <x-info-button title="Total Nilai Terjual">
                        Akumulasi total harga jual dari seluruh SPR berstatus Selesai dan Akad. Nilai ini merepresentasikan besaran penjualan yang telah dikontrakkan dan belum memperhitungkan pengembalian dana akibat pembatalan.
                    </x-info-button>
                </div>
                <div class="flex items-center gap-2 pr-6">
                    <flux:icon.banknotes class="size-4 text-blue-600 dark:text-blue-400" />
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700 dark:text-blue-400">{{ __('Total Nilai Terjual') }}</p>
                </div>
                <p class="mt-1 text-base font-bold tabular-nums text-blue-700 dark:text-blue-300">Rp {{ number_format($stats['total_nilai'], 0, ',', '.') }}</p>
                <p class="text-[10px] text-zinc-500">{{ __('Selesai + Akad') }}</p>
            </div>
        </div>

        {{-- ACTION CARDS --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">

            {{-- CARD: Data SPR --}}
            <a href="{{ route('marketing.spr.list') }}" wire:navigate
               class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-emerald-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-emerald-700">
                <div class="absolute right-0 top-0 -mr-12 -mt-12 h-32 w-32 rounded-full bg-emerald-50 opacity-60 transition group-hover:scale-110 dark:bg-emerald-950/30"></div>

                <div class="relative flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <flux:icon.queue-list class="size-6" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Data SPR') }}</h3>
                        <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('Lihat seluruh data SPR dari semua proyek. Filter status, cetak ulang PDF, lihat detail customer & termin pembayaran.') }}
                        </p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ __('Semua') }} <span class="font-mono">{{ number_format($stats['total']) }}</span>
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                {{ __('Diproses') }} <span class="font-mono">{{ number_format($stats['diproses']) }}</span>
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-950/50 dark:text-violet-300">
                                {{ __('Selesai') }} <span class="font-mono">{{ number_format($stats['selesai']) }}</span>
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">
                                {{ __('Akad') }} <span class="font-mono">{{ number_format($stats['akad']) }}</span>
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                                {{ __('Ditolak') }} <span class="font-mono">{{ number_format($stats['rejected']) }}</span>
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-950/50 dark:text-orange-300">
                                {{ __('Dibatalkan') }} <span class="font-mono">{{ number_format($stats['cancelled']) }}</span>
                            </span>
                        </div>
                    </div>
                    <flux:icon.arrow-up-right class="size-5 shrink-0 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-emerald-600 dark:group-hover:text-emerald-400" />
                </div>
            </a>

            {{-- CARD: Pembatalan SPR --}}
            <a href="{{ route('marketing.spr-batal.list') }}" wire:navigate
               class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-rose-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-rose-700">
                <div class="absolute right-0 top-0 -mr-12 -mt-12 h-32 w-32 rounded-full bg-rose-50 opacity-60 transition group-hover:scale-110 dark:bg-rose-950/20"></div>

                <div class="relative flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400">
                        <flux:icon.x-circle class="size-6" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Pembatalan SPR & Laporannya') }}</h3>
                        <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('Proses pembatalan SPR yang sudah dibuat, lengkap dengan alasan, riwayat pembayaran, status refund, dan laporan ringkasan pembatalan.') }}
                        </p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-950/50 dark:text-orange-300">
                                {{ __('Dibatalkan') }} <span class="font-mono">{{ number_format($stats['cancelled']) }}</span>
                            </span>
                        </div>
                    </div>
                    <flux:icon.arrow-up-right class="size-5 shrink-0 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-rose-600 dark:group-hover:text-rose-400" />
                </div>
            </a>

            {{-- CARD: Pindah Kavling — hanya untuk role yg punya permission --}}
            @can('spr.pindah-unit')
            <a href="{{ route('marketing.spr-pindah.list') }}" wire:navigate
               class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-blue-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-blue-700">
                <div class="absolute right-0 top-0 -mr-12 -mt-12 h-32 w-32 rounded-full bg-blue-50 opacity-60 transition group-hover:scale-110 dark:bg-blue-950/20"></div>

                <div class="relative flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-400">
                        <flux:icon.arrows-right-left class="size-6" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Pindah Kavling') }}</h3>
                        <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('Pindahkan customer ke unit lain yang tersedia, atau tukar unit antar 2 SPR aktif. Selisih harga dan realisasi otomatis diproses.') }}
                        </p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">
                                {{ __('Pindah Unit') }}
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">
                                {{ __('Tukar Unit') }}
                            </span>
                        </div>
                    </div>
                    <flux:icon.arrow-up-right class="size-5 shrink-0 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-blue-600 dark:group-hover:text-blue-400" />
                </div>
            </a>
            @endcan
        </div>
    </div>
</section>
