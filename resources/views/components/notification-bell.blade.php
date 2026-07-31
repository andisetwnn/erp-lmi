<?php

use App\Support\BusinessActivityLogger;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

new class extends Component {
    public int $lastReadId = 0;

    public array $latestActivities = [];

    public int $unreadCount = 0;

    /**
     * Event yang relevan berdasar permission user.
     * - monitoring.lihat (PM, Direktur): semua event bisnis
     * - notifikasi.keuangan (Keuangan): hanya SPR baru (butuh UTJ) + konsumen TTD (butuh materai)
     */
    private function relevantEvents(): array
    {
        $user = Auth::user();

        if ($user?->can('monitoring.lihat')) {
            return []; // empty = tidak filter event
        }

        if ($user?->can('notifikasi.keuangan')) {
            return ['spr.submitted', 'konsumen.signed'];
        }

        return ['__none__'];
    }

    /**
     * Event yang butuh action user (per role) — highlight visual + prioritas.
     * Selain daftar ini = "informational" (FYI, tidak butuh action).
     */
    private function actionableEvents(): array
    {
        $user = Auth::user();

        return match (true) {
            $user?->hasRole('project-manager') => ['utj.verified'],           // UTJ verified → PM approve
            $user?->hasRole('finance')          => ['spr.submitted', 'konsumen.signed'], // baru → UTJ, TTD → materai
            $user?->hasRole('admin-kpr')        => ['materai.stamped'],       // materai final → lanjut KPR pipeline
            default                              => [],                        // Direktur & super-admin: read-only observer
        };
    }

    private function baseQuery()
    {
        $query = Activity::query()->whereIn('log_name', ['penjualan', 'keuangan', 'unit']);
        $events = $this->relevantEvents();
        if (! empty($events)) {
            $query->whereIn('event', $events);
        }
        return $query;
    }

    public function mount(): void
    {
        // Baseline session key per user role — supaya PM & Keuangan tidak bercampur
        $key = 'notif_last_read_id:'.(Auth::id() ?? 'guest');
        $this->lastReadId = (int) session($key, 0);
        if ($this->lastReadId === 0) {
            $this->lastReadId = (int) $this->baseQuery()->max('id');
            session([$key => $this->lastReadId]);
        }

        $this->refresh();
    }

    public function refresh(): void
    {
        $this->unreadCount = (int) $this->baseQuery()
            ->where('id', '>', $this->lastReadId)
            ->count();

        $actionable = $this->actionableEvents();

        $rows = $this->baseQuery()
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'log_name', 'event', 'description', 'created_at', 'subject_id', 'subject_type']);

        $this->latestActivities = $rows->map(fn ($a) => [
            'id' => $a->id,
            'log_name' => $a->log_name,
            'event' => $a->event,
            'event_label' => BusinessActivityLogger::labelFor($a->event),
            'description' => BusinessActivityLogger::shortenDesc($a->description),
            'ago' => optional($a->created_at)->diffForHumans() ?? '—',
            'created_at' => optional($a->created_at)->format('d M Y H:i'),
            'is_unread' => $a->id > $this->lastReadId,
            'is_actionable' => in_array($a->event, $actionable, true),
        ])->toArray();
    }

    public function markAllRead(): void
    {
        $latest = (int) $this->baseQuery()->max('id');
        $this->lastReadId = $latest;
        session(['notif_last_read_id:'.(Auth::id() ?? 'guest') => $latest]);
        $this->unreadCount = 0;
        $this->refresh();
    }
}; ?>

<div x-data="{ open: false }" class="relative"
     wire:poll.30s="refresh">
    <button type="button" @click="open = !open" @click.outside="open = false"
            class="relative inline-flex size-9 items-center justify-center rounded-lg text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
            aria-label="Notifikasi">
        <flux:icon.bell class="size-5" />
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 inline-flex min-h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[9px] font-bold text-white ring-2 ring-white dark:ring-zinc-900">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-1"
         class="absolute right-0 top-full z-50 mt-2 w-80 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900">

        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
            <div class="flex items-center gap-2">
                <flux:icon.bell class="size-4 text-zinc-600 dark:text-zinc-400" />
                <div class="text-sm font-bold text-zinc-900 dark:text-white">Notifikasi</div>
            </div>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead"
                        class="text-[10px] font-semibold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">
                    Tandai dibaca
                </button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            @forelse ($latestActivities as $act)
                @php
                    $catColor = match ($act['log_name']) {
                        'penjualan' => 'bg-emerald-500',
                        'keuangan'  => 'bg-amber-500',
                        'unit'      => 'bg-indigo-500',
                        default     => 'bg-zinc-400',
                    };
                @endphp
                <div @class([
                    'flex gap-3 border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800/50',
                    'border-l-2 border-l-rose-500 bg-rose-50/50 dark:bg-rose-950/10' => $act['is_actionable'] && $act['is_unread'],
                    'bg-amber-50/30 dark:bg-amber-950/10' => $act['is_unread'] && ! $act['is_actionable'],
                    'hover:bg-zinc-50 dark:hover:bg-zinc-800/40' => ! $act['is_unread'],
                ])>
                    <div class="mt-1 shrink-0">
                        <span class="inline-flex size-2 rounded-full {{ $catColor }}"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div @class([
                            'truncate text-xs',
                            'font-bold text-rose-900 dark:text-rose-200' => $act['is_actionable'] && $act['is_unread'],
                            'font-semibold text-zinc-900 dark:text-white' => ! ($act['is_actionable'] && $act['is_unread']),
                        ])>
                            @if ($act['is_actionable'] && $act['is_unread'])
                                <span class="mr-1 inline-flex items-center rounded bg-rose-600 px-1 py-0.5 text-[8px] font-bold uppercase tracking-wider text-white">
                                    Butuh Aksi
                                </span>
                            @endif
                            {{ $act['description'] }}
                        </div>
                        <div class="mt-0.5 flex items-center gap-2 text-[10px] text-zinc-500">
                            <span class="font-semibold" title="{{ $act['event'] }}">{{ $act['event_label'] }}</span>
                            <span>·</span>
                            <span title="{{ $act['created_at'] }}">{{ $act['ago'] }}</span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center">
                    <flux:icon.inbox class="mx-auto size-8 text-zinc-300" />
                    <p class="mt-2 text-xs text-zinc-500">Belum ada notifikasi</p>
                </div>
            @endforelse
        </div>

        <a href="{{ route('monitoring.index') }}" wire:navigate
           class="flex items-center justify-center gap-1.5 border-t border-zinc-100 bg-zinc-50 py-2 text-[11px] font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-300 dark:hover:bg-zinc-800">
            Lihat semua di Monitoring
            <flux:icon.arrow-right class="size-3" />
        </a>
    </div>
</div>
