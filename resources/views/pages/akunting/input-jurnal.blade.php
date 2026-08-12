<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Input Jurnal')] class extends Component
{
    public function with(): array
    {
        $user = auth()->user();

        return [
            'canBank' => $user?->can('jurnal.bank.kelola'),
            'canKasKecil' => $user?->can('jurnal.kaskecil.kelola'),
            'canUmum' => $user?->can('jurnal.umum.kelola'),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-amber-500 to-amber-700 text-white shadow-sm">
                <flux:icon.pencil-square class="size-6" />
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl">{{ __('Input Jurnal') }}</flux:heading>
                    <x-info-button title="Input Jurnal">
                        <p>Menu untuk mencatat transaksi keuangan ke sistem — mis. pembayaran manual, penyesuaian akhir bulan, jurnal koreksi.</p>
                        <p class="mt-2">Sekarang tersedia <strong>Jurnal Umum</strong>. Klik card di bawah untuk mulai input.</p>
                        <p class="mt-2 text-xs text-zinc-500">Setiap jurnal yg disimpan wajib balance (total debet = total kredit), baru bisa <em>posted</em> ke Buku Besar.</p>
                    </x-info-button>
                </div>
                <flux:subheading>{{ __('Pilih jenis jurnal yang mau di-input.') }}</flux:subheading>
            </div>
        </div>

        {{-- INFO --}}
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800/50 dark:bg-blue-950/30">
            <div class="flex items-start gap-3">
                <flux:icon.information-circle class="mt-0.5 size-5 text-blue-600 dark:text-blue-400" />
                <div class="flex-1">
                    <p class="text-sm font-semibold text-blue-900 dark:text-blue-200">{{ __('Info') }}</p>
                    <p class="mt-1 text-sm text-blue-800 dark:text-blue-300">
                        {{ __('Gunakan Jurnal Umum untuk semua jenis transaksi (bank, kas, kas kecil, dll). Jenis jurnal khusus lain akan ditambahkan bila diperlukan.') }}
                    </p>
                </div>
            </div>
        </div>

        {{-- CARD: Jurnal Umum (compact, pojok kiri) --}}
        <div class="max-w-sm">
            <div class="rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start gap-3 p-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400">
                        <flux:icon.calculator class="size-5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <flux:heading size="sm" class="mb-0.5!">{{ __('Input Jurnal Umum') }}</flux:heading>
                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('Untuk semua jenis kode rekening.') }}
                        </p>
                    </div>
                </div>
                <div class="border-t border-zinc-200 px-4 py-2 dark:border-zinc-700">
                    @if ($canUmum)
                        <a href="{{ route('akunting.jurnal-umum.index') }}" wire:navigate
                           class="flex items-center justify-between text-xs font-semibold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                            <span>{{ __('Input disini') }}</span>
                            <flux:icon.arrow-right class="size-3.5" />
                        </a>
                    @else
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-500">
                            {{ __('Anda tidak memiliki akses.') }}
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
