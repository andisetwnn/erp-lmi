@php
    use Illuminate\Support\Facades\URL;

    /** @var \App\Models\Master\RencanaAkad $rencana */

    // Nama direksi hardcoded — mereka bergantian pakai, siapa pun yang hadir.
    $namaDireksi = 'Haryanto / Julianto Boentaran';

    $totalHargaJual = $rencana->unit->sum(fn ($u) => (float) ($u->spr?->total_harga ?? 0));
    $totalKpr = $rencana->unit->sum(fn ($u) => (float) ($u->spr?->nilai_kpr ?? 0));
    $totalBiayaProses = $rencana->unit->sum(fn ($u) =>
        (float) $u->bayar_bi_notaris + (float) $u->bayar_ps4a2 + (float) $u->bayar_bphtb
    ) + $rencana->biayaSesi->sum('nominal');

    $rupiah = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $pageTitle = 'Persetujuan Rencana Akad — '.$rencana->nomor;

    // URL PDF public — signed sendiri supaya iframe tidak dilempar ke login.
    $urlPdf = URL::signedRoute(
        'public.persetujuan-direksi.pdf',
        ['rencanaId' => $rencana->id],
        now()->addDays(7)
    );

    $urlSetuju = URL::signedRoute(
        'public.persetujuan-direksi.setuju',
        ['rencanaId' => $rencana->id],
        now()->addDays(7)
    );
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $pageTitle }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css'])
    <script>
        (function () {
            document.documentElement.classList.remove('dark');
            document.documentElement.classList.add('light');
            document.documentElement.style.colorScheme = 'light';
        })();
    </script>
    <style>
        /* Fallback minimal kalau Tailwind gagal build */
        body { font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-zinc-100 via-slate-100 to-zinc-100">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">

        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-orange-600 text-white shadow-sm">
                <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-lg font-bold">PT Langit Membangun Indonesia</div>
                <div class="text-xs text-zinc-500">Persetujuan Rencana Akad</div>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                ✓ {{ session('success') }}
            </div>
        @endif
        @if (session('info'))
            <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
                ℹ {{ session('info') }}
            </div>
        @endif

        <div class="mb-4 rounded-lg border border-zinc-200 bg-white p-4">
            <div class="text-sm">Kepada Yth.</div>
            <div class="text-lg font-bold">Bapak {{ $namaDireksi }}</div>
            <div class="mt-1 text-xs text-zinc-500">Direksi PT Langit Membangun Indonesia</div>
            <div class="mt-2 text-xs text-zinc-600">
                Mohon persetujuan atas rencana akad berikut sebelum diteruskan ke bank.
            </div>
        </div>

        <div class="mb-4 overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <div class="border-b border-zinc-200 bg-orange-50 px-4 py-3">
                <div class="text-xs uppercase text-orange-700">Rencana Akad</div>
                <div class="mt-0.5 font-mono text-sm font-bold">{{ $rencana->nomor }}</div>
            </div>
            <div class="divide-y divide-zinc-200">
                <div class="grid grid-cols-3 gap-2 px-4 py-3">
                    <div class="text-xs text-zinc-500">Proyek</div>
                    <div class="col-span-2 text-sm font-semibold">{{ $rencana->proyek?->nama_proyek }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 px-4 py-3">
                    <div class="text-xs text-zinc-500">Jumlah Unit</div>
                    <div class="col-span-2 text-sm font-semibold">{{ $rencana->unit->count() }} unit</div>
                </div>
                <div class="grid grid-cols-3 gap-2 px-4 py-3">
                    <div class="text-xs text-zinc-500">Bank</div>
                    <div class="col-span-2 text-sm">{{ $rencana->bank?->nama ?? '—' }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 px-4 py-3">
                    <div class="text-xs text-zinc-500">Notaris</div>
                    <div class="col-span-2 text-sm">{{ $rencana->notaris?->nama ?? '—' }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 px-4 py-3">
                    <div class="text-xs text-zinc-500">Tanggal Akad</div>
                    <div class="col-span-2 text-sm">{{ $rencana->tanggal_rencana?->translatedFormat('l, d F Y') }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 bg-emerald-50/60 px-4 py-3">
                    <div class="text-xs text-zinc-500">Total Harga Jual</div>
                    <div class="col-span-2 text-sm font-bold text-emerald-700">{{ $rupiah($totalHargaJual) }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 px-4 py-3">
                    <div class="text-xs text-zinc-500">Total KPR</div>
                    <div class="col-span-2 text-sm">{{ $rupiah($totalKpr) }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 bg-orange-50/60 px-4 py-3">
                    <div class="text-xs text-zinc-500">Total Biaya Proses</div>
                    <div class="col-span-2 text-sm font-bold text-orange-700">{{ $rupiah($totalBiayaProses) }}</div>
                </div>
                <div class="grid grid-cols-3 gap-2 px-4 py-3">
                    <div class="text-xs text-zinc-500">Diketahui oleh</div>
                    <div class="col-span-2 text-sm">
                        {{ $rencana->diketahuiBy?->name ?? '—' }}
                        @if ($rencana->diketahui_at)
                            <div class="text-xs text-zinc-400">
                                {{ $rencana->diketahui_at->translatedFormat('d M Y, H:i') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <details class="mb-4 overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold hover:bg-zinc-50">
                📄 Lihat Lembar Aju Dana (PDF)
            </summary>
            <div class="border-t border-zinc-200">
                <iframe src="{{ $urlPdf }}" class="h-[70vh] w-full" title="Lembar Aju Dana"></iframe>
                <div class="border-t border-zinc-200 px-4 py-2 text-xs text-zinc-500">
                    Susah dibaca di HP? Buka di
                    <a class="text-orange-600 underline" target="_blank" rel="noopener" href="{{ $urlPdf }}">tab baru</a>.
                </div>
            </div>
        </details>

        @if ($sudahDitandatangani)
            <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-6 text-center">
                <div class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div class="text-lg font-bold text-emerald-900">Sudah Ditandatangani</div>
                <div class="mt-1 text-sm text-emerald-800">
                    Terima kasih. Persetujuan sudah kami terima pada
                    <strong>{{ $rencana->disetujui_at?->translatedFormat('d F Y, H:i') }}</strong>.
                </div>
                <div class="mt-4 text-xs text-emerald-700">
                    Tanda tangan Anda otomatis muncul di kotak "Disetujui" pada lembar Aju Dana.
                </div>
            </div>
        @else
            <form id="form-setuju" method="POST" action="{{ $urlSetuju }}">
                @csrf
                <input type="hidden" name="tanda_tangan" id="tanda-tangan-input">

                <div class="rounded-lg border border-zinc-200 bg-white p-4">
                    <div class="mb-3 text-sm font-semibold text-zinc-800">Tanda Tangan Anda</div>
                    <div class="relative rounded-md border-2 border-dashed border-zinc-300 bg-white touch-none">
                        <canvas id="pad" width="600" height="200"
                                class="block h-[200px] w-full cursor-crosshair rounded-md"
                                style="touch-action: none;"></canvas>
                        <div id="pad-placeholder"
                             class="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-zinc-400">
                            Gambar tanda tangan di sini dengan jari / mouse
                        </div>
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                        <button type="button" id="btn-clear"
                                class="text-xs text-zinc-500 underline hover:text-zinc-700">
                            🗑 Bersihkan
                        </button>
                        <span id="pad-ready" class="hidden text-xs text-emerald-600">✓ Siap disimpan</span>
                    </div>

                    <div class="mt-4 rounded-md bg-zinc-50 p-3 text-xs text-zinc-600">
                        Dengan menandatangani di atas, Bapak <strong>{{ $namaDireksi }}</strong> menyetujui
                        rencana akad ini untuk diteruskan ke bank. Tanda tangan digital akan otomatis
                        dicantumkan pada lembar Aju Dana.
                    </div>

                    <button type="submit" id="btn-submit" disabled
                            class="mt-4 w-full cursor-not-allowed rounded-lg bg-zinc-300 px-6 py-4 text-base font-bold text-white shadow-sm transition">
                        <span id="btn-label">✍ Setujui Rencana Akad</span>
                    </button>

                    <div class="mt-3 text-center text-[11px] text-zinc-400">
                        Aksi ini dicatat dengan alamat IP {{ request()->ip() }} dan waktu {{ now()->translatedFormat('d M Y H:i') }}.
                    </div>
                </div>
            </form>

            <script>
                (function () {
                    const canvas = document.getElementById('pad');
                    const ctx = canvas.getContext('2d');
                    const placeholder = document.getElementById('pad-placeholder');
                    const ready = document.getElementById('pad-ready');
                    const btnClear = document.getElementById('btn-clear');
                    const btnSubmit = document.getElementById('btn-submit');
                    const btnLabel = document.getElementById('btn-label');
                    const form = document.getElementById('form-setuju');
                    const input = document.getElementById('tanda-tangan-input');

                    let isEmpty = true;
                    let drawing = false;
                    let lastX = 0, lastY = 0;

                    function initCanvas() {
                        ctx.strokeStyle = '#111';
                        ctx.lineWidth = 2.5;
                        ctx.lineCap = 'round';
                        ctx.lineJoin = 'round';
                        ctx.fillStyle = '#fff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                    }

                    function getPos(e) {
                        const rect = canvas.getBoundingClientRect();
                        const scaleX = canvas.width / rect.width;
                        const scaleY = canvas.height / rect.height;
                        const p = e.touches ? e.touches[0] : e;
                        return {
                            x: (p.clientX - rect.left) * scaleX,
                            y: (p.clientY - rect.top) * scaleY,
                        };
                    }

                    function begin(e) {
                        e.preventDefault();
                        drawing = true;
                        const p = getPos(e);
                        lastX = p.x; lastY = p.y;
                        if (isEmpty) {
                            isEmpty = false;
                            placeholder.classList.add('hidden');
                            ready.classList.remove('hidden');
                            btnSubmit.disabled = false;
                            btnSubmit.classList.remove('bg-zinc-300', 'cursor-not-allowed');
                            btnSubmit.classList.add('bg-emerald-600', 'hover:bg-emerald-700', 'active:scale-[0.98]');
                        }
                    }

                    function move(e) {
                        if (!drawing) return;
                        e.preventDefault();
                        const p = getPos(e);
                        ctx.beginPath();
                        ctx.moveTo(lastX, lastY);
                        ctx.lineTo(p.x, p.y);
                        ctx.stroke();
                        lastX = p.x; lastY = p.y;
                    }

                    function end(e) {
                        if (e) e.preventDefault();
                        drawing = false;
                    }

                    canvas.addEventListener('mousedown', begin);
                    canvas.addEventListener('mousemove', move);
                    canvas.addEventListener('mouseup', end);
                    canvas.addEventListener('mouseleave', end);
                    canvas.addEventListener('touchstart', begin, { passive: false });
                    canvas.addEventListener('touchmove', move, { passive: false });
                    canvas.addEventListener('touchend', end, { passive: false });

                    btnClear.addEventListener('click', function () {
                        initCanvas();
                        isEmpty = true;
                        placeholder.classList.remove('hidden');
                        ready.classList.add('hidden');
                        btnSubmit.disabled = true;
                        btnSubmit.classList.add('bg-zinc-300', 'cursor-not-allowed');
                        btnSubmit.classList.remove('bg-emerald-600', 'hover:bg-emerald-700', 'active:scale-[0.98]');
                    });

                    form.addEventListener('submit', function (e) {
                        if (isEmpty) { e.preventDefault(); return; }
                        input.value = canvas.toDataURL('image/png');
                        btnSubmit.disabled = true;
                        btnLabel.textContent = 'Menyimpan…';
                    });

                    initCanvas();
                })();
            </script>
        @endif

        <div class="mt-6 text-center text-[11px] text-zinc-400">
            Link ini berlaku 7 hari sejak diterbitkan. Hubungi Admin KPR bila ada pertanyaan.
        </div>
    </div>
</body>
</html>
