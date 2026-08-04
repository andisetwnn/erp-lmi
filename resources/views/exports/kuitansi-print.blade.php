@php
    if (! function_exists('kuitansi_terbilang')) {
        function kuitansi_terbilang($n)
        {
            $n = (int) $n;
            if ($n < 0) {
                return 'minus '.kuitansi_terbilang(abs($n));
            }
            $angka = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
            if ($n < 12) {
                return $n === 0 ? 'nol' : $angka[$n];
            }
            if ($n < 20) {
                return kuitansi_terbilang($n - 10).' belas';
            }
            if ($n < 100) {
                return kuitansi_terbilang((int) ($n / 10)).' puluh'.(($n % 10) ? ' '.kuitansi_terbilang($n % 10) : '');
            }
            if ($n < 200) {
                return 'seratus'.(($n - 100) ? ' '.kuitansi_terbilang($n - 100) : '');
            }
            if ($n < 1000) {
                return kuitansi_terbilang((int) ($n / 100)).' ratus'.(($n % 100) ? ' '.kuitansi_terbilang($n % 100) : '');
            }
            if ($n < 2000) {
                return 'seribu'.(($n - 1000) ? ' '.kuitansi_terbilang($n - 1000) : '');
            }
            if ($n < 1_000_000) {
                return kuitansi_terbilang((int) ($n / 1000)).' ribu'.(($n % 1000) ? ' '.kuitansi_terbilang($n % 1000) : '');
            }
            if ($n < 1_000_000_000) {
                return kuitansi_terbilang((int) ($n / 1_000_000)).' juta'.(($n % 1_000_000) ? ' '.kuitansi_terbilang($n % 1_000_000) : '');
            }
            if ($n < 1_000_000_000_000) {
                return kuitansi_terbilang((int) ($n / 1_000_000_000)).' milyar'.(($n % 1_000_000_000) ? ' '.kuitansi_terbilang($n % 1_000_000_000) : '');
            }

            return kuitansi_terbilang((int) ($n / 1_000_000_000_000)).' triliun'.(($n % 1_000_000_000_000) ? ' '.kuitansi_terbilang($n % 1_000_000_000_000) : '');
        }
    }

    $prospect = $spr->prospectCustomer;
    $rumah = $spr->rumah;
    $proyek = $rumah?->proyek;
    $tipe = $rumah?->tipeRumah;
    $sales = $spr->sales;

    $gunaPembayaran = match ($realisasi->jenis) {
        'bf' => 'Uang Tanda Jadi (UTJ)',
        'um' => 'Uang Muka (UM)',
        'sbum' => 'SBUM (Subsidi Bantuan Uang Muka)',
        'kpr' => 'Pencairan KPR',
        default => strtoupper($realisasi->jenis),
    };

    $metode = $realisasi->metode === 'tunai' ? 'Tunai' : 'Transfer Bank';

    $nominal = (float) $realisasi->jumlah;
    $terbilangStr = ucfirst(trim(kuitansi_terbilang((int) $nominal))).' Rupiah';

    $blokUnit = trim(($rumah?->kode_unit ?? ''), '-');

    $namaPerusahaan = $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA';
    $alamatPerusahaan = $perusahaan?->alamat ?? '';
    $teleponPerusahaan = $perusahaan?->no_telepon ?? '';

    $kotaTtd = $proyek?->kota;
    $tanggalTtd = $realisasi->tanggal_bayar?->translatedFormat('d F Y');
    $placeDate = trim(($kotaTtd ? $kotaTtd.', ' : '').$tanggalTtd);

    $penerimaUser = $realisasi->inputBy;
    $penerimaNama = $penerimaUser?->name ?? $sales?->nama ?? '—';
    $penerimaTtdPath = $penerimaUser?->tanda_tangan_path ?? $sales?->tanda_tangan_path;
    $penerimaTtdUrl = ($penerimaTtdPath && file_exists(storage_path('app/public/'.$penerimaTtdPath)))
        ? asset('storage/'.$penerimaTtdPath)
        : null;

    $kodeSeed = $spr->id.'|KWT|'.($realisasi->id).'|'.$penerimaNama;
    $kodeKwitansi = 'KWT-'.str_pad((string) $spr->id, 5, '0', STR_PAD_LEFT).'-'.strtoupper(substr(hash('sha256', $kodeSeed), 0, 6));

    $logoUrl = ($proyek?->logo && file_exists(storage_path('app/public/'.$proyek->logo)))
        ? asset('storage/'.$proyek->logo)
        : null;
