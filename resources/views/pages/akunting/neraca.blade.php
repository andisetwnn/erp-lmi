<?php

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Neraca')] class extends Component
{
    #[Url(as: 'tgl')]
    public string $tanggal = '';

    #[Url(as: 'from')]
    public string $from = '';

    public function mount(): void
    {
        if ($this->tanggal === '') {
            $this->tanggal = now()->endOfMonth()->toDateString();
        }
        if ($this->from === '') {
            $this->from = now()->startOfYear()->toDateString();
        }
    }

    public function with(): array
    {
        $perusahaan = Perusahaan::first();
        $data = null;
        if ($perusahaan) {
            $data = app(LaporanAkuntingService::class)->neraca(
                $perusahaan->id,
                $this->tanggal,
                $this->from,
            );
        }

        return [
            'perusahaan' => $perusahaan,
            'data' => $data,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER (hidden saat print) --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-indigo-500 to-indigo-700 text-white shadow-sm">
                    <flux:icon.scale class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Neraca') }}</flux:heading>
                        <x-info-button title="Neraca">
                            <p>Foto posisi keuangan per tanggal cutoff. Menampilkan 3 section:</p>
                            <ul class="ml-4 mt-1 list-disc space-y-1">
                                <li><strong>Aset</strong> — Kas, Bank, Piutang, Persediaan Rumah, Aktiva Tetap (yg perusahaan punya)</li>
                                <li><strong>Kewajiban</strong> — Utang Bank, Utang Pajak, Utang SPK (kontraktor)</li>
                                <li><strong>Modal</strong> — Modal Saham, Laba Ditahan, Laba/Rugi Berjalan</li>
                            </ul>
                            <p class="mt-2">Wajib balance: <span class="font-mono">Aset = Kewajiban + Modal</span>. Kalau muncul warning merah "TIDAK BALANCE" → cek Neraca Saldo dulu untuk cari jurnal yg salah.</p>
                            <p class="mt-2">Cara pakai: pilih <strong>Per Tanggal</strong> cutoff (mis. 31 Juli 2026), sistem akan hitung posisi semua akun sampai tanggal itu.</p>
                            <p class="mt-2 text-xs text-zinc-500">Ini laporan formal untuk direktur/investor/bank. Bisa export PDF &amp; Excel dgn logo LMI.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Posisi keuangan per tanggal cutoff — Aset = Kewajiban + Modal.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="document-arrow-down"
                             href="{{ route('akunting.neraca.excel', ['tgl' => $tanggal, 'from' => $from]) }}">
                    {{ __('Excel') }}
                </flux:button>
                <flux:button variant="ghost" icon="printer"
                             href="{{ route('akunting.neraca.print', ['tgl' => $tanggal, 'from' => $from]) }}"
                             target="_blank">
                    {{ __('Cetak PDF') }}
                </flux:button>
            </div>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
            <div>
                <flux:input type="date" wire:model.live="from" label="Dari Tanggal (utk Laba Periode)"
                            description="Awal periode untuk hitung laba/rugi berjalan" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="tanggal" label="Per Tanggal (Cutoff)"
                            description="Snapshot posisi aset/kewajiban/modal" />
            </div>
        </div>

        @if ($data)
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 print:border-0 print:p-0">
                {{-- Kop laporan --}}
                <div class="mb-6 text-center">
                    <div class="text-lg font-bold uppercase">
                        {{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}
                    </div>
                    <div class="text-2xl font-bold tracking-wide">NERACA</div>
                    <div class="text-sm">
                        PER : {{ strtoupper(\Carbon\Carbon::parse($tanggal)->translatedFormat('d F Y')) }}
                    </div>
                </div>

                @unless ($data['balanced'])
                    {{-- Warning: neraca tidak balance --}}
                    <div class="mb-4 rounded p-3 text-center text-sm font-semibold bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                        ⚠ Neraca TIDAK BALANCE — cek jurnal!
                        <span class="ml-2 font-mono">
                            (Aset {{ number_format($data['aset']['total'], 0, ',', '.') }}
                            vs Pasiva {{ number_format($data['total_pasiva'], 0, ',', '.') }})
                        </span>
                    </div>
                @endunless

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {{-- KIRI: ASET --}}
                    <div>
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="bg-indigo-50 dark:bg-indigo-950/30">
                                    <th colspan="2" class="border border-zinc-300 px-3 py-2 text-left text-xs font-bold uppercase dark:border-zinc-600">
                                        Aset
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['aset']['groups'] as $group)
                                    <tr class="font-semibold text-zinc-700 dark:text-zinc-300">
                                        <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">
                                            {{ $group['header']->kode }} - {{ $group['header']->nama }}
                                        </td>
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
                                        </tr>
                                    @endforeach
                                @empty
                                    <tr>
                                        <td colspan="2" class="border border-zinc-300 px-3 py-4 text-center italic text-zinc-400 dark:border-zinc-600">
                                            Tidak ada aset.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="bg-indigo-100 font-bold dark:bg-indigo-950/50">
                                    <td class="border border-zinc-300 px-3 py-2 uppercase dark:border-zinc-600">Total Aset</td>
                                    <td class="border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ number_format($data['aset']['total'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- KANAN: KEWAJIBAN + MODAL + LABA --}}
                    <div>
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="bg-amber-50 dark:bg-amber-950/30">
                                    <th colspan="2" class="border border-zinc-300 px-3 py-2 text-left text-xs font-bold uppercase dark:border-zinc-600">
                                        Kewajiban
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['kewajiban']['groups'] as $group)
                                    <tr class="font-semibold text-zinc-700 dark:text-zinc-300">
                                        <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">
                                            {{ $group['header']->kode }} - {{ $group['header']->nama }}
                                        </td>
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
                                        </tr>
                                    @endforeach
                                @empty
                                    <tr>
                                        <td colspan="2" class="border border-zinc-300 px-3 py-4 text-center italic text-zinc-400 dark:border-zinc-600">
                                            Tidak ada kewajiban.
                                        </td>
                                    </tr>
                                @endforelse
                                <tr class="bg-amber-100 font-semibold dark:bg-amber-950/50">
                                    <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">Total Kewajiban</td>
                                    <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ number_format($data['kewajiban']['total'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            </tbody>

                            <thead>
                                <tr class="bg-cyan-50 dark:bg-cyan-950/30">
                                    <th colspan="2" class="border border-zinc-300 px-3 py-2 text-left text-xs font-bold uppercase dark:border-zinc-600">
                                        Modal & Laba
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($data['modal']['groups'] as $group)
                                    <tr class="font-semibold text-zinc-700 dark:text-zinc-300">
                                        <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">
                                            {{ $group['header']->kode }} - {{ $group['header']->nama }}
                                        </td>
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
                                        </tr>
                                    @endforeach
                                @endforeach
                                {{-- Laba (Rugi) Periode --}}
                                <tr class="text-xs text-zinc-600 dark:text-zinc-400 italic">
                                    <td class="border border-zinc-300 px-3 py-1 dark:border-zinc-600">
                                        {{ $data['laba_periode'] >= 0 ? 'Laba' : 'Rugi' }} Periode Berjalan (tahun berjalan)
                                    </td>
                                    <td class="border border-zinc-300 px-3 py-1 text-right font-mono tabular-nums dark:border-zinc-600 {{ $data['laba_periode'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ number_format($data['laba_periode'], 0, ',', '.') }}
                                    </td>
                                </tr>
                                <tr class="bg-cyan-100 font-semibold dark:bg-cyan-950/50">
                                    <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">Total Modal & Laba</td>
                                    <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ number_format($data['modal']['total'] + $data['laba_periode'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-zinc-200 font-bold dark:bg-zinc-800">
                                    <td class="border border-zinc-300 px-3 py-2 uppercase dark:border-zinc-600">Total Pasiva</td>
                                    <td class="border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ number_format($data['total_pasiva'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>

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
