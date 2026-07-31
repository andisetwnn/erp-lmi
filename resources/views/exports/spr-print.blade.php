@php
    $proyekCetak = $spr->rumah?->proyek;
    $logoExistsCetak = $proyekCetak?->logo && is_file(storage_path('app/public/'.$proyekCetak->logo));
    $logoUrlCetak = $logoExistsCetak ? asset('storage/'.$proyekCetak->logo) : null;
@endphp
<!DOCTYPE html>
<html lang="id" class="light" style="color-scheme: light;">
<head>
    <meta charset="UTF-8">
    <title>SPR {{ $spr->nomor_spr }}</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <script>
        // Config dark-mode Tailwind ke 'class' (bukan 'media') supaya tidak auto-follow OS.
        tailwind.config = { darkMode: 'class' };
    </script>
    <style>
        @page { size: A4; margin: 6mm; }
        html, body { margin: 0; padding: 0; background: #e5e5e5; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; color: #111; }
        html.light { color-scheme: light !important; }

        /* Netralkan semua dark: variant jaga-jaga kalau CDN belum apply config */
        .paper [class*="dark:bg-"] { background-color: revert !important; }
        .paper [class*="dark:text-"] { color: revert !important; }
        .paper [class*="dark:border-"] { border-color: revert !important; }
        /* Force main containers stay white */
        .paper .bg-white { background-color: #ffffff !important; }
        .paper .bg-zinc-50 { background-color: #fafafa !important; }
        .paper { color: #111 !important; }

        /* Toolbar sticky di layar, hilang saat print */
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #ffffff;
            border-bottom: 1px solid #ddd;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        .print-toolbar .btn {
            border: 1px solid #d4d4d4;
            background: #ffffff;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            color: #333;
        }
        .print-toolbar .btn:hover { background: #f5f5f5; }
        .print-toolbar .btn-primary {
            background: #059669;
            color: #fff;
            border-color: #059669;
        }
        .print-toolbar .btn-primary:hover { background: #047857; }

        .paper {
            width: 210mm;
            margin: 12px auto;
            padding: 0;
            background: transparent;
        }

        /* Watermark fixed overlay — di luar .paper, tidak ganggu layout SPR */
        .print-watermark {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            @if ($logoUrlCetak)
                background-image: url('{{ $logoUrlCetak }}');
            @endif
            background-repeat: repeat;
            background-size: 160px auto;
            opacity: 0.10;
            pointer-events: none;
            z-index: 9999;
            transform: rotate(-22deg) scale(1.5);
            transform-origin: center center;
        }

        @media print {
            @page { size: A4 portrait; margin: 8mm; }
            * { box-sizing: border-box !important; }
            html, body {
                background: #ffffff;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .print-toolbar { display: none !important; }
            .paper {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                /* Zoom 0.82 — mild compression, ukuran font tetap terlihat natural */
                zoom: 0.82;
            }
            .paper > div, .paper > div > div { width: 100% !important; max-width: 100% !important; }

            /* Compress vertical spacing supaya muat 1 halaman */
            .paper .px-6 { padding-left: 10px !important; padding-right: 10px !important; }
            .paper .py-5 { padding-top: 6px !important; padding-bottom: 6px !important; }
            .paper .py-3 { padding-top: 3px !important; padding-bottom: 3px !important; }
            .paper .py-2 { padding-top: 2px !important; padding-bottom: 2px !important; }
            .paper .gap-y-5 { row-gap: 6px !important; }
            .paper .mt-4 { margin-top: 6px !important; }
            .paper .mt-3 { margin-top: 4px !important; }
            .paper .mx-6 { margin-left: 10px !important; margin-right: 10px !important; }
            .paper .mb-6 { margin-bottom: 4px !important; }
            .paper .gap-3 { gap: 4px !important; }

            /* Line-height tight untuk table & list */
            .paper table td { line-height: 1.2 !important; padding-top: 2px !important; padding-bottom: 2px !important; }
            .paper ol { line-height: 1.25 !important; }
            .paper ol > li { margin-top: 2px !important; }

            /* TTD area */
            .paper .h-24 { height: 60px !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            table, .sig-block, .rounded-lg { page-break-inside: avoid; }

            /* HILANGKAN semua rounded corners & shadows — kotak persegi biasa */
            .paper [class*="rounded"],
            .paper .rounded-2xl,
            .paper .rounded-xl,
            .paper .rounded-lg,
            .paper .rounded-md,
            .paper .rounded-full,
            .paper .rounded { border-radius: 0 !important; }
            .paper [class*="shadow"] { box-shadow: none !important; }

            /* PAKSA layout 2-kolom & 3-kolom karena media queries `md:` tidak apply di print viewport */
            .paper [class*="md:grid-cols-2"] {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .paper .md\:text-right { text-align: right !important; }
            .paper .sm\:grid-cols-3 {
                display: grid !important;
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }

            /* Rapatkan spacing supaya semua muat 1 halaman */
            .paper .px-6 { padding-left: 10px !important; padding-right: 10px !important; }
            .paper .py-5 { padding-top: 5px !important; padding-bottom: 5px !important; }
            .paper .py-3 { padding-top: 3px !important; padding-bottom: 3px !important; }
            .paper .py-2 { padding-top: 2px !important; padding-bottom: 2px !important; }
            .paper .py-1 { padding-top: 1px !important; padding-bottom: 1px !important; }
            .paper .gap-y-5 { row-gap: 5px !important; }
            .paper .mt-4 { margin-top: 5px !important; }
            .paper .mt-3 { margin-top: 4px !important; }
            .paper .mx-6 { margin-left: 8px !important; margin-right: 8px !important; }
            .paper .h-24 { height: 50px !important; }
            .paper .space-y-2 > * + * { margin-top: 3px !important; }
            .paper .mx-6.mt-4.grid { margin-top: 4px !important; }
            .paper .mb-6 { margin-bottom: 4px !important; }
            .paper .gap-3 { gap: 4px !important; }

            /* Font sizes tetap natural (transform scale sudah handle sizing) */
            .paper h2, .paper h3 { line-height: 1.2 !important; }

            /* Compress line-height */
            .paper table td { line-height: 1.15 !important; padding-top: 1.5px !important; padding-bottom: 1.5px !important; }
            .paper ol { line-height: 1.2 !important; padding-left: 14px !important; }
            .paper ol > li { margin-top: 1.5px !important; }

            /* Watermark tetap muncul di print */
            .print-watermark {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                opacity: 0.08 !important;
            }
        }

        @if (($isPreview ?? false))
        /* PREVIEW mode (konsumen sebelum TTD): block print + copy */
        @media print {
            body::before {
                content: "Dokumen ini tidak dapat dicetak dalam mode baca. Silakan tanda tangan dulu.";
                display: block;
                padding: 100px 20px;
                text-align: center;
                font-size: 24px;
                font-weight: bold;
            }
            .paper, .print-toolbar, .print-watermark { display: none !important; }
        }
        .paper { user-select: none; -webkit-user-select: none; -moz-user-select: none; }
        @endif
    </style>
</head>
<body @if(($isPreview ?? false)) oncontextmenu="return false;" @endif>

@if (! ($isPreview ?? false))
    <div class="print-toolbar">
        <button type="button" class="btn" onclick="window.close()">Tutup</button>
        <button type="button" class="btn btn-primary" onclick="window.print()">Cetak</button>
    </div>
@else
    {{-- Preview mode (konsumen sebelum TTD): read-only, no print --}}
    <div class="print-toolbar">
        <span style="font-size: 12px; color: #64748b; font-weight: 600;">
            📄 Mode Baca — silakan pelajari isi dokumen di bawah. Setelah selesai, kembali ke halaman tanda tangan untuk melanjutkan.
        </span>
    </div>
@endif

{{-- Watermark logo repeating — fixed overlay, di luar .paper --}}
<div class="print-watermark" aria-hidden="true"></div>

{{-- Watermark status DRAFT kalau belum full approved (Fitur #6: Finance download sebelum materai/PM approve) --}}
@if (! $spr->pm_approved_at)
    <div style="position: fixed; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-25deg);
                font-size: 96px; font-weight: 900; color: rgba(220, 38, 38, 0.12);
                letter-spacing: 8px; z-index: 50; pointer-events: none;
                -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;"
         aria-hidden="true">
        DRAFT — BELUM BERMATERAI
    </div>
@endif

<div class="paper">
    @php
        $proyekCetak = $spr->rumah?->proyek;
        $logoExistsCetak = $proyekCetak?->logo && is_file(storage_path('app/public/'.$proyekCetak->logo));
    @endphp

    {{-- Logo proyek di pojok kanan atas (hanya di print view) --}}
    <div style="display: flex; justify-content: flex-end; padding: 0 6px 8px; align-items: center;">
        @if ($logoExistsCetak)
            <img src="{{ asset('storage/'.$proyekCetak->logo) }}"
                 alt="{{ $proyekCetak->nama_proyek }}"
                 style="max-height: 50px; max-width: 220px; object-fit: contain;">
        @elseif ($proyekCetak?->nama_proyek)
            <div style="font-size: 16px; font-weight: 800; color: #111; letter-spacing: 1px;">
                {{ strtoupper($proyekCetak->nama_proyek) }}
            </div>
        @endif
    </div>

    @include('pages::marketing._spr-document')
</div>

</body>
</html>
