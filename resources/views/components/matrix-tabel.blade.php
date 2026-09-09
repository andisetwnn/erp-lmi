@props([
    'unit',              // Collection<MatrixUnit>
    'nonLot' => false,   // Non Lot tidak punya subcon & blok teknik, tapi punya BBA
    'sortBy' => '',      // diteruskan ke x-matrix-th lewat @aware
    'sortDir' => 'asc',
])

@php
    $rupiah = fn ($v) => (float) $v == 0.0 ? '—' : number_format((float) $v, 0, ',', '.');
    $tgl = fn ($v) => $v?->format('d/m/y') ?? '—';
    $teks = fn ($v) => filled($v) ? $v : '—';

    // Warna tahap: hijau untuk yang sudah aman, kuning untuk yang menunggu,
    // merah untuk yang tersendat. Bentuk ikut menandai, bukan cuma angka.
    $warnaSeksi = [
        'SUDAH AKAD' => 'purple',
        'ACC SP3K' => 'green',
        'CASH' => 'green',
        'WAWANCARA' => 'blue',
        'BERKAS BELUM' => 'amber',
        'TOLAK BANK' => 'red',
        'BATAL' => 'red',
        'STOCK' => 'zinc',
    ];

    $th = 'whitespace-nowrap px-2 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-zinc-500';
    $thN = 'whitespace-nowrap px-2 py-2 text-right text-[10px] font-bold uppercase tracking-wider text-zinc-500';
    $td = 'whitespace-nowrap px-2 py-1.5';
    $tdN = 'whitespace-nowrap px-2 py-1.5 text-right font-mono tabular-nums';
@endphp

