<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Rumah')] class extends Component {
    use Sortable, WithPagination;

    #[Url(as: 'proyek')]
    public $selectedProyekId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    #[Url(as: 'tipe')]
    public ?int $filterTipeId = null;

    #[Url(as: 'blok')]
    public string $filterBlok = '';

    #[Url(as: 'per')]
    public string $perPage = '20';

    protected function defaultSortBy(): ?string
    {
        return 'blok';
    }

    public ?int $editId = null;

    public string $blok = '';

    public string $nomor_unit = '';

    public $tipe_rumah_id = null;

    public string $biaya_tambahan = '';

    public string $discount = '';

    public string $ppn = '';

    public string $status = 'draft';

    // Bulk mode fields
    public string $mode = 'single'; // 'single' atau 'bulk'

    public string $nomor_dari = '';

    public string $nomor_sampai = '';

    public ?int $deleteId = null;

    public ?string $deleteNama = null;

    public function mount(): void
    {
        // Hydrate dari session global kalau URL belum punya value
        if (! $this->selectedProyekId && $sessionProyek = session('active_proyek_id')) {
            $this->selectedProyekId = $sessionProyek;
        }
    }

    // Listen ke global picker — sync property otomatis
    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->selectedProyekId = $proyekId;
        $this->resetPage();
        $this->search = '';
        $this->filterStatus = '';
    }

    protected function rules(): array
    {
        $base = [
            'blok' => ['required', 'string', 'max:20'],
            'tipe_rumah_id' => ['required', 'exists:tipe_rumah,id'],
            'biaya_tambahan' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'ppn' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:draft,available,booking,terjual'],
        ];

        if ($this->mode === 'bulk') {
            // Pakai `numeric` (bukan `integer`) supaya "03" (leading zero, string) tetap valid.
            $base['nomor_dari'] = ['required', 'numeric', 'min:1', 'max:9999'];
            $base['nomor_sampai'] = ['required', 'numeric', 'min:1', 'gte:nomor_dari', 'max:9999'];
        } else {
            $base['nomor_unit'] = ['required', 'string', 'max:20'];
        }

        return $base;
    }

    protected function validationAttributes(): array
    {
        return [
            'tipe_rumah_id' => 'tipe rumah',
            'nomor_unit' => 'nomor unit',
            'nomor_dari' => 'nomor dari',
            'nomor_sampai' => 'nomor sampai',
            'biaya_tambahan' => 'biaya tambahan',
            'discount' => 'discount',
            'ppn' => 'PPN',
        ];
    }

    // Realtime per-field validation: bersihkan & validasi ulang field yang baru saja di-update
    public function updated(string $property): void
    {
        if (array_key_exists($property, $this->rules())) {
            $this->resetValidation($property);
            try {
                $this->validateOnly($property);
            } catch (\Illuminate\Validation\ValidationException) {
                // Error sudah tersimpan di error bag oleh validateOnly
            }
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterTipeId(): void
    {
        $this->resetPage();
    }

    public function updatingFilterBlok(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function setFilterStatus(string $status): void
    {
        $this->filterStatus = $status;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterTipeId', 'filterBlok']);
        $this->resetPage();
    }

    public function with(): array
    {
        $proyekList = Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek', 'nama_perumahan', 'kota_kabupaten']);

        $counts = ['draft' => 0, 'available' => 0, 'booking' => 0, 'terjual' => 0];
        if ($this->selectedProyekId) {
            $rawCounts = Rumah::where('proyek_id', $this->selectedProyekId)
                ->selectRaw('status, COUNT(*) as cnt')
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->toArray();
            $counts = array_merge($counts, $rawCounts);
        }
        $counts['total'] = array_sum($counts);

        $rumahQuery = Rumah::query()
            ->with([
                'tipeRumah:id,tipe,nama_tipe,luas_tanah,luas_bangunan,harga_jual',
                'updatedByUser:id,name',
            ])
            ->withCount('virtualAccount')
            ->when($this->selectedProyekId, fn ($q) => $q->where('proyek_id', $this->selectedProyekId))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterTipeId, fn ($q) => $q->where('tipe_rumah_id', $this->filterTipeId))
            ->when($this->filterBlok !== '', fn ($q) => $q->where('blok', $this->filterBlok))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('blok', 'like', $term)
                        ->orWhere('nomor_unit', 'like', $term)
                        ->orWhereHas('tipeRumah', fn ($tt) => $tt->where('tipe', 'like', $term)
                            ->orWhere('nama_tipe', 'like', $term));
                });
            });

        // Default: sort by blok+nomor; user bisa override via klik header
        if (! $this->sortBy) {
            $rumahQuery->orderBy('blok')->orderBy('nomor_unit');
        } else {
            $this->applySort($rumahQuery, [
                'blok' => fn ($q, $dir) => $q->orderBy('blok', $dir)->orderBy('nomor_unit', $dir),
                'status',
                'biaya_tambahan',
                'discount',
                'ppn',
                'tanggal_launching',
                'updated_at',
            ]);
        }

        $tipeOptions = $this->selectedProyekId
            ? TipeRumah::where('proyek_id', $this->selectedProyekId)
                ->orderBy('tipe')->get(['id', 'tipe', 'nama_tipe'])
            : collect();

        $blokOptions = $this->selectedProyekId
            ? Rumah::where('proyek_id', $this->selectedProyekId)
                ->distinct()->orderBy('blok')->pluck('blok')
            : collect();

        return [
            'proyekList' => $proyekList,
            'rumahs' => $this->selectedProyekId
                ? $rumahQuery->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage))
                : null,
            'selectedProyek' => $this->selectedProyekId ? Proyek::find($this->selectedProyekId) : null,
            'tipeOptions' => $tipeOptions,
            'blokOptions' => $blokOptions,
            'counts' => $counts,
        ];
    }

    public function create(): void
    {
        if (! $this->guardCreate()) {
            return;
        }

        $this->resetForm();
        $this->mode = 'single';
        Flux::modal('rumah-form')->show();
    }

    public function createBulk(): void
    {
        if (! $this->guardCreate()) {
            return;
        }

        $this->resetForm();
        $this->mode = 'bulk';
        Flux::modal('rumah-form')->show();
    }

    protected function guardCreate(): bool
    {
        if (! $this->selectedProyekId) {
            Flux::toast(variant: 'warning', text: 'Pilih proyek dulu.');

            return false;
        }

        $tipeCount = TipeRumah::where('proyek_id', $this->selectedProyekId)->count();
        if ($tipeCount === 0) {
            Flux::toast(
                variant: 'warning',
                heading: 'Belum ada tipe rumah',
                text: 'Buat tipe rumah dulu untuk proyek ini sebelum menambah unit.',
            );

            return false;
        }

        return true;
    }

    public function edit(int $id): void
    {
        $r = Rumah::findOrFail($id);

        $this->mode = 'single'; // edit selalu single
        $this->editId = $r->id;
        $this->blok = $r->blok;
        $this->nomor_unit = $r->nomor_unit;
        $this->tipe_rumah_id = $r->tipe_rumah_id;
        $this->biaya_tambahan = $r->biaya_tambahan ? (string) $r->biaya_tambahan : '';
        $this->discount = $r->discount ? (string) $r->discount : '';
        $this->ppn = $r->ppn ? (string) $r->ppn : '';
        $this->status = $r->status;
        $this->resetErrorBag();

        Flux::modal('rumah-form')->show();
    }

    public function save(): void
    {
        if (! $this->selectedProyekId) {
            Flux::toast(variant: 'danger', text: 'Proyek belum dipilih.');

            return;
        }

        if ($this->mode === 'bulk') {
            $this->saveBulk();

            return;
        }

        $validated = $this->validate();

        $duplicateQuery = Rumah::where('proyek_id', $this->selectedProyekId)
            ->where('blok', $validated['blok'])
            ->where('nomor_unit', $validated['nomor_unit']);
        if ($this->editId) {
            $duplicateQuery->where('id', '!=', $this->editId);
        }
        if ($duplicateQuery->exists()) {
            $this->addError('nomor_unit', 'Kombinasi blok + nomor unit sudah ada di proyek ini.');

            return;
        }

        $rumah = $this->editId
            ? Rumah::findOrFail($this->editId)
            : new Rumah;

        $oldStatus = $rumah->status ?? null;
        $newStatus = $validated['status'];
        $tanggalLaunching = $rumah->tanggal_launching;
        if ($newStatus === 'available' && $oldStatus !== 'available' && empty($tanggalLaunching)) {
            $tanggalLaunching = now()->toDateString();
        }

        $rumah->fill([
            'proyek_id' => $this->selectedProyekId,
            'tipe_rumah_id' => $validated['tipe_rumah_id'],
            'blok' => $validated['blok'],
            'nomor_unit' => $validated['nomor_unit'],
            'biaya_tambahan' => $validated['biaya_tambahan'] !== '' ? $validated['biaya_tambahan'] : null,
            'discount' => $validated['discount'] !== '' ? $validated['discount'] : 0,
            'ppn' => $validated['ppn'] !== '' ? $validated['ppn'] : 0,
            'status' => $newStatus,
            'tanggal_launching' => $tanggalLaunching,
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('rumah-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Unit rumah diperbarui.'
            : 'Unit rumah baru ditambahkan.');
    }

    protected function saveBulk(): void
    {
        $validated = $this->validate();

        $from = (int) $validated['nomor_dari'];
        $to = (int) $validated['nomor_sampai'];

        if (($to - $from + 1) > 200) {
            $this->addError('nomor_sampai', 'Maksimal 200 unit per bulk add.');

            return;
        }

        $padding = max(2, strlen((string) $to));
        $tanggalLaunching = $validated['status'] === 'available' ? now()->toDateString() : null;

        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($validated, $from, $to, $padding, $tanggalLaunching, &$created, &$skipped) {
            for ($i = $from; $i <= $to; $i++) {
                $nomor = str_pad((string) $i, $padding, '0', STR_PAD_LEFT);

                $exists = Rumah::where('proyek_id', $this->selectedProyekId)
                    ->where('blok', $validated['blok'])
                    ->where('nomor_unit', $nomor)
                    ->exists();

                if ($exists) {
                    $skipped[] = $nomor;

                    continue;
                }

                Rumah::create([
                    'proyek_id' => $this->selectedProyekId,
                    'tipe_rumah_id' => $validated['tipe_rumah_id'],
                    'blok' => $validated['blok'],
                    'nomor_unit' => $nomor,
                    'biaya_tambahan' => $validated['biaya_tambahan'] !== '' ? $validated['biaya_tambahan'] : null,
                    'discount' => $validated['discount'] !== '' ? $validated['discount'] : 0,
                    'ppn' => $validated['ppn'] !== '' ? $validated['ppn'] : 0,
                    'status' => $validated['status'],
                    'tanggal_launching' => $tanggalLaunching,
                    'updated_by_user_id' => Auth::id(),
                ]);
                $created++;
            }
        });

        Flux::modal('rumah-form')->close();
        $this->resetForm();

        $msg = "Berhasil tambah {$created} unit.";
        if (count($skipped) > 0) {
            $sample = array_slice($skipped, 0, 5);
            $msg .= ' Dilewati '.count($skipped).' (sudah ada): '.implode(', ', $sample).(count($skipped) > 5 ? '...' : '').'.';
        }

        Flux::toast(
            variant: count($skipped) > 0 ? 'warning' : 'success',
            text: $msg,
        );
    }

    public function confirmDelete(int $id): void
    {
        $rumah = Rumah::withCount('virtualAccount')->find($id);

        if (! $rumah) {
            return;
        }

        if ($rumah->virtual_account_count > 0) {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Unit {$rumah->blok}-{$rumah->nomor_unit} masih punya {$rumah->virtual_account_count} virtual account. Hapus VA dulu.",
            );

            return;
        }

        $this->deleteId = $rumah->id;
        $this->deleteNama = "Unit {$rumah->blok}-{$rumah->nomor_unit}";

        Flux::modal('rumah-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        Rumah::findOrFail($this->deleteId)->delete();

        Flux::modal('rumah-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Unit rumah dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editId',
            'blok',
            'nomor_unit',
            'nomor_dari',
            'nomor_sampai',
            'biaya_tambahan',
            'discount',
            'ppn',
        ]);
        $this->tipe_rumah_id = '';
        $this->status = 'draft';
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6">
            <flux:heading size="xl">{{ __('Master Rumah') }}</flux:heading>
        </div>

        @if (! $selectedProyekId)
            <div class="rounded-lg border-2 border-dashed border-zinc-200 bg-zinc-50 px-8 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.key class="mx-auto size-12 text-zinc-400" />
                <flux:heading class="mt-4">{{ __('Pilih proyek dulu') }}</flux:heading>
                <flux:subheading class="mt-1">
                    {{ __('Unit rumah terhubung ke proyek tertentu. Pilih proyek aktif di widget "Proyek Aktif" pada sidebar kiri.') }}
                </flux:subheading>
            </div>
        @else
            {{-- Banner kecil + Tambah (responsive: stack on mobile) --}}
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="inline-flex max-w-full flex-wrap items-center gap-x-1.5 gap-y-0.5 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800/50">
                    <flux:icon.home-modern class="size-4 shrink-0 text-zinc-500" />
                    <span class="text-zinc-500 max-sm:hidden">{{ __('Proyek:') }}</span>
                    <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $selectedProyek?->nama_proyek }}</span>
                    <span class="text-zinc-400">— {{ $selectedProyek?->nama_perumahan }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <flux:button variant="filled" icon="squares-plus" wire:click="createBulk">
                        <span class="max-sm:hidden">{{ __('Tambah Banyak') }}</span>
                        <span class="sm:hidden">{{ __('Banyak') }}</span>
                    </flux:button>
                    <flux:button variant="primary" icon="plus" wire:click="create">
                        <span class="max-sm:hidden">{{ __('Tambah Kavling') }}</span>
                        <span class="sm:hidden">{{ __('Tambah') }}</span>
                    </flux:button>
                </div>
            </div>

            {{-- Status filter tabs --}}
            @php
                $tabs = [
                    ['key' => '',         'label' => __('Semua'),    'count' => $counts['total']],
                    ['key' => 'draft',    'label' => __('Draft'),    'count' => $counts['draft']],
                    ['key' => 'available', 'label' => __('Available'), 'count' => $counts['available']],
                    ['key' => 'booking',   'label' => __('Booking'),   'count' => $counts['booking']],
                    ['key' => 'terjual',   'label' => __('Terjual'),   'count' => $counts['terjual']],
                ];
            @endphp
            <div class="mb-4 flex flex-wrap items-center gap-2">
                @foreach ($tabs as $tab)
                    @php
                        $isActive = $filterStatus === $tab['key'];
                        $btnClass = $isActive
                            ? match ($tab['key']) {
                                'available' => 'border-green-500 bg-green-50 text-green-700 dark:border-green-400 dark:bg-green-950/40 dark:text-green-300',
                                'booking'   => 'border-amber-500 bg-amber-50 text-amber-700 dark:border-amber-400 dark:bg-amber-950/40 dark:text-amber-300',
                                'terjual'   => 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-950/40 dark:text-blue-300',
                                default    => 'border-zinc-500 bg-zinc-100 text-zinc-900 dark:border-zinc-400 dark:bg-zinc-700 dark:text-white',
                            }
                            : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-400 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:text-white';

                        $badgeClass = $isActive
                            ? match ($tab['key']) {
                                'available' => 'bg-green-500 text-white',
                                'booking'   => 'bg-amber-500 text-white',
                                'terjual'   => 'bg-blue-500 text-white',
                                default    => 'bg-zinc-500 text-white',
                            }
                            : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
                    @endphp
                    <button type="button" wire:click="setFilterStatus('{{ $tab['key'] }}')"
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

            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12">
                <div class="md:col-span-6">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                                :placeholder="__('Cari blok, nomor unit, atau tipe...')" />
                </div>
                <div class="md:col-span-3">
                    <flux:select wire:model.live="filterTipeId" :placeholder="__('Semua Tipe')">
                        <flux:select.option value="">{{ __('Semua Tipe') }}</flux:select.option>
                        @foreach ($tipeOptions as $t)
                            <flux:select.option value="{{ $t->id }}">{{ $t->tipe }} — {{ $t->nama_tipe }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="md:col-span-3">
                    <flux:select wire:model.live="filterBlok" :placeholder="__('Semua Blok')">
                        <flux:select.option value="">{{ __('Semua Blok') }}</flux:select.option>
                        @foreach ($blokOptions as $b)
                            <flux:select.option value="{{ $b }}">{{ __('Blok') }} {{ $b }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            @if ($search || $filterStatus || $filterTipeId || $filterBlok)
                <div class="mb-3">
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                        {{ __('Reset filter') }}
                    </flux:button>
                </div>
            @endif

            {{-- TABLE --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <flux:table class="px-4">
                        <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                            <x-sortable-column field="blok" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kavling') }}</x-sortable-column>
                            <flux:table.column>{{ __('Tipe') }}</flux:table.column>
                            <flux:table.column align="center">{{ __('Luas T/B') }}</flux:table.column>
                            <x-sortable-column field="biaya_tambahan" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Biaya Tambahan') }}</x-sortable-column>
                            <x-sortable-column field="discount" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Discount') }}</x-sortable-column>
                            <x-sortable-column field="ppn" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('PPN') }}</x-sortable-column>
                            <flux:table.column align="end">{{ __('Harga Jual') }}</flux:table.column>
                            <x-sortable-column field="status" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Status') }}</x-sortable-column>
                            <x-sortable-column field="tanggal_launching" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Tgl Launching') }}</x-sortable-column>
                            <x-sortable-column field="updated_at" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Diupdate') }}</x-sortable-column>
                            <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($rumahs as $row)
                                <flux:table.row :key="'row-'.$row->id">
                                    <flux:table.cell class="text-zinc-500">{{ $loop->index + ($rumahs->firstItem() ?? 1) }}</flux:table.cell>
                                    <flux:table.cell variant="strong" class="whitespace-nowrap font-mono">{{ $row->kode_unit }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap">
                                        <span class="font-mono">{{ $row->tipeRumah?->tipe }}</span>
                                        <span class="text-xs text-zinc-400">— {{ $row->tipeRumah?->nama_tipe }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell align="center" class="whitespace-nowrap font-mono text-xs">
                                        {{ (int) $row->luas_tanah }}/{{ (int) $row->luas_bangunan }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono text-zinc-500">
                                        {{ $row->biaya_tambahan ? 'Rp '.number_format($row->biaya_tambahan, 0, ',', '.') : '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono text-rose-600">
                                        {{ $row->discount > 0 ? 'Rp '.number_format($row->discount, 0, ',', '.') : '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono text-amber-600">
                                        {{ $row->ppn > 0 ? 'Rp '.number_format($row->ppn, 0, ',', '.') : '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono">
                                        Rp {{ number_format($row->harga_jual, 0, ',', '.') }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @php
                                            $badgeColor = match ($row->status) {
                                                'available' => 'green',
                                                'booking' => 'amber',
                                                'terjual' => 'blue',
                                                default => 'zinc',
                                            };
                                            $statusLabel = match ($row->status) {
                                                'draft' => 'Draft',
                                                'available' => 'Available',
                                                'booking' => 'Booking',
                                                'terjual' => 'Terjual',
                                                default => $row->status,
                                            };
                                        @endphp
                                        <flux:badge :color="$badgeColor" size="sm">{{ $statusLabel }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500">
                                        {{ $row->tanggal_launching?->format('d/m/Y') ?? '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500">
                                        <div>{{ $row->updated_at?->format('d/m/Y H:i') ?? '—' }}</div>
                                        @if ($row->updatedByUser)
                                            <div class="text-zinc-400">{{ $row->updatedByUser->name }}</div>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $row->id }})">
                                                    {{ __('Edit') }}
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
                                    <flux:table.cell colspan="12" class="py-8 text-center text-zinc-500">
                                        @if ($search || $filterStatus)
                                            {{ __('Tidak ada unit yang cocok dengan filter.') }}
                                        @else
                                            {{ __('Belum ada unit untuk proyek ini. Klik "Tambah Kavling" untuk menambahkan.') }}
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>

            @include('partials.per-page-pagination', ['paginator' => $rumahs])
        @endif

        {{-- DELETE MODAL --}}
        <flux:modal name="rumah-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Unit Rumah?') }}</flux:heading>
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

        {{-- FORM MODAL --}}
        <flux:modal name="rumah-form" class="md:w-2xl" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        @if ($editId)
                            {{ __('Edit Unit Rumah') }}
                        @elseif ($mode === 'bulk')
                            {{ __('Tambah Banyak Unit Rumah') }}
                        @else
                            {{ __('Tambah Unit Rumah') }}
                        @endif
                    </flux:heading>
                    <flux:subheading>
                        @if ($mode === 'bulk')
                            {{ __('Generate banyak unit dengan blok & spec sama, hanya berbeda nomor.') }}
                        @else
                            {{ __('Untuk proyek:') }} <span class="font-semibold">{{ $selectedProyek?->nama_proyek }}</span>
                        @endif
                    </flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Identitas Unit') }}</flux:heading>

                    @if ($mode === 'bulk')
                        <flux:field>
                            <flux:label>{{ __('Blok') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model.live.debounce.500ms="blok" required placeholder="A" />
                            <flux:error name="blok" />
                        </flux:field>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Dari Nomor') }} <span class="ms-1 text-red-500">*</span></flux:label>
                                <flux:input wire:model.live.debounce.500ms="nomor_dari" type="number" min="1" max="9999" required placeholder="1" />
                                <flux:error name="nomor_dari" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Sampai Nomor') }} <span class="ms-1 text-red-500">*</span></flux:label>
                                <flux:input wire:model.live.debounce.500ms="nomor_sampai" type="number" min="1" max="9999" required placeholder="10" />
                                <flux:error name="nomor_sampai" />
                            </flux:field>
                        </div>

                        @php
                            $bulkCount = (is_numeric($nomor_dari) && is_numeric($nomor_sampai) && (int) $nomor_sampai >= (int) $nomor_dari)
                                ? ((int) $nomor_sampai - (int) $nomor_dari + 1)
                                : 0;
                            $bulkPadding = $bulkCount > 0 ? max(2, strlen((string) (int) $nomor_sampai)) : 2;
                            $previewFirst = $bulkCount > 0 ? str_pad((string) (int) $nomor_dari, $bulkPadding, '0', STR_PAD_LEFT) : '';
                            $previewLast = $bulkCount > 0 ? str_pad((string) (int) $nomor_sampai, $bulkPadding, '0', STR_PAD_LEFT) : '';
                        @endphp
                        @if ($bulkCount > 0 && $blok)
                            <div class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm dark:border-blue-800 dark:bg-blue-950/30">
                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Akan dibuat') }}</span>
                                <span class="font-bold text-blue-700 dark:text-blue-300">{{ $bulkCount }} unit</span>
                                <span class="text-zinc-600 dark:text-zinc-400">:
                                    <span class="font-mono">{{ $blok }}-{{ $previewFirst }}</span>
                                    @if ($bulkCount > 2) <span class="text-zinc-400">,</span> <span class="font-mono">{{ $blok }}-{{ str_pad((string) ((int) $nomor_dari + 1), $bulkPadding, '0', STR_PAD_LEFT) }}</span> <span class="text-zinc-400">,</span> <span class="text-zinc-400">...</span> @endif
                                    @if ($bulkCount > 1) <span class="text-zinc-400">,</span> <span class="font-mono">{{ $blok }}-{{ $previewLast }}</span> @endif
                                </span>
                            </div>
                        @endif
                    @else
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Blok') }} <span class="ms-1 text-red-500">*</span></flux:label>
                                <flux:input wire:model.live.debounce.500ms="blok" required placeholder="A" />
                                <flux:error name="blok" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Nomor Unit / Kavling') }} <span class="ms-1 text-red-500">*</span></flux:label>
                                <flux:input wire:model.live.debounce.500ms="nomor_unit" required placeholder="12" />
                                <flux:error name="nomor_unit" />
                            </flux:field>
                        </div>
                    @endif

                    <flux:field>
                        <flux:label>{{ __('Tipe Rumah') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="tipe_rumah_id" required>
                            <flux:select.option value="">{{ __('-- Pilih tipe rumah --') }}</flux:select.option>
                            @foreach ($tipeOptions as $t)
                                <flux:select.option value="{{ $t->id }}">{{ $t->tipe }} — {{ $t->nama_tipe }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="tipe_rumah_id" />
                    </flux:field>
                </div>

                <flux:separator />

                <div class="space-y-4">
                    <div>
                        <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Override per Unit') }}</flux:heading>
                        <flux:subheading class="text-xs">
                            {{ __('Luas dasar & harga mengikuti Tipe Rumah. Field di bawah ini hanya diisi jika unit ini berbeda (mis. hook).') }}
                        </flux:subheading>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Biaya Tambahan (Rp)') }}</flux:label>
                            <x-money-input wire="biaya_tambahan" />
                            <flux:description>{{ __('Biaya bahan bangunan ekstra untuk unit hook / upgrade fasilitas.') }}</flux:description>
                            <flux:error name="biaya_tambahan" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Discount (Rp)') }}</flux:label>
                            <x-money-input wire="discount" />
                            <flux:description>{{ __('Diskon promo/negosiasi per unit. 0 jika tidak ada.') }}</flux:description>
                            <flux:error name="discount" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('PPN (Rp)') }}</flux:label>
                            <x-money-input wire="ppn" />
                            <flux:description>{{ __('PPN unit. 0 untuk subsidi normal, isi untuk komersial atau exception.') }}</flux:description>
                            <flux:error name="ppn" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Status') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="status" required>
                            <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
                            <flux:select.option value="available">{{ __('Available / Launching (siap dijual, muncul di DBOS)') }}</flux:select.option>
                            @if ($status === 'booking')
                                <flux:select.option value="booking" disabled>{{ __('Booking (otomatis dari DBOS)') }}</flux:select.option>
                            @endif
                            @if ($status === 'terjual')
                                <flux:select.option value="terjual" disabled>{{ __('Terjual (otomatis saat SPR terbit)') }}</flux:select.option>
                            @endif
                        </flux:select>
                        <flux:description>
                            {{ __('Status "Booking" & "Terjual" otomatis ter-update dari flow DBOS/SPR, tidak diatur manual.') }}
                        </flux:description>
                        <flux:error name="status" />
                    </flux:field>
                </div>

                {{-- Posisi di siteplan dipindah ke halaman dedicated:
                     Master → Proyek → action "Mapping Siteplan" --}}

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">
                        {{ $mode === 'bulk' ? __('Simpan Semua') : __('Simpan') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

    </div>
</section>
