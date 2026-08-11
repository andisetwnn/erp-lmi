{{--
    Partial dokumen SPR — dipakai di:
    - pages/marketing/spr-show (tab preview di layar)
    - exports/spr-print (halaman cetak standalone)

    Butuh variable: $spr (Spr model dengan eager-load prospectCustomer.tempatKerja,
    rumah.tipeRumah, rumah.proyek, rumah.virtualAccount.bank, sales, terminPembayaran,
    utjConfirmedBy, pmApprovedBy).
--}}
@php
    $prospect = $spr->prospectCustomer;
    $rumah = $spr->rumah;
    $tipe = $rumah?->tipeRumah;
    $proyek = $rumah?->proyek;
    $termins = $spr->terminPembayaran->sortBy([['jenis', 'asc'], ['urutan', 'asc']])->values();

    $alamatProspect = collect([
        $prospect?->alamat,
        $prospect?->kelurahan_nama ? 'Kelurahan '.$prospect->kelurahan_nama : null,
        $prospect?->kecamatan_nama ? 'Kec. '.$prospect->kecamatan_nama : null,
        $prospect?->kota_nama ? 'Dati II. '.$prospect->kota_nama : null,
        $prospect?->provinsi_nama ? 'Provinsi. '.$prospect->provinsi_nama : null,
    ])->filter()->implode(', ');

    $alamatKantor = $prospect?->tempatKerja?->alamat;
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $kategoriLabel = $spr->kategori === 'subsidi' ? 'Subsidi' : 'Komersil';
    $vaListSyarat = $rumah?->virtualAccount ?? collect();

    // Kode validasi TTD — hash unik dari SPR + role + timestamp.
    // Format: [ROLE]-[SPR_ID]-[HASH6] (mis. SLS-00154-A3F2B1)
    $makeCode = function (string $rolePrefix, $stampAt, ?string $signerName) use ($spr) {
        if (! $stampAt || ! $signerName) return null;
        $seed = $spr->id.'|'.$rolePrefix.'|'.$stampAt->timestamp.'|'.$signerName;
        $hash = strtoupper(substr(hash('sha256', $seed), 0, 6));
        return $rolePrefix.'-'.str_pad((string) $spr->id, 5, '0', STR_PAD_LEFT).'-'.$hash;
    };

    // Path TTD dengan fallback:
    //  1. Kalau snapshot di SPR ada (path terset saat submit/konfirmasi/approve) → pakai snapshot (immutable, historis stabil).
    //  2. Kalau snapshot null (mis. sales belum register TTD waktu submit SPR) → fallback ke TTD live dari sales/user.
    $ttdSalesPath = $spr->ttd_sales_path ?: $spr->sales?->tanda_tangan_path;
    $ttdFinancePath = $spr->ttd_finance_path ?: $spr->utjConfirmedBy?->tanda_tangan_path;
    $ttdPmPath = $spr->ttd_pm_path ?: $spr->pmApprovedBy?->tanda_tangan_path;
    // Konsumen TTD ditampilkan di section PEMESAN (bukan di grid otorisasi 3 kolom).
    $ttdKonsumenPath = $spr->konsumen_ttd_path;

    $ttdBoxes = [
        ['role' => 'Sales',           'code' => $makeCode('SLS', $spr->created_at,      $spr->sales?->nama),          'name' => $spr->sales?->nama,          'path' => $ttdSalesPath,   'color' => 'orange',  'stampAt' => $spr->created_at],
        ['role' => 'Keuangan',        'code' => $makeCode('FIN', $spr->utj_confirmed_at, $spr->utjConfirmedBy?->name), 'name' => $spr->utjConfirmedBy?->name, 'path' => $ttdFinancePath, 'color' => 'emerald', 'stampAt' => $spr->utj_confirmed_at],
        ['role' => 'Project Manager', 'code' => $makeCode('PMG', $spr->pm_approved_at,   $spr->pmApprovedBy?->name),   'name' => $spr->pmApprovedBy?->name,   'path' => $ttdPmPath,      'color' => 'violet',  'stampAt' => $spr->pm_approved_at],
    ];
    // TTD boxes: plain black-and-white style (tanpa warna) — semua sama.
    $ttdColor = [
        'orange'  => 'bg-white border-zinc-300 text-zinc-800 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-100',
        'emerald' => 'bg-white border-zinc-300 text-zinc-800 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-100',
        'violet'  => 'bg-white border-zinc-300 text-zinc-800 dark:bg-zinc-900 dark:border-zinc-700 dark:text-zinc-100',
    ];