<div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    <th class="{{ $thN }}">{{ __('No') }}</th>
                    <x-matrix-th field="nama">{{ __('Konsumen') }}</x-matrix-th>
                    <x-matrix-th field="unit">{{ __('Unit') }}</x-matrix-th>
                    <x-matrix-th field="seksi">{{ __('Status') }}</x-matrix-th>
                    <x-matrix-th field="progres" kanan>{{ __('Prog%') }}</x-matrix-th>
                    <x-matrix-th field="sales">{{ __('Marketing') }}</x-matrix-th>
                    <x-matrix-th field="tipe">{{ __('Tipe') }}</x-matrix-th>
                    <x-matrix-th field="lot" kanan>{{ __('Lot') }}</x-matrix-th>
                    @unless ($nonLot)
                        <x-matrix-th field="subcon">{{ __('Subcon') }}</x-matrix-th>
                    @endunless
                    <x-matrix-th field="tanggal">{{ __('Booking') }}</x-matrix-th>
                    <th class="{{ $th }}">{{ __('Cara Bayar') }}</th>
                    <x-matrix-th field="total_harga_jual" kanan>{{ __('Harga Jual') }}</x-matrix-th>
                    @if ($nonLot)
                        <x-matrix-th field="bba" kanan>{{ __('BBA') }}</x-matrix-th>
                    @endif
                    <x-matrix-th field="total_um" kanan>{{ __('Total UM') }}</x-matrix-th>
                    <x-matrix-th field="akumulasi_um" kanan>{{ __('UM Masuk') }}</x-matrix-th>
                    <x-matrix-th field="persen_um" kanan>{{ __('% Byr') }}</x-matrix-th>
                    <x-matrix-th field="sisa_um" kanan>{{ __('Sisa UM') }}</x-matrix-th>
                    <x-matrix-th field="bm">BM</x-matrix-th>
                    <x-matrix-th field="wcr">WCR</x-matrix-th>
                    <x-matrix-th field="sp3k">SP3K</x-matrix-th>
                    <x-matrix-th field="exp_sp3k">{{ __('Exp SP3K') }}</x-matrix-th>
                    <x-matrix-th field="lpa">LPA</x-matrix-th>
                    <x-matrix-th field="rencana_akad">{{ __('Rencana Akad') }}</x-matrix-th>
                    <th class="{{ $th }}">{{ __('Bank') }}</th>
                    <th class="{{ $th }}">IMB</th>
                    <th class="{{ $th }}">SLF</th>
                    <th class="{{ $th }}">HGB</th>
                    @unless ($nonLot)
                        <th class="{{ $th }}">SA</th>
                        <th class="{{ $th }}">JA</th>
                        <th class="{{ $th }}">KW</th>
                        <th class="{{ $th }}">SB</th>
                    @endunless
                    <th class="{{ $th }}">{{ __('Keterangan') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($unit as $i => $u)
                    @php
                        $persen = $u->persen_um === null ? null : (float) $u->persen_um * 100;
                        $bank = collect([$u->bank_ko, $u->bank_fl, $u->bank_ta])->filter()->implode(' / ');
                        $caraBayar = (float) $u->kpr > 0 ? 'KPR' : ((float) $u->total_harga_jual > 0 ? 'CASH' : '—');
                    @endphp
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="{{ $tdN }} text-zinc-400">{{ $i + 1 }}</td>
                        <td class="{{ $td }} font-medium text-zinc-900 dark:text-white">{{ $u->nama }}</td>
                        <td class="{{ $td }} font-mono font-semibold">{{ $u->kode_unit ?: '—' }}</td>
                        <td class="{{ $td }}">
                            @if ($u->seksi)
                                <flux:badge size="sm" :color="$warnaSeksi[$u->seksi] ?? 'zinc'">{{ $u->seksi }}</flux:badge>
                            @else
                                —
                            @endif
                        </td>
                        <td class="{{ $tdN }} font-semibold">
                            {{ $u->progres === null ? '—' : rtrim(rtrim(number_format((float) $u->progres, 2, ',', '.'), '0'), ',') }}
                        </td>
                        <td class="{{ $td }}">{{ $teks($u->sales) }}</td>
                        <td class="{{ $td }}">{{ $teks($u->tipe) }}</td>
                        <td class="{{ $tdN }}">{{ $teks($u->lot) }}</td>
                        @unless ($nonLot)
                            <td class="{{ $td }}">{{ $teks($u->subcon) }}</td>
                        @endunless
                        <td class="{{ $td }} font-mono tabular-nums">{{ $tgl($u->tanggal) }}</td>
                        <td class="{{ $td }}">{{ $caraBayar }}</td>
                        <td class="{{ $tdN }}">{{ $rupiah($u->total_harga_jual) }}</td>
                        @if ($nonLot)
                            <td class="{{ $tdN }}">{{ $rupiah($u->bba) }}</td>
                        @endif
                        <td class="{{ $tdN }}">{{ $rupiah($u->total_um) }}</td>
                        <td class="{{ $tdN }} text-emerald-700 dark:text-emerald-400">{{ $rupiah($u->akumulasi_um) }}</td>
                        <td class="{{ $tdN }}">
                            @if ($persen === null)
                                —
                            @else
                                <span class="{{ $persen >= 100 ? 'font-bold text-emerald-700 dark:text-emerald-400' : '' }}">
                                    {{ number_format($persen, 0, ',', '.') }}%
                                </span>
                            @endif
                        </td>
                        <td class="{{ $tdN }} {{ (float) $u->sisa_um > 0 ? 'text-amber-700 dark:text-amber-400' : '' }}">
                            {{ $rupiah($u->sisa_um) }}
                        </td>
                        <td class="{{ $td }} font-mono tabular-nums">{{ $tgl($u->bm) }}</td>
                        <td class="{{ $td }} font-mono tabular-nums">{{ $tgl($u->wcr) }}</td>
                        <td class="{{ $td }} font-mono tabular-nums">{{ $tgl($u->sp3k) }}</td>
                        <td class="{{ $td }} font-mono tabular-nums">{{ $tgl($u->exp_sp3k) }}</td>
                        <td class="{{ $td }} font-mono tabular-nums">{{ $tgl($u->lpa) }}</td>
                        <td class="{{ $td }} font-mono tabular-nums">{{ $tgl($u->rencana_akad) }}</td>
                        <td class="{{ $td }} font-semibold">{{ $bank ?: '—' }}</td>
                        <td class="{{ $td }}">{{ $teks($u->legal_imb) }}</td>
                        <td class="{{ $td }}">{{ $teks($u->legal_slf) }}</td>
                        <td class="{{ $td }}">{{ $teks($u->legal_hgb) }}</td>
                        @unless ($nonLot)
                            <td class="{{ $td }}">{{ $teks($u->teknik_sa) }}</td>
                            <td class="{{ $td }}">{{ $teks($u->teknik_ja) }}</td>
                            <td class="{{ $td }}">{{ $teks($u->teknik_kw) }}</td>
                            <td class="{{ $td }}">{{ $teks($u->teknik_sb) }}</td>
                        @endunless
                        <td class="max-w-xs truncate px-2 py-1.5" title="{{ $u->keterangan }}">{{ $teks($u->keterangan) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="32" class="px-4 py-12 text-center text-sm italic text-zinc-400">
                            {{ __('Tidak ada unit yang cocok dengan filter.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
