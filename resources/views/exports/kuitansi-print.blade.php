@php
    if (! function_exists('kuitansi_terbilang')) {
        function kuitansi_terbilang($n)
        {
            $n = (int) $n;
            if ($n < 0) {
                return 'minus '.kuitansi_terbilang(abs($n));
            }
            $angka = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
            if ($n < 12) {
                return $n === 0 ? 'nol' : $angka[$n];
            }
            if ($n < 20) {
                return kuitansi_terbilang($n - 10).' belas';
            }
            if ($n < 100) {
                return kuitansi_terbilang((int) ($n / 10)).' puluh'.(($n % 10) ? ' '.kuitansi_terbilang($n % 10) : '');
            }
            if ($n < 200) {
                return 'seratus'.(($n - 100) ? ' '.kuitansi_terbilang($n - 100) : '');
            }
            if ($n < 1000) {
                return kuitansi_terbilang((int) ($n / 100)).' ratus'.(($n % 100) ? ' '.kuitansi_terbilang($n % 100) : '');
            }
            if ($n < 2000) {
                return 'seribu'.(($n - 1000) ? ' '.kuitansi_terbilang($n - 1000) : '');
            }
            if ($n < 1_000_000) {
                return kuitansi_terbilang((int) ($n / 1000)).' ribu'.(($n % 1000) ? ' '.kuitansi_terbilang($n % 1000) : '');
            }
            if ($n < 1_000_000_000) {
                return kuitansi_terbilang((int) ($n / 1_000_000)).' juta'.(($n % 1_000_000) ? ' '.kuitansi_terbilang($n % 1_000_000) : '');
            }
            if ($n < 1_000_000_000_000) {
                return kuitansi_terbilang((int) ($n / 1_000_000_000)).' milyar'.(($n % 1_000_000_000) ? ' '.kuitansi_terbilang($n % 1_000_000_000) : '');
            }

            return kuitansi_terbilang((int) ($n / 1_000_000_000_000)).' triliun'.(($n % 1_000_000_000_000) ? ' '.kuitansi_terbilang($n % 1_000_000_000_000) : '');
        }
    }

    $prospect = $spr->prospectCustomer;
    $rumah = $spr->rumah;
    $proyek = $rumah?->proyek;
    $tipe = $rumah?->tipeRumah;

    // Mengikuti kuitansi cetak Grha Aryana: "UM 5" = pembayaran UM ke-5 pada SPR ini.
    $gunaPembayaran = match ($realisasi->jenis) {
        'bf' => 'UANG TANDA JADI (UTJ)',
        'um' => 'UANG MUKA (UM) KE-'.$realisasi->urutanSejenis(),
        'sbum' => 'SBUM',
        'kpr' => 'PENCAIRAN KPR',
        default => strtoupper($realisasi->jenis),
    };

    $nominal = (float) $realisasi->jumlah;
    $terbilang = strtoupper(trim(kuitansi_terbilang((int) $nominal))).' RUPIAH';
    $tunai = $realisasi->metode === 'tunai';

    $namaPerusahaan = strtoupper($perusahaan?->nama ?? 'PT Langit Membangun Indonesia');
    $namaProyek = $proyek?->nama_proyek ?? 'Grha Aryana';
    $kota = $proyek?->kota_kabupaten;

    $logoUrl = ($proyek?->logo && file_exists(storage_path('app/public/'.$proyek->logo)))
        ? asset('storage/'.$proyek->logo)
        : null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kwitansi {{ $realisasi->nomor_kwitansi ?? $spr->nomor_display }}</title>
    <style>
        @page { size: A5 landscape; margin: 6mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #e5e5e5; color: #1a1a1a; }
        body { font-family: 'Times New Roman', Times, Georgia, serif; }

        .print-toolbar { position: sticky; top: 0; z-index: 10; display: flex; justify-content: center; gap: 10px; padding: 10px; background: #fff; border-bottom: 1px solid #ddd; font-family: 'Segoe UI', Arial, sans-serif; }
        .print-toolbar .btn { border: 1px solid #d4d4d4; background: #fff; padding: 6px 16px; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer; color: #333; }
        .print-toolbar .btn-primary { background: #059669; color: #fff; border-color: #059669; }

        .kertas { width: 198mm; margin: 14px auto; background: #fff; }

        .kuitansi { display: flex; border: 1.6px solid #222; min-height: 118mm; }

        /* Pita kiri: nama perumahan + kantor pemasaran, dibaca dari bawah ke atas */
        .pita { width: 27mm; flex: none; border-right: 1.6px solid #222; position: relative; }
        /* Diputar -90°: atas bingkai jadi kiri, jadi nama di kiri dan alamat kantor di sebelah kanannya */
        .pita-isi { position: absolute; left: 50%; top: 50%; width: 112mm; transform: translate(-50%, -50%) rotate(-90deg); display: flex; flex-direction: column; align-items: center; gap: 1.2mm; text-align: center; }
        .pita-nama { font-size: 27pt; letter-spacing: 1px; line-height: 1; white-space: nowrap; }
        .pita-kantor { font-size: 7.4pt; line-height: 1.3; }
        .pita-kantor b { display: block; font-size: 9pt; letter-spacing: .5px; }

        .isi { flex: 1; padding: 5mm 6mm 4mm 7mm; position: relative; }
        .kepala { display: flex; justify-content: space-between; align-items: flex-start; }
        .judul { font-size: 24pt; font-weight: 700; letter-spacing: 1px; line-height: 1; }
        .nomor { font-size: 17pt; margin-top: 1.5mm; }
        /* Logo kepala dicetak hitam seperti di kertas — warna hanya pada stempel di bawah */
        .logo { max-height: 15mm; max-width: 62mm; object-fit: contain; margin-top: 3mm; filter: brightness(0); opacity: .88; }
        .logo-teks { font-size: 20pt; letter-spacing: 2px; margin-top: 4mm; }

        /* Nilai isian ditulis tegak dengan tinta biru tua, seperti diisi tangan */
        .nilai { font-family: Arial, 'Segoe UI', sans-serif; font-weight: 700; color: #1c2a5e; text-transform: uppercase; }

        .baris { display: flex; align-items: flex-end; gap: 3mm; margin-top: 2.6mm; font-size: 11pt; }
        .baris .label { width: 33mm; flex: none; }
        .garis { flex: 1; border-bottom: 1px solid #222; padding: 0 2mm 0.6mm; min-height: 6mm; font-size: 10.5pt; }
        .kotak-terbilang { flex: 1; border: 1.4px solid #222; padding: 1.2mm 2.5mm; min-height: 8mm; font-size: 10.5pt; }

        .unit { display: flex; gap: 4mm; align-items: flex-end; margin-top: 3.4mm; font-size: 11pt; }
        .unit span.titik { display: inline-block; min-width: 17mm; border-bottom: 1px dotted #222; padding: 0 1.5mm 0.4mm; text-align: center; font-size: 10.5pt; }

        .tengah { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 4mm; gap: 6mm; }
        .nominal-label { font-style: italic; font-size: 11pt; margin-left: 3mm; }
        .nominal { width: 84mm; height: 10mm; border: 1.6px solid #222; transform: skewX(-14deg); margin: 0.5mm 0 0 3mm; display: flex; align-items: center; padding-left: 5mm; }
        .nominal > div { transform: skewX(14deg); font-size: 11pt; display: flex; align-items: baseline; gap: 2mm; width: 100%; }
        .nominal .nilai { font-size: 14pt; border-bottom: 1px dotted #222; flex: 1; margin-right: 6mm; padding-bottom: .3mm; text-transform: none; }

        .kotak-transfer { width: 76mm; border: 1.4px solid #222; padding: 1.5mm 3mm; font-size: 10pt; }
        .kotak-transfer .baris-transfer { display: flex; gap: 2mm; align-items: flex-end; }
        .kotak-transfer .baris-transfer + .baris-transfer { margin-top: 1mm; }
        .kotak-transfer .label { width: 31mm; flex: none; }
        .kotak-transfer .garis { font-size: 10pt; min-height: 5mm; }

        .bawah { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 3mm; gap: 6mm; }
        .info { font-size: 9.6pt; line-height: 1.3; }
        .info .catatan { font-size: 8pt; margin-top: 1.5mm; line-height: 1.25; }
        .info .catatan div { padding-left: 2.6mm; text-indent: -2.6mm; }

        .pengesahan { width: 76mm; flex: none; text-align: center; font-size: 11pt; }
        .tanggal { display: flex; gap: 1.5mm; align-items: flex-end; }
        .tanggal .garis { text-align: center; font-size: 10.5pt; min-height: 5.5mm; border-bottom-style: dotted; }
        .atas-nama { font-size: 14pt; margin-top: 2mm; }
        .stempel { display: block; margin: 1mm auto 0; max-width: 52mm; max-height: 17mm; object-fit: contain; transform: rotate(-4deg); opacity: .92; mix-blend-mode: multiply; }
        .stempel-teks { display: inline-block; margin-top: 2mm; padding: 1.5mm 4mm; border: 2px solid #2f7d32; border-radius: 3mm; color: #2f7d32; font-size: 13pt; letter-spacing: 2px; transform: rotate(-4deg); }

        @media print {
            html, body { background: #fff; }
            .print-toolbar { display: none !important; }
            .kertas { margin: 0; width: auto; }
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
    <div class="kuitansi">
        <div class="pita">
            <div class="pita-isi">
                <div class="pita-nama">{{ strtoupper($namaProyek) }}</div>
                <div class="pita-kantor">
                    <b>KANTOR PEMASARAN :</b>
                    Jampang, Kec. Gn. Sindur, Kabupaten Bogor, Jawa Barat<br>
                    Telp. 081196961402
                </div>
            </div>
        </div>

        <div class="isi">
            <div class="kepala">
                <div>
                    <div class="judul">KWITANSI</div>
                    <div class="nomor">No. : <span class="nilai" style="font-family: 'Times New Roman', serif; color: #1a1a1a;">{{ $realisasi->nomor_kwitansi ?? '—' }}</span></div>
                </div>
                @if ($logoUrl)
                    <img class="logo" src="{{ $logoUrl }}" alt="{{ $namaProyek }}">
                @else
                    <div class="logo-teks">{{ strtoupper($namaProyek) }}</div>
                @endif
            </div>

            <div class="baris">
                <div class="label">Telah diterima dari</div>
                <div class="garis nilai">{{ $prospect?->nama_lengkap ?? '—' }}</div>
            </div>
            <div class="baris">
                <div class="label">Uang Sebanyak</div>
                <div class="kotak-terbilang nilai">{{ $terbilang }}</div>
            </div>
            <div class="baris">
                <div class="label">Guna Pembayaran</div>
                <div class="garis nilai">{{ $gunaPembayaran }}</div>
            </div>

            <div class="unit">
                <span>Blok : <span class="titik nilai">{{ $rumah?->blok }}</span></span>
                <span>No : <span class="titik nilai">{{ $rumah?->nomor_unit_padded }}</span></span>
                <span>Type : <span class="titik nilai">{{ $tipe?->nama_tipe ?: $tipe?->tipe }}</span></span>
                <span>No. SPR : <span class="titik nilai">{{ $spr->nomor_display }}</span></span>
            </div>

            <div class="tengah">
                <div>
                    <div class="nominal-label">{{ strtoupper($namaProyek) }}</div>
                    <div class="nominal">
                        <div>Rp. <span class="nilai">{{ number_format($nominal, 0, ',', '.') }},-</span></div>
                    </div>
                </div>
                <div class="kotak-transfer">
                    <div class="baris-transfer">
                        <div class="label">Metode Bayar :</div>
                        <div class="garis nilai">{{ $tunai ? 'Tunai' : 'Transfer' }}</div>
                    </div>
                    <div class="baris-transfer">
                        <div class="label">{{ $tunai ? 'Tanggal Bayar' : 'Tanggal Transfer' }} :</div>
                        <div class="garis nilai">{{ $realisasi->tanggal_bayar?->format('d/m/y') }}</div>
                    </div>
                </div>
            </div>

            <div class="bawah">
                <div class="info">
                    Pembayaran dapat di Transfer melalui Bank<br>
                    A/N {{ $namaPerusahaan }}<br>
                    Bank BTN Cabang Ciputat : 000.4401.3000.09130
                    <div class="catatan">
                        <div>- Pembayaran melalui Transfer harap di Whatsapp Ke No. 0811 9696 1402 dengan mencantumkan Nama/Blok/Nomor/Type.</div>
                        <div>- Semua Pembayaran Tunai dan Transfer yang dilakukan tanpa melalui rekening atau kasir pengembang {{ $perusahaan?->nama ?? 'PT Langit Membangun Indonesia' }} adalah <b>TIDAK SAH &amp; TIDAK AKAN DIAKUI OLEH PENGEMBANG.</b></div>
                    </div>
                </div>
                <div class="pengesahan">
                    <div class="tanggal">
                        <span>{{ $kota ?: '' }},</span>
                        <div class="garis nilai" style="text-transform: none;">{{ $realisasi->tanggal_bayar?->translatedFormat('d F Y') }}</div>
                    </div>
                    <div class="atas-nama">{{ $namaProyek }}</div>
                    @if ($logoUrl)
                        <img class="stempel" src="{{ $logoUrl }}" alt="Stempel {{ $namaProyek }}">
                    @else
                        <span class="stempel-teks">{{ strtoupper($namaProyek) }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
