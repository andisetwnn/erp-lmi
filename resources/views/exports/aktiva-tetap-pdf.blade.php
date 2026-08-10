<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Lampiran Aktiva Tetap</title>
    <style>
        @page { margin: 8mm 8mm 10mm 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; }
        .kop { margin-bottom: 8px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; padding: 0; }
        .kop td.logo-col { width: 50px; padding-right: 8px; }
        .kop td.logo-col img { width: 44px; height: auto; }
        .kop .company { font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .kop .title { font-size: 10px; font-weight: bold; margin-top: 2px; }
        .periode-info { font-size: 9px; padding: 3px 8px; background: #fff2cc; border: 1px solid #d4b400; font-weight: bold; }
        table.laporan { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.laporan th, table.laporan td { border: 1px solid #999; padding: 2px 3px; font-size: 7.5px; overflow: hidden; word-wrap: break-word; }
        table.laporan thead th { background: #f5f5f5; text-align: center; font-weight: bold; }
        .col-no { width: 3%; text-align: center; }
        .col-kode { width: 5%; }
        .col-nama { width: 26%; }
        .col-tgl { width: 6.5%; text-align: center; }
        .col-masa { width: 4%; text-align: center; }
        .col-tarif { width: 4%; text-align: center; }
        .col-num { width: 8.5%; text-align: right; font-family: monospace; }
        .col-num-sm { width: 5.5%; text-align: right; font-family: monospace; }
        tr.kat-header td { background: #d9edf7; font-weight: bold; font-style: italic; padding: 4px 6px; }
        tr.subtotal td { background: #e8e8e8; font-weight: bold; }
        tr.grand-total td { background: #b8d8ff; font-weight: bold; font-size: 8.5px; }
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
                <div class="title">LAMPIRAN AKTIVA TETAP</div>
            </td>
            <td style="text-align:right; width:32%;">
                <span class="periode-info">Periode: {{ \Carbon\Carbon::parse($from)->translatedFormat('d F Y') }} — {{ \Carbon\Carbon::parse($to)->translatedFormat('d F Y') }}</span>
            </td>
        </tr>
    </table>
</div>

<table class="laporan">
    <thead>
        <tr>
            <th class="col-no" rowspan="2">No</th>
            <th class="col-kode" rowspan="2">KODE</th>
            <th class="col-nama" rowspan="2">KETERANGAN</th>
            <th class="col-tgl" rowspan="2">TGL BELI</th>
            <th colspan="2">MASA / TARIF</th>
            <th class="col-num" rowspan="2">HRG BELI</th>
            <th class="col-num" rowspan="2">SUSUT AWAL</th>
            <th class="col-num" rowspan="2">BUKU AWAL</th>
            <th class="col-num-sm" rowspan="2">KURANG</th>
            <th colspan="2">PENYUSUTAN</th>
            <th class="col-num" rowspan="2">BUKU AKHIR</th>
        </tr>
        <tr>
            <th class="col-masa">MASA (Thn)</th>
            <th class="col-tarif">TARIF (%)</th>
            <th class="col-num-sm">Bulan Ini</th>
            <th class="col-num-sm">AKUMULASI</th>
        </tr>
    </thead>
    <tbody>
        @php
            $grandHp = 0; $grandSusutAwal = 0; $grandBukuAwal = 0;
            $grandKurang = 0; $grandBulanIni = 0; $grandAkum = 0; $grandBukuAkhir = 0;
        @endphp
        @foreach ($grouped as $kategori => $items)
            <tr class="kat-header">
                <td colspan="13">{{ strtoupper($kategori) }} :</td>
            </tr>
            @php
                $subHp = 0; $subSusutAwal = 0; $subBukuAwal = 0;
                $subKurang = 0; $subBulanIni = 0; $subAkum = 0; $subBukuAkhir = 0;
                $no = 0;
            @endphp
            @foreach ($items as $a)
                @php
                    $no++;
                    $hp = (float) $a->harga_perolehan;
                    $umurBulan = (int) $a->umur_ekonomis_bulan;
                    $masaThn = $umurBulan > 0 ? $umurBulan / 12 : 0;
                    $tarif = $masaThn > 0 ? (100 / $masaThn) : 0;
                    $bulanIni = ($a->metode_penyusutan === 'tidak_disusutkan' || $umurBulan <= 0)
                        ? 0
                        : ($hp - (float) $a->nilai_residu) / $umurBulan;
                    $akumTotal = (float) $a->akumulasi_penyusutan;
                    $susutAwal = max(0, $akumTotal - $bulanIni);
                    $bukuAwal = $hp - $susutAwal;
                    $kurang = 0;
                    $bukuAkhir = $hp - $susutAwal - $kurang - $bulanIni;

                    $subHp += $hp; $subSusutAwal += $susutAwal; $subBukuAwal += $bukuAwal;
                    $subKurang += $kurang; $subBulanIni += $bulanIni; $subAkum += $akumTotal;
                    $subBukuAkhir += $bukuAkhir;
                @endphp
                <tr>
                    <td class="col-no">{{ $no }}</td>
                    <td class="col-kode">{{ $a->kode ?: '' }}</td>
                    <td class="col-nama">{{ $a->nama }}</td>
                    <td class="col-tgl">{{ $a->tgl_perolehan?->format('d M Y') ?? '' }}</td>
                    <td class="col-masa">{{ $masaThn > 0 ? rtrim(rtrim(number_format($masaThn, 1, ',', ''), '0'), ',') : '-' }}</td>
                    <td class="col-tarif">{{ $tarif > 0 ? number_format($tarif, 1, ',', '') : '-' }}</td>
                    <td class="col-num">{{ number_format($hp, 0, ',', '.') }}</td>
                    <td class="col-num">{{ $susutAwal > 0 ? number_format($susutAwal, 0, ',', '.') : '-' }}</td>
                    <td class="col-num">{{ number_format($bukuAwal, 0, ',', '.') }}</td>
                    <td class="col-num-sm">{{ $kurang > 0 ? number_format($kurang, 0, ',', '.') : '-' }}</td>
                    <td class="col-num-sm">{{ $bulanIni > 0 ? number_format($bulanIni, 0, ',', '.') : '-' }}</td>
                    <td class="col-num-sm">{{ number_format($akumTotal, 0, ',', '.') }}</td>
                    <td class="col-num">{{ number_format($bukuAkhir, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td colspan="6">SUB TOTAL {{ strtoupper($kategori) }}</td>
                <td class="col-num">{{ number_format($subHp, 0, ',', '.') }}</td>
                <td class="col-num">{{ $subSusutAwal > 0 ? number_format($subSusutAwal, 0, ',', '.') : '-' }}</td>
                <td class="col-num">{{ number_format($subBukuAwal, 0, ',', '.') }}</td>
                <td class="col-num-sm">{{ $subKurang > 0 ? number_format($subKurang, 0, ',', '.') : '-' }}</td>
                <td class="col-num-sm">{{ number_format($subBulanIni, 0, ',', '.') }}</td>
                <td class="col-num-sm">{{ number_format($subAkum, 0, ',', '.') }}</td>
                <td class="col-num">{{ number_format($subBukuAkhir, 0, ',', '.') }}</td>
            </tr>
            @php
                $grandHp += $subHp; $grandSusutAwal += $subSusutAwal; $grandBukuAwal += $subBukuAwal;
                $grandKurang += $subKurang; $grandBulanIni += $subBulanIni; $grandAkum += $subAkum;
                $grandBukuAkhir += $subBukuAkhir;
            @endphp
        @endforeach
        <tr class="grand-total">
            <td colspan="6" style="text-align:right">TOTAL SELURUHNYA</td>
            <td class="col-num">{{ number_format($grandHp, 0, ',', '.') }}</td>
            <td class="col-num">{{ $grandSusutAwal > 0 ? number_format($grandSusutAwal, 0, ',', '.') : '-' }}</td>
            <td class="col-num">{{ number_format($grandBukuAwal, 0, ',', '.') }}</td>
            <td class="col-num-sm">{{ $grandKurang > 0 ? number_format($grandKurang, 0, ',', '.') : '-' }}</td>
            <td class="col-num-sm">{{ number_format($grandBulanIni, 0, ',', '.') }}</td>
            <td class="col-num-sm">{{ number_format($grandAkum, 0, ',', '.') }}</td>
            <td class="col-num">{{ number_format($grandBukuAkhir, 0, ',', '.') }}</td>
        </tr>
    </tbody>
</table>

<div class="footer-note">
    Dicetak {{ now()->translatedFormat('d F Y H:i') }} · ERP LMI
</div>

</body>
</html>
