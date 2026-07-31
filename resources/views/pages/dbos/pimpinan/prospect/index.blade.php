<?php

use App\Models\Master\PimpinanActivityLog;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectReassignmentLog;
use App\Models\Master\Sales;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Prospect Grup'), Layout('layouts.pimpinan')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'sales', except: '')]
    public string $filterSales = '';

    #[Url(as: 'stagnan', except: false)]
    public bool $onlyStagnan = false;

    #[Url(as: 'sort', except: 'terbaru')]
    public string $sort = 'terbaru';

    #[Url(as: 'preset', except: '')]
    public string $preset = '';

    // Bulk selection
    public array $selectedIds = [];

    public ?int $bulkReassignTargetId = null;

    public string $bulkReassignAlasan = '';

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function openBulkReassignModal(): void
    {
        if (empty($this->selectedIds)) return;
        $this->bulkReassignTargetId = null;
        $this->bulkReassignAlasan = '';
        $this->resetErrorBag();
        Flux::modal('bulk-reassign')->show();
    }

    public function confirmBulkReassign(): void
    {
        $this->validate([
            'bulkReassignTargetId' => ['required', 'integer'],
            'bulkReassignAlasan' => ['required', 'string', 'min:5', 'max:500'],
            'selectedIds' => ['required', 'array', 'min:1'],
        ]);

        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();
        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        if (! $bawahanIds->contains($this->bulkReassignTargetId)) {
            Flux::toast(variant: 'danger', text: 'Sales tujuan tidak valid.');
            return;
        }

        $prospects = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->whereIn('id', $this->selectedIds)
            ->get(['id', 'sales_id']);

        $movedCount = 0;
        DB::transaction(function () use ($prospects, $pimpinan, &$movedCount) {
            foreach ($prospects as $p) {
                if ($p->sales_id === $this->bulkReassignTargetId) continue;
                ProspectReassignmentLog::create([
                    'prospect_customer_id' => $p->id,
                    'from_sales_id' => $p->sales_id,
                    'to_sales_id' => $this->bulkReassignTargetId,
                    'alasan' => trim($this->bulkReassignAlasan),
                    'reassigned_by_sales_id' => $pimpinan->id,
                ]);
                ProspectCustomer::where('id', $p->id)->update(['sales_id' => $this->bulkReassignTargetId]);
                $movedCount++;
            }

            if ($movedCount > 0) {
                $toSales = Sales::find($this->bulkReassignTargetId);
                PimpinanActivityLog::log(
                    $pimpinan->id,
                    'bulk_reassign_prospect',
                    $movedCount.' prospect',
                    [
                        'count' => $movedCount,
                        'to' => $toSales?->nama,
                        'alasan' => trim($this->bulkReassignAlasan),
                    ],
                );
            }
        });

        Flux::modal('bulk-reassign')->close();
        $this->selectedIds = [];
        $this->bulkReassignTargetId = null;
        $this->bulkReassignAlasan = '';
        Flux::toast(variant: 'success', text: $movedCount.' prospect berhasil dipindahkan.');
    }

    public function exportExcel()
    {
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();
        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id')->all();

        $filters = [
            'sales_in' => $bawahanIds,
            'filterStatus' => $this->filterStatus,
        ];
        if ($this->filterSales) $filters['filterSales'] = (int) $this->filterSales;

        $filename = 'prospect-grup-'.now()->format('Y-m-d').'.xlsx';
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\ProspectCustomerExport($filters),
            $filename
        );
    }

    public function exportPdf()
    {
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();
        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        // Re-apply same filters as table — ambil all (no pagination)
        $rows = ProspectCustomer::whereIn('sales_id', $bawahanIds)
            ->with(['proyek:id,nama_proyek', 'sales:id,nama,kode'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSales, fn ($q) => $q->where('sales_id', $this->filterSales))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq->where('nama_lengkap', 'like', $term)->orWhere('hp', 'like', $term)->orWhere('nik', 'like', $term));
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.pimpinan-prospect-pdf', [
            'rows' => $rows,
            'grup' => $grup,
            'pimpinanNama' => $pimpinan->nama,
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'prospect-grup-'.now()->format('Y-m-d').'.pdf',
        );
    }

    public function setPreset(string $p): void
    {
        $this->preset = $p;
        // Apply preset semantics
        match ($p) {
            'hot-stagnan' => [$this->filterStatus = 'hot', $this->onlyStagnan = true],
            'cold-stale' => [$this->filterStatus = 'cold', $this->onlyStagnan = true],
            'finish-belum-booking' => [$this->filterStatus = 'finish', $this->onlyStagnan = false],
            'baru-minggu-ini' => [$this->filterStatus = '', $this->sort = 'terbaru'],
            '' => null,
            default => null,
        };
        $this->resetPage();
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterSales(): void { $this->resetPage(); }
    public function updatingOnlyStagnan(): void { $this->resetPage(); }
    public function updatingSort(): void { $this->resetPage(); }

    public function setStatus(string $s): void
    {
        $this->filterStatus = $s;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterSales', 'onlyStagnan']);
        $this->sort = 'terbaru';
        $this->resetPage();
    }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $salesList = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->orderBy('nama')
            ->get(['id', 'nama', 'kode']);

        $base = ProspectCustomer::query()->whereIn('sales_id', $bawahanIds);

        $counts = (clone $base)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        [$sortCol, $sortDir] = match ($this->sort) {
            'terlama' => ['created_at', 'asc'],
            'nama_asc' => ['nama_lengkap', 'asc'],
            'nama_desc' => ['nama_lengkap', 'desc'],
            default => ['created_at', 'desc'],
        };

        $query = (clone $base)
            ->with(['proyek:id,nama_proyek', 'sales:id,nama,kode'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSales, fn ($q) => $q->where('sales_id', $this->filterSales))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nama_lengkap', 'like', $term)
                        ->orWhere('hp', 'like', $term)
                        ->orWhere('nik', 'like', $term);
                });
            })
            ->when($this->onlyStagnan, function ($q) {
                $q->whereIn('status', ['cold', 'warm', 'hot'])
                    ->whereDoesntHave('statusLog', fn ($s) => $s->where('created_at', '>=', now()->subDays(7)));
            })
            ->when($this->preset === 'baru-minggu-ini', fn ($q) => $q->where('created_at', '>=', now()->subDays(7)))
            ->when($this->preset === 'finish-belum-booking', fn ($q) => $q->whereDoesntHave('booking'))
            ->orderBy($sortCol, $sortDir);

        $rows = $query->paginate(20);

        return [
            'grup' => $grup,
            'rows' => $rows,
            'salesList' => $salesList,
            'countAll' => array_sum($counts),
            'countCold' => $counts['cold'] ?? 0,
            'countWarm' => $counts['warm'] ?? 0,
            'countHot' => $counts['hot'] ?? 0,
            'countFinish' => $counts['finish'] ?? 0,
            'countArchive' => $counts['archive'] ?? 0,
        ];
    }
}; ?>

