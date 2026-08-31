<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Neraca</title>
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
        .periode-info {
            float: right; font-size: 9px; padding: 3px 8px; border: 1px solid;
            font-weight: bold;
        }
        .badge-ok { background: #d5f5e3; color: #145a32; border-color: #7dcea0; }
        .badge-err { background: #fadbd8; color: #7d1919; border-color: #e6b0aa; }
        .badge-neutral { background: #fff2cc; color: #7a5c00; border-color: #d4b400; }

        table.laporan { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 8px; }
        table.laporan th, table.laporan td {
            border: 1px solid #999; padding: 3px 6px; font-size: 8.5px;
            overflow: hidden; word-wrap: break-word;
        }
        .col-nama { width: 60%; }
        .col-item { width: 20%; text-align: right; font-family: monospace; }
        .col-total { width: 20%; text-align: right; font-family: monospace; }

        thead th {
            text-align: left; font-weight: bold; padding: 5px 8px;
            font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
        }
        .section-aset      th { background: #dceffb; color: #21618c; border-color: #85c1e9; }
        .section-kewajiban th { background: #fdebd0; color: #935116; border-color: #f5b041; }
        .section-modal     th { background: #d4efdf; color: #196f3d; border-color: #7dcea0; }

        tr.group-header td { background: #eef3f7; font-weight: bold; }
        tr.item td { padding-left: 18px; color: #444; font-size: 8.5px; }
        tr.item td.col-item { text-align: right; font-family: monospace; padding-left: 4px; color: #444; }
        tr.item-italic td { padding-left: 18px; color: #333; font-style: italic; font-size: 8.5px; }
        tr.item-italic td.col-item { text-align: right; font-family: monospace; font-style: italic; padding-left: 4px; }
        tr.subtotal td { background: #e8e8e8; font-weight: bold; font-size: 9px; }
        tr.grand-total td {
            background: #b8d8ff; font-weight: bold; font-size: 10px;
            text-transform: uppercase; padding: 5px 8px;
        }

        .balance-summary {
            margin-top: 6px; padding: 8px 12px; border: 2px solid; font-size: 10px; text-align: center;
        }
        .balance-summary strong { font-size: 11px; }
        .footer-note { margin-top: 6px; font-size: 7.5px; color: #777; text-align: right; }
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
                <div class="title">NERACA</div>
            </td>
            <td style="text-align:right; width:35%;">
                <span class="periode-info {{ $data['balanced'] ? 'badge-neutral' : 'badge-err' }}">
                    Per: {{ \Carbon\Carbon::parse($tanggal)->translatedFormat('d F Y') }}
                    @unless ($data['balanced'])
                        &nbsp;·&nbsp; ⚠ TIDAK BALANCE
                    @endunless
                </span>
            </td>
        </tr>
    </table>
</div>

{{-- ═══════════════ ASET ═══════════════ --}}
<table class="laporan">
    <thead class="section-aset">
        <tr>
            <th class="col-nama">Aset</th>
            <th class="col-item">Detail</th>
            <th class="col-total">Sub Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['aset']['groups'] as $group)
            <tr class="group-header">
                <td class="col-nama">{{ $group['header']->kode }} — {{ $group['header']->nama }}</td>
                <td class="col-item"></td>
                <td class="col-total">{{ number_format($group['total'], 0, ',', '.') }}</td>
            </tr>
            @if ($rinci)
            @foreach ($group['items'] as $item)
                <tr class="item">
                    <td class="col-nama">{{ $item['coa']->kode }} — {{ $item['coa']->nama }}</td>
                    <td class="col-item">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                    <td class="col-total"></td>
                </tr>
            @endforeach
            @endif
        @empty
            <tr><td colspan="3" style="text-align:center; color:#999; padding: 8px;">Tidak ada aset.</td></tr>
        @endforelse
        <tr class="grand-total">
            <td class="col-nama" colspan="2">TOTAL ASET</td>
            <td class="col-total">{{ number_format($data['aset']['total'], 0, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

{{-- ═══════════════ KEWAJIBAN ═══════════════ --}}
<table class="laporan">
    <thead class="section-kewajiban">
        <tr>
            <th class="col-nama">Kewajiban</th>
            <th class="col-item">Detail</th>
            <th class="col-total">Sub Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['kewajiban']['groups'] as $group)
            <tr class="group-header">
                <td class="col-nama">{{ $group['header']->kode }} — {{ $group['header']->nama }}</td>
                <td class="col-item"></td>
                <td class="col-total">{{ number_format($group['total'], 0, ',', '.') }}</td>
            </tr>
            @if ($rinci)
            @foreach ($group['items'] as $item)
                <tr class="item">
                    <td class="col-nama">{{ $item['coa']->kode }} — {{ $item['coa']->nama }}</td>
                    <td class="col-item">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                    <td class="col-total"></td>
                </tr>
            @endforeach
            @endif
        @empty
            <tr><td colspan="3" style="text-align:center; color:#999; padding: 8px;">Tidak ada kewajiban.</td></tr>
        @endforelse
        <tr class="subtotal">
            <td class="col-nama" colspan="2">TOTAL KEWAJIBAN</td>
            <td class="col-total">{{ number_format($data['kewajiban']['total'], 0, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

{{-- ═══════════════ MODAL & LABA ═══════════════ --}}
<table class="laporan">
    <thead class="section-modal">
        <tr>
            <th class="col-nama">Modal &amp; Laba</th>
            <th class="col-item">Detail</th>
            <th class="col-total">Sub Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($data['modal']['groups'] as $group)
            <tr class="group-header">
                <td class="col-nama">{{ $group['header']->kode }} — {{ $group['header']->nama }}</td>
                <td class="col-item"></td>
                <td class="col-total">{{ number_format($group['total'], 0, ',', '.') }}</td>
            </tr>
            @if ($rinci)
            @foreach ($group['items'] as $item)
                <tr class="item">
                    <td class="col-nama">{{ $item['coa']->kode }} — {{ $item['coa']->nama }}</td>
                    <td class="col-item">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                    <td class="col-total"></td>
                </tr>
            @endforeach
            @endif
        @endforeach
        <tr class="item-italic">
            <td class="col-nama">{{ $data['laba_periode'] >= 0 ? 'Laba' : 'Rugi' }} Periode Berjalan</td>
            <td class="col-item">{{ number_format($data['laba_periode'], 0, ',', '.') }}</td>
            <td class="col-total"></td>
        </tr>
        <tr class="subtotal">
            <td class="col-nama" colspan="2">TOTAL MODAL &amp; LABA</td>
            <td class="col-total">{{ number_format($data['modal']['total'] + $data['laba_periode'], 0, ',', '.') }}</td>
        </tr>
        <tr class="grand-total">
            <td class="col-nama" colspan="2">TOTAL PASIVA (KEWAJIBAN + MODAL)</td>
            <td class="col-total">{{ number_format($data['total_pasiva'], 0, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

@unless ($data['balanced'])
    <div class="balance-summary badge-err">
        <strong>⚠ NERACA TIDAK BALANCE</strong>
        &nbsp;·&nbsp;
        Total Aset: <strong>Rp {{ number_format($data['aset']['total'], 0, ',', '.') }}</strong>
        &nbsp;≠&nbsp;
        Total Pasiva: <strong>Rp {{ number_format($data['total_pasiva'], 0, ',', '.') }}</strong>
        &nbsp;·&nbsp; Selisih: <strong>Rp {{ number_format($data['aset']['total'] - $data['total_pasiva'], 0, ',', '.') }}</strong>
    </div>
@endunless

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }} · ERP LMI
</div>

</body>
</html>
