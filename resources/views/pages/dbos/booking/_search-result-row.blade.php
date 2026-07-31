<div @class(['flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-white shadow-sm', $state['avatar']])>
    <span class="text-[11px] font-extrabold leading-none">{{ $r->kode_unit }}</span>
</div>
<div class="min-w-0 flex-1">
    <div class="flex items-center justify-between gap-2">
        <span class="truncate text-xs font-bold text-zinc-900 dark:text-white">
            {{ $r->proyek?->nama_proyek }} · {{ $r->kode_unit }}
        </span>
        <span @class([
            'inline-flex shrink-0 items-center gap-0.5 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide',
            $state['badgeClr'],
        ])>
            @switch($state['badgeIcon'])
                @case('check-circle')   <flux:icon.check-circle class="size-2.5" /> @break
                @case('clock')          <flux:icon.clock class="size-2.5" /> @break
                @case('check-badge')    <flux:icon.check-badge class="size-2.5" /> @break
                @case('pencil-square')  <flux:icon.pencil-square class="size-2.5" /> @break
                @case('lock-closed')    <flux:icon.lock-closed class="size-2.5" /> @break
            @endswitch
            {{ $state['badgeLabel'] }}
        </span>
    </div>
    <div class="truncate text-[10px] text-zinc-500">{{ $tipeLabel }}</div>
</div>
@if ($clickable)
    <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400" />
@endif
