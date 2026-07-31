<?php

use App\Models\Master\Sales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Masuk DBOS'), Layout('layouts.dbos-guest')] class extends Component {
    #[Validate('required|string')]
    public string $dbos_username = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        if (Auth::guard('sales')->check()) {
            $this->redirect(route('dbos.home'), navigate: true);
        }
    }

    public function login(): void
    {
        $this->validate();

        $sales = Sales::where('dbos_username', $this->dbos_username)->first();

        if (! $sales || ! $sales->dbos_password) {
            throw ValidationException::withMessages([
                'dbos_username' => __('Username atau password salah.'),
            ]);
        }

        if (! $sales->is_aktif) {
            throw ValidationException::withMessages([
                'dbos_username' => __('Akun sales ini sedang non-aktif. Hubungi admin.'),
            ]);
        }

        $ok = Auth::guard('sales')->attempt(
            ['dbos_username' => $this->dbos_username, 'password' => $this->password],
            $this->remember,
        );

        if (! $ok) {
            throw ValidationException::withMessages([
                'dbos_username' => __('Username atau password salah.'),
            ]);
        }

        // Update last_login_at untuk tracking aktivitas anggota
        Auth::guard('sales')->user()?->forceFill(['last_login_at' => now()])->save();

        session()->regenerate();
        $this->redirect(route('dbos.home'), navigate: true);
    }
}; ?>

<div>
    {{-- LOGO + BRAND --}}
    <div class="mb-6 text-center">
        <div class="mx-auto h-28 w-28 rounded-3xl bg-white shadow-xl ring-4 ring-white/30"
             style="background-image: url('{{ asset('images/logo.png') }}');
                    background-size: 180% auto;
                    background-position: center 32%;
                    background-repeat: no-repeat;"
             role="img" aria-label="LMI">
        </div>
        <h1 class="mt-5 text-4xl font-extrabold tracking-tight text-white drop-shadow-sm">DBOS</h1>
        <p class="mt-1 text-sm font-medium text-white/85">{{ __('Data Booking Order Sales') }}</p>
        <div class="mx-auto mt-3 h-0.5 w-12 rounded-full bg-white/40"></div>
    </div>

    {{-- LOGIN CARD --}}
    <div class="relative overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-zinc-900">
        {{-- subtle top accent --}}
        <div class="absolute inset-x-0 top-0 h-1 bg-linear-to-r from-orange-500 via-amber-400 to-orange-500"></div>

        <div class="p-7">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ __('Selamat Datang') }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Masuk dengan akun sales Anda.') }}</p>
            </div>

            <form wire:submit="login" class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Username') }}</flux:label>
                    <flux:input
                        wire:model="dbos_username"
                        type="text"
                        required
                        autofocus
                        autocomplete="username"
                        inputmode="text"
                        placeholder="budi.santoso"
                        icon="user"
                    />
                    <flux:error name="dbos_username" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Password') }}</flux:label>
                    <flux:input
                        wire:model="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        :placeholder="__('Password Anda')"
                        icon="lock-closed"
                        viewable
                    />
                    <flux:error name="password" />
                </flux:field>

                <div class="flex items-center justify-between">
                    <flux:checkbox wire:model="remember" :label="__('Ingat saya')" />
                </div>

                <flux:button
                    variant="primary"
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="login"
                    class="w-full h-12! bg-linear-to-r from-orange-600! to-orange-500! hover:from-orange-700! hover:to-orange-600! shadow-lg shadow-orange-500/30! disabled:opacity-70!"
                >
                    <span wire:loading.remove wire:target="login">{{ __('Masuk') }}</span>
                    <span wire:loading wire:target="login">{{ __('Memproses...') }}</span>
                </flux:button>
            </form>

            <div class="mt-6 border-t border-zinc-200 pt-4 text-center dark:border-zinc-700">
                <p class="text-xs text-zinc-500">
                    {{ __('Lupa password?') }}
                    <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Hubungi admin di kantor.') }}</span>
                </p>
            </div>
        </div>
    </div>

    {{-- FOOTER --}}
    <div class="mt-6 text-center">
        <p class="text-xs font-medium text-white/85">PT Langit Membangun Indonesia</p>
        <p class="mt-0.5 text-[10px] text-white/60">© {{ now()->format('Y') }} · All rights reserved</p>
    </div>
</div>
