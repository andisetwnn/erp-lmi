@props([
    'current' => null,
])

@php
    // Tiap role melihat dashboard-nya sendiri lewat dispatcher di routes/web.php.
    // Super-admin perlu bisa memeriksa semuanya tanpa berganti akun, jadi hanya dia
    // yang mendapat pemilih ini.
    $daftar = [
        'executive' => ['label' => 'Executive', 'route' => 'dashboard.executive', 'untuk' => 'Super admin'],
        'direksi' => ['label' => 'Direksi', 'route' => 'dashboard.direksi', 'untuk' => 'Direktur'],
        'finance' => ['label' => 'Keuangan', 'route' => 'dashboard.finance', 'untuk' => 'Finance'],
        'pm' => ['label' => 'Project Manager', 'route' => 'dashboard.pm', 'untuk' => 'Project manager'],
        'kpr' => ['label' => 'Admin KPR', 'route' => 'dashboard.kpr', 'untuk' => 'Pemberkasan KPR'],
        'sales' => ['label' => 'Admin Sales', 'route' => 'dashboard.sales', 'untuk' => 'SPR & biaya tambahan'],
        'teknik' => ['label' => 'Admin Teknik', 'route' => 'dashboard.teknik', 'untuk' => 'Progres bangunan'],
    ];
    $aktif = $daftar[$current] ?? null;
@endphp

@if (auth()->user()?->hasRole('super-admin'))
    <flux:dropdown position="bottom" align="end">
        <flux:button size="sm" variant="ghost" icon-trailing="chevron-down">
            <span class="text-zinc-500">Tampilan</span>
            <span class="ml-1 font-semibold">{{ $aktif['label'] ?? 'Pilih' }}</span>
        </flux:button>

        <flux:menu>
            <flux:menu.group heading="Lihat dashboard role lain">
                @foreach ($daftar as $kunci => $d)
                    <flux:menu.item
                        :href="route($d['route'])"
                        wire:navigate
                        :icon="$kunci === $current ? 'check' : null">
                        {{ $d['label'] }}
                        <span class="ml-2 text-xs text-zinc-400">{{ $d['untuk'] }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu.group>
        </flux:menu>
    </flux:dropdown>
@endif
