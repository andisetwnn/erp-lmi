<?php

use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Database Konsumen'), Layout('layouts.dbos')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    #[Url(as: 'sumber')]
    public string $filterSumber = '';

    #[Url(as: 'tgl')]
    public ?string $filterTanggal = null;

    #[Url(as: 'sort')]
    public string $sort = 'terbaru';

    #[Url(as: 'view')]
    public string $viewMode = 'card'; // 'card' | 'table'

    // State change-status modal
    public ?int $statusEditId = null;

    public ?string $statusEditNama = null;

    public ?string $statusEditCurrent = null;

    public string $statusNew = 'cold';

    public string $statusCatatan = '';

    // Snapshot checklist FINISH (read-only dari prospect) — bentuk: [ ['label' => '...', 'ok' => bool], ... ]
    public array $statusChecklist = [];

    // State riwayat modal
    public ?int $riwayatProspectId = null;

    public ?string $riwayatNama = null;

    // State detail modal
    public ?int $detailId = null;

    // ============= BULK SELECTION =============
    public array $selectedIds = [];

    public string $bulkStatusNew = 'archive';

    public string $bulkCatatan = '';

    public function with(): array
    {
        $salesId = Auth::guard('sales')->id();

        $base = ProspectCustomer::query()
            ->where('sales_id', $salesId)
            ->with('proyek:id,nama_proyek');

        // Counts per status (sebelum filter)
        $counts = (clone $base)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        // Distinct sumber untuk filter dropdown
        $sumberOptions = ProspectCustomer::where('sales_id', $salesId)
            ->whereNotNull('sumber')
            ->distinct()
            ->orderBy('sumber')
            ->pluck('sumber');

        // Sort mapping
        [$sortCol, $sortDir] = match ($this->sort) {
            'terlama'  => ['created_at', 'asc'],
            'nama_asc' => ['nama_lengkap', 'asc'],
            'nama_desc'=> ['nama_lengkap', 'desc'],
            default    => ['created_at', 'desc'], // 'terbaru'
        };

        $listQuery = (clone $base)
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSumber, fn ($q) => $q->where('sumber', $this->filterSumber))
            ->when($this->filterTanggal, fn ($q) => $q->whereDate('created_at', $this->filterTanggal))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nama_lengkap', 'like', $term)
                        ->orWhere('hp', 'like', $term)
                        ->orWhere('nik', 'like', $term);
                });
            })
            ->orderBy($sortCol, $sortDir);

        // Status log untuk modal riwayat
        $riwayatLogs = $this->riwayatProspectId
            ? ProspectCustomerStatusLog::with('changedBy:id,nama,kode')
                ->where('prospect_customer_id', $this->riwayatProspectId)
                ->orderByDesc('created_at')
                ->get()
            : collect();

        // Prospect lengkap untuk modal detail
        $detailProspect = $this->detailId
            ? ProspectCustomer::with([
                'proyek:id,nama_proyek',
                'tempatKerja:id,nama',
                'bank:id,nama',
                'kontakDarurat:id,prospect_customer_id,nama,hubungan,nomor_telepon',
            ])
                ->where('sales_id', $salesId)
                ->find($this->detailId)
            : null;

        return [
            'rows' => $listQuery->paginate(15),
            'sumberOptions' => $sumberOptions,
            'riwayatLogs' => $riwayatLogs,
            'detailProspect' => $detailProspect,
            'countAll'     => array_sum($counts),
            'countCold'    => $counts['cold'] ?? 0,
            'countWarm'    => $counts['warm'] ?? 0,
            'countHot'     => $counts['hot'] ?? 0,
            'countFinish'  => $counts['finish'] ?? 0,
            'countArchive' => $counts['archive'] ?? 0,
        ];
    }

    public function updatingSearch(): void { $this->resetPage(); }

    public function updatingFilterStatus(): void { $this->resetPage(); }

    public function updatingFilterSumber(): void { $this->resetPage(); }

    public function updatingFilterTanggal(): void { $this->resetPage(); }

    public function updatingSort(): void { $this->resetPage(); }

    public function setStatus(string $s): void
    {
        $this->filterStatus = $s;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterSumber', 'filterTanggal']);
        $this->sort = 'terbaru';
        $this->resetPage();
    }

    public function openStatusModal(int $id): void
    {
        $p = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
            ->with('kontakDarurat')
            ->find($id);
        if (! $p) return;

        $this->statusEditId = $p->id;
        $this->statusEditNama = $p->nama_lengkap;
        $this->statusEditCurrent = $p->status;
        $this->statusNew = $p->status;
        $this->statusCatatan = '';
        // Snapshot checklist FINISH (single source of truth dari model)
        $this->statusChecklist = $p->finishChecklist();
        $this->resetErrorBag();

        Flux::modal('prospect-status')->show();
    }

    public function saveStatus(): void
    {
        $this->validate([
            'statusNew' => ['required', 'in:cold,warm,hot,finish,archive'],
            'statusCatatan' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'statusCatatan' => 'catatan',
        ]);

        // Kalau target = finish, hard-block kalau ada field yang missing di prospect
        if ($this->statusNew === 'finish') {
            $p = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
                ->find($this->statusEditId);

            $missing = $p ? $p->missingForFinish() : ['data prospect'];
            if (! empty($missing)) {
                Flux::toast(
                    variant: 'danger',
                    heading: 'Belum bisa FINISH',
                    text: 'Lengkapi dulu via Edit prospect: '.implode(', ', $missing),
                );
                return;
            }
        }

        $statusChanged = $this->statusEditCurrent !== $this->statusNew;
        $hasCatatan = trim($this->statusCatatan) !== '';

        if (! $statusChanged && ! $hasCatatan) {
            Flux::toast(variant: 'warning', text: 'Tidak ada perubahan.');
            return;
        }

        DB::transaction(function () use ($statusChanged) {
            ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
                ->where('id', $this->statusEditId)
                ->update(['status' => $this->statusNew]);

            // Convention: status_dari = NULL hanya untuk log inisial saat prospect dibuat.
            // Kalau cuma tambah catatan (no status change), simpan current status (= status_ke)
            // supaya bisa dibedakan dari log inisial di tampilan riwayat.
            ProspectCustomerStatusLog::create([
                'prospect_customer_id' => $this->statusEditId,
                'status_dari' => $statusChanged ? $this->statusEditCurrent : $this->statusNew,
                'status_ke' => $this->statusNew,
                'catatan' => trim($this->statusCatatan) ?: null,
                'changed_by_sales_id' => Auth::guard('sales')->id(),
            ]);
        });

        Flux::modal('prospect-status')->close();
        $this->statusEditId = null;
        $this->statusEditNama = null;
        $this->statusEditCurrent = null;
        $this->statusCatatan = '';

        Flux::toast(variant: 'success', text: $statusChanged
            ? 'Status diperbarui & dicatat di riwayat.'
            : 'Catatan ditambahkan ke riwayat.');
    }

    public function openRiwayatModal(int $id): void
    {
        $p = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())->find($id);
        if (! $p) return;

        $this->riwayatProspectId = $p->id;
        $this->riwayatNama = $p->nama_lengkap;

        Flux::modal('prospect-riwayat')->show();
    }

    public function openDetailModal(int $id): void
    {
        $this->detailId = $id;
        Flux::modal('prospect-detail')->show();
    }

    // ============= BULK ACTIONS =============

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function openBulkStatusModal(): void
    {
        if (empty($this->selectedIds)) {
            return;
        }
        $this->bulkStatusNew = 'archive';
        $this->bulkCatatan = '';
        $this->resetErrorBag();
        Flux::modal('bulk-status')->show();
    }

    public function bulkSaveStatus(): void
    {
        $this->validate([
            'bulkStatusNew' => ['required', 'in:cold,warm,hot,finish,archive'],
            'bulkCatatan' => ['nullable', 'string', 'max:1000'],
            'selectedIds' => ['required', 'array', 'min:1'],
        ], [
            'selectedIds.required' => 'Tidak ada prospect yang dipilih.',
        ], [
            'bulkStatusNew' => 'status baru',
            'bulkCatatan' => 'catatan',
        ]);

        // Bulk FINISH tidak diizinkan — tiap prospect butuh checklist NIK/foto/BI individual
        if ($this->bulkStatusNew === 'finish') {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa bulk ke FINISH',
                text: 'Status FINISH butuh kelengkapan data per prospect. Update satu per satu via tombol Status.',
            );
            return;
        }

        $salesId = Auth::guard('sales')->id();
        $catatan = trim($this->bulkCatatan) ?: 'Bulk update status';

        // Ambil prospect-prospect yang bener-bener milik sales ini (defensive)
        $prospects = ProspectCustomer::where('sales_id', $salesId)
            ->whereIn('id', $this->selectedIds)
            ->get(['id', 'status']);

        if ($prospects->isEmpty()) {
            return;
        }

        $changedCount = 0;
        DB::transaction(function () use ($prospects, $salesId, $catatan, &$changedCount) {
            foreach ($prospects as $p) {
                $oldStatus = $p->status;
                $newStatus = $this->bulkStatusNew;

                // Skip kalau status udah sama (gak ada perubahan, no log)
                if ($oldStatus === $newStatus) {
                    continue;
                }

                ProspectCustomer::where('id', $p->id)->update(['status' => $newStatus]);

                ProspectCustomerStatusLog::create([
                    'prospect_customer_id' => $p->id,
                    'status_dari' => $oldStatus,
                    'status_ke' => $newStatus,
                    'catatan' => $catatan,
                    'changed_by_sales_id' => $salesId,
                ]);
                $changedCount++;
            }
        });

        Flux::modal('bulk-status')->close();
        $this->selectedIds = [];
        $this->bulkCatatan = '';

        Flux::toast(
            variant: 'success',
            text: $changedCount > 0
                ? $changedCount.' prospect berhasil dipindahkan ke '.strtoupper($this->bulkStatusNew).'.'
                : 'Tidak ada perubahan (status sudah sama).',
        );
    }

    public function clearTanggal(): void
    {
        $this->filterTanggal = null;
        $this->resetPage();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['card', 'table']) ? $mode : 'card';
    }
}; ?>

