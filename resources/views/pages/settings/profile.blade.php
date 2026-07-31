<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profil')] class extends Component
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    // ===== Profile =====
    public string $name = '';

    public string $email = '';

    // ===== Password =====
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    // ===== Tanda Tangan =====
    public string $ttdDataUrl = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    // =============== PROFILE ===============
    public function updateProfile(): void
    {
        $user = Auth::user();
        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);
        $user->save();

        Flux::toast(variant: 'success', text: 'Profil berhasil diperbarui.');
    }

    // =============== PASSWORD ===============
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => $this->passwordRules(),
            ], [], [
                'current_password' => 'password saat ini',
                'password' => 'password baru',
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');
            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');
        Flux::toast(variant: 'success', text: 'Password berhasil diubah.');
    }

    // =============== TANDA TANGAN ===============
    public function simpanTtd(): void
    {
        abort_unless(Auth::user()?->can('ttd.kelola'), 403);

        $this->validate([
            'ttdDataUrl' => ['required', 'string', 'starts_with:data:image/png;base64,'],
        ], [], ['ttdDataUrl.required' => 'Tanda tangan belum digambar.']);

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
        Flux::toast(variant: 'success', text: 'Tanda tangan tersimpan.');
    }

    public function hapusTtd(): void
    {
        abort_unless(Auth::user()?->can('ttd.kelola'), 403);
        $user = Auth::user();

        if ($user->tanda_tangan_path && Storage::disk('public')->exists($user->tanda_tangan_path)) {
            Storage::disk('public')->delete($user->tanda_tangan_path);
        }

        $user->update(['tanda_tangan_path' => null]);
        Flux::toast(variant: 'success', text: 'Tanda tangan dihapus.');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6">
            <flux:heading size="xl">{{ __('Profil') }}</flux:heading>
            <flux:subheading>{{ __('Kelola data profil, tanda tangan digital, dan password akun.') }}</flux:subheading>
        </div>

        {{-- ============ SECTION 1: PROFIL ============ --}}
        <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-center gap-2">
                <flux:icon.user-circle class="size-5 text-indigo-600" />
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Data Profil') }}</h3>
            </div>

            <form wire:submit="updateProfile" class="space-y-4">
                <flux:input wire:model="name" :label="__('Nama Lengkap')" type="text" required autocomplete="name" />
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                <div class="flex justify-end">
                    <flux:button variant="primary" type="submit">{{ __('Simpan Profil') }}</flux:button>
                </div>
            </form>
        </div>

        {{-- ============ SECTION 2: TANDA TANGAN ============ --}}
        @can('ttd.kelola')
            <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                 x-data="signaturePad()">
                <div class="mb-2 flex items-center gap-2">
                    <flux:icon.pencil-square class="size-5 text-emerald-600" />
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Tanda Tangan Digital') }}</h3>
                </div>
                <p class="mb-4 text-xs text-zinc-500">
                    {{ __('Tanda tangan ini otomatis dilekatkan pada dokumen yang Anda setujui / konfirmasi (SPR, SP3K, kwitansi, dll).') }}
                </p>

                @if (auth()->user()->tanda_tangan_path)
                    <div class="mb-4">
                        <flux:label>{{ __('Tanda Tangan Terdaftar') }}</flux:label>
                        <div class="mt-2 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                            <div class="rounded-lg bg-white p-3">
                                <img src="{{ Storage::disk('public')->url(auth()->user()->tanda_tangan_path) }}"
                                     alt="TTD"
                                     class="mx-auto h-24 w-auto object-contain">
                            </div>
                            <div class="mt-2 flex justify-end">
                                <flux:button size="sm" variant="danger" wire:click="hapusTtd">
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
                </div>
            </div>
        @endcan

        {{-- ============ SECTION 3: PASSWORD ============ --}}
        <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-2 flex items-center gap-2">
                <flux:icon.shield-check class="size-5 text-rose-600" />
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Keamanan') }}</h3>
            </div>
            <p class="mb-4 text-xs text-zinc-500">
                {{ __('Gunakan password yang panjang & unik agar akun tetap aman.') }}
            </p>

            <form wire:submit="updatePassword" class="space-y-4">
                <flux:input wire:model="current_password" :label="__('Password Saat Ini')" type="password" required autocomplete="current-password" />
                <flux:input wire:model="password" :label="__('Password Baru')" type="password" required autocomplete="new-password" />
                <flux:input wire:model="password_confirmation" :label="__('Konfirmasi Password Baru')" type="password" required autocomplete="new-password" />

                <div class="flex justify-end">
                    <flux:button variant="primary" type="submit" icon="key">{{ __('Ubah Password') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</section>

@can('ttd.kelola')
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
            clear() { if (this.pad) this.pad.clear(); },
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
@endcan
