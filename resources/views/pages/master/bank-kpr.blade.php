<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\BankKpr;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Bank KPR')] class extends Component
{
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nama';
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

    public string $kode = '';

    public string $nama = '';

    /** Dibiarkan string supaya kosong tetap terbaca kosong, bukan berubah jadi nol. */
    public string $biayaProsesAkad = '';

    public string $keterangan = '';

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

    /** Pemisah ribuan otomatis saat mengetik, supaya nominal panjang tetap terbaca. */
    public function updatedBiayaProsesAkad(): void
    {
        $angka = preg_replace('/\D/', '', $this->biayaProsesAkad);

        $this->biayaProsesAkad = $angka !== '' ? number_format((int) $angka, 0, ',', '.') : '';
    }

    public function with(): array
    {
        $query = BankKpr::query()
            ->when($this->search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('nama', 'like', '%'.$this->search.'%')
                ->orWhere('kode', 'like', '%'.$this->search.'%')));

        $this->applySort($query, ['kode', 'nama', 'biaya_proses_akad', 'is_aktif']);

        return [
            'items' => $query->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
            'belumBertarif' => BankKpr::where('is_aktif', true)->whereNull('biaya_proses_akad')->count(),
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('bank-kpr-form')->show();
    }

    public function edit(int $id): void
    {
        $item = BankKpr::findOrFail($id);

        $this->editId = $item->id;
        $this->kode = $item->kode;
        $this->nama = $item->nama;
        $this->biayaProsesAkad = $item->biaya_proses_akad !== null
            ? number_format((float) $item->biaya_proses_akad, 0, ',', '.')
            : '';
        $this->keterangan = (string) $item->keterangan;
        $this->isAktif = (bool) $item->is_aktif;

        $this->resetErrorBag();
        Flux::modal('bank-kpr-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'kode' => [
                'required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/',
                'unique:bank_kpr,kode'.($this->editId ? ','.$this->editId : ''),
            ],
            'nama' => ['required', 'string', 'max:255'],
            'biayaProsesAkad' => ['nullable', 'string', 'max:20'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'isAktif' => ['boolean'],
        ], [
            'kode.regex' => 'Kode hanya boleh huruf dan angka, tanpa spasi.',
        ], [
            'kode' => 'kode bank',
            'nama' => 'nama bank',
            'biayaProsesAkad' => 'biaya proses akad',
        ]);

        $angka = preg_replace('/\D/', '', $validated['biayaProsesAkad'] ?? '');

        $item = $this->editId ? BankKpr::findOrFail($this->editId) : new BankKpr;

        $item->fill([
            'kode' => strtoupper($validated['kode']),
            'nama' => $validated['nama'],
            // Kosong disimpan null, bukan nol — nol berarti gratis, dan itu lain
            // artinya dengan "tarifnya belum diketahui".
            'biaya_proses_akad' => $angka !== '' ? (float) $angka : null,
            'keterangan' => $validated['keterangan'] ?: null,
            'is_aktif' => $validated['isAktif'],
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('bank-kpr-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit ? 'Bank KPR diperbarui.' : 'Bank KPR ditambahkan.');
    }

    public function toggleAktif(int $id): void
    {
        $item = BankKpr::findOrFail($id);

        $item->update([
            'is_aktif' => ! $item->is_aktif,
            'updated_by_user_id' => Auth::id(),
        ]);

        Flux::toast(variant: 'success', text: 'Status: '.($item->is_aktif ? 'aktif' : 'nonaktif'));
    }

    public function confirmDelete(int $id): void
    {
        $item = BankKpr::findOrFail($id);

        $this->deleteId = $item->id;
        $this->deleteNama = $item->nama;

        Flux::modal('bank-kpr-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $item = BankKpr::withCount('rencanaAkad')->findOrFail($this->deleteId);

        // Menghapus bank yang sudah dipakai akan mengosongkan bank pada rencana
        // yang sudah tersusun — termasuk yang sudah dicetak dan ditandatangani.
        if ($item->rencana_akad_count > 0) {
            Flux::modal('bank-kpr-delete-confirm')->close();
            Flux::toast(
                variant: 'warning',
                text: $item->nama.' dipakai '.$item->rencana_akad_count.' rencana akad. Nonaktifkan saja supaya tidak muncul lagi di pilihan.',
            );

            return;
        }

        $item->delete();

        Flux::modal('bank-kpr-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Bank KPR dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'kode', 'nama', 'biayaProsesAkad', 'keterangan']);
        $this->isAktif = true;
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Master Bank KPR') }}</flux:heading>
                <flux:subheading>
                    {{ __('Bank tempat berkas KPR diurus, beserta biaya proses akad per unitnya.') }}
                </flux:subheading>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah') }}
            </flux:button>
        </div>

        @if ($belumBertarif > 0)
            <div class="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0" />
                <div>
                    <p class="font-semibold">
                        {{ trans_choice('{1}:n bank aktif belum diisi biaya proses akadnya|[2,*]:n bank aktif belum diisi biaya proses akadnya', $belumBertarif, ['n' => $belumBertarif]) }}
                    </p>
                    <p class="mt-0.5 text-amber-700 dark:text-amber-300">
                        {{ __('Unit yang memakai bank itu akan tampil kosong di lembar Aju Dana, bukan nol.') }}
                    </p>
                </div>
            </div>
        @endif

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari kode atau nama bank...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:table class="px-4">
                <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                    <flux:table.column class="w-12">{{ __('#') }}</flux:table.column>
                    <x-sortable-column field="kode" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kode') }}</x-sortable-column>
                    <x-sortable-column field="nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama Bank') }}</x-sortable-column>
                    <x-sortable-column field="biaya_proses_akad" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Biaya Proses Akad / Unit') }}</x-sortable-column>
                    <x-sortable-column field="is_aktif" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Status') }}</x-sortable-column>
                    <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($items as $row)
                        <flux:table.row :key="'row-'.$row->id">
                            <flux:table.cell class="text-zinc-500">{{ $loop->index + ($items->firstItem() ?? 1) }}</flux:table.cell>
                            <flux:table.cell class="font-mono font-semibold">{{ $row->kode }}</flux:table.cell>
                            <flux:table.cell variant="strong">
                                {{ $row->nama }}
                                @if ($row->keterangan)
                                    <div class="text-xs font-normal text-zinc-500">{{ $row->keterangan }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap font-mono tabular-nums">
                                @if ($row->tarifDiketahui())
                                    Rp {{ number_format((float) $row->biaya_proses_akad, 0, ',', '.') }}
                                @else
                                    {{-- Bukan "Rp 0": nol berarti gratis, kosong berarti belum diketahui. --}}
                                    <span class="text-xs font-sans text-amber-600 dark:text-amber-400">{{ __('belum diisi') }}</span>
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
                            <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                {{ __('Belum ada data. Klik "Tambah" untuk menambahkan.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $items])

        {{-- DELETE CONFIRM --}}
        <flux:modal name="bank-kpr-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Bank KPR?') }}</flux:heading>
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
        <flux:modal name="bank-kpr-form" class="md:w-lg" focusable>
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Edit Bank KPR') : __('Tambah Bank KPR') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Isi per cabang, bukan per bank — proses dan biayanya bisa berbeda.') }}
                    </flux:subheading>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('Kode') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input wire:model="kode" required autofocus maxlength="10" placeholder="CBN" />
                        <flux:error name="kode" />
                    </flux:field>

                    <div class="sm:col-span-2">
                        <flux:field>
                            <flux:label>{{ __('Nama Bank') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="nama" required placeholder="BTN KC Cibinong" />
                            <flux:error name="nama" />
                        </flux:field>
                    </div>
                </div>

                <flux:field>
                    <flux:label>{{ __('Biaya Proses Akad per Unit') }}</flux:label>
                    <flux:input wire:model.live.debounce.300ms="biayaProsesAkad" inputmode="numeric" placeholder="1.645.000" />
                    <flux:description>
                        {{ __('Kosongkan kalau tarifnya belum diketahui. Jangan diisi nol — nol berarti gratis.') }}
                    </flux:description>
                    <flux:error name="biayaProsesAkad" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Keterangan') }}</flux:label>
                    <flux:input wire:model="keterangan" placeholder="{{ __('opsional') }}" />
                    <flux:error name="keterangan" />
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="isAktif" :label="__('Aktif')" />
                    <flux:description>{{ __('Yang nonaktif tidak muncul lagi di pilihan, tapi rencana lama tetap utuh.') }}</flux:description>
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
