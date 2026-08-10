<?php

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Neraca Saldo')] class extends Component
{
    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->startOfYear()->toDateString();
        }
        if ($this->to === '') {
            $this->to = now()->toDateString();
        }
    }

    public function with(): array
    {
        $perusahaan = Perusahaan::first();

        return [
            'perusahaan' => $perusahaan,
            'data' => app(LaporanAkuntingService::class)->neracaSaldo($perusahaan->id, $this->from, $this->to),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-slate-500 to-slate-700 text-white shadow-sm">
                    <flux:icon.table-cells class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Neraca Saldo') }}</flux:heading>
                    <flux:subheading>{{ __('Daftar SEMUA akun + saldo debet/kredit. Tools cross-check jurnal.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="document-arrow-down"
                             href="{{ route('akunting.neraca-saldo.excel', ['from' => $from, 'to' => $to]) }}">
                    {{ __('Excel') }}
                </flux:button>
                <flux:button variant="ghost" icon="printer"
                             href="{{ route('akunting.neraca-saldo.print', ['from' => $from, 'to' => $to]) }}"
                             target="_blank">
                    {{ __('Cetak PDF') }}
                </flux:button>
            </div>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <flux:input type="date" wire:model.live="from" label="Dari Tanggal" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="to" label="Sampai Tanggal" />
            </div>
        </div>

        @unless ($data['balanced'])
            <div class="mb-4 rounded p-3 text-center text-sm font-semibold bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                ⚠ Neraca Saldo TIDAK BALANCE — cek jurnal!
            </div>
        @endunless

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500">
                            <th class="px-3 py-2 font-semibold">Kode</th>
                            <th class="px-3 py-2 font-semibold">Nama Akun</th>
                            <th class="px-3 py-2 font-semibold">Tipe</th>
                            <th class="px-3 py-2 text-right font-semibold">Mutasi Debet</th>
                            <th class="px-3 py-2 text-right font-semibold">Mutasi Kredit</th>
                            <th class="px-3 py-2 text-right font-semibold">Saldo Debet</th>
                            <th class="px-3 py-2 text-right font-semibold">Saldo Kredit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($data['rows'] as $r)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-3 py-1.5 whitespace-nowrap font-mono text-xs text-zinc-600">{{ $r['coa']->kode }}</td>
                                <td class="px-3 py-1.5">{{ $r['coa']->nama }}</td>
                                <td class="px-3 py-1.5 text-xs">
                                    @php
                                        $badge = match($r['coa']->tipe) {
                                            'aset' => 'blue',
                                            'kewajiban' => 'orange',
                                            'modal' => 'emerald',
                                            'pendapatan' => 'green',
                                            'beban' => 'red',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge color="{{ $badge }}" size="sm">{{ ucfirst($r['coa']->tipe) }}</flux:badge>
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap text-right font-mono tabular-nums text-zinc-500">{{ $r['debet'] > 0 ? number_format($r['debet'], 0, ',', '.') : '-' }}</td>
                                <td class="px-3 py-1.5 whitespace-nowrap text-right font-mono tabular-nums text-zinc-500">{{ $r['kredit'] > 0 ? number_format($r['kredit'], 0, ',', '.') : '-' }}</td>
                                <td class="px-3 py-1.5 whitespace-nowrap text-right font-mono tabular-nums font-semibold text-blue-700 dark:text-blue-400">{{ $r['saldo_debet'] > 0 ? number_format($r['saldo_debet'], 0, ',', '.') : '-' }}</td>
                                <td class="px-3 py-1.5 whitespace-nowrap text-right font-mono tabular-nums font-semibold text-emerald-700 dark:text-emerald-400">{{ $r['saldo_kredit'] > 0 ? number_format($r['saldo_kredit'], 0, ',', '.') : '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-zinc-400">Tidak ada mutasi di periode ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (! empty($data['rows']))
                        <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800">
                            <tr class="text-sm font-bold">
                                <td colspan="3" class="px-3 py-2 text-right">TOTAL</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">{{ number_format($data['total_debet'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">{{ number_format($data['total_kredit'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-blue-700 dark:text-blue-400">{{ number_format($data['total_saldo_debet'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($data['total_saldo_kredit'], 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</section>
