<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use Barryvdh\DomPDF\Facade\Pdf;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Data SPR')] class extends Component
{
    use Sortable, WithPagination;

    /** Tab yang valid → mapping ke filter status. null = semua. */
    private const TAB_STATUS = [
        'semua' => null,
        'diproses' => ['submitted', 'approved'],
        'selesai' => ['approved'],
        'rejected' => ['rejected'],
        'cancelled' => ['cancelled'],
        'akad' => ['akad'],
    ];

    protected function defaultSortBy(): ?string
    {
        return 'tanggal_spr';
    }

    protected function defaultSortDir(): string
    {
        return 'desc';
    }

    #[Url(as: 'tab', except: 'semua')]
    public string $tab = 'semua';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Diambil dari session global 'active_proyek_id' (dipilih di sidebar). */
    public ?int $filterProyek = null;

    #[Url(as: 'sales')]
    public ?int $filterSales = null;

    #[Url(as: 'tgl_from')]
    public ?string $filterTanggalFrom = null;

    #[Url(as: 'tgl_to')]
    public ?string $filterTanggalTo = null;

    public function mount(): void
    {
        $this->filterProyek = session('active_proyek_id');
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->filterProyek = $proyekId;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterSales(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTanggalFrom(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTanggalTo(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TAB_STATUS) ? $tab : 'semua';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'filterProyek', 'filterSales', 'filterTanggalFrom', 'filterTanggalTo']);
        $this->resetPage();
    }

    public function printSpr(int $sprId)
    {
        $spr = Spr::with([
            'rumah.tipeRumah',
            'rumah.proyek',
            'rumah.virtualAccount.bank',
            'prospectCustomer.tempatKerja',
            'sales',
            'utjConfirmedBy',
            'terminPembayaran',
        ])->findOrFail($sprId);

        if ($spr->status !== 'approved') {
            Flux::toast(variant: 'warning', text: 'Hanya SPR berstatus Disetujui yang bisa dicetak.');

            return null;
        }

        $pdf = Pdf::loadView('exports.spr-print', ['spr' => $spr])
            ->setPaper('a4', 'portrait');

        $filename = str_replace('/', '-', $spr->nomor_spr).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
        );
    }

    /** Apply filter umum (proyek/sales/tanggal/search) — dipakai untuk query data + count tab. */
    private function applyCommonFilters($query)
    {
        return $query
            ->when($this->filterProyek, fn ($q) => $q->whereHas('rumah', fn ($qq) => $qq->where('proyek_id', $this->filterProyek)))
            ->when($this->filterSales, fn ($q) => $q->where('sales_id', $this->filterSales))
            ->when($this->filterTanggalFrom, fn ($q) => $q->whereDate('tanggal_spr', '>=', $this->filterTanggalFrom))
            ->when($this->filterTanggalTo, fn ($q) => $q->whereDate('tanggal_spr', '<=', $this->filterTanggalTo))
            ->when($this->search !== '', function ($q) {
                $s = $this->search;
                $q->where(function ($qq) use ($s) {
                    $qq->where('nomor_spr', 'like', "%{$s}%")
                        ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%")
                            ->orWhere('nik', 'like', "%{$s}%")
                            ->orWhere('hp', 'like', "%{$s}%"))
                        ->orWhereHas('rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", ["%{$s}%"]));
                });
            });
    }

    public function with(): array
    {
        $statusFilter = self::TAB_STATUS[$this->tab] ?? null;
        $proyekSelected = (bool) $this->filterProyek;

        // Mandatory: pilih proyek dulu.
        if (! $proyekSelected) {
            $query = Spr::query()->whereRaw('1=0');
        } else {
            $query = Spr::query()
                ->with([
                    'prospectCustomer:id,nama_lengkap,hp,nik',
                    'rumah:id,blok,nomor_unit,tipe_rumah_id,proyek_id',
                    'rumah.proyek:id,nama_proyek',
                    'rumah.tipeRumah:id,tipe,nama_tipe',
                    'rumah.virtualAccount' => fn ($q) => $q->where('is_aktif', true)->with('bank:id,nama'),
                    'sales:id,kode,nama',
                    'bankKpr:id,nama',
                ])
                ->when($statusFilter, fn ($q) => $q->whereIn('status', $statusFilter))
                // "Selesai" = status approved + sudah ber-e-Materai (spr_finalized_at set)
                ->when($this->tab === 'selesai', fn ($q) => $q->whereNotNull('spr_finalized_at'))
                // "Diproses" = submitted/approved TAPI belum final
                ->when($this->tab === 'diproses', fn ($q) => $q->whereNull('spr_finalized_at'));

            $query = $this->applyCommonFilters($query);
        }

        $this->applySort($query, ['nomor_spr', 'tanggal_spr', 'total_harga', 'utj_nominal', 'approved_at', 'created_at']);

        $sprs = $query->paginate(15);

        // Tab counts hanya berarti kalau proyek sudah dipilih
        if ($proyekSelected) {
            $countBase = $this->applyCommonFilters(Spr::query());
            $tabCounts = [
                'semua' => (clone $countBase)->count(),
                'diproses' => (clone $countBase)->whereIn('status', ['submitted', 'approved'])->whereNull('spr_finalized_at')->count(),
                'selesai' => (clone $countBase)->where('status', 'approved')->whereNotNull('spr_finalized_at')->count(),
                'rejected' => (clone $countBase)->where('status', 'rejected')->count(),
                'cancelled' => (clone $countBase)->where('status', 'cancelled')->count(),
                'akad' => (clone $countBase)->where('status', 'akad')->count(),
            ];
        } else {
            $tabCounts = array_fill_keys(['semua', 'diproses', 'selesai', 'rejected', 'cancelled', 'akad'], 0);
        }

        $salesList = Sales::where('is_aktif', true)->orderBy('nama')->get(['id', 'kode', 'nama']);

        return compact('sprs', 'salesList', 'tabCounts');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex items-start gap-3">
            <a href="{{ route('marketing.spr.index') }}" wire:navigate
               class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
               title="{{ __('Kembali ke SPR') }}">
                <flux:icon.arrow-left class="size-4" />
            </a>
            <div>
                <flux:heading size="xl">{{ __('Data SPR') }}</flux:heading>
            </div>
        </div>

        {{-- TABS --}}
        @php
            $tabs = [
                'semua'     => ['Semua',      'zinc',    'Total semua SPR di proyek ini (gabungan semua status).'],
                'diproses'  => ['Diproses',   'emerald', 'SPR yang masih berjalan (verifikasi UTJ Keuangan → PM approve → TTD konsumen → e-Materai).'],
                'selesai'   => ['Selesai',    'violet',  'SPR final — sudah lengkap semua TTD dan ber-e-Materai. Siap dikirim ke konsumen untuk arsip.'],
                'rejected'  => ['Ditolak',    'rose',    'SPR yang ditolak Keuangan atau Project Manager.'],
                'cancelled' => ['Dibatalkan', 'orange',  'SPR yang sudah disetujui lalu dibatalkan (mengundurkan diri, tolak bank, dll). Diinput via menu Pembatalan SPR.'],
                'akad'      => ['Akad',       'amber',   'SPR yang sudah akad kredit di notaris.'],
            ];
            $colorMap = [
                'zinc'    => ['active' => 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white', 'badge' => 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200'],
                'blue'    => ['active' => 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-400', 'badge' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300'],
                'emerald' => ['active' => 'border-emerald-600 text-emerald-600 dark:text-emerald-400 dark:border-emerald-400', 'badge' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'],
                'rose'    => ['active' => 'border-rose-600 text-rose-600 dark:text-rose-400 dark:border-rose-400', 'badge' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'],
                'orange'  => ['active' => 'border-orange-600 text-orange-600 dark:text-orange-400 dark:border-orange-400', 'badge' => 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300'],
                'amber'   => ['active' => 'border-amber-600 text-amber-700 dark:text-amber-400 dark:border-amber-400', 'badge' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
                'violet'  => ['active' => 'border-violet-600 text-violet-700 dark:text-violet-400 dark:border-violet-400', 'badge' => 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300'],
            ];
        @endphp
        <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
            @foreach ($tabs as $key => [$label, $color, $desc])
                @php $active = $tab === $key; @endphp
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @class([
                            'flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition -mb-px',
                            $colorMap[$color]['active'] => $active,
                            'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200' => ! $active,
                        ])>
                    {{ __($label) }}
                    <span @class([
                        'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        $colorMap[$color]['badge'] => $active,
                        'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => ! $active,
                    ])>{{ number_format($tabCounts[$key] ?? 0) }}</span>
                </button>
            @endforeach

            <div class="ml-auto pb-1">
                <flux:modal.trigger name="info-status-spr">
                    <button type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                            aria-label="Info status">
                        <flux:icon.information-circle class="size-4" />
                    </button>
                </flux:modal.trigger>
            </div>
        </div>

        {{-- FILTERS --}}
        <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-12">
            <div class="md:col-span-8">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                            :placeholder="__('Cari nomor SPR / nama / NIK / HP / blok-unit...')"
                            :disabled="! $filterProyek" />
            </div>
            <div class="md:col-span-4">
                <flux:select wire:model.live="filterSales" :placeholder="__('Semua Sales')" :disabled="! $filterProyek">
                    <flux:select.option value="">{{ __('Semua Sales') }}</flux:select.option>
                    @foreach ($salesList as $s)
                        <flux:select.option value="{{ $s->id }}">{{ $s->kode }} — {{ $s->nama }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- Date range filter (tanggal SPR) --}}
        <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12">
            <div class="md:col-span-3">
                <flux:input type="date" wire:model.live="filterTanggalFrom" :placeholder="__('Tgl SPR dari')" />
            </div>
            <div class="md:col-span-3">
                <flux:input type="date" wire:model.live="filterTanggalTo" :placeholder="__('Sampai')" />
            </div>
        </div>

        @if ($search || $filterProyek || $filterSales || $filterTanggalFrom || $filterTanggalTo)
            <div class="mb-3">
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                    {{ __('Reset filter') }}
                </flux:button>
            </div>
        @endif

        {{-- TABLE: kolom inti yang relevan untuk subsidi & komersil. Detail lengkap ada di halaman Detail SPR. --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <x-sortable-column field="nomor_spr" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('SPR') }}</x-sortable-column>
                        <flux:table.column>{{ __('Unit') }}</flux:table.column>
                        <flux:table.column>{{ __('Customer') }}</flux:table.column>
                        <flux:table.column>{{ __('Sales') }}</flux:table.column>
                        <flux:table.column>{{ __('Jenis Bayar') }}</flux:table.column>
                        <x-sortable-column field="tanggal_spr" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Tgl SPR') }}</x-sortable-column>
                        <x-sortable-column field="total_harga" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Harga Net') }}</x-sortable-column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($sprs as $row)
                            @php
                                [$badgeLabel, $badgeCls] = $row->statusBadge();
                                $jenis = $row->jenis_pembayaran;
                                $jenisLabel = \App\Models\Master\Spr::JENIS_PEMBAYARAN[$jenis] ?? '—';
                                $jenisChip = match ($jenis) {
                                    'kpr' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
                                    'cash' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
                                    'cash_bertahap' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
                                    default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                                };
                            @endphp
                            <flux:table.row :key="'row-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($sprs->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell variant="strong" class="whitespace-nowrap font-mono text-xs">
                                    <a href="{{ route('marketing.spr.show', $row->id) }}" wire:navigate
                                       class="text-emerald-700 underline-offset-2 hover:underline dark:text-emerald-400">
                                        {{ $row->nomor_display }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <div class="font-mono font-semibold">{{ $row->rumah?->kode_unit }}</div>
                                    <div class="text-[10px] text-zinc-500">{{ $row->rumah?->tipeRumah?->tipe ?? '—' }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <div class="font-semibold">{{ $row->prospectCustomer?->nama_lengkap }}</div>
                                    <div class="font-mono text-[10px] text-zinc-500">{{ $row->prospectCustomer?->hp }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <div class="font-mono text-[10px] text-zinc-500">{{ $row->sales?->kode }}</div>
                                    <div>{{ $row->sales?->nama }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $jenisChip }}">
                                        {{ $jenisLabel }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    {{ $row->tanggal_spr?->format('d/m/Y') }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap font-mono">
                                    {{ number_format((float) $row->total_harga, 0, ',', '.') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span @class([
                                        'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                        $badgeCls,
                                    ])>{{ $badgeLabel }}</span>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="9" class="py-16 text-center">
                                    @if (! $filterProyek)
                                        <div class="flex flex-col items-center gap-2 text-zinc-500">
                                            <flux:icon.building-office-2 class="size-10 text-zinc-300 dark:text-zinc-600" />
                                            <p class="font-semibold">{{ __('Pilih proyek dulu') }}</p>
                                            <p class="text-xs">{{ __('Pilih proyek aktif melalui tombol di sidebar (kiri atas).') }}</p>
                                        </div>
                                    @elseif ($tab === 'akad')
                                        <span class="text-zinc-500">{{ __('Modul Akad belum tersedia.') }}</span>
                                    @elseif ($search || $filterSales || $filterTanggalFrom || $filterTanggalTo)
                                        <span class="text-zinc-500">{{ __('Tidak ada SPR yang cocok dengan filter.') }}</span>
                                    @else
                                        <span class="text-zinc-500">{{ __('Belum ada SPR di tab ini.') }}</span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        <div class="mt-3">
            {{ $sprs->links() }}
        </div>

    </div>

    {{-- Modal keterangan status --}}
    <flux:modal name="info-status-spr" class="md:w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Keterangan Status SPR') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Arti setiap tab pada Data SPR.') }}</flux:text>
            </div>

            <ul class="space-y-3">
                @foreach ($tabs as [$label, $color, $desc])
                    <li class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                            $colorMap[$color]['badge'],
                        ])>{{ $label }}</span>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $desc }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Mengerti') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
