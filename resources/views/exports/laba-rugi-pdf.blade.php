<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Laba Rugi</title>
    <style>
        @page { margin: 10mm 10mm 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        .kop { margin-bottom: 8px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; padding: 0; }
        .kop td.logo-col { width: 55px; padding-right: 8px; }
        .kop td.logo-col img { width: 48px; height: auto; }
        .kop .company { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 11px; font-weight: bold; margin-top: 2px; }
        .periode-info {
            font-size: 9px; padding: 3px 8px;
            background: #fff2cc; border: 1px solid #d4b400; font-weight: bold;
        }

        table.laporan { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.laporan th, table.laporan td {
            border: 1px solid #999; padding: 3px 6px; font-size: 8.5px;
            overflow: hidden; word-wrap: break-word;
        }
        .col-nama { width: 60%; }
        .col-item { width: 20%; text-align: right; font-family: monospace; }
        .col-total { width: 20%; text-align: right; font-family: monospace; }
        .pct { color: #666; font-size: 7.5px; }

        thead th { text-align: left; font-weight: bold; padding: 4px 6px; font-size: 9.5px; }
        .section-pendapatan th { background: #d5f5e3; color: #145a32; }
        .section-beban th { background: #fadbd8; color: #7d1919; }

        tr.group-header td { background: #eef3f7; font-weight: bold; }
        tr.item td { padding-left: 18px; color: #444; font-size: 8.5px; }
        tr.item td.col-item { text-align: right; font-family: monospace; padding-left: 4px; color: #444; }
        tr.subtotal td { background: #e8e8e8; font-weight: bold; font-size: 9px; }
        tr.grand-total td {
            background: #b8d8ff; font-weight: bold; font-size: 10.5px;
            text-transform: uppercase; padding: 6px 8px;
        }
        tr.grand-total td.col-total.rugi { color: #7d1919; }
        tr.grand-total td.col-total.laba { color: #145a32; }

        .footer-note { margin-top: 8px; font-size: 7.5px; color: #777; text-align: right; }
    </style>
</head>
<body>

@php
    $logoPath = public_path('images/logo.png');
    $logoData = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
@endphp
<div class="kop">
    <table>
        <tr>
            @if ($logoData)
                <td class="logo-col"><img src="{{ $logoData }}" alt="Logo" /></td>
            @endif
            <td>
                <div class="company">{{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}</div>
                <div class="title">LAPORAN LABA RUGI</div>
            </td>
            <td style="text-align:right; width:38%;">
                <span class="periode-info">
                    Periode: {{ \Carbon\Carbon::parse($from)->translatedFormat('d F Y') }}
                    — {{ \Carbon\Carbon::parse($to)->translatedFormat('d F Y') }}
                </span>
            </td>
        </tr>
    </table>
</div>

@php
    $u = $data['uraian'];
    $dasar = $u['dasar_persen'];

    /**
     * Nominal beserta persennya dalam satu sel: "14.093.000.000 (100,0%)".
     * Bukan kolom tersendiri — persen di sini keterangan untuk nominal di
     * sebelahnya, dan kolom tambahan cuma melebarkan tabel yang sudah panjang.
     */
    $nilaiPersen = function ($nilai) use ($dasar) {
        return number_format((float) $nilai, 0, ',', '.')
            .' <span class="pct">('.number_format(
                \App\Services\LaporanAkuntingService::persen((float) $nilai, (float) $dasar), 1, ',', '.'
            ).'%)</span>';
    };

    // Dua seksi di atas garis laba kotor, tiga di bawahnya. Tandanya membuat
    // beban tampil negatif supaya penjumlahan ke bawah bisa diikuti mata.
    $atas = [
        ['Penjualan', $u['penjualan'], 1, 'section-pendapatan'],
        ['Harga Pokok Penjualan', $u['hpp'], -1, 'section-beban'],
    ];
    $bawah = [
        ['Biaya Usaha', $u['biaya'], -1, 'section-beban'],
        ['Pendapatan Lain-lain', $u['pendapatan_lain'], 1, 'section-pendapatan'],
        ['Pajak PPh Final', $u['pajak_final'], -1, 'section-beban'],
    ];
@endphp

<table class="laporan">
    @foreach ([$atas, $bawah] as $i => $kelompok)
        @foreach ($kelompok as [$judul, $seksi, $tanda, $kelas])
            @continue(! $seksi['groups'])
            <thead class="{{ $kelas }}">
                <tr>
                    <th class="col-nama">{{ $judul }}</th>
                    <th class="col-item">Detail</th>
                    <th class="col-total">Sub Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($seksi['groups'] as $group)
                    <tr class="group-header">
                        <td class="col-nama">{{ $group['header']->kode }} &mdash; {{ $group['header']->nama }}</td>
                        <td class="col-item"></td>
                        <td class="col-total">{!! $nilaiPersen($tanda * $group['total']) !!}</td>
                    </tr>
                    @if ($rinci)
                        @foreach ($group['items'] as $item)
                            <tr class="item">
                                <td class="col-nama">{{ $item['coa']->kode }} &mdash; {{ $item['coa']->nama }}</td>
                                <td class="col-item">{!! $nilaiPersen($tanda * $item['saldo']) !!}</td>
                                <td class="col-total"></td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach
                <tr class="subtotal">
                    <td class="col-nama" colspan="2">TOTAL {{ strtoupper($judul) }}</td>
                    <td class="col-total">{!! $nilaiPersen($tanda * $seksi['total']) !!}</td>
                </tr>
            </tbody>
        @endforeach

        {{-- Garis laba kotor disisipkan tepat setelah seksi atas. --}}
        @if ($i === 0)
            <tbody>
                <tr class="grand-total">
                    <td class="col-nama" colspan="2">LABA KOTOR (GROSS PROFIT)</td>
                    <td class="col-total {{ $u['gross_profit'] >= 0 ? 'laba' : 'rugi' }}">
                        {!! $nilaiPersen($u['gross_profit']) !!}
                    </td>
                </tr>
            </tbody>
        @endif
    @endforeach

    <tbody>
        <tr class="grand-total">
            <td class="col-nama" colspan="2">
                {{ $u['net_profit'] >= 0 ? 'LABA BERSIH (NET PROFIT)' : 'RUGI BERSIH (NET PROFIT)' }}
            </td>
            <td class="col-total {{ $u['net_profit'] >= 0 ? 'laba' : 'rugi' }}">
                {!! $nilaiPersen($u['net_profit']) !!}
            </td>
        </tr>
    </tbody>
</table>

<div class="footer-note" style="margin-top:6px; text-align:left;">
    Persentase dihitung terhadap Penjualan. Pendapatan di luar usaha tidak ikut jadi penyebut.
</div>

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }} · ERP LMI
</div>

</body>
</html>
