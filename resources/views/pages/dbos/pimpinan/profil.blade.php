<?php

use App\Models\Master\Sales;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profil Pimpinan'), Layout('layouts.pimpinan')] class extends Component {
    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $pimpinan->load(['jenisSales', 'bank', 'grup']);
        $grup = $pimpinan->grupYangDipimpin();

        $initials = collect(explode(' ', $pimpinan->nama))
            ->take(2)
            ->map(fn ($w) => mb_substr($w, 0, 1))
            ->implode('');

        $anggotaCount = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->where('is_aktif', true)
            ->count();

        return compact('pimpinan', 'grup', 'initials', 'anggotaCount');
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">{{ __('Profil') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Informasi akun dan keamanan') }}</flux:subheading>

    <div class="max-w-3xl">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-start gap-4">
                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-amber-500 text-2xl font-bold text-white shadow">
                    {{ $initials }}
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $pimpinan->nama }}</h2>
                    <p class="font-mono text-xs text-zinc-500">#{{ $pimpinan->kode }}</p>
                    <div class="mt-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                        <flux:icon.star class="size-3" />
                        {{ __('Pimpinan Grup') }}
                    </div>
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-1 gap-4 border-t border-zinc-100 pt-6 text-sm sm:grid-cols-2 dark:border-zinc-800">
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('Grup yang dipimpin') }}</dt>
                    <dd class="font-semibold text-zinc-900 dark:text-white">{{ $grup->nama }}</dd>
                    <dd class="text-xs text-zinc-500">{{ $anggotaCount }} {{ __('anggota aktif') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('Jenis sales') }}</dt>
                    <dd class="font-semibold text-zinc-900 dark:text-white">{{ $pimpinan->jenisSales?->nama ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('Username DBOS') }}</dt>
                    <dd class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $pimpinan->dbos_username ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('Telepon') }}</dt>
                    <dd class="font-semibold text-zinc-900 dark:text-white">{{ $pimpinan->telepon ?? '—' }}</dd>
                </div>
                @if ($pimpinan->alamat)
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-zinc-500">{{ __('Alamat') }}</dt>
                        <dd class="text-zinc-700 dark:text-zinc-300">{{ $pimpinan->alamat }}</dd>
                    </div>
                @endif
                @if ($pimpinan->bank)
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Bank') }}</dt>
                        <dd class="font-semibold text-zinc-900 dark:text-white">{{ $pimpinan->bank->nama }}</dd>
                        <dd class="font-mono text-xs text-zinc-500">{{ $pimpinan->nomor_rekening ?? '—' }}</dd>
                    </div>
                @endif
            </dl>

            <p class="mt-6 rounded-lg bg-zinc-50 px-3 py-2 text-[11px] text-zinc-500 dark:bg-zinc-800/50">
                {{ __('Untuk mengubah info profil, silakan hubungi admin master data.') }}
            </p>
        </div>
    </div>
</div>
