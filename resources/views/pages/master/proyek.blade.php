<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Proyek;
use App\Support\FileOptimizer;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Master Proyek')] class extends Component {
    use Sortable;
    use WithFileUploads;
    use WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nama_proyek';
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'per')]
    public string $perPage = '10';

    public ?int $editId = null;

    public string $nama_proyek = '';

    public string $nama_perumahan = '';

    public string $desa = '';

    public string $kelurahan = '';

    public string $kecamatan = '';

    public string $kota_kabupaten = '';

    public string $kode_surat = '';

    public string $kode_akuntansi = '';

    public string $kode_virtual_account = '';

    public ?string $keterangan = null;

    public $logo = null;

    public ?string $logoPath = null;

    public $siteplan = null;

    public ?string $siteplanPath = null;

    public ?int $deleteId = null;

    public ?string $deleteNama = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Proyek::query()
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nama_proyek', 'like', $term)
                        ->orWhere('nama_perumahan', 'like', $term)
                        ->orWhere('kode_surat', 'like', $term)
                        ->orWhere('kota_kabupaten', 'like', $term);
                });
            });

        $this->applySort($query, ['nama_proyek', 'nama_perumahan', 'kode_surat', 'kota_kabupaten', 'created_at']);

        return [
            'proyeks' => $query->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('proyek-form')->show();
    }

    public function edit(int $id): void
    {
        $p = Proyek::findOrFail($id);

        $this->editId = $p->id;
        $this->nama_proyek = $p->nama_proyek;
        $this->nama_perumahan = $p->nama_perumahan;
        $this->desa = $p->desa;
        $this->kelurahan = $p->kelurahan;
        $this->kecamatan = $p->kecamatan;
        $this->kota_kabupaten = $p->kota_kabupaten;
        $this->kode_surat = $p->kode_surat;
        $this->kode_akuntansi = $p->kode_akuntansi;
        $this->kode_virtual_account = $p->kode_virtual_account;
        $this->keterangan = $p->keterangan;
        $this->logoPath = $p->logo;
        $this->logo = null;
        $this->siteplanPath = $p->siteplan;
        $this->siteplan = null;
        $this->resetErrorBag();

        Flux::modal('proyek-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'nama_proyek' => ['required', 'string', 'max:255'],
            'nama_perumahan' => ['required', 'string', 'max:255'],
            'desa' => ['required', 'string', 'max:100'],
            'kelurahan' => ['required', 'string', 'max:100'],
            'kecamatan' => ['required', 'string', 'max:100'],
            'kota_kabupaten' => ['required', 'string', 'max:100'],
            'kode_surat' => [
                'required', 'string', 'max:20',
                'unique:proyek,kode_surat'.($this->editId ? ','.$this->editId : ''),
            ],
            'kode_akuntansi' => ['required', 'string', 'max:30'],
            'kode_virtual_account' => ['required', 'string', 'max:30'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'siteplan' => ['nullable', 'image', 'max:8192'], // 8MB siteplan biasanya lebih besar
        ]);

        $proyek = $this->editId
            ? Proyek::findOrFail($this->editId)
            : new Proyek;

        if ($this->logo) {
            if ($proyek->logo && Storage::disk('public')->exists($proyek->logo)) {
                Storage::disk('public')->delete($proyek->logo);
            }
            $proyek->logo = FileOptimizer::storeOptimized($this->logo, 'proyek', maxWidth: 800);
        }

        if ($this->siteplan) {
            if ($proyek->siteplan && Storage::disk('public')->exists($proyek->siteplan)) {
                Storage::disk('public')->delete($proyek->siteplan);
            }
            // Siteplan JANGAN di-compress — resolusi harus tetap tinggi supaya
            // fitur mapping polygon (flood-fill auto-detect) bisa deteksi tepi unit dengan akurat.
            $proyek->siteplan = $this->siteplan->store('proyek-siteplan', 'public');
        }

        $proyek->fill([
            'nama_proyek' => $validated['nama_proyek'],
            'nama_perumahan' => $validated['nama_perumahan'],
            'desa' => $validated['desa'],
            'kelurahan' => $validated['kelurahan'],
            'kecamatan' => $validated['kecamatan'],
            'kota_kabupaten' => $validated['kota_kabupaten'],
            'kode_surat' => $validated['kode_surat'],
            'kode_akuntansi' => $validated['kode_akuntansi'],
            'kode_virtual_account' => $validated['kode_virtual_account'],
            'keterangan' => $validated['keterangan'],
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('proyek-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Data proyek diperbarui.'
            : 'Proyek baru ditambahkan.');
    }

    public function removeLogo(): void
    {
        if (! $this->editId) {
            $this->logo = null;
            $this->logoPath = null;

            return;
        }

        $proyek = Proyek::findOrFail($this->editId);

        if ($proyek->logo && Storage::disk('public')->exists($proyek->logo)) {
            Storage::disk('public')->delete($proyek->logo);
        }

        $proyek->logo = null;
        $proyek->updated_by_user_id = Auth::id();
        $proyek->save();

        $this->logoPath = null;
        $this->logo = null;

        Flux::toast(variant: 'success', text: 'Logo dihapus.');
    }

    public function removeSiteplan(): void
    {
        if (! $this->editId) {
            $this->siteplan = null;
            $this->siteplanPath = null;

            return;
        }

        $proyek = Proyek::findOrFail($this->editId);

        if ($proyek->siteplan && Storage::disk('public')->exists($proyek->siteplan)) {
            Storage::disk('public')->delete($proyek->siteplan);
        }

        $proyek->siteplan = null;
        $proyek->updated_by_user_id = Auth::id();
        $proyek->save();

        $this->siteplanPath = null;
        $this->siteplan = null;

        Flux::toast(variant: 'success', text: 'Siteplan dihapus.');
    }

    public function confirmDelete(int $id): void
    {
        $proyek = Proyek::withCount(['tipeRumah', 'rumah'])->find($id);

        if (! $proyek) {
            return;
        }

        $totalDipakai = $proyek->tipe_rumah_count + $proyek->rumah_count;

        if ($totalDipakai > 0) {
            $detail = [];
            if ($proyek->tipe_rumah_count > 0) {
                $detail[] = "{$proyek->tipe_rumah_count} tipe rumah";
            }
            if ($proyek->rumah_count > 0) {
                $detail[] = "{$proyek->rumah_count} unit rumah";
            }

            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Proyek '{$proyek->nama_proyek}' masih punya ".implode(', ', $detail).'. Hapus data tersebut dulu.',
            );

            return;
        }

        $this->deleteId = $proyek->id;
        $this->deleteNama = $proyek->nama_proyek;

        Flux::modal('proyek-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $proyek = Proyek::findOrFail($this->deleteId);

        if ($proyek->logo && Storage::disk('public')->exists($proyek->logo)) {
            Storage::disk('public')->delete($proyek->logo);
        }
        if ($proyek->siteplan && Storage::disk('public')->exists($proyek->siteplan)) {
            Storage::disk('public')->delete($proyek->siteplan);
        }

        $proyek->delete();

        Flux::modal('proyek-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Proyek dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editId',
            'nama_proyek',
            'nama_perumahan',
            'desa',
            'kelurahan',
            'kecamatan',
            'kota_kabupaten',
            'kode_surat',
            'kode_akuntansi',
            'kode_virtual_account',
            'keterangan',
            'logo',
            'logoPath',
            'siteplan',
            'siteplanPath',
        ]);
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Master Proyek') }}</flux:heading>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah') }}
            </flux:button>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama proyek, perumahan, kode surat, atau kota...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <flux:table class="px-4">
                <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                    <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                    <flux:table.column class="w-16">{{ __('Logo') }}</flux:table.column>
                    <x-sortable-column field="nama_proyek" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama Proyek') }}</x-sortable-column>
                    <x-sortable-column field="nama_perumahan" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama Perumahan') }}</x-sortable-column>
                    <flux:table.column>{{ __('Desa') }}</flux:table.column>
                    <flux:table.column>{{ __('Kelurahan') }}</flux:table.column>
                    <flux:table.column>{{ __('Kecamatan') }}</flux:table.column>
                    <x-sortable-column field="kota_kabupaten" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kota / Kab') }}</x-sortable-column>
                    <x-sortable-column field="kode_surat" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kode Surat') }}</x-sortable-column>
                    <flux:table.column>{{ __('Kode Akuntansi') }}</flux:table.column>
                    <flux:table.column>{{ __('Kode VA') }}</flux:table.column>
                    <flux:table.column>{{ __('Keterangan') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($proyeks as $row)
                        <flux:table.row :key="'row-'.$row->id">
                            <flux:table.cell class="text-zinc-500">{{ $loop->index + ($proyeks->firstItem() ?? 1) }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row->logo)
                                    <img src="{{ asset('storage/'.$row->logo) }}" alt="Logo"
                                         class="h-10 w-10 rounded border border-zinc-200 bg-white object-contain dark:border-zinc-700" />
                                @else
                                    <div class="flex h-10 w-10 items-center justify-center rounded border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                        <flux:icon.home-modern class="size-5 text-zinc-400" />
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $row->nama_proyek }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $row->nama_perumahan }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $row->desa }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $row->kelurahan }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $row->kecamatan }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $row->kota_kabupaten }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap font-mono text-xs">{{ $row->kode_surat }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap font-mono text-xs">{{ $row->kode_akuntansi }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap font-mono text-xs">{{ $row->kode_virtual_account }}</flux:table.cell>
                            <flux:table.cell class="max-w-xs truncate" title="{{ $row->keterangan }}">
                                {{ $row->keterangan ?: '—' }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $row->id }})">
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        @if ($row->siteplan)
                                            <flux:menu.item icon="map" :href="route('master.proyek.siteplan-mapping', $row->id)" wire:navigate>
                                                {{ __('Mapping Siteplan') }}
                                            </flux:menu.item>
                                        @endif
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
                                    {{ __('Tidak ada proyek yang cocok dengan ":q".', ['q' => $search]) }}
                                @else
                                    {{ __('Belum ada data proyek. Klik "Tambah" untuk menambahkan.') }}
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $proyeks])

        <flux:modal name="proyek-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Proyek?') }}</flux:heading>
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

        <flux:modal name="proyek-form" class="md:w-2xl" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $editId ? __('Edit Proyek') : __('Tambah Proyek') }}
                    </flux:heading>
                    <flux:subheading>{{ __('Identitas, lokasi, dan kode unik proyek perumahan.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                {{-- IDENTITAS --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Identitas') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Logo Proyek') }}</flux:label>

                        @if ($logoPath)
                            <div class="mb-2 flex items-center gap-3">
                                <img src="{{ asset('storage/'.$logoPath) }}" alt="Logo"
                                     class="h-14 w-14 rounded border border-zinc-200 bg-white object-contain dark:border-zinc-700" />
                                <flux:button size="sm" variant="ghost" type="button" wire:click="removeLogo">
                                    {{ __('Hapus Logo') }}
                                </flux:button>
                            </div>
                        @endif

                        <input type="file" wire:model="logo" accept="image/*"
                               class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />

                        <flux:description>{{ __('Opsional. PNG/JPG, maks 2MB.') }}</flux:description>
                        <flux:error name="logo" />

                        <div wire:loading wire:target="logo" class="mt-1 text-sm text-zinc-500">
                            {{ __('Mengunggah...') }}
                        </div>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Siteplan Proyek') }}</flux:label>

                        @if ($siteplanPath)
                            <div class="mb-2 space-y-2">
                                <img src="{{ asset('storage/'.$siteplanPath) }}" alt="Siteplan"
                                     class="max-h-64 w-full rounded border border-zinc-200 bg-white object-contain dark:border-zinc-700" />
                                <flux:button size="sm" variant="ghost" type="button" wire:click="removeSiteplan">
                                    {{ __('Hapus Siteplan') }}
                                </flux:button>
                            </div>
                        @endif

                        <input type="file" wire:model="siteplan" accept="image/png,image/jpeg,image/webp"
                               class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />

                        <flux:description>{{ __('Opsional. PNG/JPG, maks 8MB. Dipakai untuk fitur interaktif booking — sales bisa klik unit langsung dari siteplan.') }}</flux:description>
                        <flux:error name="siteplan" />

                        <div wire:loading wire:target="siteplan" class="mt-1 text-sm text-zinc-500">
                            {{ __('Mengunggah...') }}
                        </div>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Nama Proyek') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input wire:model="nama_proyek" required />
                        <flux:description>{{ __('Nama resmi proyek di dalam sistem.') }}</flux:description>
                        <flux:error name="nama_proyek" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Nama Perumahan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input wire:model="nama_perumahan" required />
                        <flux:description>{{ __('Nama yang dipasarkan ke konsumen.') }}</flux:description>
                        <flux:error name="nama_perumahan" />
                    </flux:field>
                </div>

                <flux:separator />

                {{-- LOKASI --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Lokasi') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Desa') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="desa" required />
                            <flux:error name="desa" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Kelurahan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="kelurahan" required />
                            <flux:error name="kelurahan" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Kecamatan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="kecamatan" required />
                            <flux:error name="kecamatan" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Kota / Kabupaten') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="kota_kabupaten" required />
                            <flux:error name="kota_kabupaten" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                {{-- KODE --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Kode Unik') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Kode Surat') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input wire:model="kode_surat" required />
                        <flux:description>{{ __('Kode penomoran PO, SPK, dll. Disepakati lintas divisi.') }}</flux:description>
                        <flux:error name="kode_surat" />
                    </flux:field>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Kode Akuntansi') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="kode_akuntansi" required placeholder="100.110.01" />
                            <flux:description>{{ __('Format: 100.110.[kode]. Ditentukan bagian keuangan.') }}</flux:description>
                            <flux:error name="kode_akuntansi" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Kode Virtual Account') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="kode_virtual_account" required placeholder="08124001100380" />
                            <flux:description>{{ __('Format: 08124[kode]00380. Disepakati IT, keuangan, marketing & bank.') }}</flux:description>
                            <flux:error name="kode_virtual_account" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                {{-- LAINNYA --}}
                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Lainnya') }}</flux:heading>

                    <flux:textarea wire:model="keterangan" :label="__('Keterangan')" rows="2"
                                   :description="__('Opsional. Informasi tambahan proyek.')" />
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
