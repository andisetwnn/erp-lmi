<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Spr;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Persetujuan SPR')] class extends Component
{
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'approved_at';
    }

    protected function defaultSortDir(): string
    {
        return 'desc';
    }

    #[Url(as: 'tab', except: 'menunggu')]
    public string $tab = 'menunggu';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $filterProyek = null;

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

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['menunggu', 'disetujui'], true) ? $tab : 'menunggu';
        $this->resetPage();
    }

    public function with(): array
    {
        $proyekSelected = (bool) $this->filterProyek;

        if (! $proyekSelected) {
            $query = Spr::query()->whereRaw('1=0');
        } else {
            $query = Spr::query()
                ->with([
                    'prospectCustomer:id,nama_lengkap,hp,nik',
                    'rumah:id,blok,nomor_unit,tipe_rumah_id,proyek_id',
                    'rumah.proyek:id,nama_proyek',
                    'rumah.tipeRumah:id,tipe,nama_tipe',
                    'sales:id,kode,nama',
                    'utjConfirmedBy:id,name',
                    'pmApprovedBy:id,name',
                ])
                // Menunggu approval: current status masih "approved" & PM belum approve.
                // Sudah disetujui: semua SPR yang PM sudah approve (termasuk yang sudah akad / batal setelahnya).
                ->when($this->tab === 'menunggu', fn ($q) => $q->where('status', 'approved')->whereNull('pm_approved_at'))
                ->when($this->tab === 'disetujui', fn ($q) => $q->whereIn('status', ['approved', 'akad', 'cancelled'])->whereNotNull('pm_approved_at'))
                ->when($this->filterProyek, fn ($q) => $q->whereHas('rumah', fn ($qq) => $qq->where('proyek_id', $this->filterProyek)))
                ->when($this->search !== '', function ($q) {
                    $s = $this->search;
                    $q->where(function ($qq) use ($s) {
                        $qq->where('nomor_spr', 'like', "%{$s}%")
                            ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%"));
                    });
                });
        }

        $this->applySort($query, ['nomor_spr', 'tanggal_spr', 'total_harga', 'approved_at', 'pm_approved_at']);
        $sprs = $query->paginate(15);

        if ($proyekSelected) {
            $baseCount = Spr::query()
                ->whereHas('rumah', fn ($qq) => $qq->where('proyek_id', $this->filterProyek));

            $countMenunggu = (clone $baseCount)->where('status', 'approved')->whereNull('pm_approved_at')->count();
            $countDisetujui = (clone $baseCount)->whereIn('status', ['approved', 'akad', 'cancelled'])->whereNotNull('pm_approved_at')->count();
        } else {
            $countMenunggu = 0;
            $countDisetujui = 0;
        }

        return compact('sprs', 'countMenunggu', 'countDisetujui');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex items-start gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-purple-700 text-white shadow-sm">
                <flux:icon.clipboard-document-check class="size-6" />
            </div>
            <flux:heading size="xl">{{ __('Persetujuan SPR') }}</flux:heading>
        </div>

        {{-- WARNING kalau PM belum daftar TTD --}}
        @if (! auth()->user()->tanda_tangan_path)
            <div class="mb-4 flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0" />
                <div>
                    <div class="font-semibold">{{ __('Anda belum mendaftarkan tanda tangan.') }}</div>
                    <div class="mt-0.5">
                        {{ __('SPR yang Anda setujui akan disimpan tanpa TTD PM. Daftarkan terlebih dahulu di') }}
                        <a href="{{ route('tanda-tangan.edit') }}" class="font-bold underline">Settings > Tanda Tangan</a>.
                    </div>
                </div>
            </div>
        @endif

        {{-- TABS --}}
        @php
            $tabs = [
                'menunggu'  => ['Menunggu Approval', 'amber',  $countMenunggu],
                'disetujui' => ['Sudah Disetujui',   'emerald', $countDisetujui],
            ];
            $colorMap = [
                'amber'   => ['active' => 'border-amber-500 text-amber-700 dark:text-amber-400',   'badge' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300'],
                'emerald' => ['active' => 'border-emerald-500 text-emerald-700 dark:text-emerald-400', 'badge' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'],
            ];
        @endphp
        <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
            @foreach ($tabs as $key => [$label, $color, $count])
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
                    ])>{{ number_format($count) }}</span>
                </button>
            @endforeach
        </div>

        {{-- SEARCH --}}
        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nomor SPR / nama customer...')"
                        :disabled="! $filterProyek" />
        </div>

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <x-sortable-column field="nomor_spr" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('SPR') }}</x-sortable-column>
                        <flux:table.column>{{ __('Unit') }}</flux:table.column>
                        <flux:table.column>{{ __('Customer') }}</flux:table.column>
                        <flux:table.column>{{ __('Sales') }}</flux:table.column>
                        <x-sortable-column field="approved_at" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('UTJ Dikonfirmasi') }}</x-sortable-column>
                        <x-sortable-column field="total_harga" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Total') }}</x-sortable-column>
                        @if ($tab === 'disetujui')
                            <flux:table.column>{{ __('Disetujui PM') }}</flux:table.column>
                        @endif
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($sprs as $row)
                            <flux:table.row :key="'row-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($sprs->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell variant="strong" class="whitespace-nowrap font-mono text-xs">
                                    <a href="{{ route('approval.spr.show', $row->id) }}" wire:navigate
                                       class="text-violet-700 underline-offset-2 hover:underline dark:text-violet-400">
                                        {{ $row->nomor_display }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <div class="font-mono font-semibold">{{ $row->rumah?->blok }}-{{ $row->rumah?->nomor_unit }}</div>
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
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    <div>{{ $row->approved_at?->format('d/m/Y H:i') ?? '—' }}</div>
                                    <div class="text-[10px] text-zinc-500">{{ $row->utjConfirmedBy?->name ?? '—' }}</div>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap font-mono">
                                    {{ number_format((float) $row->total_harga, 0, ',', '.') }}
                                </flux:table.cell>
                                @if ($tab === 'disetujui')
                                    <flux:table.cell class="whitespace-nowrap text-xs">
                                        <div>{{ $row->pm_approved_at?->format('d/m/Y H:i') }}</div>
                                        <div class="text-[10px] text-zinc-500">{{ $row->pmApprovedBy?->name }}</div>
                                    </flux:table.cell>
                                @endif
                                <flux:table.cell align="end">
                                    <a href="{{ route('approval.spr.show', $row->id) }}" wire:navigate
                                       class="inline-flex items-center gap-1 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700">
                                        @if ($tab === 'menunggu')
                                            <flux:icon.check class="size-3.5" />
                                            {{ __('Review') }}
                                        @else
                                            <flux:icon.eye class="size-3.5" />
                                            {{ __('Lihat') }}
                                        @endif
                                    </a>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="{{ $tab === 'disetujui' ? 10 : 9 }}" class="py-16 text-center">
                                    @if (! $filterProyek)
                                        <div class="flex flex-col items-center gap-2 text-zinc-500">
                                            <flux:icon.building-office-2 class="size-10 text-zinc-300 dark:text-zinc-600" />
                                            <p class="font-semibold">{{ __('Pilih proyek dulu') }}</p>
                                            <p class="text-xs">{{ __('Pilih proyek aktif melalui tombol di sidebar (kiri atas).') }}</p>
                                        </div>
                                    @elseif ($tab === 'menunggu')
                                        <div class="text-zinc-500">
                                            <flux:icon.check-circle class="mx-auto size-8 text-emerald-400" />
                                            <p class="mt-2 font-semibold">{{ __('Tidak ada SPR menunggu approval') }}</p>
                                        </div>
                                    @else
                                        <span class="text-zinc-500">{{ __('Belum ada SPR yang disetujui.') }}</span>
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
</section>
