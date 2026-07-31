<?php

use App\Models\Master\Spr;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Unduh SPR Final'), Layout('layouts.public')] class extends Component
{
    public string $token = '';

    public ?Spr $spr = null;

    public string $nikInput = '';

    public bool $nikVerified = false;

    /** Status: 'invalid' | 'expired' | 'ready' */
    public string $status = 'ready';

    private function sessionKey(): string
    {
        return 'spr-dl-nik-ok:'.$this->token;
    }

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->spr = Spr::with(['prospectCustomer', 'rumah.proyek', 'rumah.tipeRumah'])
            ->where('konsumen_download_link_hash', $token)
            ->first();

        if (! $this->spr) {
            $this->status = 'invalid';
            return;
        }

        if (! $this->spr->konsumen_download_link_expires_at || $this->spr->konsumen_download_link_expires_at->isPast()) {
            $this->status = 'expired';
            return;
        }

        if (! $this->spr->materai_file_path) {
            $this->status = 'invalid';
            return;
        }

        $this->status = 'ready';
        // Restore state kalau user reload halaman setelah verifikasi
        $this->nikVerified = (bool) session()->get($this->sessionKey(), false);
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
        // Simpan flag verifikasi di session agar route download file bisa cek gate ini
        session()->put($this->sessionKey(), true);
    }
}; ?>

<section class="mx-auto flex min-h-screen max-w-lg flex-col px-4 py-6">

    {{-- HEADER --}}
    <div class="mb-5 text-center">
        <img src="{{ asset('images/logo.png') }}" alt="PT Langit Membangun Indonesia"
             class="mx-auto h-28 w-auto object-contain" />
        <h1 class="mt-3 text-lg font-bold text-zinc-900">PT Langit Membangun Indonesia</h1>
        <p class="text-xs text-zinc-500">{{ __('Salinan Surat Pemesanan Rumah') }}</p>
    </div>

    @if ($status === 'invalid')
        <div class="rounded-2xl border-2 border-rose-200 bg-white p-6 text-center shadow-sm">
            <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-rose-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
            <h2 class="mt-3 text-base font-bold text-rose-900">{{ __('Link Tidak Valid') }}</h2>
            <p class="mt-2 text-sm text-rose-700">
                {{ __('Link unduh yang Anda buka tidak ditemukan atau file dokumen belum tersedia. Silakan hubungi sales Anda.') }}
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
                {{ __('Link unduh ini sudah lewat batas waktu berlaku. Silakan hubungi sales Anda untuk meminta link baru.') }}
            </p>
        </div>
    @else
        {{-- Info SPR (hanya tampilkan nomor + proyek sebelum NIK diverifikasi, tidak bocorkan detail) --}}
        <div class="mb-4 overflow-hidden rounded-2xl bg-white shadow-sm">
            <div class="bg-linear-to-r from-purple-500 to-indigo-500 px-4 py-3 text-white">
                <div class="text-[10px] font-bold uppercase tracking-wider opacity-90">{{ __('Surat Pemesanan Rumah — Final') }}</div>
                <div class="font-mono text-lg font-bold">{{ $spr->nomor_display }}</div>
            </div>
            @if ($nikVerified)
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
                        <span class="text-right font-mono font-bold text-purple-700">Rp {{ number_format((float) $spr->total_harga, 0, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between gap-2 border-t border-zinc-100 pt-2">
                        <span class="text-zinc-500">{{ __('Ditandatangani') }}</span>
                        <span class="text-right font-semibold text-emerald-700">{{ $spr->konsumen_signed_at?->translatedFormat('d M Y · H:i') ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between gap-2">
                        <span class="text-zinc-500">{{ __('e-Materai Ditempel') }}</span>
                        <span class="text-right font-semibold text-emerald-700">{{ $spr->materai_stamped_at?->translatedFormat('d M Y · H:i') ?? '—' }}</span>
                    </div>
                </div>
            @else
                <div class="p-4 text-center text-xs text-zinc-500">
                    {{ __('Verifikasi identitas Anda di bawah untuk melihat detail & mengunduh dokumen.') }}
                </div>
            @endif
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
            {{-- STEP 2: DOWNLOAD --}}
            <div class="rounded-2xl bg-white p-5 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <div class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-purple-500 text-xs font-bold text-white">2</div>
                    <h2 class="text-base font-bold text-zinc-900">{{ __('Unduh Dokumen') }}</h2>
                </div>

                <div class="mb-3 rounded-lg bg-emerald-50 p-2 text-[11px] text-emerald-800">
                    <span class="inline-flex items-center gap-1 font-semibold">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                        NIK diverifikasi
                    </span>
                    <span class="ml-1">— {{ __('Anda dapat mengunduh dokumen.') }}</span>
                </div>

                <p class="mb-4 text-xs text-zinc-600">
                    {{ __('Dokumen SPR final sudah ditandatangani oleh semua pihak dan bermaterai sah. Simpan salinan untuk arsip Anda.') }}
                </p>

                <a href="{{ route('spr.download.file', $spr->konsumen_download_link_hash) }}"
                   class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-purple-600 text-sm font-bold text-white shadow-lg transition active:scale-95 hover:bg-purple-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    {{ __('Unduh PDF SPR Final') }}
                </a>

                <p class="mt-3 text-center text-[10px] italic text-zinc-500">
                    {{ __('Link ini berlaku sampai') }}
                    <span class="font-semibold">{{ $spr->konsumen_download_link_expires_at?->translatedFormat('d M Y H:i') }}</span>
                    ({{ $spr->konsumen_download_link_expires_at?->diffForHumans() }}).
                </p>
            </div>
        @endif
    @endif

    <div class="mt-6 pb-4 text-center">
        <p class="text-[10px] text-zinc-400">
            © {{ date('Y') }} PT Langit Membangun Indonesia. Dokumen ini dilindungi hukum yang berlaku.
        </p>
    </div>

</section>
