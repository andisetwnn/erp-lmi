<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cara Kerja'), Layout('layouts.dbos')] class extends Component {
    //
}; ?>

<section class="px-4 pb-24 pt-5">

    {{-- HEADER --}}
    <div class="mb-5 flex items-center gap-3">
        <a href="{{ route('dbos.sales-home') }}" wire:navigate
           class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <div>
            <h1 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Cara Kerja') }}</h1>
            <p class="text-xs text-zinc-500">{{ __('Panduan alur kerja sales dari awal sampai akad') }}</p>
        </div>
    </div>

    {{-- HERO BANNER --}}
    <div class="mb-5 overflow-hidden rounded-2xl bg-linear-to-br from-orange-500 via-amber-500 to-yellow-400 p-5 text-white shadow-lg">
        <div class="flex items-start gap-3">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/20 backdrop-blur-sm ring-2 ring-white/30">
                <flux:icon.map class="size-6" />
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-bold leading-tight">{{ __('Peta Perjalanan Sales') }}</h2>
                <p class="mt-1 text-xs opacity-90">{{ __('11 langkah dari perkenalan calon customer sampai SPR final ber-materai. Ikuti secara berurutan, tidak boleh dilewati.') }}</p>
            </div>
        </div>
    </div>

    {{-- ============== TIMELINE STEPS ============== --}}
    @php
        $steps = [
            [
                'num' => 1,
                'title' => 'Tambah Prospect',
                'desc' => 'Daftarkan calon customer di menu Database. Isi minimal nama, HP, dan NIK. Foto KTP dapat diunggah jika sudah tersedia.',
                'icon' => 'user-plus',
                'color' => 'cyan',
                'tips' => 'Semakin lengkap data awal, semakin cepat proses selanjutnya. OCR KTP dapat mengisi data otomatis.',
                'menu' => 'Database → +',
            ],
            [
                'num' => 2,
                'title' => 'Follow Up & Update Status',
                'desc' => 'Hubungi customer, tanyakan kebutuhan. Perbarui status: Cold (baru mengenal) → Warm (sudah tertarik) → Hot (siap mengambil keputusan).',
                'icon' => 'phone',
                'color' => 'blue',
                'tips' => 'Follow up dalam 24 jam pertama meningkatkan closing rate hingga 4 kali lipat. Perbarui setiap ada interaksi baru.',
                'menu' => 'Database → pilih prospect → Update Status',
            ],
            [
                'num' => 3,
                'title' => 'Lengkapi Data Customer',
                'desc' => 'Lengkapi biodata KTP, pekerjaan, penghasilan, bank & rekening, dan kontak darurat. Termasuk juga BI Checking (SLIK OJK) untuk memeriksa kelayakan KPR — input KOL dan DBR.',
                'icon' => 'clipboard-document-list',
                'color' => 'indigo',
                'tips' => 'Semakin lengkap data → SPR lebih cepat disetujui. Untuk BI Checking: KOL 1 = Lancar, KOL 5 = Macet. DBR maksimal 40% agar aman disetujui bank.',
                'menu' => 'Database → prospect → Edit / BI Checking',
            ],
            [
                'num' => 4,
                'title' => 'Status FINISH',
                'desc' => 'Jika BI Checking baik dan customer siap booking, ubah status menjadi FINISH. Customer sudah memenuhi syarat untuk booking unit.',
                'icon' => 'check-badge',
                'color' => 'emerald',
                'tips' => 'Hanya prospect FINISH yang dapat di-booking. Jika belum siap, biarkan status pada HOT.',
                'menu' => 'Database → prospect → Update Status → FINISH',
            ],
            [
                'num' => 5,
                'title' => 'Booking Unit',
                'desc' => 'Pilih proyek → blok → unit yang akan di-booking. Pilih prospect yang sudah FINISH.',
                'icon' => 'home-modern',
                'color' => 'orange',
                'tips' => 'JANGAN booking sebelum customer transfer UTJ. Setelah booking, timer 24 jam mulai berjalan — jika terlewat, unit akan dilepas dan Anda terkena cooldown 48 jam.',
                'menu' => 'Booking → + Booking Baru',
            ],
            [
                'num' => 6,
                'title' => 'Buat SPR + Upload Bukti UTJ',
                'desc' => 'Isi form SPR (Surat Pemesanan Rumah). Upload bukti transfer UTJ (Uang Tanda Jadi) dari customer. Harus dilakukan pada hari yang sama dengan booking.',
                'icon' => 'document-arrow-up',
                'color' => 'purple',
                'tips' => 'Bukti transfer wajib jelas (nominal & tanggal terlihat). Format JPG/PNG/PDF. Jangan menunda — masih berada dalam periode 24 jam booking.',
                'menu' => 'Booking → pilih → Buat SPR',
            ],
            [
                'num' => 7,
                'title' => 'Menunggu Verifikasi UTJ (Keuangan)',
                'desc' => 'SPR masuk antrian Keuangan. Bukti transfer akan diperiksa dengan mutasi rekening. Jika cocok → SPR berpindah ke tahap approval PM.',
                'icon' => 'clock',
                'color' => 'amber',
                'tips' => 'Umumnya kurang dari 1 jam kerja. Jika lebih dari 1 hari, periksa kembali bukti transfer atau tanyakan langsung ke Keuangan.',
                'menu' => 'SPR → tab Diproses (cek indikator Tahapan)',
            ],
            [
                'num' => 8,
                'title' => 'Menunggu Approval Project Manager',
                'desc' => 'Setelah UTJ diverifikasi, SPR diteruskan ke Project Manager untuk menyetujui isi kontrak. PM memeriksa harga, unit, dan skema pembayaran.',
                'icon' => 'shield-check',
                'color' => 'indigo',
                'tips' => 'Anda tidak perlu melakukan apa-apa di tahap ini — cukup tunggu. Setelah PM approve, Anda dapat membuat link tanda tangan digital untuk konsumen.',
                'menu' => 'SPR → detail (indikator Tahapan menunjukkan Approval PM aktif)',
            ],
            [
                'num' => 9,
                'title' => 'Kirim Link Tanda Tangan ke Konsumen',
                'desc' => 'Setelah PM approve, buka detail SPR → generate link tanda tangan digital → share ke konsumen via WhatsApp. Konsumen akan verifikasi NIK dan tanda tangan langsung di HP.',
                'icon' => 'document-arrow-up',
                'color' => 'blue',
                'tips' => 'Link berlaku 1 hari (24 jam). Jika expired, dapat digenerate ulang. Konsumen wajib input NIK yang cocok dengan data SPR sebelum bisa menandatangani.',
                'menu' => 'SPR → detail → Buat Link TTD Konsumen → Kirim WA',
            ],
            [
                'num' => 10,
                'title' => 'Menunggu e-Materai (Keuangan)',
                'desc' => 'Setelah konsumen selesai tanda tangan, Keuangan akan menempel e-Materai pada dokumen SPR final. Ini langkah terakhir untuk pengesahan.',
                'icon' => 'clock',
                'color' => 'purple',
                'tips' => 'Menunggu proses di sistem Peruri. Tidak ada action dari Anda — cukup pantau status di detail SPR.',
                'menu' => 'SPR → detail (indikator Tahapan e-Materai aktif)',
            ],
            [
                'num' => 11,
                'title' => 'SPR Selesai — Kirim Salinan ke Konsumen 🎉',
                'desc' => 'SPR final dan sah bermaterai. Generate link download PDF final untuk konsumen agar mereka dapat mengunduh salinan resmi untuk arsip.',
                'icon' => 'trophy',
                'color' => 'green',
                'tips' => 'Link download berlaku 7 hari. Konsumen juga wajib verifikasi NIK sebelum bisa unduh. Setelah ini, pantau pembayaran UM sampai lunas dan lanjut ke proses KPR/Akad.',
                'menu' => 'SPR → detail → Buat Link Download Konsumen → Kirim WA',
            ],
        ];

        $colorMap = [
            'cyan' => ['circle' => 'bg-cyan-500', 'ring' => 'ring-cyan-100 dark:ring-cyan-950/50', 'bg' => 'bg-cyan-50 dark:bg-cyan-950/20', 'text' => 'text-cyan-700 dark:text-cyan-400', 'border' => 'border-cyan-200 dark:border-cyan-900/50'],
            'blue' => ['circle' => 'bg-blue-500', 'ring' => 'ring-blue-100 dark:ring-blue-950/50', 'bg' => 'bg-blue-50 dark:bg-blue-950/20', 'text' => 'text-blue-700 dark:text-blue-400', 'border' => 'border-blue-200 dark:border-blue-900/50'],
            'indigo' => ['circle' => 'bg-indigo-500', 'ring' => 'ring-indigo-100 dark:ring-indigo-950/50', 'bg' => 'bg-indigo-50 dark:bg-indigo-950/20', 'text' => 'text-indigo-700 dark:text-indigo-400', 'border' => 'border-indigo-200 dark:border-indigo-900/50'],
            'emerald' => ['circle' => 'bg-emerald-500', 'ring' => 'ring-emerald-100 dark:ring-emerald-950/50', 'bg' => 'bg-emerald-50 dark:bg-emerald-950/20', 'text' => 'text-emerald-700 dark:text-emerald-400', 'border' => 'border-emerald-200 dark:border-emerald-900/50'],
            'orange' => ['circle' => 'bg-orange-500', 'ring' => 'ring-orange-100 dark:ring-orange-950/50', 'bg' => 'bg-orange-50 dark:bg-orange-950/20', 'text' => 'text-orange-700 dark:text-orange-400', 'border' => 'border-orange-200 dark:border-orange-900/50'],
            'purple' => ['circle' => 'bg-purple-500', 'ring' => 'ring-purple-100 dark:ring-purple-950/50', 'bg' => 'bg-purple-50 dark:bg-purple-950/20', 'text' => 'text-purple-700 dark:text-purple-400', 'border' => 'border-purple-200 dark:border-purple-900/50'],
            'amber' => ['circle' => 'bg-amber-500', 'ring' => 'ring-amber-100 dark:ring-amber-950/50', 'bg' => 'bg-amber-50 dark:bg-amber-950/20', 'text' => 'text-amber-700 dark:text-amber-400', 'border' => 'border-amber-200 dark:border-amber-900/50'],
            'green' => ['circle' => 'bg-green-500', 'ring' => 'ring-green-100 dark:ring-green-950/50', 'bg' => 'bg-green-50 dark:bg-green-950/20', 'text' => 'text-green-700 dark:text-green-400', 'border' => 'border-green-200 dark:border-green-900/50'],
        ];
    @endphp

    <div class="mb-6 space-y-3">
        <div class="mb-2 flex items-center gap-2 px-1">
            <flux:icon.map class="size-4 text-zinc-500" />
            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">{{ __('Langkah demi Langkah') }}</span>
        </div>

        @foreach ($steps as $i => $step)
            {{-- ============== STOP DULU CALLOUT + OPEN ZONA 24 JAM ============== --}}
            @if ($step['num'] === 5)
                <div class="my-1 overflow-hidden rounded-2xl border-2 border-rose-400 bg-linear-to-br from-rose-500 to-rose-600 p-5 text-white shadow-lg">
                    <div class="flex items-start gap-3">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/20 backdrop-blur-sm ring-2 ring-white/40">
                            <flux:icon.hand-raised class="size-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-bold leading-tight">{{ __('PERHATIAN — Pastikan customer sudah transfer UTJ') }}</h3>
                            <p class="mt-1.5 text-xs leading-relaxed opacity-95">
                                {{ __('Setelah booking, timer 24 jam mulai berjalan. Jika customer belum transfer atau bukti belum dapat di-upload dalam 24 jam → unit akan dilepas dan Anda terkena cooldown 48 jam.') }}
                            </p>
                            <div class="mt-3 rounded-lg bg-white/15 px-3 py-2 backdrop-blur-sm">
                                <div class="text-[10px] font-bold uppercase tracking-wider opacity-90">{{ __('Alur cepat lapangan') }}</div>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs font-bold">
                                    <span>UTJ ditransfer</span>
                                    <flux:icon.arrow-right class="size-3.5" />
                                    <span>Booking</span>
                                    <flux:icon.arrow-right class="size-3.5" />
                                    <span>SPR + upload bukti</span>
                                </div>
                                <div class="mt-1 text-[10px] opacity-90">{{ __('Selesaikan pada hari yang sama.') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Open Zona 24 Jam wrapper --}}
                <div class="rounded-2xl border-2 border-dashed border-rose-300 bg-rose-50/50 p-3 dark:border-rose-900/50 dark:bg-rose-950/10">
                    <div class="mb-3 flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <flux:icon.clock class="size-4 text-rose-600 dark:text-rose-400" />
                            <span class="text-[11px] font-bold uppercase tracking-wider text-rose-700 dark:text-rose-400">
                                {{ __('Zona 24 Jam — Segera!') }}
                            </span>
                        </div>
                        <span class="rounded-full bg-rose-600 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-white">
                            {{ __('Kritis') }}
                        </span>
                    </div>
                    <div class="space-y-3">
            @endif

            @php
                $c = $colorMap[$step['color']];
                $isLast = $i === count($steps) - 1;
                // Sembunyikan connector di step 4 (di-replace STOP callout) & step 6 (di-tutup wrapper)
                $skipConnector = in_array($step['num'], [4, 6]);
            @endphp

            <div class="relative">
                @if (! $isLast && ! $skipConnector)
                    <div class="absolute left-6 top-14 h-full w-0.5 bg-linear-to-b from-{{ $step['color'] }}-300 to-{{ $steps[$i+1]['color'] }}-300 dark:from-{{ $step['color'] }}-800 dark:to-{{ $steps[$i+1]['color'] }}-800"></div>
                @endif

                <div class="flex items-start gap-3">
                    <div class="relative h-12 w-12 shrink-0">
                        <div @class(['flex h-12 w-12 items-center justify-center rounded-full text-white shadow-md ring-4', $c['circle'], $c['ring']])>
                            @switch($step['icon'])
                                @case('user-plus') <flux:icon.user-plus class="size-5" /> @break
                                @case('phone') <flux:icon.phone class="size-5" /> @break
                                @case('clipboard-document-list') <flux:icon.clipboard-document-list class="size-5" /> @break
                                @case('shield-check') <flux:icon.shield-check class="size-5" /> @break
                                @case('check-badge') <flux:icon.check-badge class="size-5" /> @break
                                @case('home-modern') <flux:icon.home-modern class="size-5" /> @break
                                @case('document-arrow-up') <flux:icon.document-arrow-up class="size-5" /> @break
                                @case('clock') <flux:icon.clock class="size-5" /> @break
                                @case('trophy') <flux:icon.trophy class="size-5" /> @break
                            @endswitch
                        </div>
                        <div class="absolute -bottom-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full bg-white text-[11px] font-bold text-zinc-700 shadow ring-2 ring-white dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-900">
                            {{ $step['num'] }}
                        </div>
                    </div>

                    <div @class(['flex-1 rounded-2xl border p-4 shadow-sm', $c['bg'], $c['border']])>
                        <div class="flex items-start justify-between gap-2">
                            <h3 @class(['text-sm font-bold', $c['text']])>{{ $step['title'] }}</h3>
                        </div>
                        <p class="mt-1 text-xs leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $step['desc'] }}</p>

                        <div class="mt-2.5 flex items-center gap-1.5 text-[10px]">
                            <flux:icon.map-pin class="size-3 text-zinc-400" />
                            <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $step['menu'] }}</span>
                        </div>

                        <div class="mt-2.5 flex items-start gap-1.5 rounded-lg bg-white/60 p-2 dark:bg-zinc-900/40">
                            <flux:icon.light-bulb class="mt-0.5 size-3 shrink-0 text-amber-500" />
                            <p class="text-[11px] leading-snug text-zinc-600 dark:text-zinc-400">{{ $step['tips'] }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============== CLOSE ZONA 24 JAM WRAPPER ============== --}}
            @if ($step['num'] === 6)
                    </div>
                    <div class="mt-3 flex items-center gap-2 rounded-lg bg-rose-100 px-3 py-2 text-[11px] font-semibold text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                        <flux:icon.exclamation-triangle class="size-3.5 shrink-0" />
                        <span>{{ __('Melewati 24 jam → unit otomatis dilepas dan Anda terkena cooldown 48 jam untuk unit ini.') }}</span>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    {{-- ============== ATURAN PENTING ============== --}}
    <div class="mb-5">
        <div class="mb-3 flex items-center gap-2 px-1">
            <flux:icon.exclamation-triangle class="size-4 text-rose-500" />
            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">{{ __('Aturan Penting') }}</span>
        </div>

        @php
            $rules = [
                ['icon' => 'clock', 'title' => 'Booking 24 jam', 'desc' => 'Setelah booking, waktu berjalan 24 jam kalender (bukan hari kerja). Jika terlewat, unit otomatis dilepas dan dapat di-booking sales lain.'],
                ['icon' => 'no-symbol', 'title' => 'Cooldown 48 jam', 'desc' => 'Jika Anda membatalkan atau membiarkan booking expired pada unit yang sama, Anda tidak dapat booking ulang unit tersebut selama 48 jam.'],
                ['icon' => 'lock-closed', 'title' => 'Hanya Prospect FINISH', 'desc' => 'Hanya prospect status FINISH yang dapat di-booking. HOT/WARM/COLD → follow up terlebih dahulu sampai FINISH.'],
                ['icon' => 'document-check', 'title' => 'SPR = Komitmen', 'desc' => 'SPR yang sudah disubmit hanya dapat dibatalkan melalui admin. Pastikan customer berkomitmen sebelum submit SPR.'],
                ['icon' => 'currency-dollar', 'title' => 'Bukti UTJ Wajib Jelas', 'desc' => 'Foto/PDF bukti transfer wajib jelas nominal & tanggal. Jika tidak jelas, Keuangan akan menolak dan meminta upload ulang.'],
                ['icon' => 'clock', 'title' => 'Link TTD Berlaku 1 Hari', 'desc' => 'Link tanda tangan konsumen kadaluwarsa dalam 24 jam. Jika expired, generate ulang. Konsumen wajib input NIK yang cocok dengan data SPR sebelum bisa TTD.'],
                ['icon' => 'document-check', 'title' => 'e-Materai Paling Akhir', 'desc' => 'e-Materai ditempel Keuangan SETELAH semua tanda tangan lengkap (Sales, Keuangan, PM, Konsumen). Tidak boleh dibubuhkan lebih awal karena akan invalid.'],
            ];
        @endphp

        <div class="space-y-2">
            @foreach ($rules as $rule)
                <div class="flex gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3 dark:border-rose-900/50 dark:bg-rose-950/20">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-500 text-white shadow-sm">
                        @switch($rule['icon'])
                            @case('clock') <flux:icon.clock class="size-4" /> @break
                            @case('no-symbol') <flux:icon.no-symbol class="size-4" /> @break
                            @case('lock-closed') <flux:icon.lock-closed class="size-4" /> @break
                            @case('document-check') <flux:icon.document-check class="size-4" /> @break
                            @case('currency-dollar') <flux:icon.currency-dollar class="size-4" /> @break
                        @endswitch
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-bold text-rose-900 dark:text-rose-200">{{ $rule['title'] }}</div>
                        <p class="mt-0.5 text-xs leading-relaxed text-rose-800 dark:text-rose-300">{{ $rule['desc'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ============== CTA BACK TO HOME ============== --}}
    <a href="{{ route('dbos.sales-home') }}" wire:navigate
       class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-orange-600 py-3.5 text-sm font-bold text-white shadow-md transition active:scale-95 hover:bg-orange-700">
        <flux:icon.arrow-left class="size-4" />
        {{ __('Kembali ke Beranda') }}
    </a>

</section>
