<?php

use App\Models\Akunting\JurnalDetail;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Buku Besar')] class extends Component
{
    #[Url(as: 'coa')]
    public ?int $coaId = null;

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

    /** Query mutasi jurnal_detail per COA + range tgl. */
    public function getMutasiProperty()
    {
        if (! $this->coaId) {
            return collect();
        }

        return JurnalDetail::query()
            ->where('coa_id', $this->coaId)
            ->whereHas('jurnal', function ($q) {
                $q->where('status', 'posted')
                    ->whereBetween('tanggal', [$this->from, $this->to]);
            })
            ->with(['jurnal:id,tanggal,no_bukti,keterangan'])
            ->get()
            ->sortBy([
                fn ($a, $b) => $a->jurnal->tanggal <=> $b->jurnal->tanggal,
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    /** Saldo sebelum periode (Saldo Awal). */
    public function getSaldoAwalProperty(): float
    {
        if (! $this->coaId) {
            return 0;
        }

        $sums = JurnalDetail::query()
            ->where('coa_id', $this->coaId)
            ->whereHas('jurnal', function ($q) {
                $q->where('status', 'posted')
                    ->whereDate('tanggal', '<', $this->from);
            })
            ->selectRaw('COALESCE(SUM(debet),0) as debet, COALESCE(SUM(kredit),0) as kredit')
            ->first();

        return $this->hitungSaldo((float) $sums->debet, (float) $sums->kredit);
    }

    public function getTotalDebetProperty(): float
    {
        return (float) $this->mutasi->sum('debet');
    }

    public function getTotalKreditProperty(): float
    {
        return (float) $this->mutasi->sum('kredit');
    }

    public function getSaldoAkhirProperty(): float
    {
        return $this->saldoAwal + $this->hitungSaldo($this->totalDebet, $this->totalKredit);
    }

    /** Hitung saldo berdasarkan saldo_normal COA. */
    protected function hitungSaldo(float $debet, float $kredit): float
    {
        $coa = $this->coaSelected;
        if (! $coa) {
            return $debet - $kredit;
        }

        return $coa->saldo_normal === 'debit'
            ? $debet - $kredit
            : $kredit - $debet;
    }

    public function getCoaSelectedProperty(): ?Coa
    {
        return $this->coaId ? Coa::find($this->coaId) : null;
    }

    public function with(): array
    {
        return [
            'coaSelected' => $this->coaSelected,
            'coaOptions' => Coa::query()
                ->where('is_aktif', true)
                ->where('is_header', false)
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
            'perusahaan' => Perusahaan::first(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER (hidden saat print) --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-emerald-500 to-emerald-700 text-white shadow-sm">
                    <flux:icon.book-open class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Buku Besar') }}</flux:heading>
                    <flux:subheading>{{ __('Mutasi per kode akun berdasarkan jurnal yang sudah diposting.') }}</flux:subheading>
                </div>
            </div>
            @if ($coaId)
                <flux:button variant="ghost" icon="printer"
                             href="{{ route('akunting.buku-besar.print', ['coa' => $coaId, 'from' => $from, 'to' => $to]) }}"
                             target="_blank">
                    {{ __('Print / Export PDF') }}
                </flux:button>
            @endif
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
            <div class="min-w-72 flex-1">
                <x-coa-picker
                    wire-property="coaId"
                    label="Kode Akun"
                    placeholder="Pilih akun..."
                    :options="$coaOptions"
                    allow-clear
                    live
                />
            </div>
            <div>
                <flux:input type="date" wire:model.live="from" label="Dari Tanggal" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="to" label="Sampai Tanggal" />
            </div>
        </div>

        {{-- LAPORAN --}}
        @if ($coaId && $coaSelected)
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 print:border-0 print:p-0">
                {{-- Kop laporan --}}
                <div class="mb-6 text-center">
                    <div class="text-lg font-bold uppercase">
                        {{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}
                    </div>
                    <div class="text-2xl font-bold tracking-wide">BUKU BESAR</div>
                    <div class="text-sm">
                        PERIODE : {{ strtoupper(\Carbon\Carbon::parse($from)->translatedFormat('d F Y')) }}
                        &mdash; {{ strtoupper(\Carbon\Carbon::parse($to)->translatedFormat('d F Y')) }}
                    </div>
                </div>

                {{-- Info panel akun + saldo --}}
                <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-1 text-sm">
                        <div class="flex gap-4">
                            <span class="w-24 text-zinc-500">No. Akun</span>
                            <span class="font-mono font-semibold">{{ $coaSelected->kode }}</span>
                        </div>
                        <div class="flex gap-4">
                            <span class="w-24 text-zinc-500">Nama Akun</span>
                            <span class="font-semibold">{{ $coaSelected->nama }}</span>
                        </div>
                    </div>
                    <div class="space-y-1 rounded border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Saldo Awal</span>
                            <span class="font-mono tabular-nums font-semibold">
                                {{ number_format($this->saldoAwal, 2, ',', '.') }}
                            </span>
                        </div>
                        <div class="flex justify-between border-t border-zinc-200 pt-1 dark:border-zinc-700">
                            <span class="text-zinc-500">Saldo Akhir</span>
                            <span class="font-mono tabular-nums font-bold">
                                {{ number_format($this->saldoAkhir, 2, ',', '.') }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Tabel mutasi --}}
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead class="bg-zinc-100 dark:bg-zinc-800">
                            <tr>
                                <th class="border border-zinc-300 px-3 py-2 text-left text-xs font-semibold uppercase dark:border-zinc-600">Tanggal</th>
                                <th class="border border-zinc-300 px-3 py-2 text-left text-xs font-semibold uppercase dark:border-zinc-600">No Bukti</th>
                                <th class="border border-zinc-300 px-3 py-2 text-left text-xs font-semibold uppercase dark:border-zinc-600">Uraian Transaksi</th>
                                <th class="border border-zinc-300 px-3 py-2 text-right text-xs font-semibold uppercase dark:border-zinc-600">Debet</th>
                                <th class="border border-zinc-300 px-3 py-2 text-right text-xs font-semibold uppercase dark:border-zinc-600">Kredit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Row SALDO AWAL --}}
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                <td class="border border-zinc-300 px-3 py-1.5 whitespace-nowrap text-xs dark:border-zinc-600">
                                    {{ \Carbon\Carbon::parse($from)->translatedFormat('d M y') }}
                                </td>
                                <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600"></td>
                                <td class="border border-zinc-300 px-3 py-1.5 text-xs italic dark:border-zinc-600">SALDO AWAL …</td>
                                <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">-</td>
                                <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">-</td>
                            </tr>

                            @forelse ($this->mutasi as $m)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="border border-zinc-300 px-3 py-1.5 whitespace-nowrap text-xs dark:border-zinc-600">
                                        {{ $m->jurnal->tanggal->translatedFormat('d M y') }}
                                    </td>
                                    <td class="border border-zinc-300 px-3 py-1.5 whitespace-nowrap font-mono text-xs dark:border-zinc-600">
                                        {{ $m->jurnal->no_bukti }}
                                    </td>
                                    <td class="border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-600">
                                        {{ $m->jurnal->keterangan ?: '-' }}
                                    </td>
                                    <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums text-xs dark:border-zinc-600">
                                        {{ $m->debet > 0 ? number_format((float) $m->debet, 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums text-xs dark:border-zinc-600">
                                        {{ $m->kredit > 0 ? number_format((float) $m->kredit, 0, ',', '.') : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="border border-zinc-300 px-3 py-8 text-center text-zinc-400 dark:border-zinc-600">
                                        Tidak ada mutasi di periode ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-zinc-100 font-bold dark:bg-zinc-800">
                            <tr>
                                <td colspan="3" class="border border-zinc-300 px-3 py-2 text-center uppercase dark:border-zinc-600">TOTAL</td>
                                <td class="border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-600">
                                    {{ number_format($this->totalDebet, 0, ',', '.') }}
                                </td>
                                <td class="border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-600">
                                    {{ number_format($this->totalKredit, 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @else
            <div class="rounded-lg border-2 border-dashed border-zinc-300 bg-zinc-50 p-12 text-center dark:border-zinc-600 dark:bg-zinc-800/30">
                <flux:icon.book-open class="mx-auto mb-3 size-10 text-zinc-400" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Pilih <strong>Kode Akun</strong> untuk menampilkan buku besar.
                </p>
            </div>
        @endif
    </div>

    {{-- Print CSS --}}
    <style>
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body { background: white !important; }
            .print\:hidden { display: none !important; }
            [data-flux-sidebar] { display: none !important; }
            main, .max-w-screen-2xl { max-width: 100% !important; padding: 0 !important; }
        }
    </style>
</section>
