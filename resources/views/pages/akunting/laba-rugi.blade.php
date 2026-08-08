<?php

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Laporan Laba Rugi')] class extends Component
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
            $this->to = now()->endOfMonth()->toDateString();
        }
    }

    public function with(): array
    {
        $perusahaan = Perusahaan::first();
        $data = null;
        if ($perusahaan) {
            $data = app(LaporanAkuntingService::class)->labaRugi($perusahaan->id, $this->from, $this->to);
        }

        return [
            'perusahaan' => $perusahaan,
            'data' => $data,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER (hidden saat print) --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-purple-500 to-purple-700 text-white shadow-sm">
                    <flux:icon.chart-bar-square class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Laporan Laba Rugi') }}</flux:heading>
                    <flux:subheading>{{ __('Pendapatan dikurangi Beban per periode.') }}</flux:subheading>
                </div>
            </div>
            <flux:button variant="ghost" icon="printer"
                         href="{{ route('akunting.laba-rugi.print', ['from' => $from, 'to' => $to]) }}"
                         target="_blank">
                {{ __('Print / Export PDF') }}
            </flux:button>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
            <div>
                <flux:input type="date" wire:model.live="from" label="Dari Tanggal" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="to" label="Sampai Tanggal" />
            </div>
        </div>

        @if ($data)
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 print:border-0 print:p-0">
                {{-- Kop laporan --}}
                <div class="mb-6 text-center">
                    <div class="text-lg font-bold uppercase">
                        {{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}
                    </div>
                    <div class="text-2xl font-bold tracking-wide">LAPORAN LABA RUGI</div>
                    <div class="text-sm">
                        PERIODE : {{ strtoupper(\Carbon\Carbon::parse($from)->translatedFormat('d F Y')) }}
                        &mdash; {{ strtoupper(\Carbon\Carbon::parse($to)->translatedFormat('d F Y')) }}
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        {{-- PENDAPATAN --}}
                        <thead>
                            <tr class="bg-emerald-50 dark:bg-emerald-950/30">
                                <th colspan="3" class="border border-zinc-300 px-3 py-2 text-left text-xs font-bold uppercase dark:border-zinc-600">
                                    Pendapatan
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data['pendapatan']['groups'] as $group)
                                <tr class="font-semibold text-zinc-700 dark:text-zinc-300">
                                    <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">
                                        {{ $group['header']->kode }} - {{ $group['header']->nama }}
                                    </td>
                                    <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600"></td>
                                    <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ number_format($group['total'], 0, ',', '.') }}
                                    </td>
                                </tr>
                                @foreach ($group['items'] as $item)
                                    <tr class="text-xs text-zinc-600 dark:text-zinc-400">
                                        <td class="border border-zinc-300 px-3 py-1 pl-8 dark:border-zinc-600">
                                            {{ $item['coa']->kode }} - {{ $item['coa']->nama }}
                                        </td>
                                        <td class="border border-zinc-300 px-3 py-1 text-right font-mono tabular-nums dark:border-zinc-600">
                                            {{ number_format($item['saldo'], 0, ',', '.') }}
                                        </td>
                                        <td class="border border-zinc-300 px-3 py-1 dark:border-zinc-600"></td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="3" class="border border-zinc-300 px-3 py-4 text-center italic text-zinc-400 dark:border-zinc-600">
                                        Tidak ada pendapatan di periode ini.
                                    </td>
                                </tr>
                            @endforelse
                            <tr class="bg-emerald-100 font-bold dark:bg-emerald-950/50">
                                <td colspan="2" class="border border-zinc-300 px-3 py-2 dark:border-zinc-600">TOTAL PENDAPATAN</td>
                                <td class="border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-600">
                                    {{ number_format($data['pendapatan']['total'], 0, ',', '.') }}
                                </td>
                            </tr>
                        </tbody>

                        {{-- BEBAN --}}
                        <thead>
                            <tr class="bg-rose-50 dark:bg-rose-950/30">
                                <th colspan="3" class="border border-zinc-300 px-3 py-2 text-left text-xs font-bold uppercase dark:border-zinc-600">
                                    Beban / HPP
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data['beban']['groups'] as $group)
                                <tr class="font-semibold text-zinc-700 dark:text-zinc-300">
                                    <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">
                                        {{ $group['header']->kode }} - {{ $group['header']->nama }}
                                    </td>
                                    <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600"></td>
                                    <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ number_format($group['total'], 0, ',', '.') }}
                                    </td>
                                </tr>
                                @foreach ($group['items'] as $item)
                                    <tr class="text-xs text-zinc-600 dark:text-zinc-400">
                                        <td class="border border-zinc-300 px-3 py-1 pl-8 dark:border-zinc-600">
                                            {{ $item['coa']->kode }} - {{ $item['coa']->nama }}
                                        </td>
                                        <td class="border border-zinc-300 px-3 py-1 text-right font-mono tabular-nums dark:border-zinc-600">
                                            {{ number_format($item['saldo'], 0, ',', '.') }}
                                        </td>
                                        <td class="border border-zinc-300 px-3 py-1 dark:border-zinc-600"></td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="3" class="border border-zinc-300 px-3 py-4 text-center italic text-zinc-400 dark:border-zinc-600">
                                        Tidak ada beban di periode ini.
                                    </td>
                                </tr>
                            @endforelse
                            <tr class="bg-rose-100 font-bold dark:bg-rose-950/50">
                                <td colspan="2" class="border border-zinc-300 px-3 py-2 dark:border-zinc-600">TOTAL BEBAN</td>
                                <td class="border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-600">
                                    {{ number_format($data['beban']['total'], 0, ',', '.') }}
                                </td>
                            </tr>
                        </tbody>

                        {{-- LABA / RUGI --}}
                        <tbody>
                            <tr class="border-t-4 border-double bg-zinc-100 dark:bg-zinc-800">
                                <td colspan="2" class="border border-zinc-300 px-3 py-3 text-lg font-bold uppercase dark:border-zinc-600">
                                    {{ $data['laba_rugi'] >= 0 ? 'Laba Bersih Periode Berjalan' : 'Rugi Bersih Periode Berjalan' }}
                                </td>
                                <td class="border border-zinc-300 px-3 py-3 text-right font-mono tabular-nums text-lg font-bold dark:border-zinc-600 {{ $data['laba_rugi'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                                    {{ number_format(abs($data['laba_rugi']), 0, ',', '.') }}
                                    @if ($data['laba_rugi'] < 0)
                                        <span class="text-xs">(rugi)</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <style>
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: white !important; }
            .print\:hidden { display: none !important; }
            [data-flux-sidebar] { display: none !important; }
            main, .max-w-screen-xl { max-width: 100% !important; padding: 0 !important; }
        }
    </style>
</section>
