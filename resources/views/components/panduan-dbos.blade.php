{{--
    Panduan DBOS (PDF) untuk sales dan pimpinan: buka/unduh, lalu konfirmasi sudah
    membaca. Dengan `hanya-belum`, kartu hanya tampil selama belum dikonfirmasi —
    dipakai sebagai pengingat di beranda. Tidak tampil kalau PDF-nya belum ada.
--}}
@props(['hanyaBelum' => false])

@php
    $sales = auth('sales')->user();
    $ada = $sales !== null && \App\Support\Panduan::tersedia('dbos');
    $jejak = $ada ? \App\Support\Panduan::jejakSatu($sales, 'dbos') : null;
    $status = \App\Support\Panduan::status($jejak);
@endphp

@if ($ada && ! ($hanyaBelum && $status === \App\Support\Panduan::DIBACA))
    <div {{ $attributes->class('overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm dark:border-amber-900/50 dark:bg-zinc-900') }}>
        <div class="flex items-start gap-3 px-4 py-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                <flux:icon.book-open class="size-5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Panduan DBOS (PDF)') }}</div>
                <div class="text-xs text-zinc-500">
                    {{ __('Cara memakai DBOS dari prospek sampai SPR selesai, dengan gambar layar.') }}
                </div>
                <div class="mt-1 text-[11px] font-semibold">
                    @if ($status === \App\Support\Panduan::DIBACA)
                        <span class="text-emerald-700 dark:text-emerald-400">✓ {{ __('Sudah dibaca :tgl', ['tgl' => $jejak->dibaca_at->translatedFormat('j M Y')]) }}</span>
                    @elseif ($status === \App\Support\Panduan::DIBUKA)
                        <span class="text-amber-700 dark:text-amber-400">{{ __('Sudah dibuka. Klik "Saya sudah membaca" setelah selesai.') }}</span>
                    @else
                        <span class="text-amber-700 dark:text-amber-400">{{ __('Belum dibaca. Buka dan baca sampai selesai.') }}</span>
                    @endif
                </div>
                @if (session('panduan_pesan'))
                    <div class="mt-1 text-[11px] text-zinc-600 dark:text-zinc-300">{{ session('panduan_pesan') }}</div>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2 border-t border-amber-100 bg-amber-50 px-4 py-2.5 dark:border-amber-900/40 dark:bg-amber-950/20">
            <flux:button size="sm" variant="primary" icon="book-open" :href="route('dbos.panduan')" target="_blank">{{ __('Buka') }}</flux:button>
            <flux:button size="sm" icon="arrow-down-tray" :href="route('dbos.panduan', ['unduh' => 1])">{{ __('Unduh') }}</flux:button>
            @if ($status !== \App\Support\Panduan::DIBACA)
                <form method="POST" action="{{ route('dbos.panduan.baca') }}">
                    @csrf
                    <flux:button size="sm" type="submit" icon="check">{{ __('Saya sudah membaca') }}</flux:button>
                </form>
            @endif
        </div>
    </div>
@endif
