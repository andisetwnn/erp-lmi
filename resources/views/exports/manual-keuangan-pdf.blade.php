<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $judul }}</title>
    <style>
        @page { size: A4; margin: 15mm 12mm; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.5; }

        h1 { font-size: 20pt; margin: 0 0 4px 0; color: #7c3aed; }
        h2 { font-size: 13pt; margin: 18px 0 6px 0; color: #7c3aed; border-bottom: 2px solid #7c3aed; padding-bottom: 3px; }
        h3 { font-size: 11pt; margin: 12px 0 4px 0; color: #374151; font-weight: bold; }
        p { margin: 4px 0; }

        .header { border-bottom: 3px double #7c3aed; padding-bottom: 10px; margin-bottom: 15px; }
        .header .subtitle { color: #6b7280; font-size: 9pt; }
        .header .meta { color: #6b7280; font-size: 8pt; margin-top: 6px; }

        .role-badge { display: inline-block; background: #f3e8ff; color: #6b21a8; padding: 3px 10px; border-radius: 4px; font-size: 9pt; font-weight: bold; margin-top: 4px; }

        .callout { background: #f9fafb; border-left: 3px solid #7c3aed; padding: 8px 12px; margin: 10px 0; font-size: 9.5pt; }
        .callout.warning { background: #fef3c7; border-left-color: #d97706; }
        .callout.info { background: #dbeafe; border-left-color: #2563eb; }
        .callout.danger { background: #fee2e2; border-left-color: #dc2626; }

        ol.steps { padding-left: 20px; margin: 6px 0; }
        ol.steps li { margin-bottom: 4px; }

        ul.check { list-style: none; padding-left: 0; margin: 4px 0; }
        ul.check li { padding-left: 16px; position: relative; margin-bottom: 3px; }
        ul.check li::before { content: "✓"; position: absolute; left: 0; color: #7c3aed; font-weight: bold; }

        .menu-path { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; font-size: 9pt; color: #374151; }
        .btn-ref { display: inline-block; background: #7c3aed; color: white; padding: 2px 8px; border-radius: 3px; font-size: 8.5pt; font-weight: bold; }
        .btn-ref.danger { background: #dc2626; }
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
        <span class="role-badge">Role: Keuangan</span>
        <div class="meta">Diterbitkan: {{ $tanggalCetak->translatedFormat('d F Y') }}</div>
    </div>

    {{-- ============ SECTION 1: TANGGUNG JAWAB ============ --}}
    <h2>1. Tanggung Jawab</h2>
    <p>Sebagai staf <strong>Keuangan</strong>, Anda bertanggung jawab atas seluruh aliran uang customer: verifikasi UTJ, pencatatan cicilan UM, proses pengembalian uang saat pembatalan, dan tempel e-Meterai pada SPR final.</p>

    <h3>Hak akses menu</h3>
    <ul class="check">
        <li><strong>Penerimaan Konsumen</strong> — verifikasi UTJ + input realisasi UM</li>
        <li><strong>Tempel Materai</strong> — finalisasi SPR dengan e-Meterai</li>
        <li><strong>SPR (Marketing)</strong> — lihat detail SPR + edit realisasi</li>
        <li><strong>Pembatalan SPR</strong> — proses pengembalian uang</li>
        <li><strong>Laporan</strong> — statistik keuangan (kwitansi masuk, tunggakan UM, pembatalan)</li>
        <li><strong>Notifikasi Keuangan</strong> — bell notif khusus event keuangan</li>
    </ul>

    {{-- ============ SECTION 2: VERIFIKASI UTJ ============ --}}
    <h2>2. Verifikasi UTJ (Uang Tanda Jadi)</h2>
    <p>Ketika sales input SPR baru dan customer bayar UTJ, SPR masuk antrian verifikasi Keuangan. Anda cek bukti transfer, pastikan sesuai mutasi rekening, lalu konfirmasi.</p>

    <h3>Langkah verifikasi</h3>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">Penerimaan Konsumen</span> di sidebar.</li>
        <li>Tampil daftar SPR yang menunggu verifikasi UTJ (badge kuning "MENUNGGU").</li>
        <li>Klik <strong>Nomor SPR</strong> untuk buka detail.</li>
        <li>Cek info UTJ: nominal, tanggal transfer, metode, bukti file (JPG/PDF).</li>
        <li>Bandingkan dengan mutasi rekening internal LMI.</li>
        <li>Kalau cocok, klik <span class="btn-ref">Verifikasi UTJ</span>.</li>
        <li>Kalau tidak cocok, tolak SPR dengan alasan yang jelas — sales akan re-upload bukti.</li>
    </ol>

    <div class="callout warning">
        <strong>Penting:</strong> Setelah verifikasi, kwitansi UTJ otomatis di-generate dengan nomor sequential 5 digit (mis. 00023).
    </div>

    {{-- ============ SECTION 3: INPUT REALISASI UM ============ --}}
    <h2>3. Input Realisasi Cicilan UM</h2>
    <p>Customer bayar UM secara cicilan (biasanya 4 kali). Setiap cicilan yang masuk perlu di-catat sebagai realisasi.</p>

    <ol class="steps">
        <li>Buka detail SPR customer (dari menu <span class="menu-path">SPR → Data SPR</span> atau lewat link di monitoring).</li>
        <li>Scroll ke section <strong>Realisasi Pembayaran</strong>.</li>
        <li>Klik tombol <span class="btn-ref">+ Tambah Transaksi</span>.</li>
        <li>Isi:
            <ul class="check">
                <li><strong>Tanggal Transaksi</strong> — sesuai tanggal transfer masuk</li>
                <li><strong>Jumlah</strong> — nominal yang masuk (bebas — bisa cicil sebagian atau lunas)</li>
                <li><strong>Metode</strong> — Transfer / Tunai</li>
                <li><strong>Keterangan</strong> — opsional (mis. "Transfer BCA a/n Budi Santoso")</li>
            </ul>
        </li>
        <li>Klik <span class="btn-ref">Simpan</span>. Kwitansi otomatis terbit.</li>
    </ol>

    <div class="callout info">
        Tombol <strong>Preset: Lunas Sisa</strong> otomatis isi jumlah = sisa UM. Berguna kalau customer bayar lunas sekaligus.
    </div>

    {{-- ============ SECTION 4: EDIT REALISASI ============ --}}
    <h2>4. Edit Realisasi (Koreksi Kesalahan Input)</h2>
    <p>Kalau Anda salah input nominal / tanggal, bisa dikoreksi lewat tombol pensil.</p>

    <ol class="steps">
        <li>Buka detail SPR yang bermasalah.</li>
        <li>Di section <strong>Realisasi Pembayaran → Uang Muka (Cicilan)</strong>, cari baris kwitansi yang salah.</li>
        <li>Klik ikon pensil di kanan baris.</li>
        <li>Ubah field yang salah (tanggal, jumlah, metode, keterangan).</li>
        <li>Klik <span class="btn-ref">Simpan Perubahan</span>.</li>
    </ol>

    <div class="callout warning">
        Nomor kwitansi <strong>tidak berubah</strong> setelah edit. Semua perubahan tercatat di Log Audit (nilai lama vs baru + siapa yang edit).
    </div>

    <div class="callout danger">
        Edit realisasi tidak bisa dilakukan pada SPR yang sudah dibatalkan atau dipindah kavling (data historis terkunci).
    </div>

    {{-- ============ SECTION 5: PENGEMBALIAN UANG (REFUND) ============ --}}
    <h2>5. Proses Pengembalian Uang (Refund) Pembatalan</h2>
    <p>Kalau customer batalkan SPR dan berhak refund, Anda perbarui status & jumlah pengembalian.</p>

    <ol class="steps">
        <li>Klik menu <span class="menu-path">SPR → Pembatalan</span>.</li>
        <li>Cari SPR customer di tabel (bisa search nomor / nama / blok).</li>
        <li>Klik ikon pensil di kolom Aksi kanan baris.</li>
        <li>Modal <strong>Edit Pengembalian Uang</strong> muncul. Isi:
            <ul class="check">
                <li><strong>Status Pengembalian</strong>: Menunggu / Tidak Dikembalikan / Sebagian / Penuh</li>
                <li><strong>Jumlah Dikembalikan</strong>: nominal transfer ke customer</li>
                <li><strong>Tanggal Dikembalikan</strong>: tanggal transfer ke rekening customer</li>
                <li><strong>Catatan</strong>: opsional (mis. "dipotong biaya admin Rp 2.000.000")</li>
            </ul>
        </li>
        <li>Klik <span class="btn-ref">Simpan Perubahan</span>.</li>
    </ol>

    <div class="callout info">
        <strong>Umum di lapangan:</strong> untuk subsidi biasanya dikembalikan biaya administrasi + UTJ. Untuk komersial biasanya hanya UTJ. Bisa dipotong penalti sesuai kesepakatan.
    </div>

    {{-- ============ SECTION 6: MATERAI ============ --}}
    <h2>6. Tempel e-Meterai</h2>
    <p>Tahap akhir setelah PM menyetujui SPR dan konsumen sudah TTD digital. Materai membuat SPR sah secara hukum.</p>

    <ol class="steps">
        <li>Klik menu <span class="menu-path">Tempel Materai</span>.</li>
        <li>Daftar SPR yang siap materai muncul (sudah approved PM + TTD konsumen).</li>
        <li>Klik <strong>Nomor SPR</strong>.</li>
        <li>Verifikasi identitas customer & isi dokumen.</li>
        <li>Klik <span class="btn-ref">Tempel Materai</span>. Sistem otomatis konek ke Peruri, generate hash meterai, simpan file final.</li>
        <li>Status SPR berubah jadi <strong>SELESAI</strong>. Link download dokumen bisa dikirim ke customer.</li>
    </ol>

    <div class="callout danger">
        <strong>Jangan modifikasi PDF SPR</strong> setelah materai ditempel. Hash Peruri akan invalid dan meterai jadi tidak sah.
    </div>

    {{-- ============ SECTION 7: LAPORAN KEUANGAN ============ --}}
    <h2>7. Laporan Keuangan</h2>
    <ol class="steps">
        <li>Klik menu <span class="menu-path">Laporan</span>.</li>
        <li>Pilih tab yang relevan:
            <ul class="check">
                <li><strong>Kwitansi Masuk</strong> — semua realisasi UTJ + UM cair, bisa filter periode</li>
                <li><strong>Tunggakan UM</strong> — outstanding UM per customer, aging bucket</li>
                <li><strong>Pembatalan</strong> — total batal + total refund + penalti + kembali</li>
            </ul>
        </li>
        <li>Export ke Excel/PDF kalau tersedia (tombol export di kanan atas).</li>
    </ol>

    {{-- ============ SECTION 8: FAQ ============ --}}
    <h2>8. Pertanyaan Umum</h2>

    <table class="info-tbl">
        <tr>
            <th style="width: 40%;">Pertanyaan</th>
            <th>Jawaban</th>
        </tr>
        <tr>
            <td>Tombol Tambah Transaksi tidak muncul di detail SPR.</td>
            <td>Pastikan SPR sudah berstatus Diajukan / Disetujui, dan kategori subsidi (komersial tidak input UM karena langsung ke bank).</td>
        </tr>
        <tr>
            <td>Kwitansi UM ada 2 dengan nomor sama.</td>
            <td>Seharusnya tidak terjadi — sistem pakai increment safety (locking). Kalau tetap muncul, hubungi Super Admin & lampirkan screenshot.</td>
        </tr>
        <tr>
            <td>Salah input tanggal realisasi kemarin, bisa dikoreksi?</td>
            <td>Bisa lewat tombol pensil (Edit Realisasi). Semua koreksi tercatat di log audit.</td>
        </tr>
        <tr>
            <td>SPR sudah dibatalkan tapi refund belum diproses. Bagaimana catatnya?</td>
            <td>Buka menu Pembatalan → cari SPR → klik pensil (Edit Pengembalian) → set status "Sebagian/Penuh Dikembalikan" + isi jumlah & tanggal.</td>
        </tr>
        <tr>
            <td>Bagaimana cara cek total uang masuk bulan ini?</td>
            <td>Buka Laporan → Kwitansi Masuk → filter periode "Bulan Ini". Tampil total nominal + list detail per kwitansi.</td>
        </tr>
    </table>

    <div class="footer">
        {{ $judul }} — {{ $perusahaan?->nama ?? 'PT Langit Membangun Indonesia' }} — Dicetak {{ $tanggalCetak->format('d/m/Y H:i') }}
    </div>

</body>
</html>
