{{-- Card 1 unit: kotak warna kiri (kode unit) + info kanan --}}
<div @class([
    'flex h-24 w-24 shrink-0 flex-col items-center justify-center text-white',
    $statusInfo['bg'],
])>
    <div class="text-lg font-bold leading-tight">{{ $u->kode_unit }}</div>
</div>

<div class="flex flex-1 items-center justify-between p-3">
    <div class="min-w-0">
        @if ($u->tipeRumah)
            <div class="text-sm font-semibold text-zinc-900 dark:text-white">
                {{ $u->tipeRumah->tipe }}
            </div>
            <div class="text-xs text-zinc-500">
                @if ($u->tipeRumah->kategori === 'subsidi')
                    <flux:badge color="green" size="sm" inset="top bottom">Subsidi</flux:badge>
                @else
                    <flux:badge color="blue" size="sm" inset="top bottom">Komersial</flux:badge>
                @endif
            </div>
            @if ($u->tipeRumah->harga_jual > 0)
                <div class="mt-1 font-mono text-xs text-zinc-600 dark:text-zinc-400">
                    Rp {{ number_format($u->tipeRumah->harga_jual, 0, ',', '.') }}
                </div>
            @endif
        @endif
    </div>

    <div class="text-right">
        <span @class([
            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider',
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' => $u->status === 'available',
            'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' => $u->status === 'booking',
            'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300' => $u->status === 'terjual',
            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => $u->status === 'draft',
        ])>
            @if ($u->status === 'available')
                <flux:icon.check-circle class="size-3" />
            @endif
            {{ $statusInfo['badge'] }}
        </span>
    </div>
</div>
