<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Laba Rugi</title>
    <style>
        @page { margin: 12mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        .kop { text-align: center; margin-bottom: 12px; }
        .kop .company { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 16px; font-weight: bold; letter-spacing: 2px; margin-top: 2px; }
        .kop .periode { font-size: 10px; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 8px; }
        th { background: #e6e6e6; text-transform: uppercase; font-size: 9px; text-align: left; }
        td.num { text-align: right; font-family: monospace; }
        tr.group-header td { font-weight: bold; background: #f5f5f5; }
        tr.item td { font-size: 9px; color: #444; padding-left: 24px; }
        tr.total td { font-weight: bold; background: #e0e0e0; }
        .section-pendapatan th { background: #d5f5e3; color: #145a32; }
        .section-beban th { background: #fadbd8; color: #7d1919; }
        .laba-rugi td { padding: 8px; font-weight: bold; font-size: 12px; background: #ddd; text-transform: uppercase; }
        .laba-positif { color: #145a32; }
        .laba-negatif { color: #7d1919; }
        .footer-note { margin-top: 12px; font-size: 8px; color: #777; text-align: right; }
    </style>
</head>
<body>

<div class="kop">
    <div class="company">{{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}</div>
    <div class="title">LAPORAN LABA RUGI</div>
    <div class="periode">
        PERIODE : {{ strtoupper(\Carbon\Carbon::parse($from)->translatedFormat('d F Y')) }}
        &mdash; {{ strtoupper(\Carbon\Carbon::parse($to)->translatedFormat('d F Y')) }}
    </div>
</div>

<table>
    {{-- PENDAPATAN --}}
    <thead class="section-pendapatan">
        <tr>
            <th colspan="3">Pendapatan</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['pendapatan']['groups'] as $group)
            <tr class="group-header">
                <td>{{ $group['header']->kode }} - {{ $group['header']->nama }}</td>
                <td></td>
                <td class="num">{{ number_format($group['total'], 0, ',', '.') }}</td>
            </tr>
            @foreach ($group['items'] as $item)
                <tr class="item">
                    <td>{{ $item['coa']->kode }} - {{ $item['coa']->nama }}</td>
                    <td class="num">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                    <td></td>
                </tr>
            @endforeach
        @empty
            <tr>
                <td colspan="3" style="text-align:center; color:#999; padding: 12px;">
                    Tidak ada pendapatan di periode ini.
                </td>
            </tr>
        @endforelse
        <tr class="total">
            <td colspan="2">TOTAL PENDAPATAN</td>
            <td class="num">{{ number_format($data['pendapatan']['total'], 0, ',', '.') }}</td>
        </tr>
    </tbody>

    {{-- BEBAN --}}
    <thead class="section-beban">
        <tr>
            <th colspan="3">Beban / HPP</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['beban']['groups'] as $group)
            <tr class="group-header">
                <td>{{ $group['header']->kode }} - {{ $group['header']->nama }}</td>
                <td></td>
                <td class="num">{{ number_format($group['total'], 0, ',', '.') }}</td>
            </tr>
            @foreach ($group['items'] as $item)
                <tr class="item">
                    <td>{{ $item['coa']->kode }} - {{ $item['coa']->nama }}</td>
                    <td class="num">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                    <td></td>
                </tr>
            @endforeach
        @empty
            <tr>
                <td colspan="3" style="text-align:center; color:#999; padding: 12px;">
                    Tidak ada beban di periode ini.
                </td>
            </tr>
        @endforelse
        <tr class="total">
            <td colspan="2">TOTAL BEBAN</td>
            <td class="num">{{ number_format($data['beban']['total'], 0, ',', '.') }}</td>
        </tr>
    </tbody>

    {{-- LABA / RUGI BERSIH --}}
    <tbody class="laba-rugi">
        <tr>
            <td colspan="2">
                {{ $data['laba_rugi'] >= 0 ? 'Laba Bersih Periode Berjalan' : 'Rugi Bersih Periode Berjalan' }}
            </td>
            <td class="num {{ $data['laba_rugi'] >= 0 ? 'laba-positif' : 'laba-negatif' }}">
                {{ number_format(abs($data['laba_rugi']), 0, ',', '.') }}
                @if ($data['laba_rugi'] < 0) (rugi) @endif
            </td>
        </tr>
    </tbody>
</table>

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }}
</div>

</body>
</html>
