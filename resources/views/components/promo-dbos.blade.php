{{--
    Promo Penjualan untuk sales di beranda DBOS — yang berjalan hari ini dan yang
    segera dimulai, supaya sales sudah tahu promonya sebelum menawarkan unit,
    bukan baru tahu saat mengisi SPR. Tidak tampil kalau tidak ada promo.
--}}
@php
    $daftarPromo = app(\App\Services\PromoPenjualanService::class)->untukSales();
    $hariIni = now()->startOfDay();
@endphp

@if ($daftarPromo['berjalan']->isNotEmpty() || $daftarPromo['akanDatang']->isNotEmpty())
    <div {{ $attributes->class('overflow-hidden rounded-2xl border border-fuchsia-200 bg-white shadow-sm dark:border-fuchsia-900/50 dark:bg-zinc-900') }}>
        <div class="flex items-center gap-1.5 bg-fuchsia-50 px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-fuchsia-700 dark:bg-fuchsia-950/30 dark:text-fuchsia-300">
            <flux:icon.ticket class="size-3.5" /> {{ __('Promo Penjualan') }}
        </div>

        @foreach ($daftarPromo['berjalan'] as $promo)
            @php $sisaHari = (int) $hariIni->diffInDays($promo->berlaku_sampai->copy()->startOfDay()) + 1; @endphp
            <div class="border-t border-fuchsia-100 px-4 py-3 dark:border-fuchsia-900/40">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                        {{ $sisaHari <= 1 ? __('Hari terakhir') : __('Berjalan · sisa :n hari', ['n' => $sisaHari]) }}
                    </span>
                    <span class="truncate text-[11px] text-zinc-500">{{ $promo->proyek?->nama_proyek }}</span>
                </div>
                <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $promo->nama }}</div>
                <div class="mt-0.5 text-xs text-fuchsia-800 dark:text-fuchsia-200">
                    {{ $promo->ringkasanPotongan() }} ·
                    {{ $promo->semua_unit ? __('semua unit') : $promo->rumah_count.' '.__('unit') }}
                </div>
                <div class="mt-0.5 text-[11px] text-zinc-500">
                    {{ __('UTJ') }} {{ $promo->berlaku_mulai->translatedFormat('d M') }} – {{ $promo->berlaku_sampai->translatedFormat('d M Y') }}
                    @if ($promo->keterangan)
                        · <span class="italic">“{{ $promo->keterangan }}”</span>
                    @endif
                </div>
            </div>
        @endforeach

        @if ($daftarPromo['akanDatang']->isNotEmpty())
            <div class="space-y-1 border-t border-fuchsia-100 bg-zinc-50 px-4 py-2.5 text-xs dark:border-fuchsia-900/40 dark:bg-zinc-800/40">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Akan datang') }}</div>
                @foreach ($daftarPromo['akanDatang'] as $promo)
                    <div>
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $promo->nama }}</span>
                        <span class="text-zinc-500">· {{ __('mulai') }} {{ $promo->berlaku_mulai->translatedFormat('d M') }} · {{ $promo->proyek?->nama_proyek }}</span>
                        <div class="text-[11px] text-fuchsia-700 dark:text-fuchsia-300">{{ $promo->ringkasanPotongan() }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
