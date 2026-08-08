<?php

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Buku Bank')] class extends Component
{
    #[Url(as: 'tgl')]
    public string $tanggal = '';

    public function mount(): void
    {
        if ($this->tanggal === '') {
            $this->tanggal = now()->toDateString();
        }
    }

    public function with(): array
    {
        $perusahaan = Perusahaan::first();
        $list = collect();
        if ($perusahaan) {
            $list = app(LaporanAkuntingService::class)->bukuBankSaldo($perusahaan->id, $this->tanggal);
        }

        $totalKas = $list->filter(fn ($r) => str_starts_with($r['coa']->kode, '1001'))->sum('saldo');
        $totalBank = $list->filter(fn ($r) => str_starts_with($r['coa']->kode, '1002'))->sum('saldo');

        return [
            'list' => $list,
            'perusahaan' => $perusahaan,
            'totalKas' => $totalKas,
            'totalBank' => $totalBank,
            'totalAll' => $totalKas + $totalBank,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER (hidden saat print) --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-blue-500 to-blue-700 text-white shadow-sm">
                    <flux:icon.building-library class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Buku Bank') }}</flux:heading>
                    <flux:subheading>{{ __('Saldo terakhir per rekening Kas & Bank.') }}</flux:subheading>
                </div>
            </div>
            <div>
                <flux:input type="date" wire:model.live="tanggal" label="Per Tanggal" />
            </div>
        </div>

        {{-- SUMMARY CARDS --}}
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-zinc-200 bg-linear-to-br from-emerald-50 to-emerald-100 p-5 dark:border-emerald-900/50 dark:from-emerald-950/40 dark:to-emerald-900/20">
                <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
                    Total Kas
                </div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-200">
                    Rp {{ number_format($totalKas, 0, ',', '.') }}
                </div>
                <div class="mt-1 text-xs text-emerald-700/70 dark:text-emerald-400/70">
                    {{ $list->filter(fn ($r) => str_starts_with($r['coa']->kode, '1001'))->count() }} akun kas
                </div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-linear-to-br from-blue-50 to-blue-100 p-5 dark:border-blue-900/50 dark:from-blue-950/40 dark:to-blue-900/20">
                <div class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-400">
                    Total Bank
                </div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-blue-900 dark:text-blue-200">
                    Rp {{ number_format($totalBank, 0, ',', '.') }}
                </div>
                <div class="mt-1 text-xs text-blue-700/70 dark:text-blue-400/70">
                    {{ $list->filter(fn ($r) => str_starts_with($r['coa']->kode, '1002'))->count() }} rekening bank
                </div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-linear-to-br from-zinc-100 to-zinc-200 p-5 dark:border-zinc-700 dark:from-zinc-800 dark:to-zinc-800/60">
                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-700 dark:text-zinc-300">
                    Total Kas + Bank
                </div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                    Rp {{ number_format($totalAll, 0, ',', '.') }}
                </div>
                <div class="mt-1 text-xs text-zinc-700/70 dark:text-zinc-400/70">
                    Per {{ \Carbon\Carbon::parse($tanggal)->translatedFormat('d M Y') }}
                </div>
            </div>
        </div>

        {{-- LIST --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">Detail Saldo per Rekening</flux:heading>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                            <th class="px-4 py-2.5 font-semibold">Kode</th>
                            <th class="px-4 py-2.5 font-semibold">Nama Rekening</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Saldo</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($list as $row)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs">
                                    {{ $row['coa']->kode }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        @if (str_starts_with($row['coa']->kode, '1001'))
                                            <flux:icon.banknotes class="size-4 text-emerald-600" />
                                        @else
                                            <flux:icon.building-library class="size-4 text-blue-600" />
                                        @endif
                                        <span class="font-medium">{{ $row['coa']->nama }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right font-mono tabular-nums font-semibold
                                    {{ $row['saldo'] < 0 ? 'text-rose-600' : '' }}">
                                    Rp {{ number_format($row['saldo'], 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right">
                                    @can('bukubesar.lihat')
                                        <a href="{{ route('akunting.buku-besar.index', ['coa' => $row['coa']->id, 'from' => now()->startOfYear()->toDateString(), 'to' => $tanggal]) }}"
                                           wire:navigate
                                           class="text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">
                                            Lihat Buku Besar →
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-12 text-center text-zinc-400 dark:text-zinc-500">
                                    Belum ada akun Kas/Bank aktif.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
