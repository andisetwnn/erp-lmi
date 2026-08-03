<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $judul }}</title>
    <style>
        @page { size: A4; margin: 15mm 12mm; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.5; }

        h1 { font-size: 20pt; margin: 0 0 4px 0; color: #2563eb; }
        h2 { font-size: 13pt; margin: 18px 0 6px 0; color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 3px; }
        h3 { font-size: 11pt; margin: 12px 0 4px 0; color: #374151; font-weight: bold; }
        p { margin: 4px 0; }

        .header { border-bottom: 3px double #2563eb; padding-bottom: 10px; margin-bottom: 15px; }
        .header .subtitle { color: #6b7280; font-size: 9pt; }
        .header .meta { color: #6b7280; font-size: 8pt; margin-top: 6px; }

        .role-badge { display: inline-block; background: #dbeafe; color: #1e40af; padding: 3px 10px; border-radius: 4px; font-size: 9pt; font-weight: bold; margin-top: 4px; }

        .callout { background: #f9fafb; border-left: 3px solid #2563eb; padding: 8px 12px; margin: 10px 0; font-size: 9.5pt; }
        .callout.warning { background: #fef3c7; border-left-color: #d97706; }
        .callout.info { background: #dbeafe; border-left-color: #2563eb; }
        .callout.success { background: #ecfdf5; border-left-color: #059669; }

        ol.steps { padding-left: 20px; margin: 6px 0; }
        ol.steps li { margin-bottom: 4px; }

        ul.check { list-style: none; padding-left: 0; margin: 4px 0; }
        ul.check li { padding-left: 16px; position: relative; margin-bottom: 3px; }
        ul.check li::before { content: "✓"; position: absolute; left: 0; color: #2563eb; font-weight: bold; }

        .menu-path { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; font-size: 9pt; color: #374151; }
        .btn-ref { display: inline-block; background: #2563eb; color: white; padding: 2px 8px; border-radius: 3px; font-size: 8.5pt; font-weight: bold; }
        .btn-ref.indigo { background: #4f46e5; }
        .btn-ref.warn { background: #d97706; }

        table.info-tbl { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 9pt; }
        table.info-tbl th, table.info-tbl td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; }
        table.info-tbl th { background: #f3f4f6; font-weight: bold; }

        .footer { text-align: center; color: #9ca3af; font-size: 8pt; margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>

    <div class="header">
        <h1>{{ $judul }}</h1>
        <div class="subtitle">Sistem ERP {{ $perusahaan?->nama ?? 'PT Langit Membangun Indonesia' }}</div>
        <span class="role-badge">Role: Admin Sales</span>
        <div class="meta">Diterbitkan: {{ $tanggalCetak->translatedFormat('d F Y') }}</div>
    </div>

    {{-- ============ SECTION 1: TANGGUNG JAWAB ============ --}}
    <h2>1. Tanggung Jawab</h2>
    <p>Sebagai <strong>Admin Sales</strong>, Anda mengelola data SPR yang diinput sales lapangan lewat DBOS, memantau status kesepakatan, dan memproses perpindahan kavling saat customer ingin pindah / tukar unit.</p>

    <h3>Hak akses menu</h3>
    <ul class="check">
        <li><strong>SPR</strong> — hub dengan sub-menu: Data SPR, Pembatalan, Pindah Kavling</li>
        <li><strong>Data SPR</strong> — daftar & detail semua SPR customer</li>
        <li><strong>Pindah Kavling</strong> — pindah unit / tukar unit antar customer</li>
        <li><strong>Laporan</strong> — statistik penjualan & stok</li>
        <li><strong>Tanda Tangan Digital</strong> — kelola TTD sendiri</li>
    </ul>

    {{-- ============ SECTION 2: CEK DATA SPR ============ --}}
    <h2>2. Cek & Kelola Data SPR</h2>
    <p>Semua SPR yang diajukan sales lapangan muncul di menu ini. Anda bisa cek detail, status, kelola bukti UTJ, dan pantau progres approval.</p>

    <ol class="steps">
        <li>Klik menu <span class="menu-path">SPR</span> di sidebar → pilih card <strong>Data SPR</strong>.</li>
        <li>Tampil tabel semua SPR. Filter tersedia:
            <ul class="check">
                <li>Status (Diproses / Selesai / Batal / Akad)</li>
                <li>Sales</li>
                <li>Rentang tanggal SPR</li>
                <li>Kategori (Subsidi / Komersial)</li>
                <li>Search nomor SPR / nama customer / blok-unit</li>
            </ul>
        </li>
        <li>Klik <strong>Nomor SPR</strong> untuk buka detail lengkap.</li>
        <li>Di halaman detail, Anda bisa lihat:
            <ul class="check">
                <li>Info customer & unit</li>
                <li>Skema pembayaran (UTJ, UM cicilan, KPR)</li>
                <li>Realisasi pembayaran (kwitansi masuk)</li>
                <li>Progres tahap (Verifikasi Keuangan → Approval PM → TTD Konsumen → Materai → Selesai)</li>
                <li>Bukti UTJ upload sales</li>
            </ul>
        </li>
    </ol>

    <h3>Ganti bukti UTJ (sales salah upload)</h3>
    <p>Kalau sales lapangan salah upload bukti transfer UTJ dan belum diverifikasi Keuangan, Anda bisa bantu re-upload:</p>
    <ol class="steps">
        <li>Buka detail SPR yang bermasalah.</li>
        <li>Cari section <strong>Bukti UTJ</strong>.</li>
        <li>Klik <span class="btn-ref warn">Ganti Bukti UTJ</span> (hanya muncul kalau belum diverifikasi Keuangan).</li>
        <li>Upload file baru (JPG/PNG/PDF, maks 10 MB).</li>
        <li>Klik <span class="btn-ref">Simpan</span>. Sistem otomatis timpa bukti lama.</li>
    </ol>

    <div class="callout warning">
        Tombol Ganti Bukti UTJ tidak muncul kalau Keuangan sudah verifikasi UTJ. Kalau perlu koreksi setelah verifikasi, hubungi Keuangan langsung.
    </div>

    {{-- ============ SECTION 3: PINDAH KAVLING ============ --}}
    <h2>3. Pindah Kavling</h2>
    <p>Fitur untuk pindahkan customer dari satu unit ke unit lain (Pindah Unit) atau tukar unit antar 2 customer (Tukar Unit). Cocok untuk kasus customer minta ganti unit tanpa harus batalkan SPR.</p>

    <h3>Syarat wajib</h3>
    <ul class="check">
        <li>SPR harus berstatus <strong>SELESAI</strong> (sudah disetujui PM + bermeterai)</li>
        <li>SPR belum akad kredit</li>
        <li>Kategori tujuan sama (subsidi ↔ subsidi, komersial ↔ komersial)</li>
        <li>Unit tujuan berstatus <strong>Tersedia</strong> & di proyek yang sama</li>
    </ul>

    <div class="callout warning">
        SPR yang masih <strong>DIPROSES</strong> (belum disetujui / belum materai) tidak bisa dipindah. Kalau customer mau ganti unit di tahap ini, batalkan SPR & buat baru manual.
    </div>

    <h3>Cara Pindah Unit (1 customer ke unit lain)</h3>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">SPR</span> → pilih card <strong>Pindah Kavling</strong>.</li>
        <li>Klik tombol <span class="btn-ref">Pindah Unit</span>.</li>
        <li>Pilih <strong>SPR yang akan dipindah</strong> dari dropdown.</li>
        <li>Pilih <strong>Unit tujuan</strong> dari dropdown (auto-filter unit tersedia di proyek & kategori yang sama).</li>
        <li>Lihat preview harga baru & selisih (kalau lebih murah → refund, lebih mahal → tambah UM).</li>
        <li>Isi <strong>Alasan Pindah</strong> (minimal 5 karakter, mis. "Customer minta view taman").</li>
        <li>Klik <span class="btn-ref">Konfirmasi Pindah</span>.</li>
    </ol>

    <h3>Cara Tukar Unit (silang antar 2 customer)</h3>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">SPR → Pindah Kavling</span>.</li>
        <li>Klik tombol <span class="btn-ref indigo">Tukar Unit</span>.</li>
        <li>Pilih <strong>SPR A</strong> — dropdown SPR aktif.</li>
        <li>Pilih <strong>SPR B</strong> — dropdown auto-filter SPR di proyek & kategori sama dengan A.</li>
        <li>Lihat preview: A pindah ke unit B, B pindah ke unit A.</li>
        <li>Isi <strong>Alasan Tukar</strong>.</li>
        <li>Klik <span class="btn-ref indigo">Konfirmasi Tukar</span>.</li>
    </ol>

    <h3>Yang otomatis diproses sistem</h3>
    <ul class="check">
        <li>SPR lama <strong>dibatalkan</strong> otomatis (status: dibatalkan, alasan: Pindah Kavling)</li>
        <li>SPR baru <strong>diterbitkan</strong> dengan nomor baru + link ke SPR lama</li>
        <li>Realisasi UTJ/UM lama <strong>berpindah</strong> ke SPR baru (kwitansi tetap tercatat)</li>
        <li>Kalau customer overpaid (unit baru lebih murah) → realisasi <strong>refund pending</strong> dibuat otomatis</li>
        <li>Kalau unit baru lebih mahal → sisa UM dibagi ke <strong>termin baru</strong></li>
        <li>Status unit lama → <strong>Tersedia</strong>, unit baru → <strong>Booking</strong> / <strong>Terjual</strong></li>
        <li>Nomor transaksi <strong>PK/YYYY/MM/XXXX</strong> diterbitkan untuk audit</li>
    </ul>

    <div class="callout info">
        Klik <strong>Nomor Transaksi</strong> (PK/...) di tabel Riwayat untuk buka halaman detail perpindahan lengkap: sisi lama, sisi baru, realisasi terpengaruh.
    </div>

    <h3>Cek riwayat pindah kavling</h3>
    <ol class="steps">
        <li>Buka menu <span class="menu-path">SPR → Pindah Kavling</span>.</li>
        <li>Scroll ke tabel <strong>Riwayat Pindah Kavling</strong>.</li>
        <li>Filter search: nomor PK / nama customer / blok-unit.</li>
        <li>Klik nomor transaksi untuk detail.</li>
    </ol>

    {{-- ============ SECTION 4: LAPORAN ============ --}}
    <h2>4. Laporan</h2>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">Laporan</span>.</li>
        <li>Tab relevan:
            <ul class="check">
                <li><strong>Penjualan</strong> — daftar SPR + status per periode</li>
                <li><strong>Stok Unit</strong> — jumlah unit Tersedia / Booking / Terjual per tipe rumah</li>
                <li><strong>Pembatalan</strong> — daftar SPR batal + refund</li>
                <li><strong>Pindah Kavling</strong> — histori perpindahan + statistik</li>
                <li><strong>Peringkat Sales</strong> — top sales</li>
            </ul>
        </li>
        <li>Filter periode + proyek + kategori sesuai kebutuhan.</li>
    </ol>

    {{-- ============ SECTION 5: FAQ ============ --}}
    <h2>5. Pertanyaan Umum</h2>

    <table class="info-tbl">
        <tr>
            <th style="width: 40%;">Pertanyaan</th>
            <th>Jawaban</th>
        </tr>
        <tr>
            <td>Dropdown SPR di Pindah Kavling kosong.</td>
            <td>Hanya SPR berstatus SELESAI (approved + bermeterai) yang muncul. Kalau semua SPR masih DIPROSES, tidak ada yang bisa dipindah.</td>
        </tr>
        <tr>
            <td>Dropdown unit tujuan kosong.</td>
            <td>Tidak ada unit tersedia di proyek & kategori yang sama. Cek menu Master → Rumah, atau tunggu unit baru dibuka.</td>
        </tr>
        <tr>
            <td>SPR lama yang sudah dipindah, apakah data kwitansi hilang?</td>
            <td>Tidak. Kwitansi tetap terlihat di detail SPR lama (badge "Riwayat · sudah dipindah") dengan banner link ke SPR baru.</td>
        </tr>
        <tr>
            <td>Customer batal, apa yang harus saya lakukan?</td>
            <td>Kalau batal beneran (bukan pindah), gunakan menu SPR → Pembatalan → Input Pembatalan. Kalau customer ganti unit, gunakan Pindah Kavling (SPR lama otomatis batal + SPR baru diterbitkan).</td>
        </tr>
        <tr>
            <td>Kesalahan input sales di DBOS gimana koreksinya?</td>
            <td>Kalau bukti UTJ salah upload & belum diverif Keuangan → Anda bisa Ganti Bukti UTJ. Kalau data customer / unit salah → hubungi Super Admin untuk edit langsung.</td>
        </tr>
    </table>

    <div class="footer">
        {{ $judul }} — {{ $perusahaan?->nama ?? 'PT Langit Membangun Indonesia' }} — Dicetak {{ $tanggalCetak->format('d/m/Y H:i') }}
    </div>

</body>
</html>
