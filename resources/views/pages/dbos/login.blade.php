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
    <style>
        .dbos-form-judul { margin: 0; font-size: 26px; line-height: 1.15; font-weight: 600; letter-spacing: -.015em; color: #1c1917; }
        .dbos-form-sub { margin: 6px 0 0; font-size: 14.5px; color: #78716c; }
        .dark .dbos-form-judul { color: #f4f4f5; }
        .dark .dbos-form-sub { color: #a1a1aa; }
        /* Oranye DBOS yang cukup gelap untuk teks putih (kontras 5:1) */
        .dbos-form { --color-accent: #c2410c; --color-accent-content: #c2410c; --color-accent-foreground: #ffffff; }
        .dbos-lupa { margin: 14px 0 0; text-align: center; font-size: 13px; color: #78716c; }
        .dbos-lupa b { font-weight: 600; color: #44403c; }
        .dark .dbos-lupa b { color: #d4d4d8; }
    </style>

    <div style="margin-bottom: 16px">
        <h2 class="dbos-form-judul">{{ __('Masuk ke DBOS') }}</h2>
        <p class="dbos-form-sub">{{ __('Pakai akun sales yang diberikan admin.') }}</p>
    </div>

    <form wire:submit="login" class="dbos-form space-y-4">
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

        <flux:checkbox wire:model="remember" :label="__('Ingat saya di HP ini')" />

        <flux:button
            variant="primary"
            type="submit"
            wire:loading.attr="disabled"
            wire:target="login"
            class="w-full h-12!"
        >
            <span wire:loading.remove wire:target="login">{{ __('Masuk') }}</span>
            <span wire:loading wire:target="login">{{ __('Memproses...') }}</span>
        </flux:button>
    </form>

    <p class="dbos-lupa">{{ __('Lupa password?') }} <b>{{ __('Hubungi admin di kantor.') }}</b></p>
</div>
