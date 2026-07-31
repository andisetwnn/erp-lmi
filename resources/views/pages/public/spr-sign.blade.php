<?php

use App\Models\Master\Spr;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tanda Tangan SPR'), Layout('layouts.public')] class extends Component
{
    public string $token = '';

    public ?Spr $spr = null;

    public bool $nikVerified = false;

    public string $nikInput = '';

    public string $ttdDataUrl = '';

    /** Status: 'invalid' | 'expired' | 'already_signed' | 'ready' */
    public string $status = 'ready';

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->spr = Spr::with(['prospectCustomer', 'rumah.proyek', 'rumah.tipeRumah'])
            ->where('konsumen_signing_link_hash', $token)
            ->first();

        if (! $this->spr) {
            $this->status = 'invalid';
            return;
        }

        if ($this->spr->konsumen_signed_at) {
            $this->status = 'already_signed';
            return;
        }

        if (! $this->spr->konsumen_signing_link_expires_at || $this->spr->konsumen_signing_link_expires_at->isPast()) {
            $this->status = 'expired';
            return;
        }

        $this->status = 'ready';
    }

    public function verifyNik(): void
    {
        $this->validate([
            'nikInput' => ['required', 'digits:16'],
        ], [
            'nikInput.required' => 'NIK wajib diisi.',
            'nikInput.digits' => 'NIK harus 16 digit angka.',
        ]);

        if (! $this->spr || $this->status !== 'ready') {
            Flux::toast(variant: 'danger', text: 'Link tidak valid atau sudah kedaluwarsa.');
            return;
        }

        $expected = trim((string) $this->spr->prospectCustomer?->nik);
        if ($expected === '' || $expected !== $this->nikInput) {
            $this->addError('nikInput', 'NIK tidak sesuai dengan data SPR. Cek kembali nomor KTP Anda.');
            return;
        }

        $this->nikVerified = true;
    }

    public function saveSignature(): void
    {
        if (! $this->nikVerified) {
            Flux::toast(variant: 'danger', text: 'Verifikasi NIK dulu sebelum tanda tangan.');
            return;
        }

        $this->validate([
            'ttdDataUrl' => ['required', 'string', 'starts_with:data:image/png;base64,'],
        ], [
            'ttdDataUrl.required' => 'Tanda tangan belum digambar.',
            'ttdDataUrl.starts_with' => 'Format tanda tangan tidak valid.',
        ]);

        $spr = $this->spr->fresh();

        // Double-check: bisa jadi race condition — link sudah expired/di-sign di tab lain
        if ($spr->konsumen_signed_at) {
            $this->status = 'already_signed';
            return;
        }
        if ($spr->konsumen_signing_link_expires_at?->isPast()) {
            $this->status = 'expired';
            return;
        }

        $imageData = base64_decode(substr($this->ttdDataUrl, strlen('data:image/png;base64,')));
        if ($imageData === false || strlen($imageData) < 500) {
            $this->addError('ttdDataUrl', 'Tanda tangan terlalu kosong. Gambar ulang.');
            return;
        }

        $filename = 'konsumen-ttd/spr-'.$spr->id.'-'.now()->timestamp.'.png';
        Storage::disk('public')->put($filename, $imageData);

        $now = now();
        $spr->update([
            'konsumen_ttd_path' => $filename,
            'konsumen_signed_at' => $now,
            // Invalidasi link (biar tidak bisa dipakai lagi)
            'konsumen_signing_link_hash' => null,
            'konsumen_signing_link_expires_at' => null,
        ]);
        // Catatan: spr_finalized_at TIDAK di-set di sini.
        // Finalisasi = setelah Keuangan tempel e-Materai (langkah terakhir).

        // Activity log business event
        try {
            BusinessActivityLogger::konsumenSigned($spr);
        } catch (\Throwable $e) {
            // silent — jangan gagalkan flow konsumen kalau log error
        }

        $this->status = 'already_signed';
        $this->spr = $spr;
    }
}; ?>

