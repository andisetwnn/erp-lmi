<?php

use App\Exports\LogUserExport;
use App\Livewire\Concerns\Sortable;
use App\Models\User;
use App\Models\Master\Sales;
use App\Support\BusinessActivityLogger;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

new #[Title('Log User')] class extends Component
{
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'created_at';
    }

    protected function defaultSortDir(): string
    {
        return 'desc';
    }

    #[Url(as: 'log')]
    public string $logNameFilter = '';

    #[Url(as: 'event')]
    public string $eventFilter = '';

    #[Url(as: 'causer')]
    public string $causerFilter = '';

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    #[Url(as: 'q')]
    public string $search = '';

    /** Filter subject (format "Type|id" — mis. "Rumah|5" atau "TipeRumah|3"). */
    #[Url(as: 'subject')]
    public string $subjectFilter = '';

    #[Url(as: 'per')]
    public int $perPage = 30;

    public function resetFilters(): void
    {
        $this->logNameFilter = '';
        $this->eventFilter = '';
        $this->causerFilter = '';
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->search = '';
        $this->subjectFilter = '';
        $this->resetPage();
    }

    public function exportExcel()
    {
        $filename = 'log-user_'.now()->format('Y-m-d_His').'.xlsx';
        $export = new LogUserExport([
            'log_name' => $this->logNameFilter,
            'event' => $this->eventFilter,
            'causer' => $this->causerFilter,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'search' => $this->search,
        ]);
        return Excel::download($export, $filename);
    }

    public function updatingLogNameFilter(): void { $this->resetPage(); }
    public function updatingEventFilter(): void { $this->resetPage(); }
    public function updatingCauserFilter(): void { $this->resetPage(); }
    public function updatingDateFrom(): void { $this->resetPage(); }
    public function updatingDateTo(): void { $this->resetPage(); }
    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingPerPage(): void { $this->resetPage(); }

    public function with(): array
    {
        $query = Activity::query()->with(['causer', 'subject']);

        if ($this->logNameFilter) {
            $query->where('log_name', $this->logNameFilter);
        }
        if ($this->eventFilter) {
            $query->where('event', $this->eventFilter);
        }
        if ($this->causerFilter) {
            // Format: "type|id" — misal "user|3" atau "sales|5"
            [$type, $id] = explode('|', $this->causerFilter) + [null, null];
            if ($type === 'user') {
                $query->where('causer_type', User::class)->where('causer_id', $id);
            } elseif ($type === 'sales') {
                $query->where('causer_type', Sales::class)->where('causer_id', $id);
            }
        }
        if ($this->dateFrom) {
            $query->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay());
        }
        if ($this->dateTo) {
            $query->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay());
        }
        if ($this->search !== '') {
            $s = $this->search;
            $query->where('description', 'like', "%{$s}%");
        }
        if ($this->subjectFilter !== '') {
            [$type, $id] = explode('|', $this->subjectFilter) + [null, null];
            $classMap = [
                'Rumah' => \App\Models\Master\Rumah::class,
                'TipeRumah' => \App\Models\Master\TipeRumah::class,
            ];
            if (isset($classMap[$type]) && $id !== null) {
                $query->where('subject_type', $classMap[$type])->where('subject_id', (int) $id);
            }
        }

        $this->applySort($query, [
            'created_at',
            'log_name',
            'event',
            'causer_type',
            'subject_type',
        ]);

        $activities = $query->paginate($this->perPage);

        // Meta: log_name & event distinct untuk dropdown
        $logNames = Activity::query()->distinct()->pluck('log_name')->filter()->values();
        $events = Activity::query()
            ->when($this->logNameFilter, fn ($q) => $q->where('log_name', $this->logNameFilter))
            ->distinct()->pluck('event')->filter()->values();

        // Users & Sales untuk dropdown causer
        $users = User::orderBy('name')->get(['id', 'name']);
        $salesList = Sales::orderBy('nama')->get(['id', 'kode', 'nama']);

        return compact('activities', 'logNames', 'events', 'users', 'salesList');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5">
            <flux:heading size="xl">{{ __('Log User') }}</flux:heading>
            <flux:subheading>{{ __('Rekam jejak seluruh aktivitas pengguna di sistem') }}</flux:subheading>
        </div>

        {{-- FILTER BAR --}}
        <div class="mb-5 space-y-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-50 max-w-md">
                    <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                    <input type="search" wire:model.live.debounce.400ms="search"
                           placeholder="Cari deskripsi..."
                           class="block h-9 w-full rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-xs shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                </div>

                @if ($search || $logNameFilter || $eventFilter || $causerFilter || $dateFrom || $dateTo || $subjectFilter)
                    <button type="button" wire:click="resetFilters"
                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-[10px] font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                        <flux:icon.x-mark class="size-3" /> Reset
                    </button>
                @endif

                @if ($subjectFilter)
                    @php
                        [$subjType, $subjId] = explode('|', $subjectFilter) + [null, null];
                        $subjectLabel = match ($subjType) {
                            'Rumah' => (function () use ($subjId) {
                                $r = \App\Models\Master\Rumah::with('proyek', 'tipeRumah')->find($subjId);
                                return $r ? "Unit {$r->blok}-{$r->nomor_unit} · {$r->tipeRumah?->tipe} · {$r->proyek?->nama_proyek}" : "Unit #{$subjId}";
                            })(),
                            'TipeRumah' => (function () use ($subjId) {
                                $t = \App\Models\Master\TipeRumah::with('proyek')->find($subjId);
                                return $t ? "Tipe {$t->tipe} · {$t->proyek?->nama_proyek}" : "Tipe #{$subjId}";
                            })(),
                            default => "$subjType #{$subjId}",
                        };
                    @endphp
                    <div class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-[11px] font-semibold text-blue-800 dark:border-blue-800/60 dark:bg-blue-950/40 dark:text-blue-300">
                        <flux:icon.funnel class="size-3.5" />
                        Riwayat: {{ $subjectLabel }}
                    </div>
                @endif

                <button type="button" wire:click="exportExcel"
                        wire:loading.attr="disabled" wire:target="exportExcel"
                        class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-60">
                    <flux:icon.arrow-down-tray class="size-3.5" wire:loading.remove wire:target="exportExcel" />
                    <flux:icon.arrow-path class="size-3.5 animate-spin" wire:loading wire:target="exportExcel" />
                    <span wire:loading.remove wire:target="exportExcel">Export Excel</span>
                    <span wire:loading wire:target="exportExcel">Menyiapkan...</span>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                {{-- Log name (kategori) --}}
                @php
                    $kategoriLabel = fn ($ln) => match ($ln) {
                        'penjualan'  => 'Penjualan',
                        'keuangan'   => 'Keuangan',
                        'unit'       => 'Unit',
                        'tipe_rumah' => 'Tipe Rumah',
                        default      => ucfirst((string) $ln),
                    };
                @endphp
                <select wire:model.live="logNameFilter" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    <option value="">— Semua Kategori —</option>
                    @foreach ($logNames as $ln)
                        <option value="{{ $ln }}">{{ $kategoriLabel($ln) }}</option>
                    @endforeach
                </select>

                {{-- Event --}}
                <select wire:model.live="eventFilter" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    <option value="">— Semua Event —</option>
                    @foreach ($events as $ev)
                        <option value="{{ $ev }}">{{ \App\Support\BusinessActivityLogger::labelFor($ev) }}</option>
                    @endforeach
                </select>

                {{-- Causer --}}
                <select wire:model.live="causerFilter" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    <option value="">— Semua User —</option>
                    <optgroup label="Admin / Staff">
                        @foreach ($users as $u)
                            <option value="user|{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Sales (DBOS)">
                        @foreach ($salesList as $s)
                            <option value="sales|{{ $s->id }}">{{ $s->kode }} - {{ $s->nama }}</option>
                        @endforeach
                    </optgroup>
                </select>

                {{-- Date range --}}
                <div class="flex items-center gap-1">
                    <input type="date" wire:model.live="dateFrom"
                           class="rounded-lg border border-zinc-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    <span class="text-xs text-zinc-500">s/d</span>
                    <input type="date" wire:model.live="dateTo"
                           class="rounded-lg border border-zinc-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                </div>

                {{-- Page size --}}
                <select wire:model.live="perPage" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    @foreach ([10, 25, 50, 100] as $pp)
                        <option value="{{ $pp }}">{{ $pp }} baris</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    @php
                        $thBtn = function ($col, $label) use ($sortBy, $sortDir) {
                            $active = $sortBy === $col;
                            $arrow = $active ? ($sortDir === 'asc' ? '↑' : '↓') : '';
                            $colorClass = $active ? 'text-emerald-600' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200';
                            return '<button type="button" wire:click="sort(\''.$col.'\')" class="inline-flex items-center gap-1 '.$colorClass.'">'.
                                e($label).' <span class="text-[9px]">'.$arrow.'</span></button>';
                        };
                    @endphp
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                            <th class="px-3 py-2">{!! $thBtn('created_at', 'Waktu') !!}</th>
                            <th class="px-3 py-2">{!! $thBtn('causer_type', 'User') !!}</th>
                            <th class="px-3 py-2">{!! $thBtn('log_name', 'Kategori') !!}</th>
                            <th class="px-3 py-2">{!! $thBtn('event', 'Event') !!}</th>
                            <th class="px-3 py-2">Deskripsi</th>
                            <th class="px-3 py-2">{!! $thBtn('subject_type', 'Subject') !!}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $eventColor = fn (?string $ev) => match (true) {
                                str_starts_with($ev ?? '', 'utj.')       => 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
                                str_starts_with($ev ?? '', 'realisasi.') => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300',
                                str_starts_with($ev ?? '', 'refund.')    => 'bg-orange-100 text-orange-800 dark:bg-orange-950/40 dark:text-orange-300',
                                str_starts_with($ev ?? '', 'materai.')   => 'bg-purple-100 text-purple-800 dark:bg-purple-950/40 dark:text-purple-300',
                                str_starts_with($ev ?? '', 'konsumen.')  => 'bg-teal-100 text-teal-800 dark:bg-teal-950/40 dark:text-teal-300',
                                str_starts_with($ev ?? '', 'booking.')   => 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300',
                                $ev === 'spr.submitted'                  => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
                                $ev === 'spr.approved'                   => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                                $ev === 'spr.rejected'                   => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                                $ev === 'spr.cancelled'                  => 'bg-orange-100 text-orange-800 dark:bg-orange-950/40 dark:text-orange-300',
                                $ev === 'spr.akad'                       => 'bg-violet-100 text-violet-800 dark:bg-violet-950/40 dark:text-violet-300',
                                str_starts_with($ev ?? '', 'unit.')      => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300',
                                default                                  => 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300',
                            };
                            $categoryColor = fn (?string $ln) => match ($ln) {
                                'penjualan' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
                                'keuangan'  => 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
                                'unit'      => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300',
                                default     => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                            };
                        @endphp
                        @forelse ($activities as $act)
                            @php
                                $props = $act->properties ?? collect();
                                // Kalau event `konsumen.signed` → tampilkan nama konsumen dari properties + label "Konsumen"
                                if ($act->event === 'konsumen.signed' && ! $act->causer) {
                                    $causerLabel = $props['customer'] ?? 'Konsumen';
                                    $causerType = 'Konsumen';
                                } else {
                                    $causerLabel = $act->causer?->name ?? $act->causer?->nama ?? '—';
                                    $causerType = $act->causer_type ? class_basename($act->causer_type) : 'Sistem';
                                }
                                $subjectType = $act->subject_type ? class_basename($act->subject_type) : '—';
                            @endphp
                            <tr class="border-t border-zinc-100 align-top hover:bg-zinc-50/50 dark:border-zinc-800 dark:hover:bg-zinc-800/30">
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-400"
                                    title="{{ $act->created_at?->format('d M Y H:i:s') }}">
                                    <div class="font-semibold">{{ $act->created_at?->format('d/m/y H:i') }}</div>
                                    <div class="text-[9px] text-zinc-400">{{ $act->created_at?->diffForHumans() }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $causerLabel }}</div>
                                    <div class="text-[9px] uppercase text-zinc-400">{{ $causerType }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    @if ($act->log_name)
                                        <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $categoryColor($act->log_name) }}">
                                            {{ $kategoriLabel($act->log_name) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $eventColor($act->event) }}"
                                          title="{{ $act->event ?? '' }}">
                                        {{ \App\Support\BusinessActivityLogger::labelFor($act->event) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-zinc-900 dark:text-white">
                                    <div>{{ \App\Support\BusinessActivityLogger::shortenDesc($act->description) }}</div>
                                    @if ($props->isNotEmpty())
                                        <details class="mt-1">
                                            <summary class="cursor-pointer text-[10px] text-zinc-400 hover:text-zinc-600">Properties</summary>
                                            <pre class="mt-1 max-w-md overflow-auto rounded bg-zinc-50 p-2 text-[10px] text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">{{ json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </details>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-zinc-500">
                                    <div>{{ $subjectType }}</div>
                                    @if ($act->subject_id)
                                        <div class="font-mono text-[9px]">#{{ $act->subject_id }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-12 text-center text-zinc-400">Tidak ada log yang cocok dengan filter.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $activities->links() }}</div>
        </div>

    </div>
</section>
