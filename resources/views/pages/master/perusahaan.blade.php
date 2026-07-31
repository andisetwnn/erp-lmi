<?php

use App\Models\Master\Perusahaan;
use App\Support\FileOptimizer;
use App\Support\PhoneNumber;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Master Perusahaan')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'per')]
    public string $perPage = '10';

    public ?int $editId = null;

    public string $nama = '';

    public string $kode_surat = '';

    public ?string $alamat = null;

    public ?string $no_telepon = null;

    public $logo = null;

    public ?string $logoPath = null;

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

    // Auto-sanitasi No Telepon
    public function updatedNoTelepon($value): void
    {
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== $this->no_telepon) {
            $this->no_telepon = $clean;
        }
    }

    public function with(): array
    {
        return [
            'perusahaan' => Perusahaan::query()
                ->when($this->search, function ($q) {
                    $term = '%'.$this->search.'%';
                    $q->where(function ($qq) use ($term) {
                        $qq->where('nama', 'like', $term)
                            ->orWhere('kode_surat', 'like', $term)
                            ->orWhere('alamat', 'like', $term);
                    });
                })
                ->orderBy('nama')
                ->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('perusahaan-form')->show();
    }

    public function edit(int $id): void
    {
        $p = Perusahaan::findOrFail($id);

        $this->editId = $p->id;
        $this->nama = $p->nama;
        $this->kode_surat = $p->kode_surat;
        $this->alamat = $p->alamat;
        $this->no_telepon = $p->no_telepon;
        $this->logoPath = $p->logo;
        $this->logo = null;
        $this->resetErrorBag();

        Flux::modal('perusahaan-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'nama' => ['required', 'string', 'max:255'],
            'kode_surat' => [
                'required', 'string', 'max:20',
                'unique:perusahaan,kode_surat'.($this->editId ? ','.$this->editId : ''),
            ],
            'alamat' => ['required', 'string', 'max:500'],
            'no_telepon' => ['required', 'string', 'max:30'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $perusahaan = $this->editId
            ? Perusahaan::findOrFail($this->editId)
            : new Perusahaan;

        if ($this->logo) {
            if ($perusahaan->logo && Storage::disk('public')->exists($perusahaan->logo)) {
                Storage::disk('public')->delete($perusahaan->logo);
            }
            $perusahaan->logo = FileOptimizer::storeOptimized($this->logo, 'perusahaan', maxWidth: 800);
        }

        $perusahaan->fill([
            'nama' => $validated['nama'],
            'kode_surat' => $validated['kode_surat'],
            'alamat' => $validated['alamat'],
            'no_telepon' => $validated['no_telepon'],
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('perusahaan-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Data perusahaan diperbarui.'
            : 'Perusahaan baru ditambahkan.');
    }

    public function removeLogo(): void
    {
        if (! $this->editId) {
            $this->logo = null;
            $this->logoPath = null;

            return;
        }

        $perusahaan = Perusahaan::findOrFail($this->editId);

        if ($perusahaan->logo && Storage::disk('public')->exists($perusahaan->logo)) {
            Storage::disk('public')->delete($perusahaan->logo);
        }

        $perusahaan->logo = null;
        $perusahaan->updated_by_user_id = Auth::id();
        $perusahaan->save();

        $this->logoPath = null;
        $this->logo = null;

        Flux::toast(variant: 'success', text: 'Logo dihapus.');
    }

    public function confirmDelete(int $id): void
    {
        $perusahaan = Perusahaan::find($id);

        if (! $perusahaan) {
            return;
        }

        $this->deleteId = $perusahaan->id;
        $this->deleteNama = $perusahaan->nama;

        Flux::modal('perusahaan-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $perusahaan = Perusahaan::findOrFail($this->deleteId);

        if ($perusahaan->logo && Storage::disk('public')->exists($perusahaan->logo)) {
            Storage::disk('public')->delete($perusahaan->logo);
        }

        $perusahaan->delete();

        Flux::modal('perusahaan-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Perusahaan dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editId',
            'nama',
            'kode_surat',
            'alamat',
            'no_telepon',
            'logo',
            'logoPath',
        ]);
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Master Perusahaan') }}</flux:heading>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah') }}
            </flux:button>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama, kode surat, atau alamat...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <flux:table class="px-4">
            <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                <flux:table.column class="w-16">{{ __('Logo') }}</flux:table.column>
                <flux:table.column>{{ __('Nama') }}</flux:table.column>
                <flux:table.column>{{ __('Kode Surat') }}</flux:table.column>
                <flux:table.column>{{ __('Alamat') }}</flux:table.column>
                <flux:table.column>{{ __('Telepon') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($perusahaan as $row)
                    <flux:table.row :key="'row-'.$row->id">
                        <flux:table.cell class="text-zinc-500">{{ $loop->index + ($perusahaan->firstItem() ?? 1) }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($row->logo)
                                <img src="{{ asset('storage/'.$row->logo) }}" alt="Logo"
                                     class="h-10 w-10 rounded border border-zinc-200 bg-white object-contain dark:border-zinc-700" />
                            @else
                                <div class="flex h-10 w-10 items-center justify-center rounded border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <flux:icon.building-office-2 class="size-5 text-zinc-400" />
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell variant="strong">{{ $row->nama }}</flux:table.cell>
                        <flux:table.cell>{{ $row->kode_surat }}</flux:table.cell>
                        <flux:table.cell class="max-w-xs truncate">{{ $row->alamat ?: '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $row->no_telepon ?: '—' }}</flux:table.cell>
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
                        <flux:table.cell colspan="7" class="py-8 text-center text-zinc-500">
                            @if ($search)
                                {{ __('Tidak ada perusahaan yang cocok dengan ":q".', ['q' => $search]) }}
                            @else
                                {{ __('Belum ada data perusahaan. Klik "Tambah" untuk menambahkan.') }}
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $perusahaan])

        <flux:modal name="perusahaan-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Perusahaan?') }}</flux:heading>
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

        <flux:modal name="perusahaan-form" class="md:w-160" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $editId ? __('Edit Perusahaan') : __('Tambah Perusahaan') }}
                    </flux:heading>
                    <flux:subheading>{{ __('Identitas legal & kontak perusahaan pengembang.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <flux:field>
                    <flux:label>{{ __('Nama Perusahaan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="nama" required autofocus />
                    <flux:error name="nama" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Kode Surat') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="kode_surat" required placeholder="LMI" />
                    <flux:description>{{ __('Kode unik penomoran dokumen resmi (PO, SPK, dll). Disepakati lintas divisi.') }}</flux:description>
                    <flux:error name="kode_surat" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Alamat') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:textarea wire:model="alamat" rows="2" required />
                    <flux:error name="alamat" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('No Telepon') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model.blur="no_telepon" required />
                    <flux:error name="no_telepon" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Logo Perusahaan') }}</flux:label>

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

                    <flux:description>{{ __('PNG/JPG, maksimal 2MB. Dipakai pada dokumen & laporan cetak.') }}</flux:description>
                    <flux:error name="logo" />

                    <div wire:loading wire:target="logo" class="mt-1 text-sm text-zinc-500">
                        {{ __('Mengunggah...') }}
                    </div>
                </flux:field>

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