@endphp
<!DOCTYPE html>
<html lang="id" class="light" style="color-scheme: light;">
<head>
    <meta charset="UTF-8">
    <title>Kwitansi {{ $realisasi->nomor_kwitansi ?? $spr->nomor_display }}</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <style>
        @page { size: A5 landscape; margin: 6mm; }
        html, body { margin: 0; padding: 0; background: #e5e5e5; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; color: #111; }
        html.light { color-scheme: light !important; }
        .paper [class*="dark:bg-"] { background-color: revert !important; }
        .paper [class*="dark:text-"] { color: revert !important; }
        .paper [class*="dark:border-"] { border-color: revert !important; }
        .paper .bg-white { background-color: #ffffff !important; }
        .paper .bg-zinc-50 { background-color: #fafafa !important; }
        .paper { color: #111 !important; }

        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #ffffff;
            border-bottom: 1px solid #ddd;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        .print-toolbar .btn {
            border: 1px solid #d4d4d4;
            background: #ffffff;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            color: #333;
        }
        .print-toolbar .btn:hover { background: #f5f5f5; }
        .print-toolbar .btn-primary { background: #059669; color: #fff; border-color: #059669; }
        .print-toolbar .btn-primary:hover { background: #047857; }

        .paper {
            width: 210mm;
            margin: 12px auto;
            background: transparent;
        }

        /* ============ WATERMARK LOGO BERULANG ============ */
        .doc-wrapper {
            position: relative;
            overflow: hidden;
        }
        .doc-wrapper::before {
            content: '';
            position: absolute;
            inset: 0;
            @if ($logoUrl)
                background-image: url('{{ $logoUrl }}');
            @endif
            background-repeat: repeat;
            background-size: 100px auto;
            background-position: 0 0;
            opacity: 0.12;
            pointer-events: none;
            z-index: 0;
            /* Rotate untuk efek watermark diagonal */
            transform: rotate(-20deg) scale(1.5);
            transform-origin: center;
        }
        .doc-wrapper > * {
            position: relative;
            z-index: 1;
        }

        @media print {
            html, body { background: #ffffff; }
            .print-toolbar { display: none !important; }
            .paper {
                width: auto;
                margin: 0;
                zoom: 0.90;
            }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .paper .rounded-2xl { border-radius: 0 !important; box-shadow: none !important; }
            .paper > div { page-break-inside: avoid; }
            /* Watermark tetap muncul di print */
            .doc-wrapper::before {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                opacity: 0.10 !important;
            }

            /* PAKSA layout multi-kolom karena media query `md:` tidak apply di print viewport */
            .paper [class*="md:grid-cols-2"] {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .paper [class*="md:grid-cols-5"] {
                display: grid !important;
                grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            }
            .paper .md\:text-right { text-align: right !important; }
            .paper .md\:col-span-2 { grid-column: span 2 / span 2 !important; }
            .paper .md\:col-span-3 { grid-column: span 3 / span 3 !important; }

            /* Rapatkan spacing supaya muat 1 halaman A5 landscape */
            .paper .px-6 { padding-left: 14px !important; padding-right: 14px !important; }
            .paper .py-5 { padding-top: 8px !important; padding-bottom: 8px !important; }
            .paper .py-3 { padding-top: 4px !important; padding-bottom: 4px !important; }
            .paper .gap-y-5 { row-gap: 8px !important; }
            .paper .h-24 { height: 60px !important; } /* TTD sig area lebih pendek */
        }
    </style>
</head>
<body>

<div class="print-toolbar">
    <button type="button" class="btn" onclick="window.close()">Tutup</button>
    <button type="button" class="btn btn-primary" onclick="window.print()">Cetak</button>
</div>

<div class="paper">
    {{-- Logo proyek di pojok kanan atas --}}
    @if ($logoUrl || $proyek?->nama_proyek)
        <div style="display: flex; justify-content: flex-end; padding: 0 6px 8px; align-items: center;">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $proyek->nama_proyek }}"
                     style="max-height: 40px; max-width: 200px; object-fit: contain;">
            @else
                <div style="font-size: 14px; font-weight: 800; color: #111; letter-spacing: 1px;">
                    {{ strtoupper($proyek->nama_proyek) }}
                </div>
            @endif
        </div>
    @endif

    <div class="doc-wrapper overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">

        {{-- Document title --}}
        <div class="border-b border-zinc-200 px-6 py-5 text-center">
            <h2 class="text-base font-extrabold uppercase tracking-wide text-zinc-900 underline decoration-2 underline-offset-4">
                Kwitansi Pembayaran
            </h2>
        </div>

        {{-- Meta bar: No + Ref SPR --}}
        <div class="grid grid-cols-1 gap-3 border-b border-zinc-200 bg-zinc-50/50 px-6 py-3 text-xs md:grid-cols-2">
            <div>
                <span class="text-zinc-500">No. Kwitansi :</span>
                <span class="ms-2 font-mono font-bold text-zinc-900">{{ $realisasi->nomor_kwitansi ?? '—' }}</span>
            </div>
            <div class="md:text-right">
                <span class="text-zinc-500">Ref. SPR :</span>
                <span class="ms-2 font-mono font-bold text-zinc-900">{{ $spr->nomor_display }}</span>
            </div>
        </div>

        {{-- 2-column: Body kiri + Signature kanan --}}
        <div class="grid grid-cols-1 gap-x-8 gap-y-5 px-6 py-5 md:grid-cols-5">

            {{-- LEFT: Body kuitansi --}}
            <div class="md:col-span-3">
                <div class="rounded-lg border border-zinc-200">
                    <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900">
                        Detail Pembayaran
                    </h3>
                    <table class="w-full text-xs">
                        <tbody class="divide-y divide-zinc-100">
                            <tr>
                                <td class="w-40 px-3 py-2 align-top text-zinc-600">Telah diterima dari</td>
                                <td class="px-3 py-2 align-top text-zinc-400">:</td>
                                <td class="px-3 py-2 font-semibold text-zinc-900">{{ strtoupper($prospect?->nama_lengkap ?? '—') }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 align-top text-zinc-600">Uang sejumlah</td>
                                <td class="px-3 py-2 align-top text-zinc-400">:</td>
                                <td class="px-3 py-2 italic text-zinc-800">{{ $terbilangStr }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 align-top text-zinc-600">Untuk pembayaran</td>
                                <td class="px-3 py-2 align-top text-zinc-400">:</td>
                                <td class="px-3 py-2 font-semibold text-zinc-900">{{ $gunaPembayaran }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 align-top text-zinc-600">Unit / Proyek</td>
                                <td class="px-3 py-2 align-top text-zinc-400">:</td>
                                <td class="px-3 py-2 text-zinc-800">
                                    @if ($tipe?->tipe)Type <b>{{ $tipe->tipe }}</b>@endif
                                    @if ($blokUnit) · Blok <b>{{ $blokUnit }}</b>@endif
                                    @if ($proyek?->nama_proyek) · <b>{{ $proyek->nama_proyek }}</b>@endif
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 align-top text-zinc-600">Metode</td>
                                <td class="px-3 py-2 align-top text-zinc-400">:</td>
                                <td class="px-3 py-2 font-semibold text-zinc-900">{{ $metode }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- Amount box (mirroring UTJ row style di SPR) --}}
                <div class="mt-3 rounded-lg border border-zinc-200">
                    <div class="flex items-center justify-between bg-zinc-50 px-3 py-2">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-600">
                            Nominal Diterima
                        </span>
                        <span class="font-mono text-lg font-extrabold tabular-nums text-zinc-900">
                            Rp {{ number_format($nominal, 0, ',', '.') }}
                        </span>
                    </div>
                </div>

                <div class="mt-4 text-[11px] italic text-zinc-600">
                    Kwitansi ini merupakan bukti sah pembayaran. Simpan baik-baik untuk keperluan verifikasi.
                </div>
            </div>

            {{-- RIGHT: TTD Penerima --}}
            <div class="md:col-span-2">
                @if ($placeDate)
                    <div class="mb-2 text-center text-[11px] italic text-zinc-600">
                        {{ $placeDate }}
                    </div>
                @endif
                <div class="overflow-hidden rounded-lg border border-zinc-300 bg-white">
                    <div class="border-b border-zinc-300 px-3 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider text-zinc-700">
                        Penerima
                    </div>
                    <div class="flex h-24 items-center justify-center bg-white p-2">
                        @if ($penerimaTtdUrl)
                            <img src="{{ $penerimaTtdUrl }}" alt="ttd-penerima"
                                 class="max-h-full max-w-full object-contain">
                        @else
                            <div class="flex flex-col items-center gap-1 text-center">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 text-zinc-300">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                </svg>
                                <span class="text-[9px] italic text-zinc-400">belum ditandatangani</span>
                            </div>
                        @endif
                    </div>
                    <div class="border-t border-zinc-300 bg-white px-3 py-1.5 text-center">
                        <div class="text-[11px] font-bold text-zinc-900">
                            ( {{ $penerimaNama }} )
                        </div>
                        <div class="mt-0.5 font-mono text-[8px] tracking-wider text-zinc-500">
                            {{ $kodeKwitansi }}
                        </div>
                    </div>
                </div>

                <div class="mt-3 text-center text-[9px] italic text-zinc-500">
                    a/n {{ strtoupper($namaPerusahaan) }}
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto mt-2 text-right text-[9px] italic text-zinc-500">
        Dicetak pada {{ now()->translatedFormat('d/m/Y H:i') }}
    </div>
</div>

</body>
</html>
