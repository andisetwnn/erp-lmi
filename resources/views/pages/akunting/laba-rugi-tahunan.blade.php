<?php

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Laba Rugi Tahunan')] class extends Component
{
    #[Url(as: 'tahun')]
    public ?int $tahun = null;

    /** 'detail' = sampai kelompok akun; 'resume' = hanya total besar. */
    #[Url(as: 'versi')]
    public string $versi = '';

    public function mount(): void
    {
        $this->tahun ??= (int) now()->year;

        if ($this->versi === '') {
            $this->versi = auth()->user()?->hasRole('direktur') ? 'resume' : 'detail';
        }
    }

    public function gantiTahun(int $selisih): void
    {
        $this->tahun += $selisih;
    }

    public function with(): array
    {
        $perusahaan = Perusahaan::first();
        $data = $perusahaan
            ? app(LaporanAkuntingService::class)->labaRugiTahunan($perusahaan->id, $this->tahun)
            : null;

        return [
            'perusahaan' => $perusahaan,
            'data' => $data,
            'rinci' => $this->versi !== 'resume',
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-purple-500 to-purple-700 text-white shadow-sm">
                    <flux:icon.calendar-days class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Laba Rugi Tahunan') }}</flux:heading>
                        <x-info-button title="Laba Rugi Tahunan">
                            <p>Laba rugi satu tahun penuh, dipecah per bulan Januari&ndash;Desember, supaya tren dan bulan yang menyimpang langsung kelihatan.</p>
                            <p class="mt-2">Kolom <strong>Total</strong> di kanan adalah akumulasi setahun &mdash; angkanya sama dengan Laporan Laba Rugi biasa kalau periodenya diset 1 Januari sampai 31 Desember.</p>
                            <p class="mt-2">Tampilan <strong>Detail</strong> merinci per kelompok akun; <strong>Resume</strong> hanya menampilkan total pendapatan, beban, dan laba bersih.</p>
                            <p class="mt-2 text-xs text-zinc-500">Hanya jurnal berstatus <em>posted</em> yang dihitung. Jurnal draft tidak masuk laporan.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Perbandingan laba rugi bulanan dalam satu tahun.') }}</flux:subheading>
                </div>
            </div>
            <flux:button variant="ghost" icon="printer"
                         href="{{ route('akunting.laba-rugi-tahunan.print', ['tahun' => $tahun, 'versi' => $versi]) }}"
                         target="_blank">
                {{ __('Cetak PDF') }}
            </flux:button>
        </div>

        <x-tab-nav :items="[
            ['label' => 'Per Periode', 'href' => route('akunting.laba-rugi.index'), 'active' => false],
            ['label' => 'Tahunan', 'href' => route('akunting.laba-rugi-tahunan.index'), 'active' => true],
        ]" />

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
            <div>
                <label class="mb-1 block text-sm font-medium">Tahun</label>
                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="gantiTahun(-1)" />
                    <span class="min-w-16 text-center font-mono text-lg font-bold">{{ $tahun }}</span>
                    <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="gantiTahun(1)" />
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Tampilan</label>
                <flux:button.group>
                    <flux:button size="sm" wire:click="$set('versi', 'detail')"
                                 :variant="$versi !== 'resume' ? 'primary' : 'filled'">
                        Detail
                    </flux:button>
                    <flux:button size="sm" wire:click="$set('versi', 'resume')"
                                 :variant="$versi === 'resume' ? 'primary' : 'filled'">
                        Resume
                    </flux:button>
                </flux:button.group>
            </div>
        </div>

        @if ($data)
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 print:border-0 print:p-0">
                {{-- Kop laporan --}}
                <div class="mb-6 text-center">
                    <div class="text-lg font-bold uppercase">
                        {{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}
                    </div>
                    <div class="text-2xl font-bold tracking-wide">LAPORAN LABA RUGI TAHUNAN</div>
                    <div class="text-sm">TAHUN {{ $data['tahun'] }}</div>
                </div>

                @php
                    // Angka ditampilkan dalam ribuan supaya 12 kolom muat tanpa digulir.
                    $ringkas = fn ($n) => $n == 0 ? '-' : number_format($n / 1000, 0, ',', '.');
                @endphp

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-xs">
                        <thead>
                            <tr class="bg-zinc-100 dark:bg-zinc-800">
                                <th class="sticky left-0 z-10 border border-zinc-300 bg-zinc-100 px-3 py-2 text-left font-semibold uppercase dark:border-zinc-600 dark:bg-zinc-800">
                                    Keterangan
                                </th>
                                @foreach ($data['bulan'] as $nama)
                                    <th class="border border-zinc-300 px-2 py-2 text-right font-semibold uppercase dark:border-zinc-600">
                                        {{ $nama }}
                                    </th>
                                @endforeach
                                <th class="border border-zinc-300 bg-zinc-200 px-3 py-2 text-right font-semibold uppercase dark:border-zinc-600 dark:bg-zinc-700">
                                    Total
                                </th>
                            </tr>
                        </thead>

                        {{-- PENDAPATAN --}}
                        <tbody>
                            <tr class="bg-emerald-50 dark:bg-emerald-950/30">
                                <td colspan="14" class="border border-zinc-300 px-3 py-1.5 font-bold uppercase dark:border-zinc-600">
                                    Pendapatan
                                </td>
                            </tr>
                            @if ($rinci)
                                @forelse ($data['pendapatan']['baris'] as $baris)
                                    <tr>
                                        <td class="sticky left-0 z-10 border border-zinc-300 bg-white px-3 py-1 dark:border-zinc-600 dark:bg-zinc-900">
                                            {{ $baris['header']->kode }} - {{ $baris['header']->nama }}
                                        </td>
                                        @foreach (range(1, 12) as $b)
                                            <td class="border border-zinc-300 px-2 py-1 text-right font-mono tabular-nums dark:border-zinc-600">
                                                {{ $ringkas($baris['per_bulan'][$b]) }}
                                            </td>
                                        @endforeach
                                        <td class="border border-zinc-300 bg-zinc-50 px-3 py-1 text-right font-mono tabular-nums font-semibold dark:border-zinc-600 dark:bg-zinc-800">
                                            {{ $ringkas($baris['total']) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="14" class="border border-zinc-300 px-3 py-3 text-center italic text-zinc-400 dark:border-zinc-600">
                                            Tidak ada pendapatan di tahun ini.
                                        </td>
                                    </tr>
                                @endforelse
                            @endif
                            <tr class="bg-emerald-100 font-bold dark:bg-emerald-950/50">
                                <td class="sticky left-0 z-10 border border-zinc-300 bg-emerald-100 px-3 py-1.5 dark:border-zinc-600 dark:bg-emerald-950/50">
                                    Total Pendapatan
                                </td>
                                @foreach (range(1, 12) as $b)
                                    <td class="border border-zinc-300 px-2 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ $ringkas($data['pendapatan']['per_bulan'][$b]) }}
                                    </td>
                                @endforeach
                                <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                    {{ $ringkas($data['pendapatan']['total']) }}
                                </td>
                            </tr>
                        </tbody>

                        {{-- BEBAN --}}
                        <tbody>
                            <tr class="bg-rose-50 dark:bg-rose-950/30">
                                <td colspan="14" class="border border-zinc-300 px-3 py-1.5 font-bold uppercase dark:border-zinc-600">
                                    Beban / HPP
                                </td>
                            </tr>
                            @if ($rinci)
                                @forelse ($data['beban']['baris'] as $baris)
                                    <tr>
                                        <td class="sticky left-0 z-10 border border-zinc-300 bg-white px-3 py-1 dark:border-zinc-600 dark:bg-zinc-900">
                                            {{ $baris['header']->kode }} - {{ $baris['header']->nama }}
                                        </td>
                                        @foreach (range(1, 12) as $b)
                                            <td class="border border-zinc-300 px-2 py-1 text-right font-mono tabular-nums dark:border-zinc-600">
                                                {{ $ringkas($baris['per_bulan'][$b]) }}
                                            </td>
                                        @endforeach
                                        <td class="border border-zinc-300 bg-zinc-50 px-3 py-1 text-right font-mono tabular-nums font-semibold dark:border-zinc-600 dark:bg-zinc-800">
                                            {{ $ringkas($baris['total']) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="14" class="border border-zinc-300 px-3 py-3 text-center italic text-zinc-400 dark:border-zinc-600">
                                            Tidak ada beban di tahun ini.
                                        </td>
                                    </tr>
                                @endforelse
                            @endif
                            <tr class="bg-rose-100 font-bold dark:bg-rose-950/50">
                                <td class="sticky left-0 z-10 border border-zinc-300 bg-rose-100 px-3 py-1.5 dark:border-zinc-600 dark:bg-rose-950/50">
                                    Total Beban
                                </td>
                                @foreach (range(1, 12) as $b)
                                    <td class="border border-zinc-300 px-2 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                        {{ $ringkas($data['beban']['per_bulan'][$b]) }}
                                    </td>
                                @endforeach
                                <td class="border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                    {{ $ringkas($data['beban']['total']) }}
                                </td>
                            </tr>
                        </tbody>

                        {{-- LABA / RUGI --}}
                        <tfoot>
                            <tr class="border-t-4 border-double bg-zinc-100 font-bold dark:bg-zinc-800">
                                <td class="sticky left-0 z-10 border border-zinc-300 bg-zinc-100 px-3 py-2 uppercase dark:border-zinc-600 dark:bg-zinc-800">
                                    Laba / Rugi Bersih
                                </td>
                                @foreach (range(1, 12) as $b)
                                    @php($nilai = $data['laba_rugi']['per_bulan'][$b])
                                    <td @class([
                                        'border border-zinc-300 px-2 py-2 text-right font-mono tabular-nums dark:border-zinc-600',
                                        'text-rose-700 dark:text-rose-400' => $nilai < 0,
                                        'text-emerald-700 dark:text-emerald-400' => $nilai > 0,
                                    ])>
                                        {{ $ringkas($nilai) }}
                                    </td>
                                @endforeach
                                @php($total = $data['laba_rugi']['total'])
                                <td @class([
                                    'border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums text-sm dark:border-zinc-600',
                                    'text-rose-700 dark:text-rose-400' => $total < 0,
                                    'text-emerald-700 dark:text-emerald-400' => $total > 0,
                                ])>
                                    {{ $ringkas($total) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="mt-3 text-xs text-zinc-500">
                    Semua angka dalam <strong>ribuan rupiah</strong>. Angka negatif berarti rugi.
                </p>
            </div>
        @endif
    </div>

    <style>
        @media print {
            @page { size: A4 landscape; margin: 8mm; }
            body { background: white !important; }
            .print\:hidden { display: none !important; }
            [data-flux-sidebar] { display: none !important; }
            main, .max-w-screen-2xl { max-width: 100% !important; padding: 0 !important; }
        }
    </style>
</section>