@endphp

<div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">

    {{-- Document title --}}
    <div class="border-b border-zinc-200 px-12 py-5 text-center dark:border-zinc-700">
        <h2 class="text-base font-extrabold uppercase tracking-wide text-zinc-900 dark:text-white">
            {{ __('Surat Pemesanan dan Konfirmasi Pembelian Rumah') }}
        </h2>
    </div>

    {{-- Date + No SPR --}}
    <div class="grid grid-cols-1 gap-3 border-b border-zinc-200 bg-zinc-50/50 px-6 py-3 text-xs dark:border-zinc-700 dark:bg-zinc-800/30 md:grid-cols-2">
        <div>
            <span class="text-zinc-500">{{ __('Tanggal') }} :</span>
            <span class="ms-2 font-bold text-zinc-900 dark:text-white">{{ $spr->tanggal_spr?->format('d/m/Y') }}</span>
        </div>
        <div class="md:text-right">
            <span class="text-zinc-500">{{ __('Nomor SPR') }} :</span>
            <span class="ms-2 font-mono font-bold text-zinc-900 dark:text-white">{{ $spr->nomor_display }}</span>
        </div>
    </div>

    {{-- 2-column: Customer info (left) + Syarat (right) --}}
    <div class="grid grid-cols-1 gap-x-8 gap-y-5 px-6 py-5 md:grid-cols-2">

        {{-- LEFT: Identitas pemesan --}}
        <div>
            <h3 class="mb-3 text-sm font-bold text-zinc-900 dark:text-white">{{ __('Yang bertanda tangan dibawah ini') }}</h3>
            <table class="w-full border-b border-zinc-200 text-xs dark:border-zinc-700">
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr>
                        <td class="w-28 py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('Nama') }}</td>
                        <td class="w-3 py-2 align-top text-zinc-400">:</td>
                        <td class="py-2 font-semibold text-zinc-900 dark:text-white">{{ $prospect?->nama_lengkap }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('No KTP') }}</td>
                        <td class="py-2 align-top text-zinc-400">:</td>
                        <td class="py-2 font-mono font-semibold text-zinc-900 dark:text-white">{{ $prospect?->nik ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('Alamat') }}</td>
                        <td class="py-2 align-top text-zinc-400">:</td>
                        <td class="py-2 text-zinc-900 dark:text-white">{{ $alamatProspect ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('Nama Kantor') }}</td>
                        <td class="py-2 align-top text-zinc-400">:</td>
                        <td class="py-2 font-semibold text-zinc-900 dark:text-white">{{ $prospect?->tempatKerja?->nama ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('Alamat Kantor') }}</td>
                        <td class="py-2 align-top text-zinc-400">:</td>
                        <td class="py-2 text-zinc-900 dark:text-white">{{ $alamatKantor ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('No Telp') }}</td>
                        <td class="py-2 align-top text-zinc-400">:</td>
                        <td class="py-2 text-zinc-900 dark:text-white">
                            <div class="grid grid-cols-[80px_1fr] gap-y-1">
                                <span class="text-zinc-500">{{ __('HP') }}</span>
                                <span class="font-mono font-semibold">{{ $prospect?->hp ?? '—' }}</span>
                                <span class="text-zinc-500">{{ __('Kantor') }}</span>
                                <span class="font-mono">{{ $prospect?->hp_2 ?? '—' }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-3 text-[11px] italic text-zinc-600 dark:text-zinc-400">
                {{ __('Dengan ini memesan untuk membeli sebidang tanah diatasnya di perumahan') }}
                <strong class="not-italic text-zinc-900 dark:text-white">{{ $proyek?->nama_proyek ?? '—' }}</strong>
                {{ __('dengan syarat dan ketentuan:') }}
            </div>

            {{-- I. Unit & Harga --}}
            <div class="mt-4">
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                        {{ __('I. Unit & Harga') }}
                    </h3>
                    <div class="grid grid-cols-3 gap-2 border-b border-zinc-200 px-3 py-2 text-xs dark:border-zinc-700">
                        <div><span class="text-zinc-500">Blok/No :</span> <span class="ms-1 font-mono font-bold">{{ $rumah?->kode_unit }}</span></div>
                        <div><span class="text-zinc-500">Type :</span> <span class="ms-1 font-bold">{{ $tipe?->nama_tipe ?? '—' }}</span></div>
                        <div><span class="text-zinc-500">LT/LB :</span> <span class="ms-1 font-mono">{{ (int) ($rumah?->luas_tanah ?? 0) }}/{{ (int) ($rumah?->luas_bangunan ?? 0) }}</span></div>
                    </div>

                    @php
                        $isSubsidi = $spr->kategori === 'subsidi';
                        // Cell renderer: value untuk kategori aktif, dotted line untuk lawannya
                        $priceCell = function ($val, bool $activeCol, string $extraCls = '') use ($fmt) {
                            if ($activeCol) {
                                return '<span class="font-mono tabular-nums font-semibold '.$extraCls.'">: Rp '.$fmt($val).'</span>';
                            }
                            return '<div class="flex items-baseline gap-1"><span class="text-zinc-400">: Rp.</span><span class="flex-1 border-b-2 border-dotted border-zinc-500"></span></div>';
                        };
                        // Gray-out cell (untuk PPN Subsidi & SBUM Komersil)
                        $grayCell = '<span class="inline-block w-full bg-zinc-200 dark:bg-zinc-700" style="height: 12px;">&nbsp;</span>';
                        // Empty cell dengan garis titik-titik (untuk cell yang kosong tapi bukan gray-out)
                        $emptyDots = '<div class="flex items-baseline gap-1"><span class="text-zinc-400">: Rp.</span><span class="flex-1 border-b-2 border-dotted border-zinc-500"></span></div>';
                    @endphp
                    <table class="w-full text-[11px]">
                        <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-1.5 text-left font-semibold"></th>
                                <th class="w-2/5 px-3 py-1.5 text-center font-semibold">Subsidi</th>
                                <th class="w-2/5 px-3 py-1.5 text-center font-semibold">Komersil</th>
                                <th class="w-10 px-2 py-1.5 text-center font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr>
                                <td class="px-3 py-1.5 text-zinc-700">Harga JUAL/AJB</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->harga_jual, $isSubsidi) !!}</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->harga_jual, ! $isSubsidi) !!}</td>
                                <td class="px-2 py-1.5 text-center"></td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 text-zinc-700">PPN</td>
                                <td class="px-3 py-1.5">
                                    @if ($isSubsidi)
                                        {!! $grayCell !!}
                                    @else
                                        {!! $emptyDots !!}
                                    @endif
                                </td>
                                <td class="px-3 py-1.5">
                                    @if (! $isSubsidi && (float) $spr->ppn > 0)
                                        {!! $priceCell($spr->ppn, true) !!}
                                    @else
                                        {!! $emptyDots !!}
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 text-center text-zinc-500"></td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 text-zinc-700">Biaya Administrasi</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->biaya_tambahan, $isSubsidi) !!}</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->biaya_tambahan, ! $isSubsidi) !!}</td>
                                <td class="px-2 py-1.5 text-center font-semibold text-zinc-500">+/+</td>
                            </tr>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                <td class="px-3 py-1.5 font-bold text-zinc-800 dark:text-zinc-100">Harga Jual All In</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->total_harga, $isSubsidi, 'font-bold') !!}</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->total_harga, ! $isSubsidi, 'font-bold') !!}</td>
                                <td class="px-2 py-1.5 text-center font-semibold text-zinc-500">+</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 text-zinc-700">KPR</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->nilai_kpr, $isSubsidi) !!}</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->nilai_kpr, ! $isSubsidi) !!}</td>
                                <td class="px-2 py-1.5 text-center font-semibold text-zinc-500">−/−</td>
                            </tr>
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                <td class="px-3 py-1.5 font-bold text-zinc-800 dark:text-zinc-100">Total UM</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->dp_nominal, $isSubsidi, 'font-bold') !!}</td>
                                <td class="px-3 py-1.5">{!! $priceCell($spr->dp_nominal, ! $isSubsidi, 'font-bold') !!}</td>
                                <td class="px-2 py-1.5 text-center"></td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 text-zinc-700">SBUM</td>
                                <td class="px-3 py-1.5">
                                    @if ($isSubsidi && (float) $spr->sbum > 0)
                                        {!! $priceCell($spr->sbum, true) !!}
                                    @else
                                        {!! $emptyDots !!}
                                    @endif
                                </td>
                                <td class="px-3 py-1.5">
                                    @if ($isSubsidi)
                                        {!! $grayCell !!}
                                    @else
                                        {!! $emptyDots !!}
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 text-center"></td>
                            </tr>
                            <tr>
                                <td class="px-3 py-1.5 font-semibold text-zinc-800 dark:text-zinc-100">UM Sendiri</td>
                                <td class="px-3 py-1.5">{!! $emptyDots !!}</td>
                                <td class="px-3 py-1.5">{!! $emptyDots !!}</td>
                                <td class="px-2 py-1.5 text-center"></td>
                            </tr>
                        </tbody>
                    </table>

                    {{-- Diskon UM & UTJ (di luar tabel, sesuai form fisik) --}}
                    <div class="px-3 py-2 text-[11px]">
                        <div class="flex items-baseline gap-2">
                            <span class="w-40 shrink-0 font-semibold text-zinc-700 dark:text-zinc-200">Diskon UM</span>
                            <span class="font-mono tabular-nums font-bold text-zinc-900 dark:text-white">: (Rp {{ $fmt($spr->um_net) }})</span>
                        </div>
                    </div>
                    <div class="border-t border-zinc-300 px-3 py-2 text-[11px] dark:border-zinc-600">
                        <div class="flex items-baseline gap-2">
                            <span class="w-40 shrink-0 font-bold text-zinc-900 dark:text-white">UTJ</span>
                            <span class="font-mono tabular-nums font-bold text-zinc-900 dark:text-white">: Rp {{ $fmt($spr->utj_nominal) }}</span>
                            <span class="ml-auto text-[10px] text-zinc-700 dark:text-zinc-300">Tgl : <span class="font-bold">{{ $spr->utj_tanggal_transaksi?->format('d-M-y') ?? '—' }}</span></span>
                        </div>
                    </div>
                </div>

                {{-- II. Jadwal/Termin Pembayaran --}}
                <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                        {{ __('II. Jadwal / Termin Pembayaran') }}
                    </h3>
                    <table class="w-full text-[10px]" style="table-layout: fixed;">
                        <colgroup>
                            <col style="width: 32px;">
                            <col style="width: 25%;">
                            <col style="width: 20%;">
                            <col style="width: 25%;">
                            <col>
                        </colgroup>
                        <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            <tr>
                                <th class="border-r border-zinc-200 px-1 py-1 text-center font-semibold dark:border-zinc-700"></th>
                                <th class="border-r border-zinc-200 px-1 py-1 text-center font-semibold dark:border-zinc-700">Uang Muka</th>
                                <th class="border-r border-zinc-200 px-1 py-1 text-center font-semibold dark:border-zinc-700">Biaya Adm</th>
                                <th class="border-r border-zinc-200 px-1 py-1 text-center font-semibold dark:border-zinc-700">Total</th>
                                <th class="px-1 py-1 text-center font-semibold">Tgl</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @php $umTermins = $termins->where('jenis', 'um')->sortBy('urutan')->values(); @endphp
                            @for ($i = 1; $i <= 10; $i++)
                                @php
                                    $t = $umTermins->firstWhere('urutan', $i);
                                    $um = $t ? (float) $t->jumlah_jadwal : 0;
                                    $bAdmin = 0;
                                    $total = $um + $bAdmin;
                                @endphp
                                <tr>
                                    <td class="border-r border-zinc-200 px-1 py-1 text-center text-[9px] font-bold dark:border-zinc-700">ke {{ $i }}</td>
                                    <td class="border-r border-zinc-200 px-1 py-1 dark:border-zinc-700">
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-zinc-400">Rp.</span>
                                            @if ($t)
                                                <span class="font-mono tabular-nums font-semibold">{{ $fmt($um) }}</span>
                                            @else
                                                <span class="inline-block flex-1 border-b-2 border-dotted border-zinc-500"></span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="border-r border-zinc-200 px-1 py-1 dark:border-zinc-700">
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-zinc-400">Rp.</span>
                                            <span class="inline-block flex-1 border-b-2 border-dotted border-zinc-500"></span>
                                        </div>
                                    </td>
                                    <td class="border-r border-zinc-200 px-1 py-1 dark:border-zinc-700">
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-zinc-400">Rp.</span>
                                            @if ($t)
                                                <span class="font-mono tabular-nums font-semibold">{{ $fmt($total) }}</span>
                                            @else
                                                <span class="inline-block flex-1 border-b-2 border-dotted border-zinc-500"></span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-1 py-1 font-mono text-[9px]">
                                        @if ($t?->tanggal_jadwal)
                                            <span class="font-semibold">{{ $t->tanggal_jadwal->format('d-M-y') }}</span>
                                        @else
                                            <span class="inline-block w-full border-b-2 border-dotted border-zinc-500 align-middle" style="height: 8px;"></span>
                                        @endif
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>

                {{-- III. --}}
                <div class="mt-4 rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                        {{ __('III. Harga Jual All In Sudah Termasuk Biaya Administrasi') }} :
                    </h3>
                    <div class="space-y-1.5 px-3 py-2 text-[11px]">
                        <div class="flex gap-2">
                            <span class="w-16 shrink-0 text-zinc-500">- Notaris</span>
                            <span class="text-zinc-700 dark:text-zinc-300">: Biaya AJB, Validasi BPHTB, BN</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="w-16 shrink-0 text-zinc-500">- Umum</span>
                            <span class="text-zinc-700 dark:text-zinc-300">: Biaya Splitzing Sertifikat, Biaya Utilities, Biaya KPR</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="w-16 shrink-0 text-zinc-500">- Pajak</span>
                            <span class="text-zinc-700 dark:text-zinc-300">: BPHTB, PBB, IMB, PPN dan Biaya-biaya resmi sesuai ketentuan pemerintah</span>
                        </div>
                        <div class="mt-1.5 text-[11px] italic text-zinc-600 dark:text-zinc-400">
                            <span class="font-semibold not-italic">Catatan:</span> Biaya administrasi adalah dana titipan dari konsumen untuk pembayaran/pengurusan legalitas kepada pihak ketiga, bukan bagian dari nilai penjualan rumah.
                        </div>
                    </div>
                </div>

                {{-- IV. --}}
                <div class="mt-3 rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                        {{ __('IV. Harga Belum Termasuk Biaya') }} :
                    </h3>
                    <div class="space-y-1.5 px-3 py-2 text-[11px]">
                        <div class="flex items-baseline gap-2">
                            <span class="w-56 shrink-0 text-zinc-500">- Materai</span>
                            <span class="whitespace-nowrap font-mono text-zinc-700 dark:text-zinc-300">: Rp.</span>
                            <span class="flex-1 border-b-2 border-dotted border-zinc-500 dark:border-zinc-400"></span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span class="w-56 shrink-0 text-zinc-500">- Saldo mengendap ( persyaratan Bank )</span>
                            <span class="whitespace-nowrap font-mono text-zinc-700 dark:text-zinc-300">: Rp.</span>
                            <span class="flex-1 border-b-2 border-dotted border-zinc-500 dark:border-zinc-400"></span>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span class="w-56 shrink-0 text-zinc-500">- Harga Kelebihan Tanah (jika ada)</span>
                            <span class="whitespace-nowrap font-mono text-zinc-700 dark:text-zinc-300">: Rp.</span>
                            <span class="flex-1 border-b-2 border-dotted border-zinc-500 dark:border-zinc-400"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: Syarat & Kondisi --}}
        <div>
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                    {{ __('V. Syarat dan Kondisi') }}
                </h3>
                <div class="px-4 py-3">
                    <ol class="space-y-2 text-[11px] leading-relaxed text-zinc-700 dark:text-zinc-300" style="list-style-type: decimal; padding-left: 18px;">
                        <li>{{ __('Pihak pembeli wajib membayar uang tanda jadi (UTJ) dan uang muka sesuai ketentuan pada poin I SPKP (disepakati) selambat-lambatnya dalam waktu 15 (lima belas) hari kalender sejak SPKP ini di tandatangani pembeli. Apabila pembayaran uang muka ke-1 pada SPKP tidak dilakukan dalam waktu 15 (lima belas) hari kalender, maka pembeli dianggap membatalkan secara sepihak dan uang tanda jadi yang telah di bayarkan tidak dapat dikembalikan (hangus).') }}</li>
                        <li>{{ __('Pembeli wajib menyiapkan dan menyerahkan kelengkapan berkas pembelian dalam waktu 15 (lima belas) hari kalender sejak pembayaran uang tanda jadi, apabila dalam kurun waktu tersebut pembeli belum menyerahkan berkas kepada Developer maka pembeli dianggap membatalkan pembelian secara sepihak.') }}</li>
                        <li>{{ __('Apabila pihak pembeli dengan alasan apapun juga telah lalai atau tidak dapat melunasi/memenuhi pembayaran uang muka sesuai waktu/tanggal yang telah di tetapkan dan disepakati sesuai JADWAL TERMIN PEMBAYARAN (Poin II SPKP) serta tidak menyerahkan data-data/berkas KPR sesuai jadwal waktu pada ayat (2) diatas sehingga mengakibatkan pembatalan pembelian unit rumah, maka uang tanda jadi yang telah dibayarkan tidak dapat dikembalikan (hangus) dan seluruh uang muka yang telah dibayarkan dapat dikembalikan setelah dipotong biaya administrasi sebesar Rp. 2.000.000,- (dua juta rupiah).') }}</li>
                        <li>{{ __('Apabila terjadi keterlambatan pembayaran uang muka dari jadwal yang telah ditentukan, akan dikenakan denda/bunga sebesar 3% (tiga persen) per bulan (1% per hari), dan jika keterlambatan tersebut berlangsung selama 2 (dua) bulan berturut-turut sejak pembayaran uang muka ke-2, maka Pihak Pembeli dianggap membatalkan secara sepihak (sesuai ayat 3).') }}</li>
                        <li>{{ __('Pindah kaviling atau ganti nama setelah uang tanda jadi masuk dikenakan biaya tambahan sebesar Rp. 1.000.000,-/unit.') }}</li>
                        <li>{!! __('Apabila terjadi pembatalan KPR oleh Bank (unit <strong>Subsidi dan Komersil</strong>), maka uang muka dikembalikan 100% dan untuk uang tanda jadi yang telah dibayarkan tidak dapat dikembalikan (hangus) sesuai ayat 1.') !!}</li>
                        <li>{{ __('Dalam hal terdapat perbedaan luas tanah, antara luas tanah yang tertulis di SPKP dengan luas hasil pengukuran instansi berwenang (BPN) atau luas yang tercantum dalam buku sertifikat sampai batas toleransi 3% (tiga persen), maka kedua belah pihak setuju dan sepakat tidak akan melakukan penuntutan apapun juga serta menerima luas berdasarkan buku Sertifikat tanah yang terbit.') }}</li>
                        <li>
                            {{ __('Pembayaran :') }}
                            <div class="mt-1 space-y-0.5 pl-3">
                                <div>
                                    a. {!! __('Semua Pembayaran harus dilakukan di kasir atau transfer ke <strong>Bank BTN Cabang Ciputat 000.4401.3000.09130</strong> A/n <strong>PT. LANGIT MEMBANGUN INDONESIA</strong>.') !!}
                                </div>
                                <div>b. {{ __('Bukti Transfer/bukti setor Bank harap diberikan kepada kasir Developer.') }}</div>
                                <div>c. {{ __('Kwitansi resmi dapat diperoleh di kasir Perusahaan dengan membawa bukti transfer Bank/bukti setor Bank untuk ditukarkan.') }}</div>
                                <div>d. {{ __('Pembayaran dianggap sah apabila sudah dilakukan validasi oleh kasir Developer.') }}</div>
                            </div>
                        </li>
                        <li>{{ __('Developer tidak bertanggung jawab atas semua pembayaran yang di lakukan pembeli diluar ketentuan pada poin 8 diatas.') }}</li>
                    </ol>

                    <div class="mt-4 text-[11px] italic text-zinc-600 dark:text-zinc-400">
                        {{ __('Demikian Surat Pesanan dan Konfirmasi Pembayaran ini dibuat, dan telah disetujui oleh pemesan untuk dapat digunakan sebagaimana mestinya.') }}
                    </div>

                    <div class="mt-4 flex justify-end">
                        <div class="flex w-64 flex-col items-center gap-1.5">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-200">
                                {{ __('Pemesan') }}
                            </div>
                            {{-- Row: Materai (kiri) + Kolom TTD Konsumen (kanan) --}}
                            <div class="flex w-full items-start gap-2">
                                {{-- Materai — kiri --}}
                                @if ($spr->materai_stamped_at)
                                    <div class="flex h-16 w-14 shrink-0 items-center justify-center rounded border border-emerald-500 bg-emerald-50 text-center text-[7px] font-bold leading-tight text-emerald-700">
                                        e-Materai<br>Tertempel
                                    </div>
                                @else
                                    <div class="flex h-16 w-14 shrink-0 items-center justify-center rounded border-2 border-dashed border-zinc-300 text-center text-[8px] italic leading-tight text-zinc-400 dark:border-zinc-600">
                                        Materai<br>Tempel
                                    </div>
                                @endif
                                {{-- Kolom TTD Konsumen: signature + garis + nama --}}
                                <div class="flex flex-1 flex-col items-center">
                                    <div class="flex h-16 w-full items-center justify-center overflow-hidden">
                                        @if ($spr->konsumen_ttd_path && is_file(storage_path('app/public/'.$spr->konsumen_ttd_path)))
                                            <img src="{{ asset('storage/'.$spr->konsumen_ttd_path) }}"
                                                 alt="TTD Konsumen"
                                                 class="h-full w-full object-contain">
                                        @endif
                                    </div>
                                    <div class="w-full border-t border-zinc-400 pt-1 text-center text-xs font-bold text-zinc-900 dark:border-zinc-500 dark:text-white">
                                        ( {{ $prospect?->nama_lengkap ?? '—' }} )
                                    </div>
                                    @if ($spr->konsumen_signed_at)
                                        <div class="mt-0.5 text-[8px] italic text-zinc-500">
                                            Ditandatangani {{ \Carbon\Carbon::parse($spr->konsumen_signed_at)->translatedFormat('d M Y H:i') }} WIB
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- TANDA TANGAN OTORISASI — 1 box besar dengan 3 kolom (dipisah garis vertical) --}}
    <div class="mx-6 mb-6 mt-4 grid grid-cols-1 divide-y divide-zinc-300 border border-zinc-300 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        @foreach ($ttdBoxes as $ttd)
            <div class="flex flex-col text-center">
                {{-- Label role di atas (garis bawah setelah label) --}}
                <div class="border-b border-zinc-300 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-zinc-800">
                    {{ __($ttd['role']) }}
                </div>
                {{-- Area TTD (kosong, gambar TTD ditumpuk di atas nama) --}}
                <div class="flex h-20 items-end justify-center px-2 pb-1">
                    @if ($ttd['path'])
                        <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($ttd['path']) }}"
                             alt="ttd-{{ $ttd['role'] }}"
                             class="max-h-full max-w-full object-contain">
                    @else
                        <span class="text-[9px] italic text-zinc-400">{{ __('belum ditandatangani') }}</span>
                    @endif
                </div>
                {{-- Nama + kode (tanpa border, langsung di bawah TTD) --}}
                <div class="px-3 pb-2 pt-1">
                    <div class="text-[11px] font-bold text-zinc-900">
                        ( {{ $ttd['name'] ?? '—' }} )
                    </div>
                    @if ($ttd['code'])
                        <div class="mt-0.5 font-mono text-[8px] tracking-wider text-zinc-500">
                            {{ $ttd['code'] }}
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
