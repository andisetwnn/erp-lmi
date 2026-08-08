<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Neraca</title>
    <style>
        @page { margin: 12mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        .kop { text-align: center; margin-bottom: 10px; }
        .kop .company { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 16px; font-weight: bold; letter-spacing: 2px; margin-top: 2px; }
        .kop .periode { font-size: 10px; margin-top: 2px; }
        .balance-status { text-align: center; padding: 6px; margin-bottom: 10px; font-weight: bold; font-size: 10px; }
        .balance-status.ok { background: #d5f5e3; color: #145a32; border: 1px solid #7dcea0; }
        .balance-status.err { background: #fadbd8; color: #7d1919; border: 1px solid #e6b0aa; }
        .two-col { width: 100%; border-collapse: collapse; }
        .two-col > tr > td { vertical-align: top; padding: 0 5px; width: 50%; }
        table.laporan { width: 100%; border-collapse: collapse; }
        table.laporan th, table.laporan td { border: 1px solid #999; padding: 3px 6px; }
        table.laporan th { text-transform: uppercase; font-size: 9px; text-align: left; }
        table.laporan td.num { text-align: right; font-family: monospace; }
        tr.group-header td { font-weight: bold; background: #f5f5f5; }
        tr.item td { font-size: 8px; color: #444; padding-left: 20px; }
        tr.subtotal td { font-weight: bold; background: #eee; }
        tr.total td { font-weight: bold; background: #ccc; text-transform: uppercase; }
        .aset-header { background: #dceffb; color: #21618c; }
        .kewajiban-header { background: #fdebd0; color: #935116; }
        .modal-header { background: #d4efdf; color: #196f3d; }
        .footer-note { margin-top: 12px; font-size: 8px; color: #777; text-align: right; }
    </style>
</head>
<body>

<div class="kop">
    <div class="company">{{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}</div>
    <div class="title">NERACA</div>
    <div class="periode">
        PER : {{ strtoupper(\Carbon\Carbon::parse($tanggal)->translatedFormat('d F Y')) }}
    </div>
</div>

<div class="balance-status {{ $data['balanced'] ? 'ok' : 'err' }}">
    {{ $data['balanced'] ? '✓ Neraca Balance' : '⚠ Neraca TIDAK BALANCE' }}
    &nbsp;·&nbsp;
    Total Aset {{ number_format($data['aset']['total'], 0, ',', '.') }}
    vs Pasiva {{ number_format($data['total_pasiva'], 0, ',', '.') }}
</div>

<table class="two-col">
    <tr>
        {{-- KIRI: ASET --}}
        <td>
            <table class="laporan">
                <thead>
                    <tr class="aset-header">
                        <th colspan="2">Aset</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data['aset']['groups'] as $group)
                        <tr class="group-header">
                            <td>{{ $group['header']->kode }} - {{ $group['header']->nama }}</td>
                            <td class="num">{{ number_format($group['total'], 0, ',', '.') }}</td>
                        </tr>
                        @foreach ($group['items'] as $item)
                            <tr class="item">
                                <td>{{ $item['coa']->kode }} - {{ $item['coa']->nama }}</td>
                                <td class="num">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="2" style="text-align:center; color:#999;">Tidak ada aset.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="total">
                        <td>Total Aset</td>
                        <td class="num">{{ number_format($data['aset']['total'], 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </td>

        {{-- KANAN: KEWAJIBAN + MODAL --}}
        <td>
            <table class="laporan">
                <thead>
                    <tr class="kewajiban-header">
                        <th colspan="2">Kewajiban</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data['kewajiban']['groups'] as $group)
                        <tr class="group-header">
                            <td>{{ $group['header']->kode }} - {{ $group['header']->nama }}</td>
                            <td class="num">{{ number_format($group['total'], 0, ',', '.') }}</td>
                        </tr>
                        @foreach ($group['items'] as $item)
                            <tr class="item">
                                <td>{{ $item['coa']->kode }} - {{ $item['coa']->nama }}</td>
                                <td class="num">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="2" style="text-align:center; color:#999;">Tidak ada kewajiban.</td></tr>
                    @endforelse
                    <tr class="subtotal">
                        <td>Total Kewajiban</td>
                        <td class="num">{{ number_format($data['kewajiban']['total'], 0, ',', '.') }}</td>
                    </tr>
                </tbody>
                <thead>
                    <tr class="modal-header">
                        <th colspan="2">Modal & Laba</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['modal']['groups'] as $group)
                        <tr class="group-header">
                            <td>{{ $group['header']->kode }} - {{ $group['header']->nama }}</td>
                            <td class="num">{{ number_format($group['total'], 0, ',', '.') }}</td>
                        </tr>
                        @foreach ($group['items'] as $item)
                            <tr class="item">
                                <td>{{ $item['coa']->kode }} - {{ $item['coa']->nama }}</td>
                                <td class="num">{{ number_format($item['saldo'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    <tr class="item">
                        <td><em>{{ $data['laba_periode'] >= 0 ? 'Laba' : 'Rugi' }} Periode Berjalan</em></td>
                        <td class="num">{{ number_format($data['laba_periode'], 0, ',', '.') }}</td>
                    </tr>
                    <tr class="subtotal">
                        <td>Total Modal & Laba</td>
                        <td class="num">{{ number_format($data['modal']['total'] + $data['laba_periode'], 0, ',', '.') }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="total">
                        <td>Total Pasiva</td>
                        <td class="num">{{ number_format($data['total_pasiva'], 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </td>
    </tr>
</table>

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }}
</div>

</body>
</html>
