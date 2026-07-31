<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Bank;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Bank')] class extends Component {
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nama';
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'per')]
    public string $perPage = '10';

    public ?int $editId = null;

    public string $nama = '';

    public ?string $alamat = null;

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
        $query = Bank::query()
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nama', 'like', $term)
                        ->orWhere('alamat', 'like', $term);
                });
            });

        $this->applySort($query, ['nama', 'created_at']);

        return [
            'banks' => $query->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('bank-form')->show();
    }

    public function edit(int $id): void
    {
        $bank = Bank::findOrFail($id);

        $this->editId = $bank->id;
        $this->nama = $bank->nama;
        $this->alamat = $bank->alamat;
        $this->resetErrorBag();

        Flux::modal('bank-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'nama' => [
                'required', 'string', 'max:255',
                'unique:bank,nama'.($this->editId ? ','.$this->editId : ''),
            ],
            'alamat' => ['nullable', 'string', 'max:500'],
        ]);

        $bank = $this->editId
            ? Bank::findOrFail($this->editId)
            : new Bank;

        $bank->fill([
            'nama' => $validated['nama'],
            'alamat' => $validated['alamat'],
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('bank-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Data bank diperbarui.'
            : 'Bank baru ditambahkan.');
    }

    public function confirmDelete(int $id): void
    {
        $bank = Bank::withCount(['customer', 'sales', 'notaris', 'virtualAccount'])->find($id);

        if (! $bank) {
            return;
        }

        $totalDipakai = $bank->customer_count + $bank->sales_count + $bank->notaris_count + $bank->virtual_account_count;

        if ($totalDipakai > 0) {
            $detail = [];
            if ($bank->customer_count > 0) {
                $detail[] = "{$bank->customer_count} customer";
            }
            if ($bank->sales_count > 0) {
                $detail[] = "{$bank->sales_count} sales";
            }
            if ($bank->notaris_count > 0) {
                $detail[] = "{$bank->notaris_count} notaris";
            }
            if ($bank->virtual_account_count > 0) {
                $detail[] = "{$bank->virtual_account_count} virtual account";
            }

            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Bank '{$bank->nama}' masih dipakai oleh ".implode(', ', $detail).'.',
            );

            return;
        }

        $this->deleteId = $bank->id;
        $this->deleteNama = $bank->nama;

        Flux::modal('bank-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        Bank::findOrFail($this->deleteId)->delete();

        Flux::modal('bank-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Bank dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'nama', 'alamat']);
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Master Bank') }}</flux:heading>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah') }}
            </flux:button>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama bank atau alamat...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <flux:table class="px-4">
            <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                <x-sortable-column field="nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama Bank') }}</x-sortable-column>
                <flux:table.column>{{ __('Alamat') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($banks as $row)
                    <flux:table.row :key="'row-'.$row->id">
                        <flux:table.cell class="text-zinc-500">{{ $loop->index + ($banks->firstItem() ?? 1) }}</flux:table.cell>
                        <flux:table.cell variant="strong">{{ $row->nama }}</flux:table.cell>
                        <flux:table.cell class="max-w-md truncate">{{ $row->alamat ?: '—' }}</flux:table.cell>
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
                        <flux:table.cell colspan="4" class="py-8 text-center text-zinc-500">
                            @if ($search)
                                {{ __('Tidak ada bank yang cocok dengan ":q".', ['q' => $search]) }}
                            @else
                                {{ __('Belum ada data bank. Klik "Tambah" untuk menambahkan.') }}
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $banks])

        <flux:modal name="bank-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Bank?') }}</flux:heading>
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

        <flux:modal name="bank-form" class="md:w-lg" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $editId ? __('Edit Bank') : __('Tambah Bank') }}
                    </flux:heading>
                    <flux:subheading>{{ __('Bank mitra atau rekanan perusahaan.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <flux:field>
                    <flux:label>{{ __('Nama Bank') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="nama" required autofocus placeholder="Bank Central Asia (BCA)" />
                    <flux:error name="nama" />
                </flux:field>

                <flux:textarea wire:model="alamat" :label="__('Alamat Bank')" rows="2"
                               :description="__('Opsional. Alamat kantor bank.')" />

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
