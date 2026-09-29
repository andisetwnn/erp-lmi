@php
    use Illuminate\Support\Number;

    $rupiah = fn ($v) => $v > 0 ? number_format((float) $v, 0, ',', '.') : '';
    $unit = $rencana->unit->where('status', '!=', 'batal')->values();
    $tanggalAkad = $rencana->tanggal_fix ?? $rencana->tanggal_rencana;

    // Judul mengikuti format lembar Aju Dana asli:
    //   "GRHA ARYANA TYPE ARJUNA 30/60"
    // Kita ambil tipe rumah dari unit pertama sebagai representatif (biasanya
    // satu sesi akad = satu tipe rumah).
    $tipeUtama = $unit->first()?->spr?->rumah?->tipeRumah;
    $judulProyek = strtoupper($rencana->proyek?->nama_proyek ?? '');
    $judulTipe = $tipeUtama
        ? sprintf('TYPE %s %d/%d', strtoupper($tipeUtama->nama_tipe), (int) $tipeUtama->luas_bangunan, (int) $tipeUtama->luas_tanah)
        : '';

    // Format "Kamis, 27 Agustus 2026 JAM 10.00 WIB"
    $judulTanggal = $tanggalAkad ? $tanggalAkad->translatedFormat('l, d F Y').' JAM 10.00 WIB' : '';

    $judulBankNotaris = collect([
            $rencana->bank?->nama,
            $rencana->notaris ? 'Not. '.$rencana->notaris->nama : null,
        ])->filter()->join(' - ');

    // Totals per kolom (footer tabel)
    $sumHargaJual = $sumKpr = $sumSbum = 0;
    $sumTotalUm = $sumAkumulasi = $sumTargetUm = 0;
    $sumByProses = $sumAjbNotaris = $sumBphtb = $sumPph = 0;
    foreach ($unit as $u) {
        $spr = $u->spr;
        $sumHargaJual += (float) ($spr->total_harga ?? 0);
        $sumKpr += (float) ($spr->nilai_kpr ?? 0);
        $sumSbum += (float) ($spr->sbum ?? 0);
        $sumTotalUm += (float) ($spr->um_net ?? 0);

        $umMasuk = (float) ($spr->realisasiPembayaran->where('jenis', 'um')->sum('jumlah') ?? 0);
        $sumAkumulasi += $umMasuk;
        $sumTargetUm += max(0, (float) ($spr->um_net ?? 0) - $umMasuk);

        $sumByProses += (float) $u->bayar_bi_notaris;
        // AJB NOTARIS di lembar Aju Dana = nilai akta jual beli (dasar hitung
        // pajak), BUKAN biaya. Angkanya sama dengan harga jual, ~185jt/unit.
        $sumAjbNotaris += (float) $u->nilai_ajb;
        $sumBphtb += (float) $u->bayar_bphtb;
        $sumPph += (float) $u->bayar_ps4a2;
    }

    $biaya = app(\App\Services\RencanaAkadService::class)->ringkasanBiaya($rencana);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Aju Dana — {{ $rencana->nomor }}</title>
    <style>
        /*
         * Layout meniru lembar Excel Aju Dana:
         *  - Folio/F4 landscape (355.6 x 215.9 mm) — canvas lebih lebar dari A4
         *    supaya semua kolom muat di 1 halaman tanpa wrap. Waktu print
         *    di kertas A4, tinggal pilih "Fit to page" di dialog printer.
         *  - Font 8pt untuk data, 7pt untuk header
         *  - Header bertumpuk 2 baris (parent group + sub kolom)
         *  - Angka rata kanan, teks rata kiri, blok/no/lot/lb/lt rata tengah
         *  - Footer sums bold
         *  - Kotak Biaya Proses + blok TTD di-jaga tetap 1 halaman via page-break-inside
         */
        @page { margin: 8mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 8pt;
            color: #000;
            margin: 0;
            padding: 0;
        }

        .judul {
            margin-bottom: 8px;
            text-align: center;
        }
        .judul .baris1 { font-size: 11pt; font-weight: bold; letter-spacing: 0.5px; }
        .judul .baris3 { font-size: 9pt; margin-top: 2px; }
        .judul .baris4 { font-size: 9pt; }

        table.data {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
            table-layout: auto; /* biarkan Dompdf hitung lebar kolom otomatis sesuai isi */
        }
        table.data th, table.data td {
            border: 0.75pt solid #000;
            padding: 2px 3px;
            vertical-align: middle;
        }
        table.data thead th {
            background: #f5f5f5;
            font-weight: bold;
            text-align: center;
            font-size: 7pt;
            line-height: 1.15;
            padding: 3px 2px;
        }
        table.data td.num { text-align: right; font-family: 'DejaVu Sans Mono', Courier, monospace; white-space: nowrap; }
        table.data td.center { text-align: center; white-space: nowrap; }
        table.data td.left { text-align: left; padding-left: 3px; }
        table.data td.nama { text-align: left; padding-left: 3px; font-size: 7.5pt; line-height: 1.2; }
        table.data td.sales { text-align: center; font-size: 7pt; line-height: 1.2; }
        table.data .target-um { background: #fff2a8; } /* kuning highlight seperti lembar asli */

        table.data tfoot td {
            background: #e5e5e5;
            font-weight: bold;
            border-top: 1.2pt solid #000;
        }

        .footer-wrap {
            margin-top: 6px;
            width: 100%;
            /*
             * Sengaja TIDAK page-break-inside: avoid — kalau tabel unit banyak
             * dan sisa halaman tipis, "avoid" justru dorong footer ke halaman
             * berikutnya walaupun sebenarnya cukup ruang. Biarkan flow natural.
             */
        }
        table.data {
            page-break-inside: auto; /* tabel unit boleh pecah kalau memang banyak baris */
        }
        table.data tr {
            page-break-inside: avoid; /* tapi 1 baris tidak boleh dipotong */
        }
        .footer-wrap table { width: 100%; border-collapse: collapse; }
        .footer-wrap > table > tbody > tr > td { vertical-align: top; padding: 0; }
        .col-biaya { width: 40%; }
        .col-spacer { width: 4%; } /* jarak nyata antara kotak Biaya Proses dan kotak TTD */
        .col-ttd { width: 56%; }

        .info-total {
            font-size: 7.5pt;
            font-style: italic;
            margin-bottom: 3px;
        }

        table.biaya {
            width: 100%;
            border-collapse: collapse;
            font-size: 7pt;
        }
        table.biaya td {
            border: 0.75pt solid #000;
            padding: 2px 4px;
        }
        table.biaya td.label { text-align: left; }
        table.biaya td.nom { text-align: right; font-family: 'DejaVu Sans Mono', Courier, monospace; }
        table.biaya tr.total td { font-weight: bold; background: #f5f5f5; }

        table.ttd {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
            table-layout: fixed; /* paksa lebar tiap kolom sama, tidak ikut isi cell */
        }
        table.ttd td.judul-ttd {
            text-align: center;
            font-weight: bold;
            padding: 2px 4px;
            border: 0.75pt solid #000;
            width: 33.33%;
        }
        table.ttd td.tempat {
            text-align: left;
            padding: 2px 4px;
            border: 0.75pt solid #000;
            font-style: italic;
            background: #fafafa;
        }
        table.ttd td.ttd-cell {
            height: 60px;
            padding: 4px;
            border: 0.75pt solid #000;
            vertical-align: middle;
            text-align: center;
        }
        table.ttd td.ttd-cell img {
            max-height: 55px;
            max-width: 90%;
            object-fit: contain;
        }
        table.ttd td.nama-ttd {
            text-align: center;
            padding: 3px 4px;
            border: 0.75pt solid #000;
            font-weight: normal;
            font-size: 8pt;
            line-height: 1.3;
            height: 18px;
            width: 33.33%;
            overflow: hidden; /* nama panjang tidak melebarkan cell */
            word-wrap: break-word;
        }
    </style>
</head>
<body>

    {{-- JUDUL --}}
    <div class="judul">
        <div class="baris1">RENCANA AKAD {{ $judulProyek }}</div>
        <div class="baris3">{{ $judulTanggal }}</div>
        <div class="baris4">{{ $judulBankNotaris }}</div>
    </div>

    {{-- TABEL UNIT --}}
    <table class="data">
        <thead>
            {{-- Baris 1: parent group headers --}}
            <tr>
                <th rowspan="2">NO</th>
                <th rowspan="2">NAMA</th>
                <th rowspan="2">UNIT</th>
                <th rowspan="2">SALES</th>
                <th rowspan="2">TANGGAL</th>
                <th colspan="3">TYPE</th>
                <th rowspan="2">LOT</th>
                <th rowspan="2">SUB-<br>CONT</th>
                <th rowspan="2">PROGRES<br>RUMAH (%)</th>
                <th rowspan="2">TOTAL<br>HARGA JUAL</th>
                <th rowspan="2">KPR</th>
                <th rowspan="2">SBUM</th>
                <th colspan="3">UANG MUKA</th>
                <th colspan="1">LEGAL</th>
                <th rowspan="2">BY PROSES<br>AKAD</th>
                <th rowspan="2">AJB<br>NOTARIS</th>
                <th rowspan="2">BPHTB</th>
                <th rowspan="2">PPH 4 (2)</th>
                <th rowspan="2">KET</th>
            </tr>
            {{-- Baris 2: sub-kolom di bawah parent --}}
            <tr>
                <th>LB</th>
                <th>LT</th>
                <th>TYPE</th>
                <th>TOTAL UM</th>
                <th>AKUMULASI<br>MASUK</th>
                <th>TARGET<br>MASUK UM</th>
                <th>HGB</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($unit as $i => $u)
                @php
                    $spr = $u->spr;
                    $rumah = $spr?->rumah;
                    $tipe = $rumah?->tipeRumah;
                    $prospect = $spr?->prospectCustomer;

                    $umMasuk = (float) ($spr?->realisasiPembayaran->where('jenis', 'um')->sum('jumlah') ?? 0);
                    $targetUm = max(0, (float) ($spr?->um_net ?? 0) - $umMasuk);
                @endphp
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td class="nama">{{ strtoupper($prospect?->nama_lengkap ?? '') }}</td>
                    <td class="center">{{ $rumah?->blok }}-{{ $rumah?->nomor_unit }}</td>
                    <td class="sales">{{ strtoupper($spr?->sales?->nama ?? '') }}</td>
                    <td class="center">{{ $spr?->tanggal_spr?->format('d/m/y') ?? '' }}</td>
                    <td class="center">{{ (int) ($tipe?->luas_bangunan ?? 0) ?: '' }}</td>
                    <td class="center">{{ (int) ($tipe?->luas_tanah ?? 0) ?: '' }}</td>
                    <td class="center">{{ strtoupper($tipe?->nama_tipe ?? '') }}</td>
                    <td class="center">{{ $rumah?->lot ?? '' }}</td>
                    <td class="center">{{ strtoupper($rumah?->subcon?->nama ?? '') }}</td>
                    <td class="center">{{ $rumah?->progres_fisik !== null ? number_format((float) $rumah->progres_fisik, 2, ',', '.').'%' : '' }}</td>
                    <td class="num">{{ $rupiah($spr?->total_harga) }}</td>
                    <td class="num">{{ $rupiah($spr?->nilai_kpr) }}</td>
                    <td class="num">{{ $rupiah($spr?->sbum) }}</td>
                    <td class="num">{{ $rupiah($spr?->um_net) }}</td>
                    <td class="num">{{ $rupiah($umMasuk) }}</td>
                    <td class="num {{ $targetUm > 0 ? 'target-um' : '' }}">{{ $rupiah($targetUm) }}</td>
                    <td class="center">{{ $u->hgb }}</td>
                    <td class="num">{{ $rupiah($u->bayar_bi_notaris) }}</td>
                    <td class="num">{{ $rupiah($u->nilai_ajb) }}</td>
                    <td class="num">{{ $rupiah($u->bayar_bphtb) }}</td>
                    <td class="num">{{ $rupiah($u->bayar_ps4a2) }}</td>
                    <td class="center">{{ $u->catatan ?? '' }}</td>
                </tr>
            @endforeach

            {{--
                Tidak ada baris kosong penyangga. Lembar Aju Dana asli punya 4 baris kosong
                sebelum footer karena format Excel tetap, tapi di PDF justru bikin tinggi
                tabel bengkak dan mendorong footer ke halaman 2 tanpa perlu.
            --}}
        </tbody>
        <tfoot>
            <tr>
                {{-- Sums --}}
                <td colspan="11" class="center">JUMLAH</td>
                <td class="num">{{ $rupiah($sumHargaJual) }}</td>
                <td class="num">{{ $rupiah($sumKpr) }}</td>
                <td class="num">{{ $rupiah($sumSbum) }}</td>
                <td class="num">{{ $rupiah($sumTotalUm) }}</td>
                <td class="num">{{ $rupiah($sumAkumulasi) }}</td>
                <td class="num {{ $sumTargetUm > 0 ? 'target-um' : '' }}">{{ $rupiah($sumTargetUm) }}</td>
                <td></td>
                <td class="num">{{ $rupiah($sumByProses) }}</td>
                <td class="num">{{ $rupiah($sumAjbNotaris) }}</td>
                <td class="num">{{ $rupiah($sumBphtb) }}</td>
                <td class="num">{{ $rupiah($sumPph) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    {{-- FOOTER: KOTAK BIAYA + TTD --}}
    <div class="footer-wrap">
        <div class="info-total">
            Rencana Akad = {{ $unit->count() }} UNIT
        </div>

        <table>
            <tr>
                {{-- Kolom kiri: kotak Biaya Proses --}}
                <td class="col-biaya">
                    <table class="biaya">
                        @foreach ($biaya['baris'] as $b)
                            <tr>
                                <td class="label">{{ $b['nama'] }}</td>
                                <td class="nom">{{ $b['nominal'] > 0 ? number_format($b['nominal'], 0, ',', '.') : '-' }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td class="label">Jumlah</td>
                            <td class="nom">{{ number_format($biaya['jumlah'], 0, ',', '.') }}</td>
                        </tr>
                    </table>
                </td>

                {{-- Spacer eksplisit — padding-right kadang tidak dihitung Dompdf saat tabel penuh --}}
                <td class="col-spacer">&nbsp;</td>

                {{-- Kolom kanan: blok tanda tangan 3 kolom --}}
                <td class="col-ttd">
                    <table class="ttd">
                        <tr>
                            <td class="tempat" colspan="3">
                                {{--
                                    Tempat TTD = kota kantor LMI (bukan lokasi proyek).
                                    Ambil dari master.perusahaan.alamat kalau ada, fallback
                                    ke "Bogor" (kantor pusat LMI). Kalau nanti mau lebih
                                    fleksibel, tambah kolom kota di master perusahaan atau
                                    field tempat_ttd per rencana.
                                --}}
                                @php
                                    $tempatTtd = trim((string) ($perusahaan?->alamat ?? '')) !== ''
                                        ? $perusahaan->alamat
                                        : 'Bogor';
                                @endphp
                                {{ $tempatTtd }},
                                {{ ($rencana->created_at ?? now())->translatedFormat('d F Y') }}
                            </td>
                        </tr>
                        <tr>
                            <td class="judul-ttd">Dibuat</td>
                            <td class="judul-ttd">Mengetahui</td>
                            <td class="judul-ttd">Disetujui</td>
                        </tr>
                        <tr>
                            @php
                                // Helper: cari path file tanda tangan user dari relasi.
                                // Kembalikan absolute path yang bisa dibaca DomPDF via file_get_contents.
                                $ttdPath = function ($user) {
                                    if (! $user?->tanda_tangan_path) {
                                        return null;
                                    }
                                    $abs = storage_path('app/public/'.$user->tanda_tangan_path);
                                    return is_file($abs) ? $abs : null;
                                };
                                $ttdDibuat = $ttdPath($rencana->createdBy);
                                // "Mengetahui" = Project Manager yang menyetujui via halaman approval.
                                // Fallback ke pengaju (Admin KPR) kalau kolom 'diketahui_by' belum
                                // terisi — supaya PDF cetakan lama tetap terlihat wajar.
                                $ttdMengetahui = $ttdPath($rencana->diketahuiBy ?? $rencana->diajukanBy);
                                // "Disetujui" = Direksi via magic link. TTD digambar di layar
                                // dan disimpan di kolom `direksi_ttd_path` (relatif ke disk
                                // 'public'). Nama-nya di-hardcode di bawah karena Direksi
                                // pakai nama gabungan "Haryanto / Julianto Boentaran".
                                $ttdDireksiRelPath = $rencana->direksi_ttd_path;
                                $ttdDisetujui = null;
                                if ($ttdDireksiRelPath) {
                                    $abs = storage_path('app/public/'.ltrim($ttdDireksiRelPath, '/'));
                                    $ttdDisetujui = is_file($abs) ? $abs : null;
                                }
                                // Fallback lama (user login manual sebagai direksi) — tetap
                                // dipakai supaya rencana yang di-Fix sebelum fitur link ada
                                // tidak kehilangan TTD-nya.
                                $ttdDisetujui = $ttdDisetujui ?: $ttdPath($rencana->disetujuiBy ?? $rencana->fixBy);
                            @endphp
                            <td class="ttd-cell">
                                @if ($ttdDibuat)
                                    <img src="{{ $ttdDibuat }}" alt="ttd">
                                @endif
                            </td>
                            <td class="ttd-cell">
                                @if ($ttdMengetahui)
                                    <img src="{{ $ttdMengetahui }}" alt="ttd">
                                @endif
                            </td>
                            <td class="ttd-cell">
                                @if ($ttdDisetujui)
                                    <img src="{{ $ttdDisetujui }}" alt="ttd">
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="nama-ttd">{{ $rencana->createdBy?->name ?? '(...................)' }}</td>
                            <td class="nama-ttd">{{ $rencana->diketahuiBy?->name ?? $rencana->diajukanBy?->name ?? '(...................)' }}</td>
                            <td class="nama-ttd">
                                Haryanto / Julianto Boentaran
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