<section class="mx-auto flex min-h-screen max-w-lg flex-col px-4 py-6">

    {{-- HEADER --}}
    <div class="mb-5 text-center">
        <img src="{{ asset('images/logo.png') }}" alt="PT Langit Membangun Indonesia"
             class="mx-auto h-28 w-auto object-contain" />
        <h1 class="mt-3 text-lg font-bold text-zinc-900">PT Langit Membangun Indonesia</h1>
        <p class="text-xs text-zinc-500">{{ __('Portal Tanda Tangan Digital SPR') }}</p>
    </div>

    {{-- ==================== STATUS: INVALID / EXPIRED / ALREADY SIGNED ==================== --}}
    @if ($status === 'invalid')
        <div class="rounded-2xl border-2 border-rose-200 bg-white p-6 text-center shadow-sm">
            <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-rose-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
            <h2 class="mt-3 text-base font-bold text-rose-900">{{ __('Link Tidak Valid') }}</h2>
            <p class="mt-2 text-sm text-rose-700">
                {{ __('Link SPR yang Anda buka tidak ditemukan atau sudah tidak berlaku. Silakan hubungi sales Anda untuk meminta link baru.') }}
            </p>
        </div>
    @elseif ($status === 'expired')
        <div class="rounded-2xl border-2 border-amber-200 bg-white p-6 text-center shadow-sm">
            <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h2 class="mt-3 text-base font-bold text-amber-900">{{ __('Link Kedaluwarsa') }}</h2>
            <p class="mt-2 text-sm text-amber-700">
                {{ __('Link ini sudah lewat batas waktu 1 hari. Silakan hubungi sales Anda untuk meminta link baru.') }}
            </p>
        </div>
    @elseif ($status === 'already_signed')
        <div class="rounded-2xl border-2 border-emerald-200 bg-white p-6 text-center shadow-sm">
            <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h2 class="mt-3 text-lg font-bold text-emerald-900">{{ __('SPR Sudah Ditandatangani') }}</h2>
            <p class="mt-2 text-sm text-emerald-700">
                {{ __('Terima kasih! Anda telah menandatangani SPR ini pada') }}
                <span class="font-semibold">{{ $spr?->konsumen_signed_at?->translatedFormat('d M Y · H:i') ?? '—' }}</span>.
            </p>
            <p class="mt-3 text-xs text-zinc-500">
                {{ __('Dokumen final akan diarsipkan oleh tim kami. Silakan hubungi sales untuk pertanyaan lanjutan.') }}
            </p>
        </div>

    {{-- ==================== READY: NIK VERIFY THEN SIGN ==================== --}}
    @else
        {{-- Info SPR --}}
        <div class="mb-4 overflow-hidden rounded-2xl bg-white shadow-sm">
            <div class="bg-linear-to-r from-orange-500 to-amber-500 px-4 py-3 text-white">
                <div class="text-[10px] font-bold uppercase tracking-wider opacity-90">{{ __('Surat Pemesanan Rumah') }}</div>
                <div class="font-mono text-lg font-bold">{{ $spr->nomor_display }}</div>
            </div>
            <div class="space-y-2 p-4 text-sm">
                <div class="flex justify-between gap-2">
                    <span class="text-zinc-500">{{ __('Nama Pemesan') }}</span>
                    <span class="text-right font-bold">{{ $spr->prospectCustomer?->nama_lengkap }}</span>
                </div>
                <div class="flex justify-between gap-2">
                    <span class="text-zinc-500">{{ __('Proyek') }}</span>
                    <span class="text-right font-semibold">{{ $spr->rumah?->proyek?->nama_proyek ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-2">
                    <span class="text-zinc-500">{{ __('Unit') }}</span>
                    <span class="text-right font-mono font-bold">{{ $spr->rumah?->kode_unit ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-2">
                    <span class="text-zinc-500">{{ __('Tipe') }}</span>
                    <span class="text-right font-semibold">{{ $spr->rumah?->tipeRumah?->tipe ?? '—' }} {{ $spr->rumah?->tipeRumah?->nama_tipe ?? '' }}</span>
                </div>
                <div class="flex justify-between gap-2 border-t border-zinc-100 pt-2">
                    <span class="text-zinc-500">{{ __('Total Harga') }}</span>
                    <span class="text-right font-mono font-bold text-orange-700">Rp {{ number_format((float) $spr->total_harga, 0, ',', '.') }}</span>
                </div>
            </div>
        </div>

        @if (! $nikVerified)
            {{-- STEP 1: VERIFY NIK --}}
            <div class="rounded-2xl bg-white p-5 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <div class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-500 text-xs font-bold text-white">1</div>
                    <h2 class="text-base font-bold text-zinc-900">{{ __('Verifikasi Identitas') }}</h2>
                </div>
                <p class="mb-4 text-xs text-zinc-600">
                    {{ __('Untuk keamanan, masukkan Nomor Induk Kependudukan (NIK) Anda sesuai KTP. NIK akan dicocokkan dengan data SPR.') }}
                </p>

                <form wire:submit="verifyNik" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-zinc-700">{{ __('NIK (16 digit)') }}</label>
                        <input type="text" wire:model="nikInput"
                               inputmode="numeric" maxlength="16" pattern="[0-9]{16}"
                               placeholder="3xxxxxxxxxxxxxxx"
                               class="block h-12 w-full rounded-xl border-2 border-zinc-200 bg-white px-4 font-mono text-base tracking-wider shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30" />
                        @error('nikInput')
                            <p class="mt-1.5 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit"
                            wire:loading.attr="disabled" wire:target="verifyNik"
                            class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 text-sm font-bold text-white shadow-lg transition active:scale-95 hover:bg-blue-700 disabled:opacity-70">
                        <span wire:loading.remove wire:target="verifyNik">{{ __('Verifikasi NIK') }}</span>
                        <span wire:loading wire:target="verifyNik">{{ __('Memverifikasi...') }}</span>
                    </button>
                </form>
            </div>
        @else
            {{-- STEP 2: BACA DOKUMEN + STEP 3: SIGNATURE PAD --}}
            <div class="rounded-2xl bg-white p-5 shadow-sm"
                 x-data="{
                    hasRead: false,
                    canvas: null, ctx: null, drawing: false, hasSignature: false, ratio: 1,
                    setupCanvas() {
                        this.canvas = this.$refs.canvas || this.$root.querySelector('canvas[data-sig]');
                        if (!this.canvas) return false;
                        const rect = this.canvas.getBoundingClientRect();
                        if (rect.width === 0 || rect.height === 0) return false;
                        this.ratio = window.devicePixelRatio || 1;
                        // Save any existing drawing before resize
                        const prev = this.canvas.width > 0 ? this.canvas.toDataURL('image/png') : null;
                        this.canvas.width = Math.floor(rect.width * this.ratio);
                        this.canvas.height = Math.floor(rect.height * this.ratio);
                        this.canvas.style.width = rect.width + 'px';
                        this.canvas.style.height = rect.height + 'px';
                        this.ctx = this.canvas.getContext('2d');
                        this.ctx.scale(this.ratio, this.ratio);
                        this.ctx.strokeStyle = '#1e293b';
                        this.ctx.lineWidth = 2.5;
                        this.ctx.lineCap = 'round';
                        this.ctx.lineJoin = 'round';
                        if (prev && this.hasSignature) {
                            const img = new Image();
                            img.onload = () => this.ctx.drawImage(img, 0, 0, rect.width, rect.height);
                            img.src = prev;
                        }
                        return true;
                    },
                    init() {
                        // Defer sampai DOM stabil ($nextTick pastikan $refs sudah terpopulasi)
                        this.$nextTick(() => {
                            let attempts = 0;
                            const tryInit = () => {
                                attempts++;
                                if (this.setupCanvas()) return;
                                if (attempts < 60) requestAnimationFrame(tryInit); // max 60 frames (~1 detik)
                            };
                            requestAnimationFrame(tryInit);
                            window.addEventListener('resize', () => this.setupCanvas());
                        });
                    },
                    pos(e) {
                        const rect = this.canvas.getBoundingClientRect();
                        const touch = e.touches && e.touches[0] ? e.touches[0] : e;
                        return { x: touch.clientX - rect.left, y: touch.clientY - rect.top };
                    },
                    start(e) { if (!this.ctx) return; e.preventDefault(); this.drawing = true; const p = this.pos(e); this.ctx.beginPath(); this.ctx.moveTo(p.x, p.y); },
                    move(e) { if (!this.drawing || !this.ctx) return; e.preventDefault(); const p = this.pos(e); this.ctx.lineTo(p.x, p.y); this.ctx.stroke(); this.hasSignature = true; },
                    stop(e) { if (!this.drawing) return; e.preventDefault(); this.drawing = false; },
                    clear() { if (!this.ctx) return; this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height); this.hasSignature = false; },
                    save() {
                        if (!this.hasSignature) { alert('Tanda tangan dulu sebelum simpan.'); return; }
                        @this.set('ttdDataUrl', this.canvas.toDataURL('image/png'));
                        @this.saveSignature();
                    },
                 }">
                {{-- Header step 2 --}}
                <div class="mb-3 flex items-center gap-2">
                    <div class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-500 text-xs font-bold text-white">2</div>
                    <h2 class="text-base font-bold text-zinc-900">{{ __('Baca Dokumen SPR') }}</h2>
                </div>

                <p class="mb-3 text-xs text-zinc-600">
                    {{ __('Silakan buka dan baca dokumen Surat Pemesanan Rumah lengkap. Setelah selesai membaca, centang persetujuan di bawah untuk melanjutkan tanda tangan.') }}
                </p>

                {{-- Tombol buka dokumen preview --}}
                <a href="{{ route('spr.preview', $token) }}" target="_blank" rel="noopener"
                   class="mb-3 flex h-11 w-full items-center justify-center gap-2 rounded-xl border-2 border-blue-500 bg-blue-50 text-sm font-bold text-blue-700 shadow-sm transition active:scale-95 hover:bg-blue-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                    {{ __('Buka Dokumen SPR Lengkap') }}
                </a>

                {{-- Checkbox persetujuan --}}
                <label class="mb-4 flex cursor-pointer items-start gap-2.5 rounded-xl border-2 p-3 transition"
                       :class="hasRead ? 'border-emerald-500 bg-emerald-50' : 'border-zinc-200 bg-zinc-50'">
                    <input type="checkbox" x-model="hasRead"
                           class="mt-0.5 size-4 shrink-0 rounded border-zinc-300 accent-emerald-600" />
                    <span class="text-xs leading-relaxed"
                          :class="hasRead ? 'text-emerald-900 font-semibold' : 'text-zinc-700'">
                        {{ __('Saya sudah membaca dan menyetujui isi Surat Pemesanan Rumah beserta seluruh ketentuan yang tercantum.') }}
                    </span>
                </label>

                {{-- STEP 3: SIGNATURE PAD (locked sampai hasRead=true) --}}
                <div class="rounded-xl border border-zinc-200 pt-4"
                     :class="hasRead ? '' : 'pointer-events-none opacity-40'">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500 text-xs font-bold text-white">3</div>
                        <h2 class="text-base font-bold text-zinc-900">{{ __('Tanda Tangan Anda') }}</h2>
                    </div>

                    <p class="mb-2 text-xs text-zinc-600">
                        {{ __('Gambar tanda tangan Anda di kotak putih di bawah dengan jari atau stylus.') }}
                    </p>

                    {{-- Canvas --}}
                    <div class="relative overflow-hidden rounded-xl border-2 border-dashed border-zinc-300 bg-white" style="height: 180px;">
                        <canvas x-ref="canvas" data-sig
                                @mousedown="start($event)" @mousemove="move($event)" @mouseup="stop($event)" @mouseleave="stop($event)"
                                @touchstart="start($event)" @touchmove="move($event)" @touchend="stop($event)"
                                class="h-full w-full touch-none"
                                style="cursor: crosshair;"></canvas>
                        <div x-show="!hasSignature" x-cloak
                             class="pointer-events-none absolute inset-0 flex items-center justify-center text-xs italic text-zinc-400">
                            {{ __('Tanda tangan di sini') }}
                        </div>
                    </div>

                    @error('ttdDataUrl')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror

                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <button type="button" @click="clear()"
                                class="flex h-11 items-center justify-center gap-1.5 rounded-xl border border-zinc-300 bg-white text-sm font-semibold text-zinc-700 active:scale-95 hover:bg-zinc-50">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V5.5m-2.62 5.75c-.55-1.7-1.68-3.15-3.19-4.06a7.5 7.5 0 100 11.62m8.6-1.87l-4.99-4.99" /></svg>
                            {{ __('Hapus') }}
                        </button>
                        <button type="button" @click="save()"
                                wire:loading.attr="disabled" wire:target="saveSignature"
                                class="flex h-11 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 text-sm font-bold text-white shadow-lg active:scale-95 hover:bg-emerald-700 disabled:opacity-70">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" wire:loading.remove wire:target="saveSignature">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                            </svg>
                            <span wire:loading.remove wire:target="saveSignature">{{ __('Kirim') }}</span>
                            <span wire:loading wire:target="saveSignature">{{ __('Mengirim...') }}</span>
                        </button>
                    </div>

                    <p class="mt-3 text-center text-[10px] italic text-zinc-500">
                        {{ __('Dengan menandatangani dokumen ini, Anda menyetujui isi Surat Pemesanan Rumah dan seluruh ketentuan yang tercantum.') }}
                    </p>
                </div>
            </div>
        @endif
    @endif

    {{-- FOOTER --}}
    <div class="mt-6 pb-4 text-center">
        <p class="text-[10px] text-zinc-400">
            © {{ date('Y') }} PT Langit Membangun Indonesia. Dokumen ini dilindungi hukum yang berlaku.
        </p>
    </div>

    {{-- Loading overlay saat save signature --}}
    <div wire:loading.flex wire:target="saveSignature"
         class="fixed inset-0 z-9999 items-center justify-center bg-zinc-900/70 backdrop-blur-sm"
         style="display: none;">
        <div class="mx-4 flex max-w-xs flex-col items-center gap-4 rounded-2xl bg-white p-6 shadow-2xl">
            <div class="relative">
                <div class="h-14 w-14 rounded-full border-4 border-emerald-100"></div>
                <div class="absolute inset-0 h-14 w-14 animate-spin rounded-full border-4 border-transparent border-t-emerald-600"></div>
            </div>
            <div class="text-center">
                <div class="text-base font-bold text-zinc-900">{{ __('Menyimpan tanda tangan...') }}</div>
                <div class="mt-1 text-xs text-zinc-500">{{ __('Mohon tunggu, jangan tutup halaman') }}</div>
            </div>
        </div>
    </div>

</section>
