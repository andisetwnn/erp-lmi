<?php

use App\Models\Master\PimpinanActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Aktivitas Saya'), Layout('layouts.pimpinan')] class extends Component {
    use WithPagination;

    #[Url(as: 'action', except: '')]
    public string $filterAction = '';

    public function updatingFilterAction(): void { $this->resetPage(); }

    public function with(): array
    {
        $pimpinan = Auth::guard('sales')->user();

        $rows = PimpinanActivityLog::where('pimpinan_sales_id', $pimpinan->id)
            ->when($this->filterAction, fn ($q) => $q->where('action', $this->filterAction))
            ->orderByDesc('created_at')
            ->paginate(30);

        $actions = PimpinanActivityLog::where('pimpinan_sales_id', $pimpinan->id)
            ->select('action')->distinct()->pluck('action')->toArray();

        return compact('rows', 'actions');
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">{{ __('Aktivitas Saya') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Riwayat aksi yang Anda lakukan sebagai pimpinan (audit log)') }}</flux:subheading>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <flux:select wire:model.live="filterAction" class="w-56">
            <flux:select.option value="">{{ __('Semua jenis aksi') }}</flux:select.option>
            @foreach ($actions as $a)
                <flux:select.option value="{{ $a }}">
                    @switch($a)
                        @case('set_target') {{ __('Set Target') }} @break
                        @case('reassign_prospect') {{ __('Re-assign Prospect') }} @break
                        @case('bulk_reassign_prospect') {{ __('Bulk Re-assign') }} @break
                        @default {{ $a }}
                    @endswitch
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($rows->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.bolt class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Belum ada aktivitas') }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ __('Aksi yang Anda lakukan (set target, re-assign prospect, dll) akan tercatat di sini.') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($rows as $log)
                    @php
                        $cfg = match ($log->action) {
                            'set_target' => ['icon' => 'cursor-arrow-rays', 'color' => 'bg-purple-100 text-purple-600 dark:bg-purple-950/50 dark:text-purple-300', 'label' => 'Set Target'],
                            'reassign_prospect' => ['icon' => 'arrow-path-rounded-square', 'color' => 'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300', 'label' => 'Re-assign Prospect'],
                            'bulk_reassign_prospect' => ['icon' => 'arrow-path-rounded-square', 'color' => 'bg-amber-100 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300', 'label' => 'Bulk Re-assign'],
                            default => ['icon' => 'bolt', 'color' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300', 'label' => $log->action],
                        };
                    @endphp
                    <li class="flex items-start gap-3 px-5 py-3">
                        <div @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', $cfg['color']])>
                            @switch($cfg['icon'])
                                @case('cursor-arrow-rays') <flux:icon.cursor-arrow-rays class="size-4" /> @break
                                @case('arrow-path-rounded-square') <flux:icon.arrow-path-rounded-square class="size-4" /> @break
                                @case('bolt') <flux:icon.bolt class="size-4" /> @break
                            @endswitch
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $cfg['label'] }}</span>
                                @if ($log->subject)
                                    <span class="text-zinc-400">·</span>
                                    <span class="truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $log->subject }}</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-zinc-500">
                                {{ $log->created_at?->translatedFormat('d M Y · H:i') }}
                                · {{ $log->created_at?->diffForHumans() }}
                            </div>
                            @if (! empty($log->meta))
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-[10px] font-semibold uppercase tracking-wider text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                        {{ __('Detail') }}
                                    </summary>
                                    <div class="mt-1 space-y-0.5 rounded-lg bg-zinc-50 px-2.5 py-2 text-[11px] text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400">
                                        @foreach ($log->meta as $k => $v)
                                            <div>
                                                <span class="font-semibold">{{ $k }}:</span>
                                                <span>{{ is_scalar($v) ? $v : json_encode($v) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4">
            <flux:pagination :paginator="$rows" />
        </div>
    @endif
</div>
