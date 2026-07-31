<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Tipe Rumah')] class extends Component {
    use Sortable, WithPagination;

    #[Url(as: 'proyek')]
    public $selectedProyekId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kategori')]
    public string $filterKategori = '';

    #[Url(as: 'aktif')]
    public string $filterAktif = '';

    #[Url(as: 'per')]
    public string $perPage = '10';

    protected function defaultSortBy(): ?string
    {
        return 'tipe';
    }

    public ?int $editId = null;

    public string $tipe = '';

    public string $nama_tipe = '';

    public string $kategori = 'komersial';

    public string $harga_all_in = '0';

    public string $harga_jual = '0';

    public string $plafon_kpr = '0';

    public string $utj = '0';

    public string $luas_tanah = '0';

    public string $luas_bangunan = '0';

    public string $biaya_administrasi = '0';

    public string $sbum = '0';

    public ?string $spesifikasi = null;

    public bool $is_aktif = true;

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
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterKategori(): void
    {
        $this->resetPage();
    }

    public function updatingFilterAktif(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'filterKategori', 'filterAktif']);
        $this->resetPage();
    }

    public function with(): array
    {
        $proyekList = Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek', 'nama_perumahan', 'kota_kabupaten']);

        $tipeQuery = TipeRumah::query()
            ->withCount([
                'rumah',
                'rumah as unit_launching_count' => fn ($q) => $q->where('status', 'available'),
            ])
            ->with('updatedByUser:id,name')
            ->when($this->selectedProyekId, fn ($q) => $q->where('proyek_id', $this->selectedProyekId))
            ->when($this->filterKategori !== '', fn ($q) => $q->where('kategori', $this->filterKategori))
            ->when($this->filterAktif !== '', fn ($q) => $q->where('is_aktif', $this->filterAktif === '1'))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('tipe', 'like', $term)
                        ->orWhere('nama_tipe', 'like', $term);
                });
            });

        $this->applySort($tipeQuery, [
            'tipe', 'nama_tipe', 'kategori', 'luas_tanah', 'harga_jual', 'harga_all_in', 'utj',
            'rumah_count' => fn ($q, $dir) => $q->orderBy('rumah_count', $dir),
            'is_aktif',
        ]);

        return [
            'proyekList' => $proyekList,
            'tipes' => $this->selectedProyekId
                ? $tipeQuery->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage))
                : null,
            'selectedProyek' => $this->selectedProyekId ? Proyek::find($this->selectedProyekId) : null,
        ];
    }

    public function create(): void
    {
        if (! $this->selectedProyekId) {
            Flux::toast(variant: 'warning', text: 'Pilih proyek dulu sebelum menambah tipe rumah.');

            return;
        }

        $this->resetForm();
        Flux::modal('tipe-rumah-form')->show();
    }

    public function edit(int $id): void
    {
        $t = TipeRumah::findOrFail($id);

        $this->editId = $t->id;
        $this->tipe = $t->tipe;
        $this->nama_tipe = $t->nama_tipe;
        $this->kategori = $t->kategori;
        $this->harga_all_in = (string) $t->harga_all_in;
        $this->harga_jual = (string) $t->harga_jual;
        $this->plafon_kpr = (string) $t->plafon_kpr;
        $this->utj = (string) $t->utj;
        $this->luas_tanah = (string) $t->luas_tanah;
        $this->luas_bangunan = (string) $t->luas_bangunan;
        $this->biaya_administrasi = (string) $t->biaya_administrasi;
        $this->sbum = (string) $t->sbum;
        $this->spesifikasi = $t->spesifikasi;
        $this->is_aktif = (bool) $t->is_aktif;
        $this->resetErrorBag();

        Flux::modal('tipe-rumah-form')->show();
    }

    public function save(): void
    {
        if (! $this->selectedProyekId) {
            Flux::toast(variant: 'danger', text: 'Proyek belum dipilih.');

            return;
        }

        $validated = $this->validate([
            'tipe' => ['required', 'string', 'max:50'],
            'nama_tipe' => ['required', 'string', 'max:255'],
            'kategori' => ['required', 'in:komersial,subsidi'],
            'harga_all_in' => ['required', 'numeric', 'min:0'],
            'harga_jual' => ['required', 'numeric', 'min:0'],
            'plafon_kpr' => ['required', 'numeric', 'min:0'],
            'utj' => ['required', 'numeric', 'min:0'],
            'luas_tanah' => ['required', 'numeric', 'min:0'],
            'luas_bangunan' => ['required', 'numeric', 'min:0'],
            'biaya_administrasi' => ['required', 'numeric', 'min:0'],
            'sbum' => ['required', 'numeric', 'min:0'],
            'spesifikasi' => ['nullable', 'string', 'max:2000'],
            'is_aktif' => ['boolean'],
        ]);

        $tipe = $this->editId
            ? TipeRumah::findOrFail($this->editId)
            : new TipeRumah;

        $tipe->fill([
            'proyek_id' => $this->selectedProyekId,
            'tipe' => $validated['tipe'],
            'nama_tipe' => $validated['nama_tipe'],
            'kategori' => $validated['kategori'],
            'harga_all_in' => $validated['harga_all_in'],
            'harga_jual' => $validated['harga_jual'],
            'plafon_kpr' => $validated['plafon_kpr'],
            'utj' => $validated['utj'],
            'luas_tanah' => $validated['luas_tanah'],
            'luas_bangunan' => $validated['luas_bangunan'],
            'biaya_administrasi' => $validated['biaya_administrasi'],
            'sbum' => $validated['sbum'],
            'spesifikasi' => $validated['spesifikasi'] ?: null,
            'is_aktif' => (bool) ($validated['is_aktif'] ?? true),
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('tipe-rumah-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Tipe rumah diperbarui.'
            : 'Tipe rumah baru ditambahkan.');
    }

    public function confirmDelete(int $id): void
    {
        $tipe = TipeRumah::find($id);

        if (! $tipe) {
            return;
        }

        $rumahCount = Rumah::where('tipe_rumah_id', $id)->count();

        if ($rumahCount > 0) {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Tipe '{$tipe->tipe}' masih dipakai oleh {$rumahCount} unit rumah. Hapus data rumah dulu.",
            );

            return;
        }

        $this->deleteId = $tipe->id;
        $this->deleteNama = $tipe->tipe.' — '.$tipe->nama_tipe;

        Flux::modal('tipe-rumah-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        TipeRumah::findOrFail($this->deleteId)->delete();

        Flux::modal('tipe-rumah-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Tipe rumah dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editId',
            'tipe',
            'nama_tipe',
        ]);
        $this->kategori = 'komersial';
        $this->harga_all_in = '0';
        $this->harga_jual = '0';
        $this->plafon_kpr = '0';
        $this->utj = '0';
        $this->luas_tanah = '0';
        $this->luas_bangunan = '0';
        $this->biaya_administrasi = '0';
        $this->sbum = '0';
        $this->spesifikasi = null;
        $this->is_aktif = true;
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6">
            <flux:heading size="xl">{{ __('Master Tipe Rumah') }}</flux:heading>
        </div>

        @if (! $selectedProyekId)
            <div class="rounded-lg border-2 border-dashed border-zinc-200 bg-zinc-50 px-8 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.cube class="mx-auto size-12 text-zinc-400" />
                <flux:heading class="mt-4">{{ __('Pilih proyek dulu') }}</flux:heading>
                <flux:subheading class="mt-1">
                    {{ __('Tipe rumah selalu terhubung ke proyek tertentu. Pilih proyek aktif di widget "Proyek Aktif" pada sidebar kiri.') }}
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

                <flux:button variant="primary" icon="plus" wire:click="create">
                    {{ __('Tambah') }}
                </flux:button>
            </div>

            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12">
                <div class="md:col-span-6">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                                :placeholder="__('Cari tipe atau nama tipe...')" />
                </div>
                <div class="md:col-span-3">
                    <flux:select wire:model.live="filterKategori" :placeholder="__('Semua Kategori')">
                        <flux:select.option value="">{{ __('Semua Kategori') }}</flux:select.option>
                        <flux:select.option value="subsidi">{{ __('Subsidi') }}</flux:select.option>
                        <flux:select.option value="komersial">{{ __('Komersial') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="md:col-span-3">
                    <flux:select wire:model.live="filterAktif" :placeholder="__('Semua Status')">
                        <flux:select.option value="">{{ __('Semua Status') }}</flux:select.option>
                        <flux:select.option value="1">{{ __('Aktif') }}</flux:select.option>
                        <flux:select.option value="0">{{ __('Non-aktif / Closed') }}</flux:select.option>
                    </flux:select>
                </div>
            </div>

            @if ($search || $filterKategori !== '' || $filterAktif !== '')
                <div class="mb-3">
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                        {{ __('Reset filter') }}
                    </flux:button>
                </div>
            @endif

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <flux:table class="px-4">
                        <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                            <x-sortable-column field="tipe" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Tipe') }}</x-sortable-column>
                            <x-sortable-column field="nama_tipe" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama Tipe') }}</x-sortable-column>
                            <x-sortable-column field="kategori" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kategori') }}</x-sortable-column>
                            <x-sortable-column field="luas_tanah" align="center" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Luas T/B') }}</x-sortable-column>
                            <x-sortable-column field="harga_jual" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Harga Jual') }}</x-sortable-column>
                            <x-sortable-column field="harga_all_in" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('All In') }}</x-sortable-column>
                            <x-sortable-column field="utj" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('UTJ') }}</x-sortable-column>
                            <x-sortable-column field="plafon_kpr" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Plafon KPR') }}</x-sortable-column>
                            <flux:table.column align="end">{{ __('SBUM') }}</flux:table.column>
                            <x-sortable-column field="rumah_count" align="center" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Total Unit') }}</x-sortable-column>
                            <x-sortable-column field="is_aktif" align="center" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Aktif') }}</x-sortable-column>
                            <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($tipes as $row)
                                <flux:table.row :key="'row-'.$row->id">
                                    <flux:table.cell class="text-zinc-500">{{ $loop->index + ($tipes->firstItem() ?? 1) }}</flux:table.cell>
                                    <flux:table.cell variant="strong" class="whitespace-nowrap font-mono">{{ $row->tipe }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap">{{ $row->nama_tipe }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($row->kategori === 'subsidi')
                                            <flux:badge color="green" size="sm">{{ __('Subsidi') }}</flux:badge>
                                        @else
                                            <flux:badge color="blue" size="sm">{{ __('Komersial') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="center" class="whitespace-nowrap font-mono text-xs">
                                        {{ (int) $row->luas_tanah }}/{{ (int) $row->luas_bangunan }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono">Rp {{ number_format($row->harga_jual, 0, ',', '.') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono text-zinc-500">Rp {{ number_format($row->harga_all_in, 0, ',', '.') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono">Rp {{ number_format($row->utj, 0, ',', '.') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono text-xs">
                                        {{ $row->plafon_kpr > 0 ? 'Rp '.number_format($row->plafon_kpr, 0, ',', '.') : '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="whitespace-nowrap font-mono text-xs text-emerald-600">
                                        {{ $row->sbum > 0 ? 'Rp '.number_format($row->sbum, 0, ',', '.') : '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="center" class="font-semibold">{{ $row->rumah_count }}</flux:table.cell>
                                    <flux:table.cell align="center">
                                        @if ($row->is_aktif)
                                            <flux:badge color="green" size="sm">{{ __('Aktif') }}</flux:badge>
                                        @else
                                            <flux:badge color="zinc" size="sm">{{ __('Closed') }}</flux:badge>
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
                                    <flux:table.cell colspan="13" class="py-8 text-center text-zinc-500">
                                        @if ($search)
                                            {{ __('Tidak ada tipe rumah yang cocok dengan ":q".', ['q' => $search]) }}
                                        @else
                                            {{ __('Belum ada tipe rumah untuk proyek ini. Klik "Tambah" untuk menambahkan.') }}
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>

            @include('partials.per-page-pagination', ['paginator' => $tipes])
        @endif

        {{-- DELETE MODAL --}}
        <flux:modal name="tipe-rumah-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Tipe Rumah?') }}</flux:heading>
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
        <flux:modal name="tipe-rumah-form" class="md:w-2xl" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $editId ? __('Edit Tipe Rumah') : __('Tambah Tipe Rumah') }}
                    </flux:heading>
                    <flux:subheading>
                        {{ __('Untuk proyek:') }} <span class="font-semibold">{{ $selectedProyek?->nama_proyek }}</span>
                    </flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                {{-- IDENTITAS --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Identitas') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Tipe') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="tipe" required placeholder="Arjuna 30/60" />
                            <flux:description>{{ __('Contoh: Arjuna 30/60') }}</flux:description>
                            <flux:error name="tipe" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Nama Tipe') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="nama_tipe" required placeholder="Arjuna" />
                            <flux:error name="nama_tipe" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Kategori') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:select wire:model="kategori" required>
                                <flux:select.option value="komersial">{{ __('Komersial') }}</flux:select.option>
                                <flux:select.option value="subsidi">{{ __('Subsidi') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="kategori" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:checkbox wire:model="is_aktif" :label="__('Tipe ini masih dijual (aktif)')" />
                        <flux:description>{{ __('Uncheck jika tipe sudah closed/discontinued. Tipe non-aktif tetap dapat dilihat di SPR lama tetapi tidak muncul di pilihan baru.') }}</flux:description>
                    </flux:field>
                </div>

                <flux:separator />

                {{-- LUAS DASAR --}}
                <div class="space-y-4">
                    <div>
                        <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Luas Dasar') }}</flux:heading>
                        <flux:subheading class="text-xs">{{ __('Luas standar semua unit dari tipe ini. Unit hook bisa override via kolom kelebihan tanah di Master Rumah.') }}</flux:subheading>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Luas Tanah (m²)') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="luas_tanah" type="number" step="0.01" min="0" required />
                            <flux:error name="luas_tanah" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Luas Bangunan (m²)') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="luas_bangunan" type="number" step="0.01" min="0" required />
                            <flux:error name="luas_bangunan" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                {{-- HARGA --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Harga') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Harga Jual (Rp)') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <x-money-input wire="harga_jual" required />
                            <flux:description>{{ __('Harga jual ke konsumen (sebelum biaya admin).') }}</flux:description>
                            <flux:error name="harga_jual" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Harga All In (Rp)') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <x-money-input wire="harga_all_in" required />
                            <flux:description>{{ __('Total harga customer = harga jual + biaya admin.') }}</flux:description>
                            <flux:error name="harga_all_in" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Plafon KPR (Rp)') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <x-money-input wire="plafon_kpr" required />
                            <flux:description>{{ __('Plafon yang ditalangi bank. UM otomatis = All In − Plafon KPR.') }}</flux:description>
                            <flux:error name="plafon_kpr" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Biaya Administrasi (Rp)') }}</flux:label>
                            <x-money-input wire="biaya_administrasi" />
                            <flux:description>{{ __('Notaris, BPHTB, splitsing sertifikat.') }}</flux:description>
                            <flux:error name="biaya_administrasi" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('UTJ / Booking Fee (Rp)') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <x-money-input wire="utj" required />
                            <flux:description>{{ __('Uang Tanda Jadi standar.') }}</flux:description>
                            <flux:error name="utj" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                {{-- SUBSIDI --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Subsidi') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('SBUM (Rp)') }}</flux:label>
                        <x-money-input wire="sbum" />
                        <flux:description>{{ __('Subsidi Bantuan UM dari pemerintah. Subsidi: 4jt. Komersial: 0.') }}</flux:description>
                        <flux:error name="sbum" />
                    </flux:field>
                </div>

                <flux:separator />

                {{-- SPESIFIKASI --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Spesifikasi') }}</flux:heading>
                    <flux:field>
                        <flux:label>{{ __('Spesifikasi Marketing') }}</flux:label>
                        <flux:textarea wire:model="spesifikasi" rows="6"
                                       :placeholder="__('Contoh:&#10;2 Kamar Tidur&#10;1 Kamar Mandi&#10;1 Ruang Tamu&#10;Carport&#10;Daya listrik 1.300 VA')" />
                        <flux:description>{{ __('Detail KT, KM, fasilitas. Akan tampil di brosur & SPR.') }}</flux:description>
                        <flux:error name="spesifikasi" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">
                        {{ __('Simpan') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

    </div>
</section>
