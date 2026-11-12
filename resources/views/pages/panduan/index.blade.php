<?php

use App\Support\Panduan;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Panduan')] class extends Component
{
    /** Konfirmasi sudah membaca versi panduan yang sekarang; harus sudah pernah dibuka. */
    public function tandaiSudahBaca(string $kunci): void
    {
        abort_unless(Panduan::wajibBaca(Auth::user(), $kunci), 403);

        if (! Panduan::konfirmasiBaca(Auth::user(), $kunci)) {
            Flux::toast(__('Buka panduannya dulu, baru konfirmasi setelah selesai membaca.'), variant: 'warning');

            return;
        }

        Flux::toast(__('Terima kasih, panduan :judul tercatat sudah kamu baca.', ['judul' => Panduan::DAFTAR[$kunci]['judul']]), variant: 'success');
    }

    public function with(): array
    {
        $wajib = Panduan::wajibUntuk(Auth::user());
        $jejak = Panduan::jejak(Auth::user());

        return [
            'daftar' => Panduan::untuk(Auth::user()),
            'wajib' => $wajib,
            'jejak' => $jejak,
            'jumlahBelum' => count(array_filter(array_keys($wajib), fn ($kunci) => Panduan::status($jejak->get($kunci)) !== Panduan::DIBACA)),
            'bolehRekap' => Auth::user()->hasAnyRole(['direktur', 'super-admin']),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-zinc-700">
                <flux:icon.book-open class="size-6" />
            </div>
            <div class="grow">
                <flux:heading size="xl">{{ __('Panduan') }}</flux:heading>
                <flux:subheading>{{ __('Cara memakai ERP per modul. Yang tampil hanya modul yang bisa kamu buka.') }}</flux:subheading>
            </div>
            @if ($bolehRekap)
                <flux:button size="sm" variant="ghost" icon="users" :href="route('panduan.rekap')" wire:navigate class="self-center">
                    {{ __('Rekap pembaca') }}
                </flux:button>
            @endif
        </div>

        @if ($jumlahBelum > 0)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                {{ __('Ada :jumlah panduan yang belum kamu konfirmasi. Buka panduannya, baca sampai selesai, lalu klik "Saya sudah membaca".', ['jumlah' => $jumlahBelum]) }}
            </div>
        @endif

        <div class="space-y-3">
            @forelse ($daftar as $kunci => $p)
                @php
                    $j = $jejak->get($kunci);
                    $status = \App\Support\Panduan::status($j);
                @endphp
                <div wire:key="panduan-{{ $kunci }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 bg-white p-4 sm:flex-row sm:items-center dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <flux:icon.document-text class="size-5" />
                    </div>
                    <div class="min-w-0 grow">
                        <div class="font-semibold">{{ __('Panduan') }} {{ $p['judul'] }}</div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $p['ringkasan'] }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500">
                            <span>{{ __('Diperbarui') }} {{ \Illuminate\Support\Carbon::parse($p['versi'])->translatedFormat('j F Y') }}</span>
                            @if (! isset($wajib[$kunci]))
                                {{-- Bukan peran yang menjalankan modul ini: boleh dibaca, tidak dilacak. --}}
                            @elseif ($status === \App\Support\Panduan::DIBACA)
                                <flux:badge size="sm" color="emerald" icon="check">{{ __('Sudah dibaca :tgl', ['tgl' => $j->dibaca_at->translatedFormat('j M Y')]) }}</flux:badge>
                            @elseif ($status === \App\Support\Panduan::DIBUKA)
                                <flux:badge size="sm" color="amber">{{ __('Dibuka, belum dikonfirmasi') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Belum dibuka') }}</flux:badge>
                            @endif
                        </div>
                    </div>
                    {{-- Halaman dimuat ulang sebentar setelah panduan dibuka di tab lain, supaya statusnya ikut berubah. --}}
                    <div class="flex shrink-0 flex-wrap gap-2" x-data x-on:click="if ($event.target.closest('a')) setTimeout(() => $wire.$refresh(), 1500)">
                        <flux:button size="sm" icon="arrow-down-tray" :href="route('panduan.buka', ['kunci' => $kunci, 'unduh' => 1])">{{ __('Unduh') }}</flux:button>
                        <flux:button size="sm" variant="primary" icon="book-open" :href="route('panduan.buka', $kunci)" target="_blank">{{ __('Buka') }}</flux:button>
                        @if (isset($wajib[$kunci]) && $status !== \App\Support\Panduan::DIBACA)
                            <flux:button size="sm" icon="check" wire:click="tandaiSudahBaca('{{ $kunci }}')">{{ __('Saya sudah membaca') }}</flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                    {{ __('Panduan untuk modul yang kamu pakai sedang disusun.') }}
                </div>
            @endforelse
        </div>
    </div>
</section>
