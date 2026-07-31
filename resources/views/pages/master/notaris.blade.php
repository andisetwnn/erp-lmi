<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Bank;
use App\Models\Master\Notaris;
use App\Models\Master\NotarisBiayaAjbHistory;
use App\Support\PhoneNumber;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Notaris')] class extends Component {
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nama';
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'per')]
    public string $perPage = '10';

    // ===== FORM NOTARIS =====
    public ?int $editId = null;

    public string $nama = '';

    public string $nik = '';

    public ?string $hp = null;

    public ?string $alamat = null;

    public ?string $bankId = null;

    public ?string $nomorRekening = null;

    // ===== DELETE STATE =====
    public ?int $deleteId = null;

    public ?string $deleteNama = null;

    // ===== SETTING AJB STATE =====
    public ?int $ajbNotarisId = null;

    public ?string $ajbNotarisNama = null;

    // Form tambah AJB baru
    public string $ajbNewNominal = '';

    public string $ajbNewPph = '';

    public ?string $ajbNewTglBerlaku = null;

    // Inline edit existing entries — keyed by ajb id
    public array $ajbEdits = [];

    // Auto-sanitasi HP
    public function updatedHp($value): void
    {
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== $this->hp) {
            $this->hp = $clean;
        }
    }

    public function with(): array
    {
        $query = Notaris::query()
            ->with(['bank:id,nama', 'updatedByUser:id,name'])
            ->withCount('biayaAjbHistory')
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nama', 'like', $term)
                        ->orWhere('nik', 'like', $term)
                        ->orWhere('hp', 'like', $term)
                        ->orWhere('nomor_rekening', 'like', $term);
                });
            });

        $this->applySort($query, ['nama', 'nik', 'hp', 'created_at']);

        return [
            'bankOptions' => Bank::orderBy('nama')->get(['id', 'nama']),
            'notarisList' => $query->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
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

    // -------------------------------------------------------------
    // CRUD NOTARIS
    // -------------------------------------------------------------

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('notaris-form')->show();
    }

    public function edit(int $id): void
    {
        $n = Notaris::findOrFail($id);

        $this->editId = $n->id;
        $this->nama = $n->nama;
        $this->nik = $n->nik;
        $this->hp = $n->hp;
        $this->alamat = $n->alamat;
        $this->bankId = $n->bank_id ? (string) $n->bank_id : null;
        $this->nomorRekening = $n->nomor_rekening;
        $this->resetErrorBag();

        Flux::modal('notaris-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'nama' => ['required', 'string', 'max:255'],
            'nik' => [
                'required', 'string', 'max:32',
                'unique:notaris,nik'.($this->editId ? ','.$this->editId : ''),
            ],
            'hp' => ['nullable', 'string', 'max:30'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'bankId' => ['nullable', 'exists:bank,id'],
            'nomorRekening' => ['nullable', 'string', 'max:50'],
        ], [], [
            'nik' => 'NIK',
            'bankId' => 'bank',
            'nomorRekening' => 'nomor rekening',
        ]);

        $n = $this->editId
            ? Notaris::findOrFail($this->editId)
            : new Notaris;

        $n->fill([
            'nama' => $validated['nama'],
            'nik' => $validated['nik'],
            'hp' => $validated['hp'] ?: null,
            'alamat' => $validated['alamat'] ?: null,
            'bank_id' => $validated['bankId'] ? (int) $validated['bankId'] : null,
            'nomor_rekening' => $validated['nomorRekening'] ?: null,
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('notaris-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Data notaris diperbarui.'
            : 'Notaris baru ditambahkan.');
    }

    public function confirmDelete(int $id): void
    {
        $n = Notaris::withCount('biayaAjbHistory')->find($id);
        if (! $n) {
            return;
        }

        $this->deleteId = $n->id;
        $this->deleteNama = $n->nama;

        Flux::modal('notaris-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        Notaris::findOrFail($this->deleteId)->delete();

        Flux::modal('notaris-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::toast(variant: 'success', text: 'Notaris dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'nama', 'nik', 'hp', 'alamat', 'bankId', 'nomorRekening']);
        $this->resetErrorBag();
    }

    // -------------------------------------------------------------
    // SETTING AJB
    // -------------------------------------------------------------

    public function openAjb(int $notarisId): void
    {
        $n = Notaris::findOrFail($notarisId);
        $this->ajbNotarisId = $n->id;
        $this->ajbNotarisNama = $n->nama;

        $this->ajbNewNominal = '';
        $this->ajbNewPph = '';
        $this->ajbNewTglBerlaku = now()->toDateString();
        $this->loadAjbEdits();
        $this->resetErrorBag();

        Flux::modal('notaris-ajb')->show();
    }

    protected function loadAjbEdits(): void
    {
        $rows = NotarisBiayaAjbHistory::where('notaris_id', $this->ajbNotarisId)
            ->orderByDesc('berlaku_mulai')
            ->get();

        $this->ajbEdits = [];
        foreach ($rows as $r) {
            $this->ajbEdits[$r->id] = [
                'nominal' => (string) (int) $r->nominal_biaya_ajb,
                'pph' => $r->pph_promo_ajb !== null ? (string) (int) $r->pph_promo_ajb : '',
                'tgl' => $r->berlaku_mulai?->toDateString(),
            ];
        }
    }

    public function getAjbRowsProperty()
    {
        if (! $this->ajbNotarisId) {
            return collect();
        }

        return NotarisBiayaAjbHistory::where('notaris_id', $this->ajbNotarisId)
            ->orderByDesc('berlaku_mulai')
            ->get();
    }

    public function addAjb(): void
    {
        $this->validate([
            'ajbNewNominal' => ['required', 'numeric', 'min:0'],
            'ajbNewPph' => ['nullable', 'numeric', 'min:0'],
            'ajbNewTglBerlaku' => ['required', 'date'],
        ], [], [
            'ajbNewNominal' => 'nominal AJB',
            'ajbNewPph' => 'PPh promo',
            'ajbNewTglBerlaku' => 'tanggal berlaku',
        ]);

        NotarisBiayaAjbHistory::create([
            'notaris_id' => $this->ajbNotarisId,
            'nominal_biaya_ajb' => $this->ajbNewNominal,
            'pph_promo_ajb' => $this->ajbNewPph !== '' ? $this->ajbNewPph : null,
            'berlaku_mulai' => $this->ajbNewTglBerlaku,
            'created_by_user_id' => Auth::id(),
        ]);

        $this->ajbNewNominal = '';
        $this->ajbNewPph = '';
        $this->ajbNewTglBerlaku = now()->toDateString();
        $this->loadAjbEdits();

        Flux::toast(variant: 'success', text: 'Tarif AJB baru ditambahkan.');
    }

    public function updateAjb(int $ajbId): void
    {
        $row = $this->ajbEdits[$ajbId] ?? null;
        if (! $row) {
            return;
        }

        $data = $this->validate([
            "ajbEdits.$ajbId.nominal" => ['required', 'numeric', 'min:0'],
            "ajbEdits.$ajbId.pph" => ['nullable', 'numeric', 'min:0'],
            "ajbEdits.$ajbId.tgl" => ['required', 'date'],
        ], [], [
            "ajbEdits.$ajbId.nominal" => 'nominal',
            "ajbEdits.$ajbId.pph" => 'PPh',
            "ajbEdits.$ajbId.tgl" => 'tanggal',
        ])['ajbEdits'][$ajbId];

        $ajb = NotarisBiayaAjbHistory::where('notaris_id', $this->ajbNotarisId)
            ->where('id', $ajbId)
            ->firstOrFail();

        $ajb->update([
            'nominal_biaya_ajb' => $data['nominal'],
            'pph_promo_ajb' => ($data['pph'] ?? '') !== '' ? $data['pph'] : null,
            'berlaku_mulai' => $data['tgl'],
        ]);

        Flux::toast(variant: 'success', text: 'Tarif AJB diperbarui.');
    }

    public function deleteAjb(int $ajbId): void
    {
        NotarisBiayaAjbHistory::where('notaris_id', $this->ajbNotarisId)
            ->where('id', $ajbId)
            ->delete();

        unset($this->ajbEdits[$ajbId]);

        Flux::toast(variant: 'success', text: 'Tarif AJB dihapus.');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Master Notaris') }}</flux:heading>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create" class="self-start sm:self-auto">
                {{ __('Tambah Notaris') }}
            </flux:button>
        </div>

        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nama, NIK, HP, atau nomor rekening...')" />
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <x-sortable-column field="nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama') }}</x-sortable-column>
                        <x-sortable-column field="nik" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('NIK') }}</x-sortable-column>
                        <x-sortable-column field="hp" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('HP') }}</x-sortable-column>
                        <flux:table.column>{{ __('Alamat') }}</flux:table.column>
                        <flux:table.column>{{ __('Rekening') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Tarif AJB Aktif') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Riwayat') }}</flux:table.column>
                        <x-sortable-column field="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Diupdate') }}</x-sortable-column>
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($notarisList as $row)
                            @php $ajbAktif = $row->biayaAjbAktif(); @endphp
                            <flux:table.row :key="'notaris-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($notarisList->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $row->nama }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap font-mono text-xs">{{ $row->nik }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap font-mono text-xs">{{ $row->hp ?: '—' }}</flux:table.cell>
                                <flux:table.cell class="max-w-xs">
                                    @if ($row->alamat)
                                        <div class="text-xs text-zinc-600 dark:text-zinc-300 line-clamp-2" title="{{ $row->alamat }}">
                                            {{ $row->alamat }}
                                        </div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">
                                    @if ($row->bank)
                                        <div class="font-medium">{{ $row->bank->nama }}</div>
                                        <div class="font-mono text-zinc-500">{{ $row->nomor_rekening ?: '—' }}</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">
                                    @if ($ajbAktif)
                                        <div class="font-mono font-semibold">Rp {{ number_format($ajbAktif->nominal_biaya_ajb, 0, ',', '.') }}</div>
                                        <div class="text-xs text-zinc-500">{{ __('mulai') }} {{ $ajbAktif->berlaku_mulai?->format('d/m/Y') }}</div>
                                    @else
                                        <span class="text-zinc-400">{{ __('belum di-set') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <flux:badge color="zinc" size="sm">{{ $row->biaya_ajb_history_count }}</flux:badge>
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
                                            <flux:menu.item icon="banknotes" wire:click="openAjb({{ $row->id }})">
                                                {{ __('Setting AJB') }}
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
                                <flux:table.cell colspan="10" class="py-8 text-center text-zinc-500">
                                    @if ($search)
                                        {{ __('Tidak ada notaris yang cocok dengan ":q".', ['q' => $search]) }}
                                    @else
                                        {{ __('Belum ada notaris. Klik "Tambah Notaris" untuk menambahkan.') }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $notarisList])

        {{-- FORM NOTARIS --}}
        <flux:modal name="notaris-form" class="md:w-2xl" focusable>
            <form wire:submit="save" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Edit Notaris') : __('Tambah Notaris') }}</flux:heading>
                    <flux:subheading>{{ __('Identitas notaris/PPAT.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Identitas') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Nama Notaris') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="nama" required />
                            <flux:error name="nama" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('NIK') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="nik" required placeholder="3201xxxxxxxxxxxx" />
                            <flux:error name="nik" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('HP') }}</flux:label>
                        <flux:input wire:model.blur="hp" placeholder="08xxxxxxxxxx" />
                        <flux:error name="hp" />
                    </flux:field>

                    <flux:textarea wire:model="alamat" :label="__('Alamat Kantor')" rows="2"
                                   :description="__('Opsional.')" />
                    <flux:error name="alamat" />
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
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- DELETE CONFIRM --}}
        <flux:modal name="notaris-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus Notaris?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Anda akan menghapus :nama beserta seluruh riwayat tarif AJB-nya. Tindakan ini tidak dapat dibatalkan.', ['nama' => $deleteNama]) }}
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

        {{-- SETTING AJB MODAL --}}
        <flux:modal name="notaris-ajb" class="md:w-[95vw]! lg:w-[85vw]! xl:w-[80vw]! max-w-350!">
            <div class="space-y-6">
                <div>
                    <div class="flex items-center gap-2">
                        <flux:icon.banknotes class="size-5 text-zinc-500" />
                        <flux:heading size="lg">{{ __('Riwayat AJB') }}</flux:heading>
                    </div>
                    <flux:subheading>
                        {{ __('Notaris:') }} <span class="font-semibold">{{ $ajbNotarisNama ?? '—' }}</span>
                    </flux:subheading>
                </div>

                {{-- FORM TAMBAH --}}
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Tambah nominal AJB dan tanggal berlaku.') }}
                    </p>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <flux:field>
                            <flux:label class="text-xs uppercase tracking-wider">{{ __('Nominal') }}</flux:label>
                            <x-money-input wire="ajbNewNominal" placeholder="1.500.000" />
                            <flux:error name="ajbNewNominal" />
                        </flux:field>

                        <flux:field>
                            <flux:label class="text-xs uppercase tracking-wider">{{ __('PPh Promo (opsional)') }}</flux:label>
                            <x-money-input wire="ajbNewPph" placeholder="50.000" />
                            <flux:error name="ajbNewPph" />
                        </flux:field>

                        <flux:field>
                            <flux:label class="text-xs uppercase tracking-wider">{{ __('Tgl Berlaku') }}</flux:label>
                            <flux:input wire:model="ajbNewTglBerlaku" type="date" />
                            <flux:error name="ajbNewTglBerlaku" />
                        </flux:field>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <flux:button variant="primary" icon="plus" wire:click="addAjb">
                            {{ __('Tambah Tarif Baru') }}
                        </flux:button>
                    </div>
                </div>

                {{-- LIST AJB --}}
                @php $rows = $this->ajbRows; @endphp
                @if ($rows->isEmpty())
                    <div class="rounded-md border-2 border-dashed border-zinc-200 px-4 py-10 text-center text-zinc-500 dark:border-zinc-700">
                        <flux:icon.banknotes class="mx-auto size-10 text-zinc-400" />
                        <p class="mt-2 text-sm">{{ __('Belum ada tarif AJB. Tambah dari form di atas.') }}</p>
                    </div>
                @else
                    @php
                        $today = now()->toDateString();
                        $activeAjbId = $rows->first(fn ($x) => $x->berlaku_mulai && $x->berlaku_mulai->toDateString() <= $today)?->id;
                    @endphp
                    <div>
                        <flux:heading size="sm" class="mb-2 text-zinc-500 uppercase tracking-wider">{{ __('Daftar Tarif (urut dari terbaru)') }}</flux:heading>
                        <div class="space-y-2">
                            @foreach ($rows as $r)
                                @php $isAktif = $r->id === $activeAjbId; @endphp
                                <div wire:key="ajb-{{ $r->id }}"
                                     @class([
                                        'rounded-lg border p-3 transition',
                                        'border-green-300 bg-green-50 dark:border-green-700 dark:bg-green-950/30' => $isAktif,
                                        'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => ! $isAktif,
                                     ])>
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-sm font-semibold">Rp {{ number_format($r->nominal_biaya_ajb, 0, ',', '.') }}</span>
                                            <span class="text-zinc-400">·</span>
                                            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('mulai') }} {{ $r->berlaku_mulai?->format('d/m/Y') }}</span>
                                            @if ($r->pph_promo_ajb !== null)
                                                <span class="text-zinc-400">·</span>
                                                <span class="text-xs text-zinc-500">PPh: Rp {{ number_format($r->pph_promo_ajb, 0, ',', '.') }}</span>
                                            @endif
                                            @if ($isAktif)
                                                <flux:badge color="green" size="sm" icon="check-circle">{{ __('Aktif') }}</flux:badge>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_1fr_1fr_auto] md:items-end">
                                        <flux:field>
                                            <flux:label class="text-xs">{{ __('Ubah Nominal') }}</flux:label>
                                            <x-money-input :wire="'ajbEdits.'.$r->id.'.nominal'" />
                                            <flux:error :name="'ajbEdits.'.$r->id.'.nominal'" />
                                        </flux:field>

                                        <flux:field>
                                            <flux:label class="text-xs">{{ __('Ubah PPh') }}</flux:label>
                                            <x-money-input :wire="'ajbEdits.'.$r->id.'.pph'" placeholder="—" />
                                            <flux:error :name="'ajbEdits.'.$r->id.'.pph'" />
                                        </flux:field>

                                        <flux:field>
                                            <flux:label class="text-xs">{{ __('Ubah Tgl') }}</flux:label>
                                            <flux:input wire:model="ajbEdits.{{ $r->id }}.tgl" type="date" />
                                            <flux:error :name="'ajbEdits.'.$r->id.'.tgl'" />
                                        </flux:field>

                                        <div class="flex gap-2">
                                            <flux:button size="sm" variant="primary" icon="check" wire:click="updateAjb({{ $r->id }})">
                                                {{ __('Simpan') }}
                                            </flux:button>
                                            <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteAjb({{ $r->id }})" />
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="filled" icon="x-mark" type="button">{{ __('Tutup') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>

    </div>
</section>
