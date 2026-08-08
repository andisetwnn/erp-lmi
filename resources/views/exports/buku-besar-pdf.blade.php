<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Buku Besar {{ $coa->kode }} - {{ $coa->nama }}</title>
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        .kop { text-align: center; margin-bottom: 12px; }
        .kop .company { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 16px; font-weight: bold; letter-spacing: 2px; margin-top: 2px; }
        .kop .periode { font-size: 10px; margin-top: 2px; }
        .info { display: table; width: 100%; margin-bottom: 10px; }
        .info-l { display: table-cell; vertical-align: top; }
        .info-r { display: table-cell; vertical-align: top; text-align: right; width: 250px; }
        .info-row { margin-bottom: 3px; }
        .info-lbl { display: inline-block; width: 90px; color: #666; }
        .info-val { font-weight: bold; }
        .saldo-box { border: 1px solid #999; padding: 6px 10px; display: inline-block; }
        .saldo-box table { width: 100%; }
        .saldo-box td { padding: 2px 8px; }
        .saldo-box td.lbl { color: #666; }
        .saldo-box td.val { text-align: right; font-weight: bold; font-family: monospace; }
        table.mutasi { width: 100%; border-collapse: collapse; }
        table.mutasi th, table.mutasi td { border: 1px solid #999; padding: 4px 6px; }
        table.mutasi th { background: #e6e6e6; text-transform: uppercase; font-size: 9px; text-align: left; }
        table.mutasi td.num { text-align: right; font-family: monospace; }
        table.mutasi tr.saldo-awal { background: #f5f5f5; font-style: italic; }
        table.mutasi tfoot { background: #ddd; font-weight: bold; }
        .footer-note { margin-top: 12px; font-size: 9px; color: #777; text-align: right; }
    </style>
</head>
<body>

<div class="kop">
    <div class="company">{{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}</div>
    <div class="title">BUKU BESAR</div>
    <div class="periode">
        PERIODE : {{ strtoupper(\Carbon\Carbon::parse($from)->translatedFormat('d F Y')) }}
        &mdash; {{ strtoupper(\Carbon\Carbon::parse($to)->translatedFormat('d F Y')) }}
    </div>
</div>

<div class="info">
    <div class="info-l">
        <div class="info-row"><span class="info-lbl">No. Akun</span><span class="info-val">{{ $coa->kode }}</span></div>
        <div class="info-row"><span class="info-lbl">Nama Akun</span><span class="info-val">{{ $coa->nama }}</span></div>
    </div>
    <div class="info-r">
        <div class="saldo-box">
            <table>
                <tr>
                    <td class="lbl">Saldo Awal</td>
                    <td class="val">{{ number_format($saldoAwal, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="lbl">Saldo Akhir</td>
                    <td class="val">{{ number_format($saldoAkhir, 2, ',', '.') }}</td>
                </tr>
            </table>
        </div>
    </div>
</div>

<table class="mutasi">
    <thead>
        <tr>
            <th style="width:70px;">Tanggal</th>
            <th style="width:110px;">No Bukti</th>
            <th>Uraian Transaksi</th>
            <th style="width:110px; text-align:right;">Debet</th>
            <th style="width:110px; text-align:right;">Kredit</th>
        </tr>
    </thead>
    <tbody>
        <tr class="saldo-awal">
            <td>{{ \Carbon\Carbon::parse($from)->translatedFormat('d M y') }}</td>
            <td></td>
            <td>SALDO AWAL …</td>
            <td class="num">-</td>
            <td class="num">-</td>
        </tr>

        @forelse ($mutasi as $m)
            <tr>
                <td>{{ \Carbon\Carbon::parse($m->tanggal)->translatedFormat('d M y') }}</td>
                <td>{{ $m->no_bukti }}</td>
                <td>{{ $m->keterangan ?: '-' }}</td>
                <td class="num">{{ $m->debet > 0 ? number_format($m->debet, 0, ',', '.') : '-' }}</td>
                <td class="num">{{ $m->kredit > 0 ? number_format($m->kredit, 0, ',', '.') : '-' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5" style="text-align:center; color:#999; padding: 20px;">Tidak ada mutasi di periode ini.</td>
            </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" style="text-align:center; text-transform:uppercase;">TOTAL</td>
            <td class="num">{{ number_format($totalDebet, 0, ',', '.') }}</td>
            <td class="num">{{ number_format($totalKredit, 0, ',', '.') }}</td>
        </tr>
    </tfoot>
</table>

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }}
</div>

</body>
</html>
