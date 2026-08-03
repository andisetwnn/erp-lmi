<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $judul }}</title>
    <style>
        @page { size: A4; margin: 15mm 12mm; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.5; }

        h1 { font-size: 20pt; margin: 0 0 4px 0; color: #059669; }
        h2 { font-size: 13pt; margin: 18px 0 6px 0; color: #059669; border-bottom: 2px solid #059669; padding-bottom: 3px; }
        h3 { font-size: 11pt; margin: 12px 0 4px 0; color: #374151; font-weight: bold; }
        p { margin: 4px 0; }

        .header { border-bottom: 3px double #059669; padding-bottom: 10px; margin-bottom: 15px; }
        .header .subtitle { color: #6b7280; font-size: 9pt; }
        .header .meta { color: #6b7280; font-size: 8pt; margin-top: 6px; }

        .role-badge { display: inline-block; background: #ecfdf5; color: #065f46; padding: 3px 10px; border-radius: 4px; font-size: 9pt; font-weight: bold; margin-top: 4px; }

        .callout { background: #f9fafb; border-left: 3px solid #059669; padding: 8px 12px; margin: 10px 0; font-size: 9.5pt; }
        .callout.warning { background: #fef3c7; border-left-color: #d97706; }
        .callout.info { background: #dbeafe; border-left-color: #2563eb; }

        ol.steps { padding-left: 20px; margin: 6px 0; }
        ol.steps li { margin-bottom: 4px; }

        ul.check { list-style: none; padding-left: 0; margin: 4px 0; }
        ul.check li { padding-left: 16px; position: relative; margin-bottom: 3px; }
        ul.check li::before { content: "✓"; position: absolute; left: 0; color: #059669; font-weight: bold; }

        .menu-path { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; font-size: 9pt; color: #374151; }
        .btn-ref { display: inline-block; background: #059669; color: white; padding: 2px 8px; border-radius: 3px; font-size: 8.5pt; font-weight: bold; }
        .btn-ref.reject { background: #dc2626; }
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
        <span class="role-badge">Role: Project Manager</span>
        <div class="meta">Diterbitkan: {{ $tanggalCetak->translatedFormat('d F Y') }}</div>
    </div>

    {{-- ============ SECTION 1: TANGGUNG JAWAB ============ --}}
    <h2>1. Tanggung Jawab</h2>
    <p>Sebagai <strong>Project Manager (PM)</strong>, Anda bertanggung jawab menyetujui atau menolak Surat Pengikatan Rumah (SPR) yang diajukan oleh sales. SPR yang belum disetujui PM tidak dapat lanjut ke tahap tanda tangan konsumen dan e-Meterai.</p>

    <h3>Hak akses menu</h3>
    <ul class="check">
        <li><strong>Persetujuan SPR</strong> — setujui / tolak SPR baru</li>
        <li><strong>SPR (Marketing)</strong> — lihat semua data SPR</li>
        <li><strong>Laporan</strong> — statistik penjualan, pembatalan, pindah kavling</li>
        <li><strong>Monitoring</strong> — feed aktivitas realtime & notifikasi</li>
        <li><strong>Log Aktivitas</strong> — audit trail perubahan data</li>
        <li><strong>Tanda Tangan Digital</strong> — kelola TTD sendiri</li>
    </ul>

    {{-- ============ SECTION 2: LOGIN ============ --}}
    <h2>2. Login ke Sistem</h2>
    <ol class="steps">
        <li>Buka alamat sistem di browser (mis. <strong>https://erp.lameind.com</strong>).</li>
        <li>Masukkan <strong>Email</strong> dan <strong>Password</strong> yang diberikan admin.</li>
        <li>Klik <span class="btn-ref">Login</span>. Anda diarahkan ke halaman utama.</li>
    </ol>

    <div class="callout info">
        Kalau lupa password, hubungi Super Admin — password bisa di-reset dari menu Kelola Pengguna.
    </div>

    {{-- ============ SECTION 3: APPROVE SPR ============ --}}
    <h2>3. Menyetujui SPR</h2>
    <p>Ini adalah tugas utama Anda. SPR yang menunggu persetujuan muncul di menu Persetujuan.</p>

    <h3>Langkah menyetujui SPR</h3>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">Persetujuan → SPR</span> di sidebar kiri.</li>
        <li>Pilih tab <strong>Menunggu Persetujuan</strong> — tampil daftar SPR yang belum diproses.</li>
        <li>Klik <strong>Nomor SPR</strong> pada baris yang mau diperiksa. Halaman detail SPR terbuka.</li>
        <li>Periksa isi detail secara teliti:
            <ul class="check">
                <li>Data customer (nama, KTP, kontak)</li>
                <li>Unit yang dipilih (blok, tipe, harga)</li>
                <li>Skema pembayaran (UTJ, UM, KPR)</li>
                <li>Bukti verifikasi UTJ oleh Keuangan (checkmark hijau)</li>
            </ul>
        </li>
        <li>Kalau semua sudah benar, klik tombol <span class="btn-ref">Setujui</span> di kanan atas.</li>
        <li>Sistem otomatis catat tanggal & nama PM yang menyetujui, status berubah jadi <strong>SELESAI</strong> setelah tahap TTD konsumen & materai.</li>
    </ol>

    <h3>Langkah menolak SPR</h3>
    <ol class="steps">
        <li>Buka detail SPR yang mau ditolak.</li>
        <li>Klik <span class="btn-ref reject">Tolak</span>.</li>
        <li>Isi <strong>Alasan Penolakan</strong> (mis. dokumen tidak lengkap, harga salah, bukti UTJ meragukan).</li>
        <li>Klik <span class="btn-ref reject">Konfirmasi Tolak</span>.</li>
    </ol>

    <div class="callout warning">
        <strong>Penting:</strong> Persetujuan tidak bisa dibatalkan sembarangan. Kalau salah menyetujui, hubungi Keuangan/Super Admin untuk proses koreksi lewat alur pembatalan.
    </div>

    {{-- ============ SECTION 4: MONITORING ============ --}}
    <h2>4. Memantau Aktivitas (Monitoring)</h2>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">Monitoring</span> di sidebar.</li>
        <li>Tampil feed aktivitas realtime: SPR baru diajukan, disetujui, UTJ diverifikasi, akad kredit, dll.</li>
        <li>Filter per kategori: <strong>Penjualan</strong>, <strong>Keuangan</strong>, atau <strong>Unit</strong>.</li>
        <li>Filter per event spesifik lewat dropdown (mis. hanya "SPR Diajukan").</li>
        <li>Filter periode: 24 jam, 7 hari, 30 hari, atau semua.</li>
    </ol>

    <div class="callout info">
        Notifikasi bell di kanan atas menunjukkan ada aktivitas baru. Klik untuk buka feed monitoring.
    </div>

    {{-- ============ SECTION 5: LAPORAN ============ --}}
    <h2>5. Melihat Laporan</h2>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">Laporan</span> di sidebar.</li>
        <li>Pilih tab yang ingin dilihat:
            <ul class="check">
                <li><strong>Penjualan</strong> — daftar SPR beserta status</li>
                <li><strong>Stok Unit</strong> — jumlah unit tersedia / booking / terjual per tipe</li>
                <li><strong>Pembatalan</strong> — data batal + pengembalian uang</li>
                <li><strong>Pindah Kavling</strong> — riwayat customer pindah unit / tukar unit</li>
                <li><strong>Peringkat Sales</strong> — top sales berdasarkan nilai kontrak / jumlah SPR</li>
            </ul>
        </li>
        <li>Filter periode: Bulan Ini, 3 Bulan Terakhir, Tahun Berjalan, Semua Data, atau custom tanggal.</li>
        <li>Filter proyek: pilih di sidebar kiri (tombol proyek aktif).</li>
        <li>Filter kategori: Subsidi / Komersial / Semua.</li>
    </ol>

    {{-- ============ SECTION 6: LOG AKTIVITAS ============ --}}
    <h2>6. Melihat Log Aktivitas (Audit Trail)</h2>
    <p>Menu <span class="menu-path">Log Aktivitas</span> menyimpan rekam jejak semua perubahan data — siapa yang mengubah, kapan, dan data apa yang berubah. Berguna untuk investigasi kalau ada dispute.</p>

    <ol class="steps">
        <li>Klik menu <span class="menu-path">Log Aktivitas</span>.</li>
        <li>Filter berdasarkan user, tanggal, atau jenis aktivitas.</li>
        <li>Klik detail untuk lihat perubahan spesifik (nilai lama vs baru).</li>
    </ol>

    {{-- ============ SECTION 7: FAQ ============ --}}
    <h2>7. Pertanyaan Umum</h2>

    <table class="info-tbl">
        <tr>
            <th style="width: 40%;">Pertanyaan</th>
            <th>Jawaban</th>
        </tr>
        <tr>
            <td>SPR belum bisa disetujui, tombol tidak muncul.</td>
            <td>Pastikan UTJ sudah diverifikasi Keuangan (checkmark hijau di detail SPR). Tanpa verifikasi UTJ, PM tidak bisa approve.</td>
        </tr>
        <tr>
            <td>Kalau saya salah menyetujui SPR, gimana?</td>
            <td>Hubungi Keuangan/Super Admin. Alur koreksinya lewat pembatalan SPR (menu SPR → Pembatalan) atau Pindah Kavling kalau customer ganti unit.</td>
        </tr>
        <tr>
            <td>Bagaimana cara cek kinerja sales bulan ini?</td>
            <td>Buka Laporan → tab Peringkat Sales, pilih periode "Bulan Ini". Muncul ranking sales berdasarkan nilai kontrak.</td>
        </tr>
        <tr>
            <td>Kenapa monitoring tidak ada notifikasi baru?</td>
            <td>Sistem cek setiap 30 detik. Kalau tetap kosong, mungkin memang tidak ada aktivitas baru di periode filter.</td>
        </tr>
    </table>

    <div class="footer">
        {{ $judul }} — {{ $perusahaan?->nama ?? 'PT Langit Membangun Indonesia' }} — Dicetak {{ $tanggalCetak->format('d/m/Y H:i') }}
    </div>

</body>
</html>
