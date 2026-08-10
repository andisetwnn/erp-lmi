<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Neraca Saldo</title>
    <style>
        @page { margin: 10mm 10mm 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; }
        .kop { margin-bottom: 8px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; padding: 0; }
        .kop td.logo-col { width: 55px; padding-right: 8px; }
        .kop td.logo-col img { width: 48px; height: auto; }
        .kop .company { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 11px; font-weight: bold; margin-top: 2px; }
        .periode-info { font-size: 9px; padding: 3px 8px; background: #fff2cc; border: 1px solid #d4b400; font-weight: bold; }

        table.laporan { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.laporan th, table.laporan td {
            border: 1px solid #999; padding: 3px 5px; font-size: 8px;
            overflow: hidden; word-wrap: break-word;
        }
        .col-kode { width: 8%; font-family: monospace; }
        .col-nama { width: 32%; }
        .col-tipe { width: 8%; text-align: center; text-transform: uppercase; font-size: 7px; }
        .col-num  { width: 13%; text-align: right; font-family: monospace; }

        thead th { background: #e8eef4; font-weight: bold; text-align: center; padding: 4px 4px; }
        tr.total td { background: #b8d8ff; font-weight: bold; font-size: 8.5px; }
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
                <div class="title">NERACA SALDO (TRIAL BALANCE)</div>
            </td>
            <td style="text-align:right; width:38%;">
                <span class="periode-info">Periode: {{ \Carbon\Carbon::parse($from)->translatedFormat('d F Y') }} — {{ \Carbon\Carbon::parse($to)->translatedFormat('d F Y') }}</span>
            </td>
        </tr>
    </table>
</div>

<table class="laporan">
    <thead>
        <tr>
            <th class="col-kode" rowspan="2">KODE</th>
            <th class="col-nama" rowspan="2">NAMA AKUN</th>
            <th class="col-tipe" rowspan="2">TIPE</th>
            <th colspan="2">MUTASI PERIODE</th>
            <th colspan="2">SALDO AKHIR</th>
        </tr>
        <tr>
            <th class="col-num">Debet</th>
            <th class="col-num">Kredit</th>
            <th class="col-num">Debet</th>
            <th class="col-num">Kredit</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['rows'] as $r)
            <tr>
                <td class="col-kode">{{ $r['coa']->kode }}</td>
                <td class="col-nama">{{ $r['coa']->nama }}</td>
                <td class="col-tipe">{{ substr(ucfirst($r['coa']->tipe), 0, 4) }}</td>
                <td class="col-num">{{ $r['debet'] > 0 ? number_format($r['debet'], 0, ',', '.') : '-' }}</td>
                <td class="col-num">{{ $r['kredit'] > 0 ? number_format($r['kredit'], 0, ',', '.') : '-' }}</td>
                <td class="col-num">{{ $r['saldo_debet'] > 0 ? number_format($r['saldo_debet'], 0, ',', '.') : '-' }}</td>
                <td class="col-num">{{ $r['saldo_kredit'] > 0 ? number_format($r['saldo_kredit'], 0, ',', '.') : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; padding: 12px; color: #999;">Tidak ada mutasi di periode ini.</td></tr>
        @endforelse
    </tbody>
    @if (! empty($data['rows']))
        <tfoot>
            <tr class="total">
                <td colspan="3" style="text-align:right">TOTAL</td>
                <td class="col-num">{{ number_format($data['total_debet'], 0, ',', '.') }}</td>
                <td class="col-num">{{ number_format($data['total_kredit'], 0, ',', '.') }}</td>
                <td class="col-num">{{ number_format($data['total_saldo_debet'], 0, ',', '.') }}</td>
                <td class="col-num">{{ number_format($data['total_saldo_kredit'], 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    @endif
</table>

@unless ($data['balanced'])
    <div style="margin-top: 8px; padding: 6px; text-align: center; background: #fadbd8; color: #7d1919; border: 1px solid #e6b0aa; font-weight: bold;">
        ⚠ NERACA SALDO TIDAK BALANCE — cek jurnal!
    </div>
@endunless

<div class="footer-note">Dicetak {{ now()->translatedFormat('d F Y H:i') }} · ERP LMI</div>
</body>
</html>
