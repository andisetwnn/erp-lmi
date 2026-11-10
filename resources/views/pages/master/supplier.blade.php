<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Bank;
use App\Models\Master\Supplier;
use App\Support\BusinessActivityLogger;
use App\Support\PhoneNumber;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Supplier')] class extends Component
{
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nama';
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'per')]
    public string $perPage = '10';

    // ===== FORM =====
    public ?int $editId = null;

    public string $nama = '';

    public ?string $kontakNama = null;

    public ?string $hp = null;

    public ?string $email = null;

    public ?string $alamat = null;

    public ?string $npwp = null;

    public bool $isPkp = false;

    public ?string $bankId = null;

    public ?string $nomorRekening = null;

    public ?string $rekeningAtasNama = null;

    public bool $isAktif = true;

    public ?string $keterangan = null;

    // ===== HAPUS =====
    public ?int $deleteId = null;

    public ?string $deleteNama = null;

    public function updatedHp($value): void
    {
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== $this->hp) {
            $this->hp = $clean;
        }
    }

    public function with(): array
    {
        $query = Supplier::query()
            ->with(['bank:id,nama'])
            ->withCount('pesanan')
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq->where('nama', 'like', $term)
                    ->orWhere('kontak_nama', 'like', $term)
                    ->orWhere('hp', 'like', $term)
                    ->orWhere('npwp', 'like', $term));
            });

        $this->applySort($query, ['nama', 'kontak_nama', 'created_at']);

        return [
            'bankOptions' => Bank::orderBy('nama')->get(['id', 'nama']),
            'supplierList' => $query->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('supplier-form')->show();
    }

    public function edit(int $id): void
    {
        $s = Supplier::findOrFail($id);

        $this->editId = $s->id;
        $this->nama = $s->nama;
        $this->kontakNama = $s->kontak_nama;
        $this->hp = $s->hp;
        $this->email = $s->email;
        $this->alamat = $s->alamat;
        $this->npwp = $s->npwp;
        $this->isPkp = $s->is_pkp;
        $this->bankId = $s->bank_id ? (string) $s->bank_id : null;
        $this->nomorRekening = $s->nomor_rekening;
        $this->rekeningAtasNama = $s->rekening_atas_nama;
        $this->isAktif = $s->is_aktif;
        $this->keterangan = $s->keterangan;
        $this->resetErrorBag();

        Flux::modal('supplier-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'nama' => ['required', 'string', 'max:255'],
            'kontakNama' => ['nullable', 'string', 'max:255'],
            'hp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'isPkp' => ['boolean'],
            'bankId' => ['nullable', 'exists:bank,id'],
            'nomorRekening' => ['nullable', 'string', 'max:50'],
            'rekeningAtasNama' => ['nullable', 'string', 'max:255'],
            'isAktif' => ['boolean'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'kontakNama' => 'nama kontak',
            'npwp' => 'NPWP',
            'bankId' => 'bank',
            'nomorRekening' => 'nomor rekening',
            'rekeningAtasNama' => 'atas nama rekening',
        ]);

        $s = $this->editId ? Supplier::findOrFail($this->editId) : new Supplier;

        $s->fill([
            'nama' => $validated['nama'],
            'kontak_nama' => $validated['kontakNama'] ?: null,
            'hp' => $validated['hp'] ?: null,
            'email' => $validated['email'] ?: null,
            'alamat' => $validated['alamat'] ?: null,
            'npwp' => $validated['npwp'] ?: null,
            'is_pkp' => $validated['isPkp'],
            'bank_id' => $validated['bankId'] ? (int) $validated['bankId'] : null,
            'nomor_rekening' => $validated['nomorRekening'] ?: null,
            'rekening_atas_nama' => $validated['rekeningAtasNama'] ?: null,
            'is_aktif' => $validated['isAktif'],
            'keterangan' => $validated['keterangan'] ?: null,
            'updated_by_user_id' => Auth::id(),
        ]);
        $baru = ! $s->exists;
        $berubah = $baru ? [] : $this->labelPerubahan(array_keys($s->getDirty()));
        $s->save();

        if ($baru) {
            BusinessActivityLogger::pembelian('supplier.dibuat', "Supplier {$s->nama} ditambahkan", ['supplier' => $s->nama, 'pkp' => $s->is_pkp], $s);
        } elseif ($berubah !== []) {
            BusinessActivityLogger::pembelian('supplier.diubah', "Data supplier {$s->nama} diubah: ".implode(', ', $berubah), ['supplier' => $s->nama, 'kolom' => $berubah], $s);
        }

        Flux::modal('supplier-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit ? 'Data supplier diperbarui.' : 'Supplier baru ditambahkan.');
    }

    /**
     * Nama bagian yang berubah, sebagaimana dikenal pengguna.
     *
     * @param  list<string>  $kolom
     * @return list<string>
     */
    private function labelPerubahan(array $kolom): array
    {
        $label = [
            'nama' => 'nama', 'kontak_nama' => 'kontak', 'hp' => 'HP', 'email' => 'email', 'alamat' => 'alamat',
            'npwp' => 'NPWP', 'is_pkp' => 'PKP', 'bank_id' => 'rekening', 'nomor_rekening' => 'rekening',
            'rekening_atas_nama' => 'rekening', 'is_aktif' => 'status aktif', 'keterangan' => 'keterangan',
        ];

        return array_values(array_unique(array_filter(array_map(fn ($k) => $label[$k] ?? null, $kolom))));
    }

    public function confirmDelete(int $id): void
    {
        $s = Supplier::withCount('pesanan')->findOrFail($id);

        if ($s->pesanan_count > 0) {
            Flux::toast(variant: 'warning', text: "{$s->nama} sudah punya PO, jadi tidak bisa dihapus. Nonaktifkan saja lewat Edit.");

            return;
        }

        $this->deleteId = $s->id;
        $this->deleteNama = $s->nama;
        Flux::modal('supplier-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $s = Supplier::withCount('pesanan')->findOrFail($this->deleteId);
        if ($s->pesanan_count === 0) {
            BusinessActivityLogger::pembelian('supplier.dihapus', "Supplier {$s->nama} dihapus", ['supplier' => $s->nama]);
            $s->delete();
            Flux::toast(variant: 'success', text: 'Supplier dihapus.');
        }

        Flux::modal('supplier-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;
    }

    private function resetForm(): void
    {
        $this->reset(['editId', 'nama', 'kontakNama', 'hp', 'email', 'alamat', 'npwp', 'isPkp', 'bankId', 'nomorRekening', 'rekeningAtasNama', 'isAktif', 'keterangan']);
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Master Supplier') }}</flux:heading>
                <flux:subheading>{{ __('Supplier barang untuk Purchase Order.') }}</flux:subheading>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah Supplier') }}
            </flux:button>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama, kontak, HP, atau NPWP...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <x-sortable-column field="nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama') }}</x-sortable-column>
                        <x-sortable-column field="kontak_nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kontak') }}</x-sortable-column>
                        <flux:table.column>{{ __('Pajak') }}</flux:table.column>
                        <flux:table.column>{{ __('Rekening') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('PO') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($supplierList as $row)
                            <flux:table.row :key="'supplier-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($supplierList->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $row->nama }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    <div>{{ $row->kontak_nama ?: '—' }}</div>
                                    @if ($row->hp)
                                        <div class="font-mono text-zinc-500">{{ $row->hp }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->is_pkp)
                                        <flux:badge color="blue" size="sm">PKP</flux:badge>
                                    @endif
                                    <div class="mt-0.5 font-mono text-zinc-500">{{ $row->npwp ?: '—' }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->nomor_rekening)
                                        <div>{{ $row->bank?->nama }}</div>
                                        <div class="font-mono text-zinc-500">{{ $row->nomor_rekening }}</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="center" class="tabular-nums">{{ $row->pesanan_count }}</flux:table.cell>
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
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $row->id }})">{{ __('Edit') }}</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $row->id }})">{{ __('Hapus') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="8" class="py-8 text-center text-zinc-500">
                                    @if ($search)
                                        {{ __('Tidak ada supplier yang cocok dengan ":q".', ['q' => $search]) }}
                                    @else
                                        {{ __('Belum ada supplier. Klik "Tambah Supplier" untuk menambahkan.') }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $supplierList])

        {{-- FORM SUPPLIER --}}
        <flux:modal name="supplier-form" class="md:w-2xl" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Edit Supplier') : __('Tambah Supplier') }}</flux:heading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Identitas') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Nama Supplier') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input wire:model="nama" required placeholder="TB Sumber Bangunan" />
                        <flux:error name="nama" />
                    </flux:field>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Nama Kontak') }}</flux:label>
                            <flux:input wire:model="kontakNama" />
                            <flux:error name="kontakNama" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('HP') }}</flux:label>
                            <flux:input wire:model.blur="hp" placeholder="08xxxxxxxxxx" />
                            <flux:error name="hp" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Email') }}</flux:label>
                        <flux:input type="email" wire:model="email" />
                        <flux:error name="email" />
                    </flux:field>

                    <flux:textarea wire:model="alamat" :label="__('Alamat')" rows="2" />
                    <flux:error name="alamat" />
                </div>

                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Pajak') }}</flux:heading>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('NPWP') }}</flux:label>
                            <flux:input wire:model="npwp" />
                            <flux:error name="npwp" />
                        </flux:field>
                        <div class="flex items-end pb-2">
                            <flux:checkbox wire:model="isPkp" :label="__('Pengusaha Kena Pajak (PKP)')" />
                        </div>
                    </div>
                </div>

                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Rekening') }}</flux:heading>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Bank') }}</flux:label>
                            <flux:select wire:model="bankId">
                                <flux:select.option value="">{{ __('-- Pilih bank --') }}</flux:select.option>
                                @foreach ($bankOptions as $b)
                                    <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="bankId" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Nomor Rekening') }}</flux:label>
                            <flux:input wire:model="nomorRekening" />
                            <flux:error name="nomorRekening" />
                        </flux:field>
                    </div>
                    <flux:field>
                        <flux:label>{{ __('Atas Nama') }}</flux:label>
                        <flux:input wire:model="rekeningAtasNama" />
                        <flux:error name="rekeningAtasNama" />
                    </flux:field>
                </div>

                <flux:separator />

                <flux:textarea wire:model="keterangan" :label="__('Keterangan')" rows="2" />
                <flux:checkbox wire:model="isAktif" :label="__('Aktif (bisa dipilih saat menerbitkan PO)')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- HAPUS --}}
        <flux:modal name="supplier-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Supplier?') }}</flux:heading>
                        <flux:subheading>{{ __(':nama akan dihapus. Tindakan ini tidak dapat dibatalkan.', ['nama' => $deleteNama]) }}</flux:subheading>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="button" icon="trash" wire:click="delete">{{ __('Hapus') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </div>
</section>