<section class="px-4 pb-24 pt-5">

    {{-- ============== FAB BULK ACTION (muncul kalau ada selection) ============== --}}
    @if (! empty($selectedIds))
        <div x-data="{ open: false }"
             @click.outside="open = false"
             @keydown.escape.window="open = false"
             class="fixed bottom-24 right-4 z-40 sm:right-6">

            {{-- Menu pop-up (muncul saat open) --}}
            <div x-show="open" x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="mb-3 flex flex-col items-end gap-2"
                 style="transform-origin: bottom right;">

                {{-- Label info --}}
                <div class="rounded-lg bg-zinc-900/90 px-3 py-1.5 text-[11px] font-semibold text-white shadow-lg backdrop-blur-sm">
                    {{ count($selectedIds) }} {{ __('prospect dipilih') }}
                </div>

                {{-- Action 1: Ubah Status --}}
                <button type="button" wire:click="openBulkStatusModal" @click="open = false"
                        class="inline-flex items-center gap-2 rounded-2xl bg-orange-600 px-4 py-3 text-sm font-semibold text-white shadow-xl transition active:scale-95 hover:bg-orange-700">
                    <flux:icon.arrow-path class="size-4" />
                    {{ __('Ubah Status Sekaligus') }}
                </button>

                {{-- Action 2: Batal Pilih --}}
                <button type="button" wire:click="clearSelection" @click="open = false"
                        class="inline-flex items-center gap-2 rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm font-semibold text-zinc-700 shadow-xl transition active:scale-95 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <flux:icon.x-mark class="size-4" />
                    {{ __('Batal Pilih') }}
                </button>
            </div>

            {{-- FAB Trigger --}}
            <button type="button" @click="open = !open"
                    class="relative inline-flex h-14 w-14 items-center justify-center rounded-full bg-orange-600 text-white shadow-xl ring-4 ring-white transition active:scale-90 hover:bg-orange-700 dark:ring-zinc-950"
                    :class="{ 'rotate-45': open }">
                <flux:icon.bolt class="size-6 transition-transform" />
                <span class="absolute -right-1 -top-1 inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-white px-1.5 text-xs font-bold tabular-nums text-orange-600 shadow-md ring-2 ring-orange-600">
                    {{ count($selectedIds) }}
                </span>
            </button>
        </div>
    @endif

    {{-- HEADER --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-600 text-white">
                <flux:icon.circle-stack class="size-5" />
            </div>
            <div>
                <h1 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Database Konsumen') }}</h1>
            </div>
        </div>

        <a href="{{ route('dbos.database.create') }}" wire:navigate
           class="inline-flex h-10 items-center gap-1.5 rounded-full bg-orange-600 px-4 text-sm font-semibold text-white shadow active:scale-95">
            <flux:icon.plus class="size-4" />
            {{ __('Tambah') }}
        </a>
    </div>

    {{-- STATUS TABS (horizontal scroll mobile) --}}
    @php
        $tabs = [
            ['key' => '',        'label' => __('Semua'),   'count' => $countAll,     'color' => 'orange'],
            ['key' => 'cold',    'label' => __('Cold'),    'count' => $countCold,    'color' => 'blue'],
            ['key' => 'warm',    'label' => __('Warm'),    'count' => $countWarm,    'color' => 'amber'],
            ['key' => 'hot',     'label' => __('Hot'),     'count' => $countHot,     'color' => 'red'],
            ['key' => 'finish',  'label' => __('Finish'),  'count' => $countFinish,  'color' => 'green'],
            ['key' => 'archive', 'label' => __('Archive'), 'count' => $countArchive, 'color' => 'zinc'],
        ];
    @endphp
    <div class="-mx-4 mb-3 overflow-x-auto px-4">
        <div class="flex w-max gap-2">
            @foreach ($tabs as $tab)
                @php
                    $isActive = $filterStatus === $tab['key'];
                    $colorMap = [
                        'orange' => 'border-orange-500 bg-orange-500 text-white',
                        'blue'    => 'border-blue-500 bg-blue-500 text-white',
                        'amber'   => 'border-amber-500 bg-amber-500 text-white',
                        'red'     => 'border-red-500 bg-red-500 text-white',
                        'green'   => 'border-green-500 bg-green-500 text-white',
                        'zinc'    => 'border-zinc-500 bg-zinc-500 text-white',
                    ];
                    $cls = $isActive
                        ? $colorMap[$tab['color']]
                        : 'border-zinc-200 bg-white text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300';
                @endphp
                <button type="button" wire:click="setStatus('{{ $tab['key'] }}')"
                        @class([
                            'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm font-semibold transition active:scale-95',
                            $cls,
                        ])>
                    {{ $tab['label'] }}
                    <span @class([
                        'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs',
                        'bg-white/25' => $isActive,
                        'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => ! $isActive,
                    ])>{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- SEARCH + DATE FILTER --}}
    <div class="mb-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                    :placeholder="__('Cari nama, HP, atau KTP...')" />
    </div>

    {{-- ADDITIONAL FILTERS (sumber + tanggal + sort) --}}
    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
        <flux:select wire:model.live="filterSumber" placeholder="{{ __('Sumber') }}">
            <flux:select.option value="">{{ __('Semua Sumber') }}</flux:select.option>
            @foreach ($sumberOptions as $s)
                <flux:select.option value="{{ $s }}">{{ $s }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex items-center gap-1">
            <flux:input wire:model.live="filterTanggal" type="date" class="flex-1" />
            @if ($filterTanggal)
                <button type="button" wire:click="clearTanggal"
                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900"
                        title="{{ __('Reset tanggal') }}">
                    <flux:icon.x-mark class="size-4" />
                </button>
            @endif
        </div>

        <flux:select wire:model.live="sort" class="col-span-2 sm:col-span-1">
            <flux:select.option value="terbaru">{{ __('Terbaru') }}</flux:select.option>
            <flux:select.option value="terlama">{{ __('Terlama') }}</flux:select.option>
            <flux:select.option value="nama_asc">{{ __('Nama A-Z') }}</flux:select.option>
            <flux:select.option value="nama_desc">{{ __('Nama Z-A') }}</flux:select.option>
        </flux:select>
    </div>

    @if ($search || $filterStatus || $filterSumber || $filterTanggal || $sort !== 'terbaru')
        <div class="mb-3 flex items-center justify-between rounded-lg bg-orange-50 px-3 py-2 text-xs text-orange-700 dark:bg-orange-950/30 dark:text-orange-300">
            <span>{{ __('Filter aktif') }}</span>
            <button type="button" wire:click="resetFilters" class="font-semibold underline">
                {{ __('Reset semua') }}
            </button>
        </div>
    @endif

    {{-- VIEW MODE TOGGLE + RESULT COUNT --}}
    <div class="mb-3 flex items-center justify-between">
        <p class="text-xs text-zinc-500">
            {{ __('Menampilkan :showing dari :total data', [
                'showing' => $rows->count(),
                'total' => number_format($rows->total(), 0, ',', '.'),
            ]) }}
        </p>

        <div class="inline-flex overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <button type="button" wire:click="setViewMode('card')"
                    @class([
                        'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold transition',
                        'bg-orange-600 text-white' => $viewMode === 'card',
                        'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300' => $viewMode !== 'card',
                    ])>
                <flux:icon.squares-2x2 class="size-4" />
                {{ __('Card') }}
            </button>
            <button type="button" wire:click="setViewMode('table')"
                    @class([
                        'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold transition',
                        'bg-orange-600 text-white' => $viewMode === 'table',
                        'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300' => $viewMode !== 'table',
                    ])>
                <flux:icon.table-cells class="size-4" />
                {{ __('Tabel') }}
            </button>
        </div>
    </div>

    {{-- LIST --}}
    @if ($rows->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.circle-stack class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                {{ __('Belum ada data') }}
            </p>
            <p class="mt-1 text-xs text-zinc-500">
                @if ($search || $filterStatus || $filterTanggal || $filterSumber)
                    {{ __('Filter tidak menemukan hasil.') }}
                @else
                    {{ __('Tambah prospek pertama Anda dengan tombol "Tambah" di atas.') }}
                @endif
            </p>
        </div>
    @elseif ($viewMode === 'card')
        <div class="space-y-2">
            @foreach ($rows as $i => $row)
                @php
                    $statusBadge = match ($row->status) {
                        'cold'    => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',     'COLD'],
                        'warm'    => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                        'hot'     => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',         'HOT'],
                        'finish'  => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                        'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',        'ARCHIVE'],
                    };
                    $waLink = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $row->hp);
                @endphp
                @php
                    // Progress kelengkapan data (untuk badge)
                    $hasNik = ! empty($row->nik);
                    $hasFoto = ! empty($row->foto_ktp);
                    $hasBi = $row->bi_kol !== null && $row->bi_dbr !== null;
                    $completeForFinish = $hasNik && $hasFoto && $hasBi;
                    $completionCount = ($hasNik ? 1 : 0) + ($hasFoto ? 1 : 0) + ($hasBi ? 1 : 0);
                @endphp
                @php $isSelected = in_array($row->id, $selectedIds); @endphp
                <div @class([
                    'rounded-2xl border bg-white p-4 shadow-sm transition dark:bg-zinc-900',
                    'border-orange-500 ring-2 ring-orange-500/30 dark:border-orange-500' => $isSelected,
                    'border-zinc-200 dark:border-zinc-700' => ! $isSelected,
                ])>
                    {{-- Top: checkbox + nama + status badge --}}
                    <div class="flex items-start justify-between gap-2">
                        <label class="mt-0.5 inline-flex shrink-0 cursor-pointer items-center" @click.stop>
                            <input type="checkbox" value="{{ $row->id }}" wire:model.live="selectedIds"
                                   class="size-4 cursor-pointer rounded border-zinc-300 text-orange-600 focus:ring-orange-500 dark:border-zinc-600 dark:bg-zinc-800" />
                        </label>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-zinc-400">#{{ ($rows->firstItem() ?? 1) + $i }}</span>
                                <h3 class="truncate font-bold text-zinc-900 dark:text-white">{{ $row->nama_lengkap }}</h3>
                            </div>
                            <a href="{{ $waLink }}" target="_blank"
                               class="mt-0.5 inline-flex items-center gap-1 font-mono text-sm text-green-600 hover:underline dark:text-green-400">
                                <flux:icon.phone class="size-3.5" />
                                {{ $row->hp }}
                            </a>
                        </div>
                        <span @class([
                            'shrink-0 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider',
                            $statusBadge[0],
                        ])>{{ $statusBadge[1] }}</span>
                    </div>

                    {{-- Data completeness indicator (kalau belum FINISH, tidak relevan untuk archive) --}}
                    @if ($row->status !== 'finish' && $row->status !== 'archive')
                        <div class="mt-2 flex items-center gap-2 text-[10px]">
                            @if ($completeForFinish)
                                <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 font-semibold text-green-700 dark:bg-green-950/50 dark:text-green-300">
                                    <flux:icon.check-circle class="size-3" />
                                    {{ __('Siap FINISH') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ __('Data') }} {{ $completionCount }}/3
                                </span>
                                <span class="text-zinc-500">
                                    @if (! $hasNik) · NIK @endif
                                    @if (! $hasFoto) · Foto @endif
                                    @if (! $hasBi) · BI @endif
                                </span>
                            @endif
                        </div>
                    @endif

                    {{-- Mid: meta info --}}
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <div class="text-zinc-400">{{ __('Proyek') }}</div>
                            <div class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $row->proyek?->nama_proyek ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-zinc-400">{{ __('Sumber') }}</div>
                            <div class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $row->sumber }}</div>
                        </div>
                    </div>

                    <div class="mt-2 text-[11px] text-zinc-400">
                        {{ $row->created_at?->translatedFormat('d M Y · H:i') ?? '—' }}
                    </div>

                    {{-- Action buttons --}}
                    <div class="mt-3 grid grid-cols-4 gap-1.5 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <button type="button" wire:click="openDetailModal({{ $row->id }})"
                                class="inline-flex h-10 flex-col items-center justify-center gap-0.5 rounded-lg border border-zinc-200 bg-white text-[10px] font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            <flux:icon.eye class="size-4" />
                            {{ __('Detail') }}
                        </button>
                        <a href="{{ route('dbos.database.edit', $row->id) }}" wire:navigate
                           class="inline-flex h-10 flex-col items-center justify-center gap-0.5 rounded-lg border border-zinc-200 bg-white text-[10px] font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            <flux:icon.pencil-square class="size-4" />
                            {{ __('Edit') }}
                        </a>
                        <button type="button" wire:click="openRiwayatModal({{ $row->id }})"
                                class="inline-flex h-10 flex-col items-center justify-center gap-0.5 rounded-lg border border-zinc-200 bg-white text-[10px] font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            <flux:icon.clock class="size-4" />
                            {{ __('Riwayat') }}
                        </button>
                        <button type="button" wire:click="openStatusModal({{ $row->id }})"
                                class="inline-flex h-10 flex-col items-center justify-center gap-0.5 rounded-lg bg-orange-600 text-[10px] font-semibold text-white active:scale-95">
                            <flux:icon.arrow-path class="size-4" />
                            {{ __('Status') }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- TABLE VIEW (responsive, horizontal scroll on small screen) --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wider text-zinc-500">
                            <th class="px-3 py-3 text-left font-semibold">
                                @php
                                    $pageIds = $rows->pluck('id')->toArray();
                                    $allOnPageSelected = ! empty($pageIds) && empty(array_diff($pageIds, $selectedIds));
                                @endphp
                                <input type="checkbox"
                                       x-data
                                       :checked="{{ $allOnPageSelected ? 'true' : 'false' }}"
                                       @change="$wire.set('selectedIds', $event.target.checked
                                           ? [...new Set([...$wire.selectedIds, ...{{ json_encode($pageIds) }}])]
                                           : $wire.selectedIds.filter(id => !{{ json_encode($pageIds) }}.includes(id)))"
                                       class="size-4 cursor-pointer rounded border-zinc-300 text-orange-600 focus:ring-orange-500 dark:border-zinc-600 dark:bg-zinc-800"
                                       title="{{ __('Pilih semua di halaman ini') }}" />
                            </th>
                            <th class="px-3 py-3 text-left font-semibold">#</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('Nama') }}</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('HP / WA') }}</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('Proyek') }}</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('Sumber') }}</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('Status') }}</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('Tanggal') }}</th>
                            <th class="px-3 py-3 text-right font-semibold">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($rows as $i => $row)
                            @php
                                $statusBadgeT = match ($row->status) {
                                    'cold'    => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',     'COLD'],
                                    'warm'    => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                                    'hot'     => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',         'HOT'],
                                    'finish'  => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                                    'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',        'ARCHIVE'],
                                };
                                $waLinkT = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $row->hp);
                            @endphp
                            @php $isRowSelected = in_array($row->id, $selectedIds); @endphp
                            <tr @class([
                                'hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30',
                                'bg-orange-50/50 dark:bg-orange-950/20' => $isRowSelected,
                            ])>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <input type="checkbox" value="{{ $row->id }}" wire:model.live="selectedIds"
                                           class="size-4 cursor-pointer rounded border-zinc-300 text-orange-600 focus:ring-orange-500 dark:border-zinc-600 dark:bg-zinc-800" />
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-zinc-400">
                                    {{ ($rows->firstItem() ?? 1) + $i }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $row->nama_lengkap }}</div>
                                    @if ($row->nik)
                                        <div class="font-mono text-[10px] text-zinc-400">{{ $row->nik }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <a href="{{ $waLinkT }}" target="_blank"
                                       class="inline-flex items-center gap-1 font-mono text-xs text-green-600 hover:underline dark:text-green-400">
                                        <flux:icon.phone class="size-3" />
                                        {{ $row->hp }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ $row->proyek?->nama_proyek ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ $row->sumber }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                        $statusBadgeT[0],
                                    ])>{{ $statusBadgeT[1] }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-zinc-500">
                                    {{ $row->created_at?->translatedFormat('d M Y') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" wire:click="openDetailModal({{ $row->id }})"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-600 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                                title="{{ __('Detail') }}">
                                            <flux:icon.eye class="size-4" />
                                        </button>
                                        <a href="{{ route('dbos.database.edit', $row->id) }}" wire:navigate
                                           class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-600 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                           title="{{ __('Edit') }}">
                                            <flux:icon.pencil-square class="size-4" />
                                        </a>
                                        <button type="button" wire:click="openRiwayatModal({{ $row->id }})"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-600 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                                title="{{ __('Riwayat') }}">
                                            <flux:icon.clock class="size-4" />
                                        </button>
                                        <button type="button" wire:click="openStatusModal({{ $row->id }})"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-orange-600 text-white active:scale-95"
                                                title="{{ __('Ubah Status') }}">
                                            <flux:icon.arrow-path class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if (! $rows->isEmpty())
        <div class="mt-4">
            <flux:pagination :paginator="$rows" />
        </div>
    @endif

    {{-- MODAL: Change Status (dengan catatan + BI checking kalau FINISH) --}}
    <flux:modal name="prospect-status" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Ubah Status & Catat Aktivitas') }}</flux:heading>
                <flux:subheading>
                    {{ __('Prospek:') }} <span class="font-semibold">{{ $statusEditNama ?? '—' }}</span>
                </flux:subheading>
            </div>

            <div class="space-y-2">
                <flux:label class="text-xs uppercase tracking-wider">{{ __('Status') }}</flux:label>
                @foreach ([
                    'cold'    => ['Cold',    'Baru lead, belum follow up',              'blue'],
                    'warm'    => ['Warm',    'Sudah follow up, ada interest',           'amber'],
                    'hot'     => ['Hot',     'Siap booking / sangat tertarik',          'red'],
                    'finish'  => ['Finish',  'Siap booking / sudah booking',            'green'],
                    'archive' => ['Archive', 'Tidak ada respon — parkir, tidak follow up lagi', 'zinc'],
                ] as $key => $info)
                    @php
                        $checked = $statusNew === $key;
                        $borderColor = match ($info[2]) {
                            'blue'  => 'border-blue-500 bg-blue-50 dark:bg-blue-950/30',
                            'amber' => 'border-amber-500 bg-amber-50 dark:bg-amber-950/30',
                            'red'   => 'border-red-500 bg-red-50 dark:bg-red-950/30',
                            'green' => 'border-green-500 bg-green-50 dark:bg-green-950/30',
                            'zinc'  => 'border-zinc-500 bg-zinc-50 dark:bg-zinc-800/40',
                        };
                    @endphp
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-xl border-2 p-3 transition',
                        $borderColor => $checked,
                        'border-zinc-200 dark:border-zinc-700' => ! $checked,
                    ])>
                        <input type="radio" wire:model="statusNew" value="{{ $key }}" class="mt-1 accent-orange-600" />
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $info[0] }}</div>
                                @if ($statusEditCurrent === $key)
                                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[9px] font-bold uppercase text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ __('Saat ini') }}
                                    </span>
                                @endif
                            </div>
                            <div class="text-xs text-zinc-500">{{ $info[1] }}</div>
                        </div>
                    </label>
                @endforeach
            </div>

            {{-- ============= CHECKLIST untuk target FINISH ============= --}}
            @if ($statusNew === 'finish')
                @php
                    $checks = $statusChecklist;
                    $missingChecks = array_filter($checks, fn ($c) => ! $c['ok']);
                    $allOk = empty($missingChecks);
                @endphp

                <div @class([
                    'rounded-xl border-2 p-4',
                    'border-green-300 bg-green-50 dark:border-green-700 dark:bg-green-950/30' => $allOk,
                    'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-950/30' => ! $allOk,
                ])>
                    <div class="flex items-start gap-2">
                        @if ($allOk)
                            <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-green-600 dark:text-green-400" />
                        @else
                            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" />
                        @endif
                        <div class="flex-1">
                            <h3 @class([
                                'text-sm font-bold',
                                'text-green-900 dark:text-green-200' => $allOk,
                                'text-red-900 dark:text-red-200' => ! $allOk,
                            ])>
                                {{ $allOk ? __('Semua data lengkap, siap FINISH') : __('Belum bisa FINISH — ada data yang kurang') }}
                            </h3>

                            <ul class="mt-2 space-y-1 text-xs">
                                @foreach ($checks as $c)
                                    <li class="flex items-center gap-2">
                                        @if ($c['ok'])
                                            <flux:icon.check-circle class="size-4 text-green-600 dark:text-green-400" />
                                            <span class="text-green-800 dark:text-green-300">{{ $c['label'] }}</span>
                                        @else
                                            <flux:icon.x-circle class="size-4 text-red-500 dark:text-red-400" />
                                            <span class="text-red-800 dark:text-red-300">{{ $c['label'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            @if (! $allOk)
                                <flux:modal.close>
                                    <a href="{{ route('dbos.database.edit', $statusEditId) }}" wire:navigate
                                       class="mt-3 inline-flex items-center gap-1 rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">
                                        {{ __('Edit prospect & lengkapi') }} →
                                    </a>
                                </flux:modal.close>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <flux:field>
                <flux:label class="text-xs uppercase tracking-wider">{{ __('Catatan Aktivitas') }}</flux:label>
                <flux:textarea wire:model="statusCatatan" rows="3"
                               placeholder="Contoh: Sudah follow up via WA, customer minta jadwal survey lokasi Sabtu pagi." />
                <flux:description>
                    {{ __('Opsional. Catatan akan disimpan ke riwayat perubahan status.') }}
                </flux:description>
                <flux:error name="statusCatatan" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                @php
                    $blockFinish = $statusNew === 'finish'
                        && collect($statusChecklist)->contains(fn ($c) => ! $c['ok']);
                @endphp
                <flux:button variant="primary" type="button" wire:click="saveStatus"
                             :disabled="$blockFinish"
                             class="bg-orange-600! hover:bg-orange-700! disabled:bg-zinc-300! disabled:cursor-not-allowed">
                    {{ __('Simpan') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: Riwayat Status --}}
    <flux:modal name="prospect-riwayat" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.clock class="size-5 text-orange-600" />
                    <flux:heading size="lg">{{ __('Riwayat Status') }}</flux:heading>
                </div>
                <flux:subheading>
                    {{ __('Prospek:') }} <span class="font-semibold">{{ $riwayatNama ?? '—' }}</span>
                </flux:subheading>
            </div>

            @if ($riwayatLogs->isEmpty())
                <div class="rounded-md border-2 border-dashed border-zinc-200 px-4 py-10 text-center text-zinc-500 dark:border-zinc-700">
                    <flux:icon.clock class="mx-auto size-10 text-zinc-400" />
                    <p class="mt-2 text-sm">{{ __('Belum ada riwayat perubahan.') }}</p>
                </div>
            @else
                @php
                    $statusBadgeColors = [
                        'cold'    => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',     'COLD'],
                        'warm'    => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                        'hot'     => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',         'HOT'],
                        'finish'  => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                        'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',        'ARCHIVE'],
                    ];
                @endphp
                <ol class="relative ms-2 space-y-4 border-s-2 border-zinc-200 ps-5 dark:border-zinc-700">
                    @foreach ($riwayatLogs as $log)
                        @php
                            // riwayatLogs ordered by created_at DESC, jadi $loop->last = log paling lama (initial creation).
                            // "Initial" cuma valid kalau ini log paling pertama DAN status_dari null.
                            $isInitial = $loop->last && $log->status_dari === null;
                            $isStatusChange = $log->status_dari !== null && $log->status_dari !== $log->status_ke;
                            // Sisanya = catatan only (status_dari = status_ke, atau data lama yang status_dari=null tapi bukan log pertama)
                        @endphp
                        <li class="relative">
                            <span class="absolute -inset-s-6.75 mt-1.5 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-orange-500 ring-4 ring-white dark:ring-zinc-900"></span>

                            <div class="text-xs text-zinc-500">
                                {{ $log->created_at?->translatedFormat('d M Y · H:i') ?? '—' }}
                                @if ($log->changedBy)
                                    <span class="text-zinc-400">· oleh</span> <span class="font-semibold">{{ $log->changedBy->nama }}</span>
                                @endif
                            </div>

                            <div class="mt-1.5 flex items-center gap-2 text-sm">
                                @if ($isInitial)
                                    <flux:icon.plus-circle class="size-4 text-orange-600" />
                                    <span class="font-semibold text-zinc-900 dark:text-white">{{ __('Prospect dibuat dengan status') }}</span>
                                    <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $statusBadgeColors[$log->status_ke][0] ?? 'bg-zinc-100'])>
                                        {{ $statusBadgeColors[$log->status_ke][1] ?? strtoupper($log->status_ke) }}
                                    </span>
                                @elseif ($isStatusChange)
                                    <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $statusBadgeColors[$log->status_dari][0] ?? 'bg-zinc-100'])>
                                        {{ $statusBadgeColors[$log->status_dari][1] ?? strtoupper($log->status_dari) }}
                                    </span>
                                    <flux:icon.arrow-right class="size-3.5 text-zinc-400" />
                                    <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $statusBadgeColors[$log->status_ke][0] ?? 'bg-zinc-100'])>
                                        {{ $statusBadgeColors[$log->status_ke][1] ?? strtoupper($log->status_ke) }}
                                    </span>
                                @else
                                    <flux:icon.pencil-square class="size-4 text-zinc-500" />
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ __('Catatan ditambahkan pada status') }}</span>
                                    <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $statusBadgeColors[$log->status_ke][0] ?? 'bg-zinc-100'])>
                                        {{ $statusBadgeColors[$log->status_ke][1] ?? strtoupper($log->status_ke) }}
                                    </span>
                                @endif
                            </div>

                            @if ($log->catatan)
                                <div class="mt-1.5 rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                                    {{ $log->catatan }}
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: Detail Prospek --}}
    <flux:modal name="prospect-detail" class="md:w-xl">
        @if ($detailProspect)
            @php
                $d = $detailProspect;
                $waPhone = preg_replace('/[^0-9]/', '', $d->hp ?? '');
                $waLinkD = $waPhone ? 'https://wa.me/'.$waPhone : null;
                $detailStatusBadge = match ($d->status) {
                    'cold'    => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',     'COLD'],
                    'warm'    => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                    'hot'     => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',         'HOT'],
                    'finish'  => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                    'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',        'ARCHIVE'],
                };
                $hasNikD = ! empty($d->nik);
                $hasFotoD = ! empty($d->foto_ktp);
                $hasBiD = $d->bi_kol !== null && $d->bi_dbr !== null;
                $alamatPenuh = collect([
                    $d->alamat,
                    $d->kelurahan_nama,
                    $d->kecamatan_nama,
                    $d->kota_nama,
                    $d->provinsi_nama,
                ])->filter()->implode(', ');
                $kolLabel = match ($d->bi_kol) {
                    '1' => 'KOL 1 — Lancar',
                    '2' => 'KOL 2 — Dalam Perhatian Khusus',
                    '3' => 'KOL 3 — Kurang Lancar',
                    '4' => 'KOL 4 — Diragukan',
                    '5' => 'KOL 5 — Macet',
                    default => null,
                };
            @endphp

            <div class="space-y-4">
                {{-- HEADER --}}
                <div class="flex items-start justify-between gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">{{ $d->nama_lengkap }}</flux:heading>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                $detailStatusBadge[0],
                            ])>{{ $detailStatusBadge[1] }}</span>
                        </div>
                        <flux:subheading>
                            #{{ $d->id }} · {{ __('Dibuat') }} {{ $d->created_at?->translatedFormat('d M Y · H:i') ?? '—' }}
                        </flux:subheading>
                    </div>
                </div>

                {{-- IDENTITAS --}}
                <div>
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Identitas') }}</div>
                    <dl class="space-y-2 rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-xs text-zinc-500">{{ __('NIK / Nomor KTP') }}</dt>
                            <dd class="text-right font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $d->nik ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-xs text-zinc-500">{{ __('NPWP') }}</dt>
                            <dd class="text-right font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $d->npwp ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-xs text-zinc-500">{{ __('Nomor HP') }}</dt>
                            <dd class="text-right text-xs">
                                @if ($waLinkD)
                                    <a href="{{ $waLinkD }}" target="_blank"
                                       class="font-mono font-semibold text-green-600 hover:underline dark:text-green-400">
                                        {{ $d->hp }}
                                    </a>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </dd>
                        </div>
                        @if ($d->hp_2)
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-xs text-zinc-500">{{ __('HP Cadangan') }}</dt>
                                <dd class="text-right font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                    {{ $d->hp_2 }}
                                </dd>
                            </div>
                        @endif
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-xs text-zinc-500">{{ __('Sumber Info') }}</dt>
                            <dd class="text-right text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $d->sumber ?? '—' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-xs text-zinc-500">{{ __('Proyek') }}</dt>
                            <dd class="text-right text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                {{ $d->proyek?->nama_proyek ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>

                {{-- FOTO KTP --}}
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Foto KTP') }}</div>
                        @if ($hasFotoD)
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-green-700 dark:bg-green-950/50 dark:text-green-300">
                                <flux:icon.check-circle class="size-2.5" />
                                {{ __('Ada') }}
                            </span>
                        @endif
                    </div>
                    @if ($hasFotoD)
                        <a href="{{ asset('storage/'.$d->foto_ktp) }}" target="_blank"
                           class="block overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 transition active:scale-[0.98] dark:border-zinc-700 dark:bg-zinc-800">
                            <img src="{{ asset('storage/'.$d->foto_ktp) }}" alt="Foto KTP"
                                 class="block w-full object-contain" style="max-height: 200px;" />
                        </a>
                    @else
                        <div class="rounded-xl border-2 border-dashed border-zinc-200 bg-white px-4 py-5 text-center dark:border-zinc-700 dark:bg-zinc-900">
                            <flux:icon.photo class="mx-auto size-6 text-zinc-400" />
                            <p class="mt-1 text-[11px] text-zinc-500">{{ __('Foto KTP belum diupload') }}</p>
                        </div>
                    @endif
                </div>

                {{-- ALAMAT --}}
                @if ($alamatPenuh)
                    <div>
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Alamat KTP') }}</div>
                        <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                            <p class="text-xs leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $alamatPenuh }}</p>
                        </div>
                    </div>
                @endif

                {{-- PEKERJAAN --}}
                @if ($d->tempatKerja)
                    <div>
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Pekerjaan') }}</div>
                        <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                            <p class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ $d->tempatKerja->nama }}</p>
                        </div>
                    </div>
                @endif

                {{-- REKENING --}}
                @if ($d->bank || $d->nomor_rekening || $d->rekening_atas_nama)
                    <div>
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Rekening') }}</div>
                        <dl class="space-y-2 rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-xs text-zinc-500">{{ __('Bank') }}</dt>
                                <dd class="text-right text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ $d->bank?->nama ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-xs text-zinc-500">{{ __('No. Rekening') }}</dt>
                                <dd class="text-right font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ $d->nomor_rekening ?? '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-xs text-zinc-500">{{ __('Atas Nama') }}</dt>
                                <dd class="text-right text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ $d->rekening_atas_nama ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                {{-- KONTAK DARURAT --}}
                @if ($d->kontakDarurat->isNotEmpty())
                    <div>
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                            {{ __('Kontak Darurat') }} ({{ $d->kontakDarurat->count() }})
                        </div>
                        <ul class="space-y-2">
                            @foreach ($d->kontakDarurat as $k)
                                @php
                                    $kHubunganLabel = \App\Models\Master\ProspectCustomerKontakDarurat::HUBUNGAN_OPTIONS[$k->hubungan] ?? ucfirst($k->hubungan);
                                    $kWa = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $k->nomor_telepon);
                                @endphp
                                <li class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-xs font-bold text-zinc-900 dark:text-zinc-100">{{ $k->nama }}</div>
                                            <div class="text-[10px] uppercase tracking-wider text-zinc-500">{{ $kHubunganLabel }}</div>
                                        </div>
                                        <a href="{{ $kWa }}" target="_blank"
                                           class="inline-flex items-center gap-1 font-mono text-xs text-green-600 hover:underline">
                                            <flux:icon.phone class="size-3" />
                                            {{ $k->nomor_telepon }}
                                        </a>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- BI CHECKING --}}
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('BI Checking') }}</div>
                        @if ($hasBiD)
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-green-700 dark:bg-green-950/50 dark:text-green-300">
                                <flux:icon.check-circle class="size-2.5" />
                                {{ __('Lengkap') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                {{ __('Belum') }}
                            </span>
                        @endif
                    </div>
                    @if ($d->bi_kol !== null || $d->bi_dbr !== null || $d->bi_keterangan)
                        <dl class="space-y-2 rounded-xl bg-amber-50 px-3 py-2.5 dark:bg-amber-950/20">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('Kolektibilitas') }}</dt>
                                <dd class="text-right text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ $kolLabel ?? '—' }}
                                </dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('DBR') }}</dt>
                                <dd class="text-right text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ $d->bi_dbr !== null ? number_format((float) $d->bi_dbr, 2).' %' : '—' }}
                                </dd>
                            </div>
                            @if ($d->bi_keterangan)
                                <div class="border-t border-amber-200 pt-2 dark:border-amber-900/50">
                                    <dt class="mb-1 text-[10px] uppercase tracking-wider text-zinc-500">{{ __('Keterangan') }}</dt>
                                    <dd class="text-xs leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $d->bi_keterangan }}</dd>
                                </div>
                            @endif
                        </dl>
                    @else
                        <div class="rounded-xl border-2 border-dashed border-zinc-200 bg-white px-4 py-3 text-center dark:border-zinc-700 dark:bg-zinc-900">
                            <p class="text-[11px] text-zinc-500">{{ __('Belum dilakukan BI checking') }}</p>
                        </div>
                    @endif
                </div>

                {{-- CATATAN --}}
                @if ($d->catatan)
                    <div>
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Catatan') }}</div>
                        <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                            <p class="text-xs leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $d->catatan }}</p>
                        </div>
                    </div>
                @endif

                {{-- FOOTER ACTIONS --}}
                <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                    @if ($waLinkD)
                        <a href="{{ $waLinkD }}" target="_blank"
                           class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-green-600 px-3 py-2.5 text-xs font-semibold text-white shadow-sm active:scale-95">
                            <flux:icon.phone class="size-3.5" />
                            {{ __('Hubungi WA') }}
                        </a>
                    @endif
                    <flux:modal.close>
                        <a href="{{ route('dbos.database.edit', $d->id) }}" wire:navigate
                           class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-xs font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            <flux:icon.pencil-square class="size-3.5" />
                            {{ __('Edit') }}
                        </a>
                    </flux:modal.close>
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- ============== MODAL: Bulk Change Status ============== --}}
    <flux:modal name="bulk-status" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.arrow-path class="size-5 text-orange-600" />
                    <flux:heading size="lg">{{ __('Ubah Status Sekaligus') }}</flux:heading>
                </div>
                <flux:subheading>
                    {{ __('Akan diterapkan ke') }}
                    <span class="font-bold text-orange-600">{{ count($selectedIds) }}</span>
                    {{ __('prospect terpilih.') }}
                </flux:subheading>
            </div>

            <div class="space-y-2">
                <flux:label class="text-xs uppercase tracking-wider">{{ __('Status Tujuan') }}</flux:label>
                @foreach ([
                    'cold'    => ['Cold',    'Pindah balik ke awal pipeline',                'blue'],
                    'warm'    => ['Warm',    'Sudah ada interaksi / interest',               'amber'],
                    'hot'     => ['Hot',     'Siap booking / sangat tertarik',               'red'],
                    'archive' => ['Archive', 'Tidak ada respon — parkir, tidak follow up lagi','zinc'],
                ] as $key => $info)
                    @php
                        $checked = $bulkStatusNew === $key;
                        $borderColor = match ($info[2]) {
                            'blue'  => 'border-blue-500 bg-blue-50 dark:bg-blue-950/30',
                            'amber' => 'border-amber-500 bg-amber-50 dark:bg-amber-950/30',
                            'red'   => 'border-red-500 bg-red-50 dark:bg-red-950/30',
                            'zinc'  => 'border-zinc-500 bg-zinc-50 dark:bg-zinc-800/40',
                        };
                    @endphp
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-xl border-2 p-3 transition',
                        $borderColor => $checked,
                        'border-zinc-200 dark:border-zinc-700' => ! $checked,
                    ])>
                        <input type="radio" wire:model="bulkStatusNew" value="{{ $key }}" class="mt-1 accent-orange-600" />
                        <div class="flex-1">
                            <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $info[0] }}</div>
                            <div class="text-xs text-zinc-500">{{ $info[1] }}</div>
                        </div>
                    </label>
                @endforeach
                <flux:error name="bulkStatusNew" />
            </div>

            <div class="rounded-lg bg-amber-50 px-3 py-2 text-[11px] text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                <flux:icon.information-circle class="-mt-0.5 mr-1 inline size-3.5" />
                {{ __('Status FINISH tidak bisa di-bulk karena butuh checklist kelengkapan data per prospect. Update satu per satu via tombol Status.') }}
            </div>

            <flux:field>
                <flux:label class="text-xs uppercase tracking-wider">{{ __('Catatan') }}</flux:label>
                <flux:textarea wire:model="bulkCatatan" rows="2"
                               placeholder="Contoh: 3x WA tidak respon, pindah Archive." />
                <flux:description>
                    {{ __('Catatan ini akan disimpan di riwayat setiap prospect yang diubah.') }}
                </flux:description>
                <flux:error name="bulkCatatan" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="button" wire:click="bulkSaveStatus"
                             class="bg-orange-600! hover:bg-orange-700!">
                    {{ __('Terapkan ke') }} {{ count($selectedIds) }} {{ __('prospect') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

</section>
