<?php

use App\Models\Master\Sales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profil Sales'), Layout('layouts.dbos')] class extends Component
{
    public string $ttdDataUrl = '';

    public function with(): array
    {
        /** @var Sales $sales */
        $sales = Auth::guard('sales')->user();
        $sales->load(['jenisSales', 'bank', 'grup']);

        $initials = collect(explode(' ', $sales->nama))
            ->take(2)
            ->map(fn ($w) => mb_substr($w, 0, 1))
            ->implode('');

        return compact('sales', 'initials');
    }

    public function simpanTtd(): void
    {
        $this->validate([
            'ttdDataUrl' => ['required', 'string', 'starts_with:data:image/png;base64,'],
        ], [
            'ttdDataUrl.required' => 'Tanda tangan belum digambar.',
            'ttdDataUrl.starts_with' => 'Format tanda tangan tidak valid.',
        ]);

        /** @var Sales $sales */
        $sales = Auth::guard('sales')->user();

        $imageData = base64_decode(substr($this->ttdDataUrl, strlen('data:image/png;base64,')));

        if ($imageData === false || strlen($imageData) < 200) {
            $this->addError('ttdDataUrl', 'Tanda tangan terlalu kosong. Gambar ulang.');
            return;
        }

        // Hapus TTD lama kalau ada
        if ($sales->tanda_tangan_path && Storage::disk('public')->exists($sales->tanda_tangan_path)) {
            Storage::disk('public')->delete($sales->tanda_tangan_path);
        }

        $filename = 'tanda-tangan/sales/'.$sales->id.'-'.now()->timestamp.'.png';
        Storage::disk('public')->put($filename, $imageData);

        $sales->update(['tanda_tangan_path' => $filename]);

        $this->ttdDataUrl = '';
        $this->dispatch('ttd-tersimpan');
        session()->flash('ttd_ok', 'Tanda tangan berhasil disimpan.');
    }

    public function hapusTtd(): void
    {
        /** @var Sales $sales */
        $sales = Auth::guard('sales')->user();

        if ($sales->tanda_tangan_path && Storage::disk('public')->exists($sales->tanda_tangan_path)) {
            Storage::disk('public')->delete($sales->tanda_tangan_path);
        }

        $sales->update(['tanda_tangan_path' => null]);
        session()->flash('ttd_ok', 'Tanda tangan dihapus.');
    }
}; ?>

<div class="p-4 pb-32">
    {{-- HEADER --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('dbos.sales-home') }}" wire:navigate
           class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-4" />
        </a>
        <div>
            <flux:heading size="lg">{{ __('Profil') }}</flux:heading>
            <flux:subheading>{{ __('Data akun & tanda tangan') }}</flux:subheading>
        </div>
    </div>

    @if (session('ttd_ok'))
        <div class="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">
            <flux:icon.check-circle class="-mt-0.5 mr-1 inline size-3.5" />
            {{ session('ttd_ok') }}
        </div>
    @endif

    {{-- KARTU IDENTITAS --}}
    <div class="mb-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start gap-4">
            <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-orange-500 text-xl font-bold text-white shadow">
                {{ $initials }}
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $sales->nama }}</h2>
                <p class="font-mono text-xs text-zinc-500">#{{ $sales->kode }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ $sales->jenisSales?->nama ?? '—' }} · {{ $sales->grup?->nama ?? '—' }}</p>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-zinc-100 pt-4 text-xs dark:border-zinc-800">
            <div>
                <dt class="text-zinc-500">{{ __('Username') }}</dt>
                <dd class="font-mono font-semibold">{{ $sales->dbos_username ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('Telepon') }}</dt>
                <dd class="font-semibold">{{ $sales->telepon ?? '—' }}</dd>
            </div>
            @if ($sales->bank)
                <div class="col-span-2">
                    <dt class="text-zinc-500">{{ __('Rekening') }}</dt>
                    <dd class="font-semibold">{{ $sales->bank->nama }} · <span class="font-mono">{{ $sales->nomor_rekening ?? '—' }}</span></dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- ============ TANDA TANGAN ============ --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
         x-data="signaturePad()">
        <div class="mb-3">
            <div class="flex items-center gap-2">
                <flux:icon.pencil-square class="size-4 text-orange-600" />
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Tanda Tangan Digital') }}</h3>
            </div>
            <p class="mt-1 text-[11px] text-zinc-500">
                {{ __('Tanda tangan ini akan otomatis dilekatkan pada setiap SPR yang Anda kirim. Gambar sekali, digunakan berulang.') }}
            </p>
        </div>

        {{-- TTD Existing --}}
        @if ($sales->tanda_tangan_path)
            <div class="mb-3 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                        {{ __('TTD Terdaftar') }}
                    </span>
                    <button type="button" wire:click="hapusTtd" wire:confirm="Hapus tanda tangan?"
                            class="text-[10px] font-semibold text-rose-600 hover:underline">
                        {{ __('Hapus') }}
                    </button>
                </div>
                <div class="rounded-lg bg-white p-2">
                    <img src="{{ Storage::disk('public')->url($sales->tanda_tangan_path) }}"
                         alt="TTD"
                         class="mx-auto h-24 w-auto object-contain">
                </div>
            </div>
        @endif

        {{-- Canvas SignaturePad --}}
        <div>
            <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                {{ $sales->tanda_tangan_path ? __('Ganti Tanda Tangan') : __('Buat Tanda Tangan') }}
            </label>
            <div class="rounded-xl border-2 border-dashed border-zinc-300 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                <canvas x-ref="canvas"
                        class="block h-40 w-full touch-none rounded-t-xl bg-white"></canvas>
                <div class="flex items-center justify-between border-t border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <button type="button" @click="clear()"
                            class="text-[11px] font-semibold text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                        <flux:icon.arrow-uturn-left class="-mt-0.5 mr-0.5 inline size-3" />
                        {{ __('Bersihkan') }}
                    </button>
                    <button type="button" @click="save()"
                            class="rounded-lg bg-orange-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm active:scale-95">
                        <flux:icon.check class="-mt-0.5 mr-0.5 inline size-3" />
                        {{ __('Simpan') }}
                    </button>
                </div>
            </div>
            @error('ttdDataUrl')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-[10px] italic text-zinc-500">
                {{ __('Tips: gunakan stylus atau jari di layar sentuh untuk hasil terbaik.') }}
            </p>
        </div>
    </div>
</div>

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
