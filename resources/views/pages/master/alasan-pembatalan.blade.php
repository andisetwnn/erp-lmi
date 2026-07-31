<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\AlasanPembatalan;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Alasan Pembatalan')] class extends Component
{
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'created_at';
    }

    protected function defaultSortDir(): string
    {
        return 'asc';
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'per')]
    public string $perPage = '15';

    public ?int $editId = null;

    public string $nama = '';

    public bool $dapatMeneruskanAngsuran = false;

    public bool $isAktif = true;

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
        $query = AlasanPembatalan::query()
            ->when($this->search, fn ($q) => $q->where('nama', 'like', '%'.$this->search.'%'));

        $this->applySort($query, ['nama', 'dapat_meneruskan_angsuran', 'is_aktif', 'created_at']);

        return [
            'items' => $query->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('alasan-form')->show();
    }

    public function edit(int $id): void
    {
        $item = AlasanPembatalan::findOrFail($id);
        $this->editId = $item->id;
        $this->nama = $item->nama;
        $this->dapatMeneruskanAngsuran = (bool) $item->dapat_meneruskan_angsuran;
        $this->isAktif = (bool) $item->is_aktif;
        $this->resetErrorBag();
        Flux::modal('alasan-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'nama' => [
                'required', 'string', 'max:255',
                'unique:alasan_pembatalan,nama'.($this->editId ? ','.$this->editId : ''),
            ],
            'dapatMeneruskanAngsuran' => ['boolean'],
            'isAktif' => ['boolean'],
        ], [], [
            'nama' => 'alasan pembatalan',
            'dapatMeneruskanAngsuran' => 'dapat meneruskan angsuran',
        ]);

        $item = $this->editId
            ? AlasanPembatalan::findOrFail($this->editId)
            : new AlasanPembatalan;

        $item->fill([
            'nama' => $validated['nama'],
            'dapat_meneruskan_angsuran' => $validated['dapatMeneruskanAngsuran'],
            'is_aktif' => $validated['isAktif'],
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('alasan-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit ? 'Alasan diperbarui.' : 'Alasan ditambahkan.');
    }

    public function toggleAktif(int $id): void
    {
        $item = AlasanPembatalan::findOrFail($id);
        $item->update([
            'is_aktif' => ! $item->is_aktif,
            'updated_by_user_id' => Auth::id(),
        ]);
        Flux::toast(variant: 'success', text: 'Status: '.($item->is_aktif ? 'aktif' : 'nonaktif'));
    }

    public function confirmDelete(int $id): void
    {
        $item = AlasanPembatalan::findOrFail($id);
        $this->deleteId = $item->id;
        $this->deleteNama = $item->nama;
        Flux::modal('alasan-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        AlasanPembatalan::findOrFail($this->deleteId)->delete();

        Flux::modal('alasan-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Alasan dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'nama']);
        $this->dapatMeneruskanAngsuran = false;
        $this->isAktif = true;
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <a href="{{ route('marketing.spr-batal.list') }}" wire:navigate
                   class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                   title="{{ __('Kembali ke Pembatalan SPR') }}">
                    <flux:icon.arrow-left class="size-4" />
                </a>
                <div>
                    <flux:heading size="xl">{{ __('Master Alasan Pembatalan') }}</flux:heading>
                    <flux:subheading>{{ __('Daftar alasan yang dapat dipilih saat membatalkan SPR.') }}</flux:subheading>
                </div>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah') }}
            </flux:button>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari alasan...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:table class="px-4">
                <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                    <flux:table.column class="w-12">{{ __('#') }}</flux:table.column>
                    <x-sortable-column field="nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Alasan Pembatalan') }}</x-sortable-column>
                    <x-sortable-column field="dapat_meneruskan_angsuran" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Dapat Meneruskan Angsuran') }}</x-sortable-column>
                    <x-sortable-column field="is_aktif" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Status') }}</x-sortable-column>
                    <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($items as $row)
                        <flux:table.row :key="'row-'.$row->id">
                            <flux:table.cell class="text-zinc-500">{{ $loop->index + ($items->firstItem() ?? 1) }}</flux:table.cell>
                            <flux:table.cell variant="strong">{{ $row->nama }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row->dapat_meneruskan_angsuran)
                                    <flux:badge color="green" size="sm">{{ __('Ya') }}</flux:badge>
                                @else
                                    <flux:badge color="rose" size="sm">{{ __('Tidak') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($row->is_aktif)
                                    <flux:badge color="emerald" size="sm">{{ __('Aktif') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Nonaktif') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $row->id }})">
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="{{ $row->is_aktif ? 'pause-circle' : 'play-circle' }}"
                                                        wire:click="toggleAktif({{ $row->id }})">
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
                            <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">
                                {{ __('Belum ada data. Klik "Tambah" untuk menambahkan.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $items])

        {{-- DELETE CONFIRM --}}
        <flux:modal name="alasan-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Alasan?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Anda akan menghapus ":nama". Tindakan ini tidak dapat dibatalkan.', ['nama' => $deleteNama]) }}
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
        <flux:modal name="alasan-form" class="md:w-lg" focusable>
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Edit Alasan') : __('Tambah Alasan') }}</flux:heading>
                    <flux:subheading>{{ __('Alasan ini akan tampil di dropdown saat pembatalan SPR.') }}</flux:subheading>
                </div>

                <flux:field>
                    <flux:label>{{ __('Alasan Pembatalan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="nama" required autofocus placeholder="contoh: Mengundurkan diri" />
                    <flux:error name="nama" />
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="dapatMeneruskanAngsuran"
                                   :label="__('Dapat meneruskan angsuran pada SPR baru')" />
                    <flux:description>{{ __('Jika dicentang, UM yang sudah dibayar customer bisa di-carry-over ke SPR baru (mis. ganti nama).') }}</flux:description>
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="isAktif" :label="__('Aktif')" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

    </div>
</section>
