<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master COA')] class extends Component {
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'kode';
    }

    #[Url(as: 'perusahaan')]
    public $selectedPerusahaanId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tipe')]
    public string $filterTipe = '';

    #[Url(as: 'aktif')]
    public string $filterAktif = '';

    #[Url(as: 'per')]
    public string $perPage = '20';

    public ?int $editId = null;

    public string $kode = '';

    public string $nama = '';

    public string $tipe = 'aset';

    public string $saldoNormal = 'debit';

    public ?string $parentId = null;

    public bool $isHeader = false;

    public bool $isAktif = true;

    public ?string $satuanHpp = null;

    public ?int $deleteId = null;

    public ?string $deleteNama = null;

    public ?int $copyFromPerusahaanId = null;

    public function mount(): void
    {
        // Hydrate dari session global kalau URL belum punya value
        if (! $this->selectedPerusahaanId && $sess = session('active_perusahaan_id')) {
            $this->selectedPerusahaanId = $sess;
        }

        // Auto-pilih perusahaan pertama kalau masih kosong
        if (! $this->selectedPerusahaanId) {
            $first = Perusahaan::orderBy('nama')->first();
            if ($first) {
                $this->selectedPerusahaanId = $first->id;
                session(['active_perusahaan_id' => $first->id]);
            }
        }
    }

    public function updatedSelectedPerusahaanId($value): void
    {
        if ($value) {
            session(['active_perusahaan_id' => (int) $value]);
        } else {
            session()->forget('active_perusahaan_id');
        }
        $this->resetPage();
        $this->search = '';
        $this->filterTipe = '';
        $this->filterAktif = '';
    }

    public function with(): array
    {
        $per = $this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage);

        $perusahaanList = Perusahaan::orderBy('nama')->get(['id', 'nama', 'kode_surat']);

        $counts = $this->selectedPerusahaanId
            ? Coa::query()
                ->where('perusahaan_id', $this->selectedPerusahaanId)
                ->selectRaw('tipe, COUNT(*) as cnt')
                ->groupBy('tipe')
                ->pluck('cnt', 'tipe')
                ->toArray()
            : [];

        return [
            'perusahaanList' => $perusahaanList,
            'selectedPerusahaan' => $this->selectedPerusahaanId
                ? Perusahaan::find($this->selectedPerusahaanId)
                : null,
            'coaList' => $this->selectedPerusahaanId
                ? $this->applySort(
                    Coa::query()
                        ->where('perusahaan_id', $this->selectedPerusahaanId)
                        ->with(['parent:id,kode,nama', 'updatedByUser:id,name'])
                        ->withCount('children')
                        ->when($this->filterTipe, fn ($q) => $q->where('tipe', $this->filterTipe))
                        ->when($this->filterAktif !== '', function ($q) {
                            $q->where('is_aktif', $this->filterAktif === 'aktif');
                        })
                        ->when($this->search, function ($q) {
                            $term = '%'.$this->search.'%';
                            $q->where(function ($qq) use ($term) {
                                $qq->where('kode', 'like', $term)
                                    ->orWhere('nama', 'like', $term);
                            });
                        }),
                    ['kode', 'nama', 'tipe', 'saldo_normal', 'is_aktif', 'children_count']
                )->paginate($per)
                : null,
            'parentOptions' => $this->selectedPerusahaanId
                ? Coa::where('perusahaan_id', $this->selectedPerusahaanId)
                    ->orderBy('kode')->get(['id', 'kode', 'nama'])
                : collect(),
            'totalAll' => array_sum($counts),
            'countAset' => $counts['aset'] ?? 0,
            'countKewajiban' => $counts['kewajiban'] ?? 0,
            'countModal' => $counts['modal'] ?? 0,
            'countPendapatan' => $counts['pendapatan'] ?? 0,
            'countBeban' => $counts['beban'] ?? 0,
        ];
    }

    public function updatingSearch(): void { $this->resetPage(); }

    public function updatingFilterTipe(): void { $this->resetPage(); }

    public function updatingFilterAktif(): void { $this->resetPage(); }

    public function updatingPerPage(): void { $this->resetPage(); }

    public function setFilterTipe(string $tipe): void
    {
        $this->filterTipe = $tipe;
        $this->resetPage();
    }

    // Auto-suggest saldo_normal berdasarkan tipe yang dipilih
    public function updatedTipe(string $value): void
    {
        $this->saldoNormal = match ($value) {
            'aset', 'beban' => 'debit',
            default => 'kredit',
        };
    }

    public function create(): void
    {
        if (! $this->selectedPerusahaanId) {
            Flux::toast(variant: 'warning', text: 'Pilih perusahaan dulu.');
            return;
        }

        $this->resetForm();
        Flux::modal('coa-form')->show();
    }

    public function edit(int $id): void
    {
        $c = Coa::findOrFail($id);

        $this->editId = $c->id;
        $this->kode = $c->kode;
        $this->nama = $c->nama;
        $this->tipe = $c->tipe;
        $this->saldoNormal = $c->saldo_normal;
        $this->parentId = $c->parent_id ? (string) $c->parent_id : null;
        $this->isHeader = (bool) $c->is_header;
        $this->isAktif = (bool) $c->is_aktif;
        $this->satuanHpp = $c->satuan_hpp;
        $this->resetErrorBag();

        Flux::modal('coa-form')->show();
    }

    public function save(): void
    {
        if (! $this->selectedPerusahaanId) {
            Flux::toast(variant: 'danger', text: 'Perusahaan belum dipilih.');
            return;
        }

        $validated = $this->validate([
            'kode' => [
                'required', 'string', 'max:30',
                \Illuminate\Validation\Rule::unique('coa', 'kode')
                    ->where(fn ($q) => $q->where('perusahaan_id', $this->selectedPerusahaanId))
                    ->ignore($this->editId),
            ],
            'nama' => ['required', 'string', 'max:255'],
            'tipe' => ['required', 'in:aset,kewajiban,modal,pendapatan,beban'],
            'saldoNormal' => ['required', 'in:debit,kredit'],
            'parentId' => ['nullable', 'exists:coa,id'],
            'isHeader' => ['boolean'],
            'isAktif' => ['boolean'],
            'satuanHpp' => ['nullable', 'string', 'max:30'],
        ], [], [
            'parentId' => 'parent',
            'saldoNormal' => 'saldo normal',
            'isHeader' => 'header',
            'isAktif' => 'status aktif',
            'satuanHpp' => 'satuan HPP',
        ]);

        // Cegah jadi parent diri sendiri
        if ($this->editId && (int) $validated['parentId'] === $this->editId) {
            $this->addError('parentId', 'Akun tidak boleh jadi parent dirinya sendiri.');
            return;
        }

        $c = $this->editId
            ? Coa::findOrFail($this->editId)
            : new Coa;

        $c->fill([
            'perusahaan_id' => $this->selectedPerusahaanId,
            'kode' => $validated['kode'],
            'nama' => $validated['nama'],
            'tipe' => $validated['tipe'],
            'saldo_normal' => $validated['saldoNormal'],
            'parent_id' => $validated['parentId'] ? (int) $validated['parentId'] : null,
            'is_header' => (bool) ($validated['isHeader'] ?? false),
            'is_aktif' => (bool) ($validated['isAktif'] ?? true),
            'satuan_hpp' => $validated['satuanHpp'] ?: null,
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('coa-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Akun COA diperbarui.'
            : 'Akun COA baru ditambahkan.');
    }

    // COPY COA dari perusahaan lain
    public function openCopyModal(): void
    {
        if (! $this->selectedPerusahaanId) {
            Flux::toast(variant: 'warning', text: 'Pilih perusahaan tujuan dulu.');
            return;
        }

        $this->copyFromPerusahaanId = null;
        Flux::modal('coa-copy')->show();
    }

    public function copyCoa(): void
    {
        $this->validate([
            'copyFromPerusahaanId' => ['required', 'exists:perusahaan,id', 'different:selectedPerusahaanId'],
        ], [
            'copyFromPerusahaanId.different' => 'Tidak bisa copy dari perusahaan yang sama.',
        ], [
            'copyFromPerusahaanId' => 'sumber perusahaan',
        ]);

        $source = Coa::where('perusahaan_id', $this->copyFromPerusahaanId)
            ->orderBy('id') // urut id buat jaga urutan parent
            ->get();

        if ($source->isEmpty()) {
            Flux::toast(variant: 'warning', text: 'Perusahaan sumber belum punya COA.');
            return;
        }

        $copied = 0;
        $skipped = 0;
        $idMap = []; // map old id → new id biar parent_id ikut ke-copy

        \Illuminate\Support\Facades\DB::transaction(function () use ($source, &$copied, &$skipped, &$idMap) {
            // Pass 1: copy semua akun tanpa parent_id dulu
            foreach ($source as $old) {
                $exists = Coa::where('perusahaan_id', $this->selectedPerusahaanId)
                    ->where('kode', $old->kode)
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }

                $new = Coa::create([
                    'perusahaan_id' => $this->selectedPerusahaanId,
                    'kode' => $old->kode,
                    'nama' => $old->nama,
                    'tipe' => $old->tipe,
                    'saldo_normal' => $old->saldo_normal,
                    'parent_id' => null,
                    'is_header' => $old->is_header,
                    'is_aktif' => $old->is_aktif,
                    'satuan_hpp' => $old->satuan_hpp,
                    'updated_by_user_id' => Auth::id(),
                ]);
                $idMap[$old->id] = $new->id;
                $copied++;
            }

            // Pass 2: set parent_id berdasarkan map
            foreach ($source as $old) {
                if ($old->parent_id && isset($idMap[$old->id], $idMap[$old->parent_id])) {
                    Coa::where('id', $idMap[$old->id])
                        ->update(['parent_id' => $idMap[$old->parent_id]]);
                }
            }
        });

        Flux::modal('coa-copy')->close();
        $this->copyFromPerusahaanId = null;

        $msg = "Berhasil copy {$copied} akun.";
        if ($skipped > 0) {
            $msg .= " {$skipped} dilewati (kode sudah ada).";
        }

        Flux::toast(
            variant: $skipped > 0 ? 'warning' : 'success',
            text: $msg,
        );
    }

    public function toggleAktif(int $id): void
    {
        $c = Coa::findOrFail($id);
        $c->is_aktif = ! $c->is_aktif;
        $c->updated_by_user_id = Auth::id();
        $c->save();

        Flux::toast(variant: 'success', text: $c->is_aktif
            ? "Akun {$c->kode} diaktifkan."
            : "Akun {$c->kode} dinonaktifkan.");
    }

    public function confirmDelete(int $id): void
    {
        $c = Coa::withCount('children')->find($id);
        if (! $c) {
            return;
        }

        if ($c->children_count > 0) {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Akun '{$c->kode} {$c->nama}' masih punya {$c->children_count} child akun.",
            );
            return;
        }

        $this->deleteId = $c->id;
        $this->deleteNama = "{$c->kode} {$c->nama}";

        Flux::modal('coa-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        Coa::findOrFail($this->deleteId)->delete();

        Flux::modal('coa-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Akun COA dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'kode', 'nama', 'parentId', 'satuanHpp']);
        $this->tipe = 'aset';
        $this->saldoNormal = 'debit';
        $this->isHeader = false;
        $this->isAktif = true;
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6">
            <flux:heading size="xl">{{ __('Master COA') }}</flux:heading>
        </div>

        {{-- PILIH PERUSAHAAN --}}
        <div class="mb-5 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex-1 lg:max-w-md">
                    <flux:field>
                        <flux:label class="text-xs uppercase tracking-wider">{{ __('Pilih Perusahaan') }}</flux:label>
                        <flux:select wire:model.live="selectedPerusahaanId">
                            <flux:select.option value="">{{ __('-- Pilih PT --') }}</flux:select.option>
                            @foreach ($perusahaanList as $p)
                                <flux:select.option value="{{ $p->id }}">{{ $p->kode_surat }} — {{ $p->nama }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                @if ($selectedPerusahaanId)
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="filled" icon="document-duplicate" wire:click="openCopyModal">
                            {{ __('Copy COA dari PT lain') }}
                        </flux:button>
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="create">
                            {{ __('Tambah Akun') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>

        @if (! $selectedPerusahaanId)
            <div class="rounded-lg border-2 border-dashed border-zinc-200 bg-zinc-50 px-8 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.building-office-2 class="mx-auto size-12 text-zinc-400" />
                <flux:heading class="mt-4">{{ __('Pilih perusahaan dulu') }}</flux:heading>
                <flux:subheading class="mt-1">
                    {{ __('COA terikat ke perusahaan. Pilih PT di atas untuk lihat / kelola akun.') }}
                </flux:subheading>
            </div>
        @else

        {{-- TIPE TABS --}}
        @php
            $tipeTabs = [
                ['key' => '',           'label' => __('Semua'),      'count' => $totalAll,        'color' => 'zinc'],
                ['key' => 'aset',       'label' => __('Aset'),       'count' => $countAset,       'color' => 'blue'],
                ['key' => 'kewajiban',  'label' => __('Kewajiban'),  'count' => $countKewajiban,  'color' => 'amber'],
                ['key' => 'modal',      'label' => __('Modal'),      'count' => $countModal,      'color' => 'purple'],
                ['key' => 'pendapatan', 'label' => __('Pendapatan'), 'count' => $countPendapatan, 'color' => 'green'],
                ['key' => 'beban',      'label' => __('Beban'),      'count' => $countBeban,      'color' => 'red'],
            ];
        @endphp
        <div class="mb-4 flex flex-wrap items-center gap-2">
            @foreach ($tipeTabs as $tab)
                @php
                    $isActive = $filterTipe === $tab['key'];
                    $colorMap = [
                        'zinc'   => ['active' => 'border-zinc-500 bg-zinc-100 text-zinc-900 dark:border-zinc-400 dark:bg-zinc-700 dark:text-white', 'badge' => 'bg-zinc-500 text-white'],
                        'blue'   => ['active' => 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-950/40 dark:text-blue-300', 'badge' => 'bg-blue-500 text-white'],
                        'amber'  => ['active' => 'border-amber-500 bg-amber-50 text-amber-700 dark:border-amber-400 dark:bg-amber-950/40 dark:text-amber-300', 'badge' => 'bg-amber-500 text-white'],
                        'purple' => ['active' => 'border-purple-500 bg-purple-50 text-purple-700 dark:border-purple-400 dark:bg-purple-950/40 dark:text-purple-300', 'badge' => 'bg-purple-500 text-white'],
                        'green'  => ['active' => 'border-green-500 bg-green-50 text-green-700 dark:border-green-400 dark:bg-green-950/40 dark:text-green-300', 'badge' => 'bg-green-500 text-white'],
                        'red'    => ['active' => 'border-red-500 bg-red-50 text-red-700 dark:border-red-400 dark:bg-red-950/40 dark:text-red-300', 'badge' => 'bg-red-500 text-white'],
                    ];
                    $btnClass = $isActive
                        ? $colorMap[$tab['color']]['active']
                        : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-400 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:text-white';
                    $badgeClass = $isActive
                        ? $colorMap[$tab['color']]['badge']
                        : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
                @endphp
                <button type="button" wire:click="setFilterTipe('{{ $tab['key'] }}')"
                        @class([
                            'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition',
                            $btnClass,
                        ])>
                    {{ $tab['label'] }}
                    <span @class([
                        'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold',
                        $badgeClass,
                    ])>{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari kode atau nama akun...')" />
            <flux:select wire:model.live="filterAktif" placeholder="{{ __('Filter status') }}">
                <flux:select.option value="">{{ __('Semua Status') }}</flux:select.option>
                <flux:select.option value="aktif">{{ __('Aktif') }}</flux:select.option>
                <flux:select.option value="nonaktif">{{ __('Non-Aktif') }}</flux:select.option>
            </flux:select>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <x-sortable-column field="kode" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kode') }}</x-sortable-column>
                        <x-sortable-column field="nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama Akun') }}</x-sortable-column>
                        <x-sortable-column field="tipe" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Tipe') }}</x-sortable-column>
                        <x-sortable-column field="saldo_normal" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Saldo Normal') }}</x-sortable-column>
                        <flux:table.column>{{ __('Parent') }}</flux:table.column>
                        <flux:table.column>{{ __('Header') }}</flux:table.column>
                        <x-sortable-column field="is_aktif" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Status') }}</x-sortable-column>
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($coaList as $row)
                            <flux:table.row :key="'coa-'.$row->id"
                                @class([
                                    'bg-zinc-50/70 dark:bg-zinc-800/30' => $row->is_header,
                                ])>
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($coaList->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell
                                    @class([
                                        'whitespace-nowrap font-mono',
                                        'font-bold text-sm text-zinc-900 dark:text-white' => $row->is_header,
                                        'text-sm text-zinc-700 dark:text-zinc-300 ps-6' => ! $row->is_header,
                                    ])>
                                    @unless ($row->is_header)
                                        <flux:icon.arrow-turn-down-right class="me-1 inline size-3 text-zinc-400" />
                                    @endunless
                                    {{ $row->kode }}
                                </flux:table.cell>
                                <flux:table.cell
                                    @class([
                                        'whitespace-nowrap',
                                        'font-bold uppercase tracking-wide text-zinc-900 dark:text-white' => $row->is_header,
                                    ])>{{ $row->nama }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    @php
                                        $tipeColor = match ($row->tipe) {
                                            'aset' => 'blue',
                                            'kewajiban' => 'amber',
                                            'modal' => 'purple',
                                            'pendapatan' => 'green',
                                            'beban' => 'red',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge :color="$tipeColor" size="sm">{{ ucfirst($row->tipe) }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs uppercase tracking-wide">
                                    @if ($row->saldo_normal === 'debit')
                                        <span class="font-mono text-blue-600 dark:text-blue-400">DR</span>
                                    @else
                                        <span class="font-mono text-amber-600 dark:text-amber-400">CR</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->parent)
                                        <span class="font-mono">{{ $row->parent->kode }}</span>
                                        <span class="text-zinc-400">{{ $row->parent->nama }}</span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->is_header)
                                        <flux:badge color="zinc" size="sm" icon="folder">{{ __('Header') }}</flux:badge>
                                    @else
                                        <span class="text-zinc-400">{{ __('Detail') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($row->is_aktif)
                                        <flux:badge color="green" size="sm" icon="check-circle">{{ __('Aktif') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm" icon="x-circle">{{ __('Non-Aktif') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $row->id }})">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.item :icon="$row->is_aktif ? 'pause-circle' : 'play-circle'" wire:click="toggleAktif({{ $row->id }})">
                                                {{ $row->is_aktif ? __('Nonaktifkan') : __('Aktifkan') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $row->id }})">
                                                {{ __('Hapus') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="9" class="py-8 text-center text-zinc-500">
                                    @if ($search || $filterTipe || $filterAktif)
                                        {{ __('Tidak ada akun yang cocok dengan filter.') }}
                                    @else
                                        {{ __('Belum ada akun COA. Klik "Tambah Akun".') }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $coaList])

        @endif {{-- /selectedPerusahaanId --}}

        {{-- FORM MODAL --}}
        <flux:modal name="coa-form" class="md:w-2xl" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Edit Akun COA') : __('Tambah Akun COA') }}</flux:heading>
                    <flux:subheading>{{ __('Akun pembukuan. Saldo normal otomatis menyesuaikan tipe (bisa di-override untuk akun kontra).') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('Kode') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input wire:model="kode" required placeholder="1001.001" />
                        <flux:error name="kode" />
                    </flux:field>

                    <div class="md:col-span-2">
                        <flux:field>
                            <flux:label>{{ __('Nama Akun') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="nama" required placeholder="Kas Pusat" />
                            <flux:error name="nama" />
                        </flux:field>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Tipe') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="tipe" required>
                            <flux:select.option value="aset">{{ __('Aset') }}</flux:select.option>
                            <flux:select.option value="kewajiban">{{ __('Kewajiban') }}</flux:select.option>
                            <flux:select.option value="modal">{{ __('Modal') }}</flux:select.option>
                            <flux:select.option value="pendapatan">{{ __('Pendapatan') }}</flux:select.option>
                            <flux:select.option value="beban">{{ __('Beban (termasuk HPP)') }}</flux:select.option>
                        </flux:select>
                        <flux:error name="tipe" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Saldo Normal') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model="saldoNormal" required>
                            <flux:select.option value="debit">{{ __('Debit (DR)') }}</flux:select.option>
                            <flux:select.option value="kredit">{{ __('Kredit (CR)') }}</flux:select.option>
                        </flux:select>
                        <flux:description>{{ __('Auto-set ikut tipe. Override untuk akun kontra (mis. Akumulasi Penyusutan = aset tapi kredit).') }}</flux:description>
                        <flux:error name="saldoNormal" />
                    </flux:field>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Parent Akun') }}</flux:label>
                        <flux:select wire:model="parentId">
                            <flux:select.option value="">{{ __('-- Tidak ada (akun root) --') }}</flux:select.option>
                            @foreach ($parentOptions as $p)
                                @if ($editId !== $p->id)
                                    <flux:select.option value="{{ $p->id }}">{{ $p->kode }} — {{ $p->nama }}</flux:select.option>
                                @endif
                            @endforeach
                        </flux:select>
                        <flux:description>{{ __('Opsional. Untuk struktur hierarkis.') }}</flux:description>
                        <flux:error name="parentId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Satuan HPP') }}</flux:label>
                        <flux:input wire:model="satuanHpp" placeholder="unit, m², kg, dll" />
                        <flux:description>{{ __('Opsional. Untuk akun HPP yang dihitung per satuan.') }}</flux:description>
                        <flux:error name="satuanHpp" />
                    </flux:field>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1">
                                <flux:label class="font-semibold">{{ __('Akun Header') }}</flux:label>
                                <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                    {{ __('Header = akun induk untuk pengelompokan saja, tidak boleh di-input transaksi langsung.') }}
                                </p>
                            </div>
                            <flux:switch wire:model="isHeader" class="shrink-0" />
                        </div>
                    </div>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1">
                                <flux:label class="font-semibold">{{ __('Aktif') }}</flux:label>
                                <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                    {{ __('Non-aktif berarti akun tidak muncul di pilihan jurnal/transaksi baru.') }}
                                </p>
                            </div>
                            <flux:switch wire:model="isAktif" class="shrink-0" />
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- COPY COA MODAL --}}
        <flux:modal name="coa-copy" class="md:w-lg">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-950">
                        <flux:icon.document-duplicate class="size-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Copy COA dari Perusahaan Lain') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Salin semua akun dari perusahaan lain ke') }}
                            <span class="font-semibold">{{ $selectedPerusahaan?->nama }}</span>.
                            {{ __('Akun dengan kode yang sudah ada akan dilewati.') }}
                        </flux:subheading>
                    </div>
                </div>

                <flux:field>
                    <flux:label>{{ __('Sumber Perusahaan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model="copyFromPerusahaanId">
                        <flux:select.option value="">{{ __('-- Pilih perusahaan sumber --') }}</flux:select.option>
                        @foreach ($perusahaanList as $p)
                            @if ($p->id != $selectedPerusahaanId)
                                <flux:select.option value="{{ $p->id }}">{{ $p->kode_surat }} — {{ $p->nama }}</flux:select.option>
                            @endif
                        @endforeach
                    </flux:select>
                    <flux:error name="copyFromPerusahaanId" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" icon="document-duplicate" type="button" wire:click="copyCoa">
                        {{ __('Copy Sekarang') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- DELETE CONFIRM --}}
        <flux:modal name="coa-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Akun COA?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Anda akan menghapus :nama. Tindakan ini tidak dapat dibatalkan.', ['nama' => $deleteNama]) }}
                        </flux:subheading>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="button" icon="trash" wire:click="delete">
                        {{ __('Hapus') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

    </div>
</section>
