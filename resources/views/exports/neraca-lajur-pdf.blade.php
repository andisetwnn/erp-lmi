<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Neraca Lajur</title>
    <style>
        @page { margin: 8mm 8mm 10mm 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7px; color: #111; }
        .kop { margin-bottom: 8px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; padding: 0; }
        .kop td.logo-col { width: 55px; padding-right: 8px; }
        .kop td.logo-col img { width: 44px; height: auto; }
        .kop .company { font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 10px; font-weight: bold; margin-top: 2px; }
        .periode-info { font-size: 9px; padding: 3px 8px; background: #fff2cc; border: 1px solid #d4b400; font-weight: bold; }

        table.laporan { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.laporan th, table.laporan td { border: 1px solid #999; padding: 2px 3px; font-size: 6.5px; overflow: hidden; word-wrap: break-word; }
        table.laporan thead th { background: #f5f5f5; text-align: center; font-weight: bold; }
        .col-kode { width: 5%; font-family: monospace; }
        .col-nama { width: 18%; }
        .col-num { width: 7.7%; text-align: right; font-family: monospace; }

        .h-ns  { background: #e8e8e8 !important; }
        .h-ajp { background: #fff2cc !important; color: #7a5c00; }
        .h-adj { background: #dceffb !important; color: #21618c; }
        .h-lr  { background: #fadbd8 !important; color: #7d1919; }
        .h-nr  { background: #d5f5e3 !important; color: #145a32; }

        .bg-ns td.col-num  { background: #fafafa; }
        .bg-ajp { background: #fff8e1; }
        .bg-adj { background: #eaf4fb; font-weight: bold; }
        .bg-lr  { background: #fdf2f0; }
        .bg-nr  { background: #eaf7ef; }

        tr.total td { background: #b8d8ff !important; font-weight: bold; font-size: 7.5px; padding: 4px 3px; }
        tr.laba-rugi td { background: #f8f4d8 !important; font-weight: bold; font-size: 7.5px; padding: 4px 3px; }

        .footer-note { margin-top: 6px; font-size: 7px; color: #777; text-align: right; }
    </style>
</head>
<body>

@php
    $logoPath = public_path('images/logo.png');
    $logoData = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
    $fmt = fn ($v) => $v > 0 ? number_format($v, 0, ',', '.') : '-';
@endphp
<div class="kop">
    <table>
        <tr>
            @if ($logoData)
                <td class="logo-col"><img src="{{ $logoData }}" alt="Logo" /></td>
            @endif
            <td>
                <div class="company">{{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}</div>
                <div class="title">NERACA LAJUR (WORKSHEET)</div>
            </td>
            <td style="text-align:right; width:35%;">
                <span class="periode-info">Periode: {{ \Carbon\Carbon::parse($from)->translatedFormat('d F Y') }} — {{ \Carbon\Carbon::parse($to)->translatedFormat('d F Y') }}</span>
            </td>
        </tr>
    </table>
</div>

<table class="laporan">
    <thead>
        <tr>
            <th class="col-kode" rowspan="2">Kode</th>
            <th class="col-nama" rowspan="2">Nama Akun</th>
            <th class="h-ns" colspan="2">Neraca Saldo</th>
            <th class="h-ajp" colspan="2">AJP</th>
            <th class="h-adj" colspan="2">Disesuaikan</th>
            <th class="h-lr" colspan="2">Rugi/Laba</th>
            <th class="h-nr" colspan="2">Neraca</th>
        </tr>
        <tr>
            <th class="col-num h-ns">Debet</th>
            <th class="col-num h-ns">Kredit</th>
            <th class="col-num h-ajp">Debet</th>
            <th class="col-num h-ajp">Kredit</th>
            <th class="col-num h-adj">Debet</th>
            <th class="col-num h-adj">Kredit</th>
            <th class="col-num h-lr">Debet</th>
            <th class="col-num h-lr">Kredit</th>
            <th class="col-num h-nr">Debet</th>
            <th class="col-num h-nr">Kredit</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['rows'] as $r)
            <tr>
                <td class="col-kode">{{ $r['coa']->kode }}</td>
                <td class="col-nama">{{ $r['coa']->nama }}</td>
                <td class="col-num bg-ns">{{ $fmt($r['ns_debet']) }}</td>
                <td class="col-num bg-ns">{{ $fmt($r['ns_kredit']) }}</td>
                <td class="col-num bg-ajp">{{ $fmt($r['ajp_debet']) }}</td>
                <td class="col-num bg-ajp">{{ $fmt($r['ajp_kredit']) }}</td>
                <td class="col-num bg-adj">{{ $fmt($r['adj_debet']) }}</td>
                <td class="col-num bg-adj">{{ $fmt($r['adj_kredit']) }}</td>
                <td class="col-num bg-lr">{{ $fmt($r['lr_debet']) }}</td>
                <td class="col-num bg-lr">{{ $fmt($r['lr_kredit']) }}</td>
                <td class="col-num bg-nr">{{ $fmt($r['nr_debet']) }}</td>
                <td class="col-num bg-nr">{{ $fmt($r['nr_kredit']) }}</td>
            </tr>
        @empty
            <tr><td colspan="12" style="text-align:center; padding: 12px; color:#999;">Tidak ada mutasi di periode ini.</td></tr>
        @endforelse
    </tbody>
    @if (! empty($data['rows']))
        <tfoot>
            <tr class="total">
                <td colspan="2" style="text-align:right">TOTAL</td>
                <td class="col-num">{{ $fmt($data['total_ns_debet']) }}</td>
                <td class="col-num">{{ $fmt($data['total_ns_kredit']) }}</td>
                <td class="col-num">{{ $fmt($data['total_ajp_debet']) }}</td>
                <td class="col-num">{{ $fmt($data['total_ajp_kredit']) }}</td>
                <td class="col-num">{{ $fmt($data['total_adj_debet']) }}</td>
                <td class="col-num">{{ $fmt($data['total_adj_kredit']) }}</td>
                <td class="col-num">{{ $fmt($data['total_lr_debet']) }}</td>
                <td class="col-num">{{ $fmt($data['total_lr_kredit']) }}</td>
                <td class="col-num">{{ $fmt($data['total_nr_debet']) }}</td>
                <td class="col-num">{{ $fmt($data['total_nr_kredit']) }}</td>
            </tr>
            @php $lr = $data['laba_rugi']; @endphp
            <tr class="laba-rugi">
                <td colspan="8" style="text-align:right">{{ $lr >= 0 ? 'LABA' : 'RUGI' }} Bersih Periode Berjalan</td>
                <td class="col-num">{{ $lr >= 0 ? $fmt($lr) : '-' }}</td>
                <td class="col-num">{{ $lr < 0 ? $fmt(-$lr) : '-' }}</td>
                <td class="col-num">{{ $lr < 0 ? $fmt(-$lr) : '-' }}</td>
                <td class="col-num">{{ $lr >= 0 ? $fmt($lr) : '-' }}</td>
            </tr>
        </tfoot>
    @endif
</table>

@unless ($data['balanced'])
    <div style="margin-top:8px; padding:6px; text-align:center; background:#fadbd8; color:#7d1919; border:1px solid #e6b0aa; font-weight:bold;">
        ⚠ Neraca Lajur TIDAK BALANCE — cek jurnal yg debet ≠ kredit
    </div>
@endunless

<div class="footer-note">Dicetak {{ now()->translatedFormat('d F Y H:i') }} · ERP LMI</div>
</body>
</html>
