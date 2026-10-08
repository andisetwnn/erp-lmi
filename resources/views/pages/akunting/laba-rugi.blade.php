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

    /** 'detail' = sampai akun; 'resume' = berhenti di kelompok. */
    #[Url(as: 'versi')]
    public string $versi = '';

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->startOfYear()->toDateString();
        }
        if ($this->to === '') {
            $this->to = now()->endOfMonth()->toDateString();
        }
        if ($this->versi === '') {
            // Direksi butuh gambaran besar, accounting butuh rinciannya.
            $this->versi = auth()->user()?->hasRole('direktur') ? 'resume' : 'detail';
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
            'rinci' => $this->versi !== 'resume',
        ];
    }
}; ?>

@php
    /**
     * Nominal beserta persennya dalam satu sel: "14.093.000.000 (100,0%)".
     *
     * Sengaja tidak dipecah jadi kolom sendiri — persen di sini bukan angka yang
     * berdiri sendiri, melainkan keterangan untuk nominal di sebelahnya. Kolom
     * terpisah melebarkan tabel yang sudah panjang tanpa menambah apa pun.
     */
    $nilaiPersen = function ($nilai, $dasar) {
        $angka = number_format((float) $nilai, 0, ',', '.');
        $pct = number_format(
            \App\Services\LaporanAkuntingService::persen((float) $nilai, (float) $dasar), 1, ',', '.'
        );

        return [$angka, $pct.'%'];
    };
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER (hidden saat print) --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-purple-500 to-purple-700 text-white shadow-sm">
                    <flux:icon.chart-bar-square class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Laporan Laba Rugi') }}</flux:heading>
                        <x-info-button title="Laporan Laba Rugi">
                            <p>Ringkasan performa keuangan untuk periode yg dipilih. Rumusnya:</p>
                            <p class="ml-4 mt-1 font-mono text-sm text-center bg-zinc-50 dark:bg-zinc-800 py-2 rounded">LABA/RUGI = PENDAPATAN − BEBAN</p>
                            <ul class="ml-4 mt-2 list-disc space-y-1">
                                <li>Positif → <strong>Laba Bersih</strong> (untung)</li>
                                <li>Negatif → <strong>Rugi Bersih</strong></li>
                            </ul>
                            <p class="mt-2">Cara pakai: pilih periode <strong>Dari</strong>–<strong>Sampai Tanggal</strong>, sistem akan aggregate semua akun tipe pendapatan (4xxx) &amp; beban (5xxx-6xxx) yg posted di periode itu.</p>
                            <p class="mt-2">Angka Laba/Rugi ini otomatis nyambung ke Neraca (bagian Modal). Contoh: Juli 2026 rugi 657jt → di Neraca kolom Modal jadi berkurang 657jt.</p>
                            <p class="mt-2 text-xs text-zinc-500">Untuk property: pendapatan diakui saat AKAD KREDIT (bukan saat UM masuk). HPP diakui saat rumah terjual (bukan saat beli tanah).</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Performa keuangan periode: Pendapatan − Beban = Laba/Rugi Bersih.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="document-arrow-down"
                             title="Excel selalu berisi rincian per akun — berkas kerja Accounting"
                             href="{{ route('akunting.laba-rugi.excel', ['from' => $from, 'to' => $to]) }}">
                    {{ __('Excel') }}
                </flux:button>
                <flux:button variant="ghost" icon="printer"
                             href="{{ route('akunting.laba-rugi.print', ['from' => $from, 'to' => $to, 'versi' => $versi]) }}"
                             target="_blank">
                    {{ __('Cetak PDF') }}
                </flux:button>
            </div>
        </div>

        <x-tab-nav :items="[
            ['label' => 'Per Periode', 'href' => route('akunting.laba-rugi.index'), 'active' => true],
            ['label' => 'Tahunan', 'href' => route('akunting.laba-rugi-tahunan.index'), 'active' => false],
        ]" />

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
            <div>
                <flux:input type="date" wire:model.live="from" label="Dari Tanggal" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="to" label="Sampai Tanggal" />
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
                    <div class="text-2xl font-bold tracking-wide">LAPORAN LABA RUGI</div>
                    <div class="text-sm">
                        PERIODE : {{ strtoupper(\Carbon\Carbon::parse($from)->translatedFormat('d F Y')) }}
                        &mdash; {{ strtoupper(\Carbon\Carbon::parse($to)->translatedFormat('d F Y')) }}
                    </div>
                </div>

                @php
                    $u = $data['uraian'];
                    $dasar = $u['dasar_persen'];

                    // Urutannya yang membentuk laporan: dua seksi di atas garis
                    // laba kotor, tiga di bawahnya. Tanda menentukan arah angka —
                    // beban ditampilkan negatif supaya penjumlahannya ke bawah
                    // bisa diikuti mata, bukan harus dikurangi dalam kepala.
                    $atas = [
                        ['Penjualan', $u['penjualan'], 1, 'emerald'],
                        ['Harga Pokok Penjualan', $u['hpp'], -1, 'rose'],
                    ];
                    $bawah = [
                        ['Biaya Usaha', $u['biaya'], -1, 'rose'],
                        ['Pendapatan Lain-lain', $u['pendapatan_lain'], 1, 'emerald'],
                        ['Pajak PPh Final', $u['pajak_final'], -1, 'rose'],
                    ];

                    // Kelas Tailwind harus utuh sebagai teks, tidak boleh dirangkai.
                    $nada = [
                        'emerald' => ['bg-emerald-50 dark:bg-emerald-950/30', 'bg-emerald-100 dark:bg-emerald-950/50'],
                        'rose' => ['bg-rose-50 dark:bg-rose-950/30', 'bg-rose-100 dark:bg-rose-950/50'],
                    ];
                @endphp

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        @foreach ([$atas, $bawah] as $i => $kelompok)
                            @foreach ($kelompok as [$judul, $seksi, $tanda, $warna])
                                @continue(! $seksi['groups'])
                                @php [$latarJudul, $latarTotal] = $nada[$warna]; @endphp

                                <thead>
                                    <tr class="{{ $latarJudul }}">
                                        <th colspan="3" class="border border-zinc-300 px-3 py-2 text-left text-xs font-bold uppercase dark:border-zinc-600">
                                            {{ $judul }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($seksi['groups'] as $group)
                                        @php [$angka, $pct] = $nilaiPersen($tanda * $group['total'], $dasar); @endphp
                                        <tr class="font-semibold text-zinc-700 dark:text-zinc-300">
                                            <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600">
                                                {{ $group['header']->kode }} - {{ $group['header']->nama }}
                                            </td>
                                            <td class="border border-zinc-300 px-3 py-1.5 dark:border-zinc-600"></td>
                                            <td class="whitespace-nowrap border border-zinc-300 px-3 py-1.5 text-right font-mono tabular-nums dark:border-zinc-600">
                                                {{ $angka }}
                                                <span class="ms-1 text-xs font-normal text-zinc-500">({{ $pct }})</span>
                                            </td>
                                        </tr>
                                        @if ($rinci)
                                            @foreach ($group['items'] as $item)
                                                @php [$angkaItem, $pctItem] = $nilaiPersen($tanda * $item['saldo'], $dasar); @endphp
                                                <tr class="text-xs text-zinc-600 dark:text-zinc-400">
                                                    <td class="border border-zinc-300 px-3 py-1 pl-8 dark:border-zinc-600">
                                                        {{ $item['coa']->kode }} - {{ $item['coa']->nama }}
                                                    </td>
                                                    <td class="whitespace-nowrap border border-zinc-300 px-3 py-1 text-right font-mono tabular-nums dark:border-zinc-600">
                                                        {{ $angkaItem }}
                                                        <span class="ms-1 text-zinc-400">({{ $pctItem }})</span>
                                                    </td>
                                                    <td class="border border-zinc-300 px-3 py-1 dark:border-zinc-600"></td>
                                                </tr>
                                            @endforeach
                                        @endif
                                    @endforeach
                                    @php [$angkaTotal, $pctTotal] = $nilaiPersen($tanda * $seksi['total'], $dasar); @endphp
                                    <tr class="{{ $latarTotal }} font-bold">
                                        <td colspan="2" class="border border-zinc-300 px-3 py-2 dark:border-zinc-600">
                                            TOTAL {{ strtoupper($judul) }}
                                        </td>
                                        <td class="whitespace-nowrap border border-zinc-300 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-600">
                                            {{ $angkaTotal }}
                                            <span class="ms-1 text-xs font-normal text-zinc-500">({{ $pctTotal }})</span>
                                        </td>
                                    </tr>
                                </tbody>
                            @endforeach

                            {{-- Garis laba kotor disisipkan tepat setelah seksi atas. --}}
                            @if ($i === 0)
                                @php [$angkaKotor, $pctKotor] = $nilaiPersen($u['gross_profit'], $dasar); @endphp
                                <tbody>
                                    <tr class="border-t-2 bg-zinc-100 font-bold dark:bg-zinc-800">
                                        <td colspan="2" class="border border-zinc-300 px-3 py-2.5 uppercase dark:border-zinc-600">
                                            Laba Kotor <span class="font-normal normal-case text-zinc-500">(Gross Profit)</span>
                                        </td>
                                        <td class="whitespace-nowrap border border-zinc-300 px-3 py-2.5 text-right font-mono tabular-nums dark:border-zinc-600 {{ $u['gross_profit'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                                            {{ $angkaKotor }}
                                            <span class="ms-1 text-xs font-normal">({{ $pctKotor }})</span>
                                        </td>
                                    </tr>
                                </tbody>
                            @endif
                        @endforeach

                        {{-- LABA BERSIH --}}
                        @php [$angkaBersih, $pctBersih] = $nilaiPersen($u['net_profit'], $dasar); @endphp
                        <tbody>
                            <tr class="border-t-4 border-double bg-zinc-100 dark:bg-zinc-800">
                                <td colspan="2" class="border border-zinc-300 px-3 py-3 text-lg font-bold uppercase dark:border-zinc-600">
                                    {{ $u['net_profit'] >= 0 ? 'Laba Bersih' : 'Rugi Bersih' }}
                                    <span class="text-sm font-normal normal-case text-zinc-500">(Net Profit)</span>
                                </td>
                                <td class="whitespace-nowrap border border-zinc-300 px-3 py-3 text-right font-mono text-lg font-bold tabular-nums dark:border-zinc-600 {{ $u['net_profit'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                                    {{ $angkaBersih }}
                                    <span class="ms-1 text-sm font-normal">({{ $pctBersih }})</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-xs text-zinc-500">
                    Persentase dihitung terhadap <strong>Penjualan</strong>. Pendapatan di luar usaha tidak
                    ikut jadi penyebut supaya marjinnya tetap sebanding antar periode.
                </p>
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
