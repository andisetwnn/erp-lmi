<x-layouts::auth :title="__('Log in')">
    <style>
        .lmi-login-judul { margin: 0; font-size: 30px; line-height: 1.15; font-weight: 600; letter-spacing: -.015em; color: #1d1b26; }
        .lmi-login-sub { margin: 8px 0 0; font-size: 15px; color: #6f6b78; }
        .dark .lmi-login-judul { color: #f4f4f5; }
        .dark .lmi-login-sub { color: #a1a1aa; }
        /* Oranye LMI yang cukup gelap untuk teks putih (kontras 4,5:1) */
        .lmi-login-form { --color-accent: #c2560f; --color-accent-content: #c2560f; --color-accent-foreground: #ffffff; }
        .lmi-login-catatan { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; background: #fbf4ee; border: 1px solid #f1dfcf; font-size: 13.5px; line-height: 1.5; color: #5e4636; }
        .lmi-login-catatan svg { flex: none; width: 17px; height: 17px; margin-top: 1px; color: #c2560f; }
        .dark .lmi-login-catatan { background: rgba(194, 86, 15, .12); border-color: rgba(194, 86, 15, .35); color: #e8d3c3; }
    </style>

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="lmi-login-judul">{{ __('Masuk ke akun Anda') }}</h1>
            <p class="lmi-login-sub">{{ __('Pakai username dan password dari admin LMI.') }}</p>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="lmi-login-form flex flex-col gap-6"
              x-data="{ submitting: false }"
              @submit="submitting = true">
            @csrf

            <flux:input
                name="username"
                :label="__('Username')"
                :value="old('username')"
                type="text"
                required
                autofocus
                autocomplete="username"
                placeholder="admin"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:checkbox name="remember" :label="__('Ingat saya di perangkat ini')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button"
                         x-bind:disabled="submitting">
                <span x-show="!submitting">{{ __('Masuk') }}</span>
                <span x-show="submitting" x-cloak class="inline-flex items-center gap-2">
                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ __('Memproses...') }}
                </span>
            </flux:button>
        </form>

        <div class="lmi-login-catatan">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>
            <span>{{ __('Lupa password? Hubungi admin untuk meresetnya.') }}</span>
        </div>
    </div>
</x-layouts::auth>
