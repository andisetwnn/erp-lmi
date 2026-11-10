@php
    $angka = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $rupiah = fn ($n) => number_format((float) $n, 0, ',', '.');
    $supplier = $pesanan->supplier;
    $namaPerusahaan = $perusahaan?->nama ?? 'PT Langit Membangun Indonesia';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PO {{ $pesanan->nomor }}</title>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <style>
        @page { size: A4 portrait; margin: 14mm 14mm 16mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #e5e5e5; color: #1a1a1a; font-family: Arial, 'Segoe UI', sans-serif; font-size: 10.5pt; line-height: 1.4; }
        .print-toolbar { position: sticky; top: 0; z-index: 10; display: flex; justify-content: center; gap: 10px; padding: 10px; background: #fff; border-bottom: 1px solid #ddd; }
        .print-toolbar .btn { border: 1px solid #d4d4d4; background: #fff; padding: 6px 16px; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer; color: #333; }
        .print-toolbar .btn-primary { background: #059669; color: #fff; border-color: #059669; }
        .kertas { width: 182mm; margin: 14px auto; background: #fff; padding: 12mm 12mm 14mm; }
        .kop { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; }
        .kop img { height: 46px; }
        .kop-nama { font-size: 14pt; font-weight: 700; }
        .kop-alamat { font-size: 9pt; color: #555; }
        h1 { margin: 14px 0 2px; font-size: 16pt; letter-spacing: 2px; text-align: center; }
        .nomor { text-align: center; font-family: 'Courier New', monospace; font-size: 10.5pt; }
        .dua { display: flex; gap: 18px; margin-top: 16px; }
        .dua > div { flex: 1; border: 1px solid #ccc; border-radius: 4px; padding: 8px 10px; }
        .label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .06em; color: #666; margin-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th { background: #f2f2f2; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; text-align: left; padding: 6px 8px; border: 1px solid #ccc; }
        td { padding: 6px 8px; border: 1px solid #ccc; vertical-align: top; }
        .kanan { text-align: right; white-space: nowrap; }
        .kecil { font-size: 9pt; color: #555; }
        tfoot td { border: none; padding: 3px 8px; }
        tfoot .total td { font-weight: 700; border-top: 1.5px solid #1a1a1a; }
        .ketentuan { margin-top: 16px; font-size: 9.5pt; }
        .ketentuan div { margin: 2px 0; }
        .ttd { display: flex; justify-content: space-between; margin-top: 30px; text-align: center; }
        .ttd > div { width: 45%; }
        .ttd .ruang { height: 64px; }
        .ttd .garis { border-top: 1px solid #1a1a1a; padding-top: 3px; }
        @media print {
            html, body { background: #fff; }
            .print-toolbar { display: none !important; }
            .kertas { width: auto; margin: 0; padding: 0; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>

<div class="print-toolbar">
    <button type="button" class="btn" onclick="window.close()">Tutup</button>
    <button type="button" class="btn btn-primary" onclick="window.print()">Cetak</button>
</div>

<div class="kertas">
    <div class="kop">
        <div>
            <div class="kop-nama">{{ strtoupper($namaPerusahaan) }}</div>
            @if ($perusahaan?->alamat)<div class="kop-alamat">{{ $perusahaan->alamat }}</div>@endif
            @if ($perusahaan?->no_telepon)<div class="kop-alamat">Telp. {{ $perusahaan->no_telepon }}</div>@endif
        </div>
        <img src="{{ asset('images/logo-mark.png') }}" alt="LMI">
    </div>

    <h1>PURCHASE ORDER</h1>
    <div class="nomor">No. {{ $pesanan->nomor }}</div>

    <div class="dua">
        <div>
            <div class="label">Kepada</div>
            <div><b>{{ $supplier?->nama }}</b></div>
            @if ($supplier?->alamat)<div class="kecil">{{ $supplier->alamat }}</div>@endif
            @if ($supplier?->kontak_nama || $supplier?->hp)<div class="kecil">{{ $supplier->kontak_nama }} {{ $supplier->hp }}</div>@endif
            @if ($supplier?->npwp)<div class="kecil">NPWP {{ $supplier->npwp }}</div>@endif
        </div>
        <div>
            <div class="label">Tanggal PO</div>
            <div>{{ $pesanan->tanggal->translatedFormat('d F Y') }}</div>
            <div class="label" style="margin-top:6px">Dikirim ke</div>
            <div>{{ $pesanan->permintaan?->namaLokasi() }}</div>
            @if ($pesanan->tanggal_kirim)<div class="kecil">Paling lambat {{ $pesanan->tanggal_kirim->translatedFormat('d F Y') }}</div>@endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:6%">No</th>
                <th>Barang</th>
                <th class="kanan" style="width:16%">Jumlah</th>
                <th class="kanan" style="width:17%">Harga</th>
                <th class="kanan" style="width:19%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pesanan->item as $i)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $i->nama_barang }}@if ($i->spesifikasi)<div class="kecil">{{ $i->spesifikasi }}</div>@endif</td>
                    <td class="kanan">{{ $angka($i->jumlah) }} {{ $i->satuan }}</td>
                    <td class="kanan">{{ $rupiah($i->harga) }}</td>
                    <td class="kanan">{{ $rupiah($i->subtotal()) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="4" class="kanan">Subtotal</td><td class="kanan">{{ $rupiah($pesanan->subtotal) }}</td></tr>
            @if ($pesanan->ppn_terpisah)
                <tr><td colspan="4" class="kanan">PPN {{ $angka($pesanan->ppn_persen) }}%</td><td class="kanan">{{ $rupiah($pesanan->ppn) }}</td></tr>
            @endif
            <tr class="total"><td colspan="4" class="kanan">TOTAL</td><td class="kanan">Rp {{ $rupiah($pesanan->total) }}</td></tr>
        </tfoot>
    </table>

    <div class="ketentuan">
        @if ($pesanan->syarat_bayar)<div><b>Syarat bayar:</b> {{ $pesanan->syarat_bayar }}</div>@endif
        @if (! $pesanan->ppn_terpisah)<div class="kecil">Harga sudah termasuk pajak yang berlaku.</div>@endif
        @if ($pesanan->catatan)<div><b>Catatan:</b> {{ $pesanan->catatan }}</div>@endif
        <div class="kecil">Mohon cantumkan nomor PO ini pada surat jalan dan tagihan.</div>
    </div>

    <div class="ttd">
        <div>
            <div>Hormat kami,</div>
            <div class="kecil">{{ $namaPerusahaan }}</div>
            <div class="ruang"></div>
            <div class="garis">{{ $pesanan->createdBy?->name ?? '' }}</div>
        </div>
        <div>
            <div>Disetujui,</div>
            <div class="kecil">{{ $supplier?->nama }}</div>
            <div class="ruang"></div>
            <div class="garis">&nbsp;</div>
        </div>
    </div>
</div>

</body>
</html>
