<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Laba Rugi Tahunan {{ $data['tahun'] }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; }

        .kop { text-align: center; margin-bottom: 8px; }
        .kop .company { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 13px; font-weight: bold; letter-spacing: 1px; margin-top: 2px; }
        .kop .periode { font-size: 9px; margin-top: 2px; }

        table.laporan { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.laporan th, table.laporan td {
            border: 1px solid #999; padding: 2px 3px; font-size: 7px;
            overflow: hidden; word-wrap: break-word;
        }
        th.col-nama, td.col-nama { width: 21%; text-align: left; }
        th.col-bulan, td.col-bulan { width: 6%; text-align: right; font-family: monospace; }
        th.col-total, td.col-total { width: 7%; text-align: right; font-family: monospace; background: #f0f0f0; }

        thead th { background: #e6e6e6; text-transform: uppercase; font-weight: bold; text-align: center; }
        tr.seksi td { background: #eef3f7; font-weight: bold; text-transform: uppercase; }
        tr.seksi-pendapatan td { background: #d5f5e3; color: #145a32; }
        tr.seksi-beban td { background: #fadbd8; color: #7d1919; }
        tr.total-pendapatan td { background: #abebc6; font-weight: bold; }
        tr.total-beban td { background: #f5b7b1; font-weight: bold; }
        tr.laba td { background: #d6dbdf; font-weight: bold; border-top: 2px solid #333; font-size: 8px; }
        .rugi { color: #7d1919; }
        .catatan { margin-top: 6px; font-size: 7px; color: #777; }
        .footer-note { margin-top: 8px; font-size: 7px; color: #777; text-align: right; }
    </style>
</head>
<body>

@php
    // Angka dalam ribuan supaya 12 kolom muat di satu halaman A4 landscape.
    $ringkas = fn ($n) => $n == 0 ? '-' : number_format($n / 1000, 0, ',', '.');
@endphp

<div class="kop">
    <div class="company">{{ $perusahaan?->nama ?? 'PT LANGIT MEMBANGUN INDONESIA' }}</div>
    <div class="title">LAPORAN LABA RUGI TAHUNAN</div>
    <div class="periode">TAHUN {{ $data['tahun'] }}{{ $rinci ? '' : ' — RESUME' }}</div>
</div>

<table class="laporan">
    <thead>
        <tr>
            <th class="col-nama">Keterangan</th>
            @foreach ($data['bulan'] as $nama)
                <th class="col-bulan">{{ $nama }}</th>
            @endforeach
            <th class="col-total">Total</th>
        </tr>
    </thead>

    <tbody>
        <tr class="seksi seksi-pendapatan"><td colspan="14">Pendapatan</td></tr>
        @if ($rinci)
            @forelse ($data['pendapatan']['baris'] as $baris)
                <tr>
                    <td class="col-nama">{{ $baris['header']->kode }} — {{ $baris['header']->nama }}</td>
                    @foreach (range(1, 12) as $b)
                        <td class="col-bulan">{{ $ringkas($baris['per_bulan'][$b]) }}</td>
                    @endforeach
                    <td class="col-total">{{ $ringkas($baris['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="14" style="text-align:center; color:#999;">Tidak ada pendapatan di tahun ini.</td></tr>
            @endforelse
        @endif
        <tr class="total-pendapatan">
            <td class="col-nama">Total Pendapatan</td>
            @foreach (range(1, 12) as $b)
                <td class="col-bulan">{{ $ringkas($data['pendapatan']['per_bulan'][$b]) }}</td>
            @endforeach
            <td class="col-total">{{ $ringkas($data['pendapatan']['total']) }}</td>
        </tr>

        <tr class="seksi seksi-beban"><td colspan="14">Beban / HPP</td></tr>
        @if ($rinci)
            @forelse ($data['beban']['baris'] as $baris)
                <tr>
                    <td class="col-nama">{{ $baris['header']->kode }} — {{ $baris['header']->nama }}</td>
                    @foreach (range(1, 12) as $b)
                        <td class="col-bulan">{{ $ringkas($baris['per_bulan'][$b]) }}</td>
                    @endforeach
                    <td class="col-total">{{ $ringkas($baris['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="14" style="text-align:center; color:#999;">Tidak ada beban di tahun ini.</td></tr>
            @endforelse
        @endif
        <tr class="total-beban">
            <td class="col-nama">Total Beban</td>
            @foreach (range(1, 12) as $b)
                <td class="col-bulan">{{ $ringkas($data['beban']['per_bulan'][$b]) }}</td>
            @endforeach
            <td class="col-total">{{ $ringkas($data['beban']['total']) }}</td>
        </tr>
    </tbody>

    <tfoot>
        <tr class="laba">
            <td class="col-nama">Laba / Rugi Bersih</td>
            @foreach (range(1, 12) as $b)
                @php($nilai = $data['laba_rugi']['per_bulan'][$b])
                <td class="col-bulan {{ $nilai < 0 ? 'rugi' : '' }}">{{ $ringkas($nilai) }}</td>
            @endforeach
            <td class="col-total {{ $data['laba_rugi']['total'] < 0 ? 'rugi' : '' }}">
                {{ $ringkas($data['laba_rugi']['total']) }}
            </td>
        </tr>
    </tfoot>
</table>

<div class="catatan">
    Semua angka dalam <strong>ribuan rupiah</strong>. Angka negatif berarti rugi.
    Hanya jurnal berstatus <em>posted</em> yang dihitung.
</div>

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }}
</div>

</body>
</html>
