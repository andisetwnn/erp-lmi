<?php

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Arus Kas')] class extends Component
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
            'data' => app(LaporanAkuntingService::class)->arusKas($perusahaan->id, $this->from, $this->to),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-cyan-500 to-cyan-700 text-white shadow-sm">
                    <flux:icon.banknotes class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Arus Kas') }}</flux:heading>
                    <flux:subheading>{{ __('Cash Flow Statement — mutasi kas & bank periode.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="document-arrow-down"
                             href="{{ route('akunting.arus-kas.excel', ['from' => $from, 'to' => $to]) }}">
                    {{ __('Excel') }}
                </flux:button>
                <flux:button variant="ghost" icon="printer"
                             href="{{ route('akunting.arus-kas.print', ['from' => $from, 'to' => $to]) }}"
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

        {{-- SUMMARY --}}
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Kas Awal</div>
                <div class="mt-1 font-mono text-lg font-bold tabular-nums">Rp {{ number_format($data['kas_awal'], 0, ',', '.') }}</div>
            </div>
            <div class="rounded-lg border {{ $data['kenaikan_bersih'] >= 0 ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/40 dark:bg-emerald-950/30' : 'border-rose-200 bg-rose-50 dark:border-rose-900/40 dark:bg-rose-950/30' }} p-4">
                <div class="text-xs uppercase tracking-wide {{ $data['kenaikan_bersih'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">Kenaikan Bersih</div>
                <div class="mt-1 font-mono text-lg font-bold tabular-nums {{ $data['kenaikan_bersih'] >= 0 ? 'text-emerald-900 dark:text-emerald-200' : 'text-rose-900 dark:text-rose-200' }}">Rp {{ number_format($data['kenaikan_bersih'], 0, ',', '.') }}</div>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/40 dark:bg-blue-950/30">
                <div class="text-xs uppercase tracking-wide text-blue-700 dark:text-blue-400">Kas Akhir</div>
                <div class="mt-1 font-mono text-lg font-bold tabular-nums text-blue-900 dark:text-blue-200">Rp {{ number_format($data['kas_akhir'], 0, ',', '.') }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Periode</div>
                <div class="mt-1 text-xs">
                    <div>{{ \Carbon\Carbon::parse($from)->translatedFormat('d M Y') }}</div>
                    <div class="text-zinc-500">— {{ \Carbon\Carbon::parse($to)->translatedFormat('d M Y') }}</div>
                </div>
            </div>
        </div>

        {{-- 3 SECTIONS --}}
        @foreach ([
            ['key' => 'operasi',    'title' => 'AKTIVITAS OPERASI',    'color' => 'blue',    'icon' => '💼'],
            ['key' => 'investasi',  'title' => 'AKTIVITAS INVESTASI',  'color' => 'purple',  'icon' => '🏗️'],
            ['key' => 'pendanaan',  'title' => 'AKTIVITAS PENDANAAN',  'color' => 'emerald', 'icon' => '🏦'],
        ] as $section)
            @php $s = $data[$section['key']]; @endphp
            <div class="mb-4 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 bg-{{ $section['color'] }}-50 px-4 py-2 dark:border-zinc-700 dark:bg-{{ $section['color'] }}-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-bold uppercase text-{{ $section['color'] }}-800 dark:text-{{ $section['color'] }}-300">
                            {{ $section['icon'] }} {{ $section['title'] }}
                        </div>
                        <div class="font-mono text-sm font-bold tabular-nums {{ $s['net'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                            Rp {{ number_format($s['net'], 0, ',', '.') }}
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                            <tr class="text-left text-xs uppercase text-zinc-500">
                                <th class="px-4 py-2 font-semibold">Kode</th>
                                <th class="px-4 py-2 font-semibold">Lawan Akun (mutasi vs kas)</th>
                                <th class="px-4 py-2 text-right font-semibold">Kas Masuk</th>
                                <th class="px-4 py-2 text-right font-semibold">Kas Keluar</th>
                                <th class="px-4 py-2 text-right font-semibold">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($s['items'] as $item)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="px-4 py-1.5 whitespace-nowrap font-mono text-xs text-zinc-600">{{ $item['lawan_coa']->kode }}</td>
                                    <td class="px-4 py-1.5">{{ $item['lawan_coa']->nama }}</td>
                                    <td class="px-4 py-1.5 whitespace-nowrap text-right font-mono tabular-nums text-emerald-700 dark:text-emerald-400">{{ $item['masuk'] > 0 ? number_format($item['masuk'], 0, ',', '.') : '-' }}</td>
                                    <td class="px-4 py-1.5 whitespace-nowrap text-right font-mono tabular-nums text-rose-700 dark:text-rose-400">{{ $item['keluar'] > 0 ? number_format($item['keluar'], 0, ',', '.') : '-' }}</td>
                                    <td class="px-4 py-1.5 whitespace-nowrap text-right font-mono tabular-nums font-semibold {{ $item['net'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">{{ number_format($item['net'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-6 text-center text-zinc-400">Tidak ada mutasi di aktivitas ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

    </div>
</section>