<div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Prospect Grup') }}</flux:heading>
            <flux:subheading>{{ $grup->nama }}</flux:subheading>
        </div>
        <flux:dropdown align="end">
            <flux:button icon="arrow-down-tray" variant="filled" size="sm">{{ __('Export') }}</flux:button>
            <flux:menu>
                <flux:menu.item icon="document-arrow-down" wire:click="exportExcel">
                    {{ __('Excel (.xlsx)') }}
                </flux:menu.item>
                <flux:menu.item icon="document-text" wire:click="exportPdf">
                    {{ __('PDF') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- QUICK FILTER PRESET CHIPS --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $presets = [
                '' => ['Semua', 'zinc', null],
                'hot-stagnan' => ['Hot stagnan', 'red', 'fire'],
                'cold-stale' => ['Cold lama', 'blue', 'cloud'],
                'finish-belum-booking' => ['Siap booking belum di-book', 'green', 'check-badge'],
                'baru-minggu-ini' => ['Baru minggu ini', 'amber', 'sparkles'],
            ];
        @endphp
        @foreach ($presets as $key => [$label, $color, $icon])
            @php
                $active = $preset === $key;
                $activeCls = match ($color) {
                    'zinc' => 'bg-zinc-700 text-white border-zinc-700',
                    'red' => 'bg-rose-600 text-white border-rose-600',
                    'blue' => 'bg-blue-600 text-white border-blue-600',
                    'green' => 'bg-emerald-600 text-white border-emerald-600',
                    'amber' => 'bg-amber-600 text-white border-amber-600',
                };
            @endphp
            <button type="button" wire:click="setPreset('{{ $key }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition',
                        $activeCls => $active,
                        'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => ! $active,
                    ])>
                @if ($icon)
                    @switch($icon)
                        @case('fire') <flux:icon.fire class="size-3" /> @break
                        @case('cloud') <flux:icon.cloud class="size-3" /> @break
                        @case('check-badge') <flux:icon.check-badge class="size-3" /> @break
                        @case('sparkles') <flux:icon.sparkles class="size-3" /> @break
                    @endswitch
                @endif
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- STATUS TABS --}}
    @php
        $tabs = [
            ['key' => '', 'label' => __('Semua'), 'count' => $countAll],
            ['key' => 'cold', 'label' => __('Cold'), 'count' => $countCold],
            ['key' => 'warm', 'label' => __('Warm'), 'count' => $countWarm],
            ['key' => 'hot', 'label' => __('Hot'), 'count' => $countHot],
            ['key' => 'finish', 'label' => __('Finish'), 'count' => $countFinish],
            ['key' => 'archive', 'label' => __('Archive'), 'count' => $countArchive],
        ];
    @endphp
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $tab)
            @php $isActive = $filterStatus === $tab['key']; @endphp
            <button type="button" wire:click="setStatus('{{ $tab['key'] }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition',
                        'border-amber-600 bg-amber-600 text-white' => $isActive,
                        'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => ! $isActive,
                    ])>
                {{ $tab['label'] }}
                <span @class([
                    'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px]',
                    'bg-white/25 text-white' => $isActive,
                    'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => ! $isActive,
                ])>{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- FILTER BAR --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex-1 min-w-60">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama, HP, atau KTP...')" />
        </div>

        <flux:select wire:model.live="filterSales" placeholder="{{ __('Sales') }}" class="w-48">
            <flux:select.option value="">{{ __('Semua sales') }}</flux:select.option>
            @foreach ($salesList as $s)
                <flux:select.option value="{{ $s->id }}">{{ $s->nama }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="sort" class="w-40">
            <flux:select.option value="terbaru">{{ __('Terbaru') }}</flux:select.option>
            <flux:select.option value="terlama">{{ __('Terlama') }}</flux:select.option>
            <flux:select.option value="nama_asc">{{ __('Nama A-Z') }}</flux:select.option>
            <flux:select.option value="nama_desc">{{ __('Nama Z-A') }}</flux:select.option>
        </flux:select>

        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-900">
            <input type="checkbox" wire:model.live="onlyStagnan"
                   class="size-4 cursor-pointer rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-600 dark:bg-zinc-800" />
            <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Hanya stagnan (>7 hari)') }}</span>
        </label>
    </div>

    @if ($search || $filterStatus || $filterSales || $onlyStagnan || $sort !== 'terbaru')
        <div class="mb-3 flex items-center justify-between rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
            <span>{{ __('Filter aktif') }}</span>
            <button type="button" wire:click="resetFilters" class="font-semibold underline">{{ __('Reset semua') }}</button>
        </div>
    @endif

    <p class="mb-2 text-xs text-zinc-500">
        {{ __('Menampilkan :showing dari :total data', ['showing' => $rows->count(), 'total' => number_format($rows->total(), 0, ',', '.')]) }}
    </p>

    {{-- TABLE --}}
    @if ($rows->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.circle-stack class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Tidak ada prospect') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wider text-zinc-500">
                            <th class="px-3 py-3 text-left font-semibold">
                                <span class="sr-only">{{ __('Pilih') }}</span>
                            </th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Customer') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('HP') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Sales') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Proyek') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Sumber') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Tanggal') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($rows as $row)
                            @php
                                $statusBadge = match ($row->status) {
                                    'cold' => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300', 'COLD'],
                                    'warm' => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                                    'hot' => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300', 'HOT'],
                                    'finish' => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                                    'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', 'ARCHIVE'],
                                };
                                $waLink = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $row->hp);
                            @endphp
                            <tr @class([
                                'hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30',
                                'bg-amber-50/40 dark:bg-amber-950/15' => in_array($row->id, $selectedIds),
                            ])>
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $row->id }}" wire:model.live="selectedIds"
                                           class="size-4 cursor-pointer rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-600 dark:bg-zinc-800" />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $row->nama_lengkap }}</div>
                                    @if ($row->nik)
                                        <div class="font-mono text-[10px] text-zinc-400">{{ $row->nik }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ $waLink }}" target="_blank"
                                       class="inline-flex items-center gap-1 font-mono text-xs text-green-600 hover:underline dark:text-green-400">
                                        <flux:icon.phone class="size-3" />
                                        {{ $row->hp }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs font-semibold text-amber-700 dark:text-amber-300">{{ $row->sales?->nama ?? '—' }}</div>
                                    <div class="font-mono text-[10px] text-zinc-400">#{{ $row->sales?->kode ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $row->proyek?->nama_proyek ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">{{ $row->sumber }}</td>
                                <td class="px-4 py-3">
                                    <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $statusBadge[0]])>{{ $statusBadge[1] }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-500">{{ $row->created_at?->translatedFormat('d M Y') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('dbos.pimpinan.prospect.show', $row->id) }}" wire:navigate
                                       class="text-xs font-semibold text-amber-600 hover:underline">{{ __('Detail') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            <flux:pagination :paginator="$rows" />
        </div>
    @endif

    {{-- FAB BULK ACTION --}}
    @if (! empty($selectedIds))
        <div class="fixed bottom-6 right-6 z-40 flex items-center gap-2 rounded-2xl bg-zinc-900 px-4 py-3 text-white shadow-2xl">
            <flux:icon.bolt class="size-5 text-amber-400" />
            <span class="text-sm font-semibold">{{ count($selectedIds) }} {{ __('prospect dipilih') }}</span>
            <button type="button" wire:click="openBulkReassignModal"
                    class="inline-flex h-9 items-center gap-1 rounded-lg bg-amber-500 px-3 text-xs font-bold text-amber-950 transition hover:bg-amber-400">
                <flux:icon.arrow-path-rounded-square class="size-4" />
                {{ __('Pindahkan sekaligus') }}
            </button>
            <button type="button" wire:click="clearSelection"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-zinc-800 text-zinc-300 hover:bg-zinc-700"
                    title="{{ __('Batal pilih') }}">
                <flux:icon.x-mark class="size-4" />
            </button>
        </div>
    @endif

    {{-- MODAL BULK REASSIGN --}}
    <flux:modal name="bulk-reassign" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.arrow-path-rounded-square class="size-5 text-amber-600" />
                    <flux:heading size="lg">{{ __('Pindahkan banyak prospect') }}</flux:heading>
                </div>
                <flux:subheading>
                    {{ count($selectedIds) }} {{ __('prospect akan dipindahkan ke sales yang sama') }}
                </flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Sales tujuan') }}</flux:label>
                <flux:select wire:model="bulkReassignTargetId" placeholder="{{ __('Pilih sales...') }}">
                    @foreach ($salesList as $s)
                        <flux:select.option value="{{ $s->id }}">{{ $s->nama }} (#{{ $s->kode }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="bulkReassignTargetId" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Alasan re-assign') }}</flux:label>
                <flux:textarea wire:model="bulkReassignAlasan" rows="3"
                               :placeholder="__('Alasan akan disimpan sebagai audit log untuk semua prospect.')" />
                <flux:error name="bulkReassignAlasan" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="button" wire:click="confirmBulkReassign"
                             class="bg-amber-600! hover:bg-amber-700!">
                    {{ __('Pindahkan Semua') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
