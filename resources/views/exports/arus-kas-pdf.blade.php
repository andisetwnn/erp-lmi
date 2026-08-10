<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Arus Kas</title>
    <style>
        @page { margin: 10mm 12mm 12mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        .kop { margin-bottom: 8px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; padding: 0; }
        .kop td.logo-col { width: 55px; padding-right: 8px; }
        .kop td.logo-col img { width: 48px; height: auto; }
        .kop .company { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 11px; font-weight: bold; margin-top: 2px; }
        .periode-info { font-size: 9px; padding: 3px 8px; background: #fff2cc; border: 1px solid #d4b400; font-weight: bold; }

        table.laporan { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 8px; }
        table.laporan th, table.laporan td {
            border: 1px solid #999; padding: 3px 5px; font-size: 8.5px;
            overflow: hidden; word-wrap: break-word;
        }
        .col-kode { width: 10%; font-family: monospace; }
        .col-nama { width: 42%; }
        .col-num { width: 16%; text-align: right; font-family: monospace; }

        thead th { text-align: left; font-weight: bold; padding: 5px 8px; font-size: 10px; text-transform: uppercase; }
        .section-operasi   th { background: #dceffb; color: #21618c; }
        .section-investasi th { background: #ebdef0; color: #6c3483; }
        .section-pendanaan th { background: #d4efdf; color: #196f3d; }

        tr.item td.col-num { text-align: right; font-family: monospace; color: #444; }
        tr.item-empty td { text-align: center; color: #999; font-style: italic; padding: 8px; }
        tr.subtotal td { background: #e8e8e8; font-weight: bold; }
        tr.grand-total td { background: #b8d8ff; font-weight: bold; font-size: 10px; padding: 6px 8px; }
        tr.grand-total td.laba { color: #145a32; }
        tr.grand-total td.rugi { color: #7d1919; }

        .summary { margin-bottom: 8px; padding: 6px 10px; background: #f8f9fa; border: 1px solid #ddd; font-size: 9px; }
        .summary strong { font-family: monospace; }
        .footer-note { margin-top: 6px; font-size: 7px; color: #777; text-align: right; }
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
                <div class="title">LAPORAN ARUS KAS (CASH FLOW STATEMENT)</div>
            </td>
            <td style="text-align:right; width:35%;">
                <span class="periode-info">Periode: {{ \Carbon\Carbon::parse($from)->translatedFormat('d F Y') }} — {{ \Carbon\Carbon::parse($to)->translatedFormat('d F Y') }}</span>
            </td>
        </tr>
    </table>
</div>

<div class="summary">
    Kas Awal: <strong>Rp {{ number_format($data['kas_awal'], 0, ',', '.') }}</strong>
    &nbsp;+&nbsp; Kenaikan Bersih: <strong>Rp {{ number_format($data['kenaikan_bersih'], 0, ',', '.') }}</strong>
    &nbsp;=&nbsp; Kas Akhir: <strong>Rp {{ number_format($data['kas_akhir'], 0, ',', '.') }}</strong>
</div>

@foreach ([
    ['key' => 'operasi',    'title' => 'AKTIVITAS OPERASI',    'cls' => 'section-operasi'],
    ['key' => 'investasi',  'title' => 'AKTIVITAS INVESTASI',  'cls' => 'section-investasi'],
    ['key' => 'pendanaan',  'title' => 'AKTIVITAS PENDANAAN',  'cls' => 'section-pendanaan'],
] as $section)
    @php $s = $data[$section['key']]; @endphp
    <table class="laporan">
        <thead class="{{ $section['cls'] }}">
            <tr>
                <th class="col-kode">Kode</th>
                <th class="col-nama">{{ $section['title'] }}</th>
                <th class="col-num">Kas Masuk</th>
                <th class="col-num">Kas Keluar</th>
                <th class="col-num">Net</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($s['items'] as $item)
                <tr class="item">
                    <td class="col-kode">{{ $item['lawan_coa']->kode }}</td>
                    <td class="col-nama">{{ $item['lawan_coa']->nama }}</td>
                    <td class="col-num">{{ $item['masuk'] > 0 ? number_format($item['masuk'], 0, ',', '.') : '-' }}</td>
                    <td class="col-num">{{ $item['keluar'] > 0 ? number_format($item['keluar'], 0, ',', '.') : '-' }}</td>
                    <td class="col-num">{{ number_format($item['net'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr class="item-empty"><td colspan="5">Tidak ada mutasi di aktivitas ini.</td></tr>
            @endforelse
            <tr class="subtotal">
                <td colspan="4" style="text-align:right">Net {{ $section['title'] }}</td>
                <td class="col-num">{{ number_format($s['net'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
@endforeach

<table class="laporan">
    <tbody>
        <tr class="grand-total">
            <td colspan="4" style="text-align:right; width:84%;">
                KENAIKAN / (PENURUNAN) KAS BERSIH
            </td>
            <td class="col-num {{ $data['kenaikan_bersih'] >= 0 ? 'laba' : 'rugi' }}">{{ number_format($data['kenaikan_bersih'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td colspan="4" style="text-align:right">Kas &amp; Bank Awal Periode</td>
            <td class="col-num">{{ number_format($data['kas_awal'], 0, ',', '.') }}</td>
        </tr>
        <tr class="grand-total">
            <td colspan="4" style="text-align:right">KAS &amp; BANK AKHIR PERIODE</td>
            <td class="col-num">{{ number_format($data['kas_akhir'], 0, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

<div class="footer-note">Dicetak {{ now()->translatedFormat('d F Y H:i') }} · ERP LMI</div>
</body>
</html>
