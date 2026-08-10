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
        .col-nama { width: 68%; }
        .col-item { width: 16%; text-align: right; font-family: monospace; }
        .col-total { width: 16%; text-align: right; font-family: monospace; }

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

<table class="laporan">
    {{-- PENDAPATAN --}}
    <thead class="section-pendapatan">
        <tr>
            <th class="col-nama">Pendapatan</th>
            <th class="col-item">Detail</th>
            <th class="col-total">Sub Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['pendapatan']['groups'] as $group)
            <tr class="group-header">
                <td class="col-nama">{{ $group['header']->kode }} — {{ $group['header']->nama }}</td>
                <td class="col-item"></td>
                <td class="col-total">{{ number_format($group['total'], 0, ',', '.') }}</td>
            </tr>
            @foreach ($group['items'] as $item)
                <tr class="item">
                    <td class="col-nama">{{ $item['coa']->kode }} — {{ $item['coa']->nama }}</td>
                    <td class="col-item">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                    <td class="col-total"></td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="3" style="text-align:center; color:#999; padding: 8px;">Tidak ada pendapatan di periode ini.</td></tr>
        @endforelse
        <tr class="subtotal">
            <td class="col-nama" colspan="2">TOTAL PENDAPATAN</td>
            <td class="col-total">{{ number_format($data['pendapatan']['total'], 0, ',', '.') }}</td>
        </tr>
    </tbody>

    {{-- BEBAN --}}
    <thead class="section-beban">
        <tr>
            <th class="col-nama">Beban / HPP</th>
            <th class="col-item">Detail</th>
            <th class="col-total">Sub Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['beban']['groups'] as $group)
            <tr class="group-header">
                <td class="col-nama">{{ $group['header']->kode }} — {{ $group['header']->nama }}</td>
                <td class="col-item"></td>
                <td class="col-total">{{ number_format($group['total'], 0, ',', '.') }}</td>
            </tr>
            @foreach ($group['items'] as $item)
                <tr class="item">
                    <td class="col-nama">{{ $item['coa']->kode }} — {{ $item['coa']->nama }}</td>
                    <td class="col-item">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                    <td class="col-total"></td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="3" style="text-align:center; color:#999; padding: 8px;">Tidak ada beban di periode ini.</td></tr>
        @endforelse
        <tr class="subtotal">
            <td class="col-nama" colspan="2">TOTAL BEBAN</td>
            <td class="col-total">{{ number_format($data['beban']['total'], 0, ',', '.') }}</td>
        </tr>
    </tbody>

    {{-- LABA / RUGI BERSIH --}}
    <tbody>
        <tr class="grand-total">
            <td class="col-nama" colspan="2">
                {{ $data['laba_rugi'] >= 0 ? 'LABA BERSIH PERIODE BERJALAN' : 'RUGI BERSIH PERIODE BERJALAN' }}
            </td>
            <td class="col-total {{ $data['laba_rugi'] >= 0 ? 'laba' : 'rugi' }}">
                {{ number_format($data['laba_rugi'], 0, ',', '.') }}
            </td>
        </tr>
    </tbody>
</table>

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }} · ERP LMI
</div>

</body>
</html>
