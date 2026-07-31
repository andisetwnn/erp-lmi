<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tanda Tangan')] class extends Component
{
    public string $ttdDataUrl = '';

    public function simpanTtd(): void
    {
        abort_unless(Auth::user()->can('ttd.kelola'), 403);

        $this->validate([
            'ttdDataUrl' => ['required', 'string', 'starts_with:data:image/png;base64,'],
        ], [
            'ttdDataUrl.required' => 'Tanda tangan belum digambar.',
            'ttdDataUrl.starts_with' => 'Format tanda tangan tidak valid.',
        ]);

        $user = Auth::user();
        $imageData = base64_decode(substr($this->ttdDataUrl, strlen('data:image/png;base64,')));

        if ($imageData === false || strlen($imageData) < 200) {
            $this->addError('ttdDataUrl', 'Tanda tangan terlalu kosong. Gambar ulang.');
            return;
        }

        if ($user->tanda_tangan_path && Storage::disk('public')->exists($user->tanda_tangan_path)) {
            Storage::disk('public')->delete($user->tanda_tangan_path);
        }

        $filename = 'tanda-tangan/user/'.$user->id.'-'.now()->timestamp.'.png';
        Storage::disk('public')->put($filename, $imageData);

        $user->update(['tanda_tangan_path' => $filename]);

        $this->ttdDataUrl = '';
        $this->dispatch('ttd-tersimpan');
        Flux::toast(variant: 'success', text: 'Tanda tangan berhasil disimpan.');
    }

    public function hapusTtd(): void
    {
        abort_unless(Auth::user()->can('ttd.kelola'), 403);

        $user = Auth::user();

        if ($user->tanda_tangan_path && Storage::disk('public')->exists($user->tanda_tangan_path)) {
            Storage::disk('public')->delete($user->tanda_tangan_path);
        }

        $user->update(['tanda_tangan_path' => null]);
        Flux::toast(variant: 'success', text: 'Tanda tangan dihapus.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Tanda Tangan')" :subheading="__('Tanda tangan akan otomatis dilekatkan pada dokumen yang Anda setujui/konfirmasi (SPR, SP3K, kwitansi, dll).')">
        <div class="my-6 w-full space-y-6" x-data="signaturePad()">

            @if (auth()->user()->tanda_tangan_path)
                <div>
                    <flux:label>{{ __('Tanda Tangan Terdaftar') }}</flux:label>
                    <div class="mt-2 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                        <div class="rounded-lg bg-white p-3">
                            <img src="{{ Storage::disk('public')->url(auth()->user()->tanda_tangan_path) }}"
                                 alt="TTD"
                                 class="mx-auto h-28 w-auto object-contain">
                        </div>
                        <div class="mt-2 flex justify-end">
                            <flux:button size="sm" variant="danger" wire:click="hapusTtd" wire:confirm="Hapus tanda tangan?">
                                {{ __('Hapus') }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            @endif

            <div>
                <flux:label>
                    {{ auth()->user()->tanda_tangan_path ? __('Ganti Tanda Tangan') : __('Buat Tanda Tangan') }}
                </flux:label>
                <div class="mt-2 rounded-xl border-2 border-dashed border-zinc-300 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <canvas x-ref="canvas"
                            class="block h-48 w-full touch-none rounded-t-xl bg-white"></canvas>
                    <div class="flex items-center justify-between border-t border-zinc-200 px-3 py-2 dark:border-zinc-700">
                        <button type="button" @click="clear()"
                                class="text-xs font-semibold text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                            <flux:icon.arrow-uturn-left class="-mt-0.5 mr-0.5 inline size-3" />
                            {{ __('Bersihkan') }}
                        </button>
                        <flux:button size="sm" variant="primary" @click="save()" type="button" icon="check">
                            {{ __('Simpan Tanda Tangan') }}
                        </flux:button>
                    </div>
                </div>
                @error('ttdDataUrl')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
                <flux:text class="mt-2 text-xs">
                    {{ __('Gunakan mouse, trackpad, atau stylus untuk menggambar tanda tangan.') }}
                </flux:text>
            </div>
        </div>
    </x-pages::settings.layout>
</section>

@script
<script>
    Alpine.data('signaturePad', () => ({
        pad: null,
        init() {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js';
            script.onload = () => this.initPad();
            document.head.appendChild(script);

            this.$wire.on('ttd-tersimpan', () => this.clear());
        },
        initPad() {
            const canvas = this.$refs.canvas;
            this.resize(canvas);
            this.pad = new SignaturePad(canvas, {
                penColor: '#111827',
                backgroundColor: 'rgba(255,255,255,0)',
                minWidth: 0.6,
                maxWidth: 2.2,
            });
            window.addEventListener('resize', () => this.resize(canvas));
        },
        resize(canvas) {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            if (this.pad) this.pad.clear();
        },
        clear() {
            if (this.pad) this.pad.clear();
        },
        save() {
            if (!this.pad || this.pad.isEmpty()) {
                alert('Tanda tangan belum digambar.');
                return;
            }
            const dataUrl = this.pad.toDataURL('image/png');
            this.$wire.set('ttdDataUrl', dataUrl, false);
            this.$wire.simpanTtd();
        },
    }));
</script>
@endscript
