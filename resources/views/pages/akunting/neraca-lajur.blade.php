<?php

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Neraca Lajur')] class extends Component
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
            'data' => app(LaporanAkuntingService::class)->neracaLajur($perusahaan->id, $this->from, $this->to),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-purple-500 to-purple-700 text-white shadow-sm">
                    <flux:icon.table-cells class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Neraca Lajur') }}</flux:heading>
                    <flux:subheading>{{ __('Worksheet 10 kolom: Neraca Saldo → AJP → Disesuaikan → Rugi/Laba + Neraca. Tools closing periode.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="document-arrow-down"
                             href="{{ route('akunting.neraca-lajur.excel', ['from' => $from, 'to' => $to]) }}">
                    {{ __('Excel') }}
                </flux:button>
                <flux:button variant="ghost" icon="printer"
                             href="{{ route('akunting.neraca-lajur.print', ['from' => $from, 'to' => $to]) }}"
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
            <div class="text-xs text-zinc-500">
                Kolom AJP = jurnal dgn kategori <strong>AKM</strong> (Akumulasi Penyusutan) + <strong>RJE</strong> (Reversing).
                Kolom Rugi/Laba & Neraca dipecah otomatis dari kolom Disesuaikan sesuai tipe akun.
            </div>
        </div>

        @unless ($data['balanced'])
            <div class="mb-4 rounded p-3 text-center text-sm font-semibold bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                ⚠ Neraca Lajur TIDAK BALANCE — ada jurnal yg debet ≠ kredit!
            </div>
        @endunless

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-[11px]">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-100 text-xs uppercase text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            <th rowspan="2" class="border-r border-zinc-200 px-2 py-2 text-left font-bold dark:border-zinc-700">Kode</th>
                            <th rowspan="2" class="border-r border-zinc-200 px-2 py-2 text-left font-bold dark:border-zinc-700">Nama Akun</th>
                            <th colspan="2" class="border-x border-zinc-200 px-2 py-1.5 text-center font-bold bg-zinc-200/50 dark:border-zinc-700 dark:bg-zinc-800">Neraca Saldo</th>
                            <th colspan="2" class="border-x border-zinc-200 px-2 py-1.5 text-center font-bold bg-amber-100/60 dark:border-zinc-700 dark:bg-amber-950/30">AJP</th>
                            <th colspan="2" class="border-x border-zinc-200 px-2 py-1.5 text-center font-bold bg-blue-100/60 dark:border-zinc-700 dark:bg-blue-950/30">Disesuaikan</th>
                            <th colspan="2" class="border-x border-zinc-200 px-2 py-1.5 text-center font-bold bg-rose-100/60 dark:border-zinc-700 dark:bg-rose-950/30">Rugi/Laba</th>
                            <th colspan="2" class="border-x border-zinc-200 px-2 py-1.5 text-center font-bold bg-emerald-100/60 dark:border-zinc-700 dark:bg-emerald-950/30">Neraca</th>
                        </tr>
                        <tr class="border-b border-zinc-200 bg-zinc-50 text-[10px] font-semibold text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <th class="px-2 py-1 text-right">Debet</th>
                            <th class="border-r border-zinc-200 px-2 py-1 text-right dark:border-zinc-700">Kredit</th>
                            <th class="px-2 py-1 text-right">Debet</th>
                            <th class="border-r border-zinc-200 px-2 py-1 text-right dark:border-zinc-700">Kredit</th>
                            <th class="px-2 py-1 text-right">Debet</th>
                            <th class="border-r border-zinc-200 px-2 py-1 text-right dark:border-zinc-700">Kredit</th>
                            <th class="px-2 py-1 text-right">Debet</th>
                            <th class="border-r border-zinc-200 px-2 py-1 text-right dark:border-zinc-700">Kredit</th>
                            <th class="px-2 py-1 text-right">Debet</th>
                            <th class="px-2 py-1 text-right">Kredit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @php
                            $fmt = fn ($v) => $v > 0 ? number_format($v, 0, ',', '.') : '-';
                        @endphp
                        @forelse ($data['rows'] as $r)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="border-r border-zinc-100 px-2 py-1 font-mono text-[10px] text-zinc-600 dark:border-zinc-800 dark:text-zinc-400 whitespace-nowrap">{{ $r['coa']->kode }}</td>
                                <td class="border-r border-zinc-100 px-2 py-1 font-medium dark:border-zinc-800">{{ $r['coa']->nama }}</td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums">{{ $fmt($r['ns_debet']) }}</td>
                                <td class="border-r border-zinc-100 px-2 py-1 text-right font-mono tabular-nums dark:border-zinc-800">{{ $fmt($r['ns_kredit']) }}</td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums bg-amber-50/40 dark:bg-amber-950/10">{{ $fmt($r['ajp_debet']) }}</td>
                                <td class="border-r border-zinc-100 px-2 py-1 text-right font-mono tabular-nums bg-amber-50/40 dark:border-zinc-800 dark:bg-amber-950/10">{{ $fmt($r['ajp_kredit']) }}</td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums bg-blue-50/40 dark:bg-blue-950/10 font-semibold">{{ $fmt($r['adj_debet']) }}</td>
                                <td class="border-r border-zinc-100 px-2 py-1 text-right font-mono tabular-nums bg-blue-50/40 dark:border-zinc-800 dark:bg-blue-950/10 font-semibold">{{ $fmt($r['adj_kredit']) }}</td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums bg-rose-50/30 dark:bg-rose-950/10">{{ $fmt($r['lr_debet']) }}</td>
                                <td class="border-r border-zinc-100 px-2 py-1 text-right font-mono tabular-nums bg-rose-50/30 dark:border-zinc-800 dark:bg-rose-950/10">{{ $fmt($r['lr_kredit']) }}</td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums bg-emerald-50/30 dark:bg-emerald-950/10">{{ $fmt($r['nr_debet']) }}</td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums bg-emerald-50/30 dark:bg-emerald-950/10">{{ $fmt($r['nr_kredit']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="px-4 py-12 text-center text-zinc-400">Tidak ada mutasi di periode ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (! empty($data['rows']))
                        <tfoot class="border-t-2 border-zinc-300 bg-zinc-100 text-xs font-bold dark:border-zinc-600 dark:bg-zinc-800">
                            <tr>
                                <td colspan="2" class="border-r border-zinc-300 px-2 py-2 text-right dark:border-zinc-600">TOTAL</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums">{{ $fmt($data['total_ns_debet']) }}</td>
                                <td class="border-r border-zinc-300 px-2 py-2 text-right font-mono tabular-nums dark:border-zinc-600">{{ $fmt($data['total_ns_kredit']) }}</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-amber-100 dark:bg-amber-950/40">{{ $fmt($data['total_ajp_debet']) }}</td>
                                <td class="border-r border-zinc-300 px-2 py-2 text-right font-mono tabular-nums bg-amber-100 dark:border-zinc-600 dark:bg-amber-950/40">{{ $fmt($data['total_ajp_kredit']) }}</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-blue-100 dark:bg-blue-950/40">{{ $fmt($data['total_adj_debet']) }}</td>
                                <td class="border-r border-zinc-300 px-2 py-2 text-right font-mono tabular-nums bg-blue-100 dark:border-zinc-600 dark:bg-blue-950/40">{{ $fmt($data['total_adj_kredit']) }}</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-rose-100 dark:bg-rose-950/40">{{ $fmt($data['total_lr_debet']) }}</td>
                                <td class="border-r border-zinc-300 px-2 py-2 text-right font-mono tabular-nums bg-rose-100 dark:border-zinc-600 dark:bg-rose-950/40">{{ $fmt($data['total_lr_kredit']) }}</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-emerald-100 dark:bg-emerald-950/40">{{ $fmt($data['total_nr_debet']) }}</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-emerald-100 dark:bg-emerald-950/40">{{ $fmt($data['total_nr_kredit']) }}</td>
                            </tr>
                            {{-- Selisih Laba/Rugi baris balancing --}}
                            @php $lr = $data['laba_rugi']; @endphp
                            <tr class="border-t border-zinc-300 bg-blue-50 dark:border-zinc-600 dark:bg-blue-950/30">
                                <td colspan="6" class="border-r border-zinc-300 px-2 py-2 text-right dark:border-zinc-600"></td>
                                <td colspan="2" class="border-r border-zinc-300 px-2 py-2 text-right dark:border-zinc-600">{{ $lr >= 0 ? 'LABA' : 'RUGI' }} Bersih</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-rose-50 dark:bg-rose-950/20">{{ $lr >= 0 ? $fmt($lr) : '-' }}</td>
                                <td class="border-r border-zinc-300 px-2 py-2 text-right font-mono tabular-nums bg-rose-50 dark:border-zinc-600 dark:bg-rose-950/20">{{ $lr < 0 ? $fmt(-$lr) : '-' }}</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-emerald-50 dark:bg-emerald-950/20">{{ $lr < 0 ? $fmt(-$lr) : '-' }}</td>
                                <td class="px-2 py-2 text-right font-mono tabular-nums bg-emerald-50 dark:bg-emerald-950/20">{{ $lr >= 0 ? $fmt($lr) : '-' }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        @if (! empty($data['rows']))
            <div class="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
                <strong>Interpretasi:</strong>
                @if ($data['laba_rugi'] >= 0)
                    Perusahaan mengalami <span class="font-bold text-emerald-700 dark:text-emerald-400">LABA</span>
                    sebesar <strong>Rp {{ number_format($data['laba_rugi'], 0, ',', '.') }}</strong> di periode ini.
                    Angka ini menutup kolom Rugi/Laba (di sisi Debet) dan Neraca (di sisi Kredit — menambah modal).
                @else
                    Perusahaan mengalami <span class="font-bold text-rose-700 dark:text-rose-400">RUGI</span>
                    sebesar <strong>Rp {{ number_format(-$data['laba_rugi'], 0, ',', '.') }}</strong> di periode ini.
                    Angka ini menutup kolom Rugi/Laba (di sisi Kredit) dan Neraca (di sisi Debet — mengurangi modal).
                @endif
            </div>
        @endif

    </div>
</section>
