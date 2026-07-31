<?php

use App\Exports\ProspectCustomerExport;
use App\Livewire\Concerns\Sortable;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Proyek;
use App\Models\Master\Sales;
use Barryvdh\DomPDF\Facade\Pdf;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new #[Title('Prospect Customer')] class extends Component
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

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sales')]
    public string $filterSales = '';

    #[Url(as: 'proyek')]
    public string $filterProyek = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    #[Url(as: 'sumber')]
    public string $filterSumber = '';

    #[Url(as: 'tgl_from')]
    public ?string $filterTanggalFrom = null;

    #[Url(as: 'tgl_to')]
    public ?string $filterTanggalTo = null;

    #[Url(as: 'urut')]
    public string $sort = 'terbaru';

    #[Url(as: 'per')]
    public string $perPage = '20';

    // Detail modal
    public ?int $detailId = null;

    // Riwayat modal
    public ?int $riwayatProspectId = null;

    public ?string $riwayatNama = null;

    public function with(): array
    {
        $base = ProspectCustomer::query()
            ->with(['proyek:id,nama_proyek', 'sales:id,kode,nama']);

        // Counts global (sebelum filter)
        $counts = (clone $base)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $listQuery = (clone $base)
            ->when($this->filterSales, fn ($q) => $q->where('sales_id', $this->filterSales))
            ->when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSumber, fn ($q) => $q->where('sumber', $this->filterSumber))
            ->when($this->filterTanggalFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->filterTanggalFrom))
            ->when($this->filterTanggalTo, fn ($q) => $q->whereDate('created_at', '<=', $this->filterTanggalTo))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nama_lengkap', 'like', $term)
                        ->orWhere('hp', 'like', $term)
                        ->orWhere('hp_2', 'like', $term)
                        ->orWhere('nik', 'like', $term);
                });
            });

        // Sort via header kolom (kalau user klik header, override dropdown "Urutan").
        if ($this->sortBy !== '') {
            $this->applySort($listQuery, ['nama_lengkap', 'sumber', 'status', 'created_at']);
        } else {
            [$sortCol, $sortDir] = match ($this->sort) {
                'terlama' => ['created_at', 'asc'],
                'nama_asc' => ['nama_lengkap', 'asc'],
                'nama_desc' => ['nama_lengkap', 'desc'],
                default => ['created_at', 'desc'],
            };
            $listQuery->orderBy($sortCol, $sortDir);
        }

        $sumberOptions = ProspectCustomer::whereNotNull('sumber')
            ->distinct()
            ->orderBy('sumber')
            ->pluck('sumber');

        $salesOptions = Sales::orderBy('nama')->get(['id', 'kode', 'nama']);
        $proyekOptions = Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']);

        $riwayatLogs = $this->riwayatProspectId
            ? ProspectCustomerStatusLog::with('changedBy:id,nama,kode')
                ->where('prospect_customer_id', $this->riwayatProspectId)
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $detailProspect = $this->detailId
            ? ProspectCustomer::with([
                'proyek:id,nama_proyek',
                'sales:id,kode,nama',
                'tempatKerja:id,nama',
                'bank:id,nama',
                'kontakDarurat:id,prospect_customer_id,nama,hubungan,nomor_telepon',
            ])
                ->find($this->detailId)
            : null;

        return [
            'rows' => $listQuery->paginate((int) $this->perPage),
            'salesOptions' => $salesOptions,
            'proyekOptions' => $proyekOptions,
            'sumberOptions' => $sumberOptions,
            'riwayatLogs' => $riwayatLogs,
            'detailProspect' => $detailProspect,
            'countAll' => array_sum($counts),
            'countCold' => $counts['cold'] ?? 0,
            'countWarm' => $counts['warm'] ?? 0,
            'countHot' => $counts['hot'] ?? 0,
            'countFinish' => $counts['finish'] ?? 0,
            'countArchive' => $counts['archive'] ?? 0,
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterSales(): void
    {
        $this->resetPage();
    }

    public function updatingFilterProyek(): void
    {
        $this->resetPage();
    }

    public function updatingFilterSumber(): void
    {
        $this->resetPage();
    }

    public function updatingFilterTanggalFrom(): void
    {
        $this->resetPage();
    }

    public function updatingFilterTanggalTo(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function setStatus(string $s): void
    {
        $this->filterStatus = $s === $this->filterStatus ? '' : $s;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterSales', 'filterProyek', 'filterStatus', 'filterSumber', 'filterTanggalFrom', 'filterTanggalTo']);
        $this->sort = 'terbaru';
        $this->resetPage();
    }

    public function openDetailModal(int $id): void
    {
        $this->detailId = $id;
        Flux::modal('prospect-detail')->show();
    }

    public function openRiwayatModal(int $id): void
    {
        $p = ProspectCustomer::find($id);
        if (! $p) {
            return;
        }
        $this->riwayatProspectId = $p->id;
        $this->riwayatNama = $p->nama_lengkap;
        Flux::modal('prospect-riwayat')->show();
    }

    /**
     * Bundle filter sekarang untuk dipassing ke export class / query.
     */
    protected function currentFilters(): array
    {
        return [
            'search' => $this->search,
            'filterSales' => $this->filterSales,
            'filterProyek' => $this->filterProyek,
            'filterStatus' => $this->filterStatus,
            'filterSumber' => $this->filterSumber,
            'filterTanggalFrom' => $this->filterTanggalFrom,
            'filterTanggalTo' => $this->filterTanggalTo,
            'sort' => $this->sort,
        ];
    }

    /**
     * Bikin label filter human-readable untuk header PDF.
     */
    protected function buildFilterLabel(): string
    {
        $parts = [];
        if ($this->search) {
            $parts[] = 'Cari "'.$this->search.'"';
        }
        if ($this->filterSales) {
            $salesName = Sales::where('id', $this->filterSales)->value('nama');
            if ($salesName) {
                $parts[] = 'Sales: '.$salesName;
            }
        }
        if ($this->filterProyek) {
            $proyekName = Proyek::where('id', $this->filterProyek)->value('nama_proyek');
            if ($proyekName) {
                $parts[] = 'Proyek: '.$proyekName;
            }
        }
        if ($this->filterStatus) {
            $parts[] = 'Status: '.strtoupper($this->filterStatus);
        }
        if ($this->filterSumber) {
            $parts[] = 'Sumber: '.$this->filterSumber;
        }
        if ($this->filterTanggalFrom || $this->filterTanggalTo) {
            $parts[] = 'Tanggal: '.($this->filterTanggalFrom ?: '...').' s/d '.($this->filterTanggalTo ?: '...');
        }
        return implode(' · ', $parts);
    }

    public function exportExcel()
    {
        $filename = 'prospect-customer-'.now()->format('Ymd-His').'.xlsx';
        return Excel::download(new ProspectCustomerExport($this->currentFilters()), $filename);
    }

    public function exportPdf()
    {
        // Pakai query yang sama dengan list (tanpa pagination).
        $export = new ProspectCustomerExport($this->currentFilters());
        $rows = $export->query()->get();

        $pdf = Pdf::loadView('exports.prospect-customer-pdf', [
            'rows' => $rows,
            'filterLabel' => $this->buildFilterLabel(),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'prospect-customer-'.now()->format('Ymd-His').'.pdf',
        );
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="xl">{{ __('Prospect Customer') }}</flux:heading>

            <flux:dropdown align="end">
                <flux:button icon="arrow-down-tray" icon-trailing="chevron-down" variant="primary"
                             class="bg-orange-600! hover:bg-orange-700!">
                    {{ __('Export') }}
                </flux:button>
                <flux:menu>
                    <flux:menu.item wire:click="exportExcel" icon="table-cells">
                        {{ __('Excel') }} (.xlsx)
                    </flux:menu.item>
                    <flux:menu.item wire:click="exportPdf" icon="document-text">
                        {{ __('PDF') }} (.pdf)
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>

        {{-- STATS PER STATUS --}}
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @php
                $statusCards = [
                    ['key' => '',        'label' => 'Semua',   'count' => $countAll,     'color' => 'zinc',  'icon' => 'circle-stack'],
                    ['key' => 'cold',    'label' => 'Cold',    'count' => $countCold,    'color' => 'blue',  'icon' => 'cloud'],
                    ['key' => 'warm',    'label' => 'Warm',    'count' => $countWarm,    'color' => 'amber', 'icon' => 'sun'],
                    ['key' => 'hot',     'label' => 'Hot',     'count' => $countHot,     'color' => 'red',   'icon' => 'fire'],
                    ['key' => 'finish',  'label' => 'Finish',  'count' => $countFinish,  'color' => 'green', 'icon' => 'check-badge'],
                    ['key' => 'archive', 'label' => 'Archive', 'count' => $countArchive, 'color' => 'stone', 'icon' => 'archive-box'],
                ];
            @endphp
            @foreach ($statusCards as $card)
                @php
                    $isActive = $filterStatus === $card['key'];
                    $palette = [
                        'zinc'  => ['accent' => 'bg-zinc-500',   'text' => 'text-zinc-900 dark:text-white',          'softText' => 'text-zinc-500', 'ringActive' => 'ring-zinc-400'],
                        'blue'  => ['accent' => 'bg-blue-500',   'text' => 'text-blue-700 dark:text-blue-300',       'softText' => 'text-blue-600 dark:text-blue-400', 'ringActive' => 'ring-blue-500'],
                        'amber' => ['accent' => 'bg-amber-500',  'text' => 'text-amber-700 dark:text-amber-300',     'softText' => 'text-amber-600 dark:text-amber-400', 'ringActive' => 'ring-amber-500'],
                        'red'   => ['accent' => 'bg-red-500',    'text' => 'text-red-700 dark:text-red-300',         'softText' => 'text-red-600 dark:text-red-400', 'ringActive' => 'ring-red-500'],
                        'green' => ['accent' => 'bg-green-500',  'text' => 'text-green-700 dark:text-green-300',     'softText' => 'text-green-600 dark:text-green-400', 'ringActive' => 'ring-green-500'],
                        'stone' => ['accent' => 'bg-stone-500',  'text' => 'text-stone-700 dark:text-stone-300',     'softText' => 'text-stone-600 dark:text-stone-400', 'ringActive' => 'ring-stone-500'],
                    ][$card['color']];
                @endphp
                <button type="button" wire:click="setStatus('{{ $card['key'] }}')"
                        @class([
                            'group relative overflow-hidden rounded-xl border bg-white text-left shadow-sm transition active:scale-[0.98] dark:bg-zinc-900',
                            'border-zinc-200 hover:border-zinc-300 hover:shadow-md dark:border-zinc-700' => ! $isActive,
                            'border-transparent shadow-md ring-2 '.$palette['ringActive'] => $isActive,
                        ])>
                    {{-- Top accent bar --}}
                    <div @class(['h-1 w-full', $palette['accent']])></div>

                    <div class="flex items-center gap-3 px-4 py-3">
                        <div @class(['flex size-9 shrink-0 items-center justify-center rounded-lg text-white', $palette['accent']])>
                            @switch($card['icon'])
                                @case('circle-stack')  <flux:icon.circle-stack class="size-5" /> @break
                                @case('cloud')         <flux:icon.cloud class="size-5" /> @break
                                @case('sun')           <flux:icon.sun class="size-5" /> @break
                                @case('fire')          <flux:icon.fire class="size-5" /> @break
                                @case('check-badge')   <flux:icon.check-badge class="size-5" /> @break
                                @case('archive-box')   <flux:icon.archive-box class="size-5" /> @break
                            @endswitch
                        </div>
                        <div class="min-w-0 flex-1">
                            <div @class(['text-[10px] font-bold uppercase tracking-wider', $palette['softText']])>
                                {{ $card['label'] }}
                            </div>
                            <div @class(['text-2xl font-extrabold leading-tight tabular-nums', $palette['text']])>
                                {{ $card['count'] }}
                            </div>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

        {{-- FILTERS --}}
        <div class="mb-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                {{-- Search --}}
                <flux:field>
                    <flux:label>{{ __('Cari') }}</flux:label>
                    <flux:input wire:model.live.debounce.400ms="search"
                                placeholder="{{ __('Nama, NIK, atau HP...') }}"
                                icon="magnifying-glass" />
                </flux:field>

                {{-- Sales --}}
                <flux:field>
                    <flux:label>{{ __('Sales') }}</flux:label>
                    <flux:select wire:model.live="filterSales">
                        <flux:select.option value="">{{ __('Semua sales') }}</flux:select.option>
                        @foreach ($salesOptions as $s)
                            <flux:select.option value="{{ $s->id }}">{{ $s->kode }} · {{ $s->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                {{-- Proyek --}}
                <flux:field>
                    <flux:label>{{ __('Proyek') }}</flux:label>
                    <flux:select wire:model.live="filterProyek">
                        <flux:select.option value="">{{ __('Semua proyek') }}</flux:select.option>
                        @foreach ($proyekOptions as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                {{-- Sumber --}}
                <flux:field>
                    <flux:label>{{ __('Sumber Info') }}</flux:label>
                    <flux:select wire:model.live="filterSumber">
                        <flux:select.option value="">{{ __('Semua sumber') }}</flux:select.option>
                        @foreach ($sumberOptions as $opt)
                            <flux:select.option value="{{ $opt }}">{{ $opt }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                {{-- Tanggal --}}
                <flux:field>
                    <flux:label>{{ __('Dari Tanggal') }}</flux:label>
                    <flux:input type="date" wire:model.live="filterTanggalFrom" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Sampai Tanggal') }}</flux:label>
                    <flux:input type="date" wire:model.live="filterTanggalTo" />
                </flux:field>

                {{-- Sort --}}
                <flux:field>
                    <flux:label>{{ __('Urutan') }}</flux:label>
                    <flux:select wire:model.live="sort">
                        <flux:select.option value="terbaru">{{ __('Terbaru') }}</flux:select.option>
                        <flux:select.option value="terlama">{{ __('Terlama') }}</flux:select.option>
                        <flux:select.option value="nama_asc">{{ __('Nama A → Z') }}</flux:select.option>
                        <flux:select.option value="nama_desc">{{ __('Nama Z → A') }}</flux:select.option>
                    </flux:select>
                </flux:field>

                {{-- Per page --}}
                <flux:field>
                    <flux:label>{{ __('Per Halaman') }}</flux:label>
                    <flux:select wire:model.live="perPage">
                        <flux:select.option value="10">10</flux:select.option>
                        <flux:select.option value="20">20</flux:select.option>
                        <flux:select.option value="50">50</flux:select.option>
                        <flux:select.option value="100">100</flux:select.option>
                    </flux:select>
                </flux:field>
            </div>

            @if ($search || $filterSales || $filterProyek || $filterStatus || $filterSumber || $filterTanggalFrom || $filterTanggalTo)
                <div class="mt-3 flex items-center gap-2 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-zinc-800">
                    <flux:icon.funnel class="size-3.5" />
                    {{ __('Menampilkan') }}
                    <span class="font-bold text-zinc-700 dark:text-zinc-300">{{ $rows->total() }}</span>
                    {{ __('hasil') }}
                    <button type="button" wire:click="resetFilters"
                            class="ml-auto inline-flex items-center gap-1 rounded-md border border-zinc-200 px-2 py-1 font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300">
                        <flux:icon.x-mark class="size-3" />
                        {{ __('Reset Filter') }}
                    </button>
                </div>
            @endif
        </div>

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wider text-zinc-500">
                            <th class="px-3 py-3 text-left font-semibold">#</th>
                            @foreach ([
                                ['nama_lengkap', __('Nama')],
                            ] as [$f, $l])
                                <th class="px-3 py-3 text-left font-semibold">
                                    <button type="button" wire:click="sort('{{ $f }}')"
                                            @class([
                                                '-mx-1 -my-0.5 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-inherit transition',
                                                'hover:bg-zinc-100 dark:hover:bg-zinc-800' => true,
                                                'text-zinc-900 dark:text-white' => $sortBy === $f,
                                            ])>
                                        <span>{{ $l }}</span>
                                        @if ($sortBy === $f)
                                            @if ($sortDir === 'asc')
                                                <flux:icon.chevron-up class="size-3" />
                                            @else
                                                <flux:icon.chevron-down class="size-3" />
                                            @endif
                                        @else
                                            <flux:icon.chevron-up-down class="size-3 opacity-40" />
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                            <th class="px-3 py-3 text-left font-semibold">{{ __('HP / WA') }}</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('Sales') }}</th>
                            <th class="px-3 py-3 text-left font-semibold">{{ __('Proyek') }}</th>
                            @foreach ([
                                ['sumber', __('Sumber')],
                                ['status', __('Status')],
                                ['created_at', __('Tanggal')],
                            ] as [$f, $l])
                                <th class="px-3 py-3 text-left font-semibold">
                                    <button type="button" wire:click="sort('{{ $f }}')"
                                            @class([
                                                '-mx-1 -my-0.5 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-inherit transition',
                                                'hover:bg-zinc-100 dark:hover:bg-zinc-800' => true,
                                                'text-zinc-900 dark:text-white' => $sortBy === $f,
                                            ])>
                                        <span>{{ $l }}</span>
                                        @if ($sortBy === $f)
                                            @if ($sortDir === 'asc')
                                                <flux:icon.chevron-up class="size-3" />
                                            @else
                                                <flux:icon.chevron-down class="size-3" />
                                            @endif
                                        @else
                                            <flux:icon.chevron-up-down class="size-3 opacity-40" />
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                            <th class="px-3 py-3 text-right font-semibold">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($rows as $i => $row)
                            @php
                                $statusBadge = match ($row->status) {
                                    'cold'    => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',     'COLD'],
                                    'warm'    => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                                    'hot'     => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',         'HOT'],
                                    'finish'  => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                                    'archive' => ['bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300',    'ARCHIVE'],
                                };
                                $waLink = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $row->hp);
                                $hasNik = ! empty($row->nik);
                                $hasFoto = ! empty($row->foto_ktp);
                                $hasBi = $row->bi_kol !== null && $row->bi_dbr !== null;
                            @endphp
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-zinc-400">
                                    {{ ($rows->firstItem() ?? 1) + $i }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $row->nama_lengkap }}</span>
                                        @if ($hasFoto)
                                            <flux:icon.camera class="size-3 text-green-500" title="{{ __('Ada foto KTP') }}" />
                                        @endif
                                        @if ($hasBi)
                                            <flux:icon.shield-check class="size-3 text-emerald-500" title="{{ __('BI checked') }}" />
                                        @endif
                                    </div>
                                    @if ($row->nik)
                                        <div class="font-mono text-[10px] text-zinc-400">{{ $row->nik }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <a href="{{ $waLink }}" target="_blank"
                                       class="inline-flex items-center gap-1 font-mono text-xs text-green-600 hover:underline dark:text-green-400">
                                        <flux:icon.phone class="size-3" />
                                        {{ $row->hp }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">
                                    @if ($row->sales)
                                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $row->sales->nama }}</div>
                                        <div class="font-mono text-[10px] text-zinc-400">#{{ $row->sales->kode }}</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ $row->proyek?->nama_proyek ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ $row->sumber ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                        $statusBadge[0],
                                    ])>{{ $statusBadge[1] }}</span>
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
                                        <button type="button" wire:click="openRiwayatModal({{ $row->id }})"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-600 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                                title="{{ __('Riwayat') }}">
                                            <flux:icon.clock class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-12 text-center">
                                    <flux:icon.user-group class="mx-auto size-10 text-zinc-300" />
                                    <p class="mt-3 text-sm font-semibold text-zinc-500">
                                        {{ __('Tidak ada prospect ditemukan.') }}
                                    </p>
                                    <p class="mt-1 text-xs text-zinc-400">
                                        {{ __('Coba ubah filter atau tunggu sales menambahkan data baru.') }}
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if (! $rows->isEmpty())
            <div class="mt-4">
                <flux:pagination :paginator="$rows" />
            </div>
        @endif

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
                        'archive' => ['bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300',    'ARCHIVE'],
                    };
                    $hasFotoD = ! empty($d->foto_ktp);
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
                                #{{ $d->id }} ·
                                {{ __('Dibuat') }} {{ $d->created_at?->translatedFormat('d M Y · H:i') ?? '—' }}
                                @if ($d->sales)
                                    · {{ __('oleh') }}
                                    <span class="font-semibold">{{ $d->sales->nama }}</span>
                                    <span class="font-mono text-[10px]">#{{ $d->sales->kode }}</span>
                                @endif
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
                                     class="block w-full object-contain" style="max-height: 240px;" />
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
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('BI Checking') }}</div>
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

                    <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                        @if ($waLinkD)
                            <a href="{{ $waLinkD }}" target="_blank"
                               class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-green-600 px-3 py-2.5 text-xs font-semibold text-white shadow-sm active:scale-95">
                                <flux:icon.phone class="size-3.5" />
                                {{ __('Hubungi WA') }}
                            </a>
                        @endif
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                        </flux:modal.close>
                    </div>
                </div>
            @endif
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
                            'archive' => ['bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300',    'ARCHIVE'],
                        ];
                    @endphp
                    <ol class="relative ms-2 space-y-4 border-s-2 border-zinc-200 ps-5 dark:border-zinc-700">
                        @foreach ($riwayatLogs as $log)
                            <li class="relative">
                                <span class="absolute -inset-s-7 mt-1.5 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-orange-500 ring-4 ring-white dark:ring-zinc-900"></span>

                                <div class="text-xs text-zinc-500">
                                    {{ $log->created_at?->translatedFormat('d M Y · H:i') ?? '—' }}
                                    @if ($log->changedBy)
                                        · {{ $log->changedBy->nama }}
                                        <span class="font-mono">(#{{ $log->changedBy->kode }})</span>
                                    @endif
                                </div>

                                <div class="mt-1 flex items-center gap-1.5">
                                    @if ($log->status_dari && $log->status_dari !== $log->status_ke)
                                        <span @class([
                                            'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                            $statusBadgeColors[$log->status_dari][0],
                                        ])>{{ $statusBadgeColors[$log->status_dari][1] }}</span>
                                        <flux:icon.arrow-right class="size-3 text-zinc-400" />
                                    @endif
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                        $statusBadgeColors[$log->status_ke][0],
                                    ])>{{ $statusBadgeColors[$log->status_ke][1] }}</span>
                                </div>

                                @if ($log->catatan)
                                    <div class="mt-1.5 rounded-md bg-zinc-50 px-2.5 py-1.5 text-xs text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
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

    </div>
</section>
