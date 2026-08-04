<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Bank;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\VirtualAccount;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Virtual Account')] class extends Component
{
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nomor_va';
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'proyek')]
    public ?int $filterProyek = null;

    #[Url(as: 'bank')]
    public ?int $filterBank = null;

    #[Url(as: 'aktif')]
    public ?string $filterAktif = null;

    #[Url(as: 'per')]
    public string $perPage = '15';

    // Single form
    public ?int $editId = null;

    public ?int $rumahId = null;

    public ?int $bankId = null;

    public string $nomorVa = '';

    public bool $isAktif = true;

    // Bulk paste form
    public ?int $bulkProyekId = null;

    public ?int $bulkBankId = null;

    public string $bulkRows = '';

    public array $bulkPreview = [];

    public ?int $deleteId = null;

    public ?string $deleteNomor = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterProyek(): void
    {
        $this->resetPage();
    }

    public function updatingFilterBank(): void
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
        $this->reset(['search', 'filterProyek', 'filterBank', 'filterAktif']);
        $this->resetPage();
    }

    public function with(): array
    {
        // Filter yang di-apply ke child VA (dipakai di whereHas & di eager-load)
        $vaFilter = function ($q) {
            $q->when($this->filterBank, fn ($qq) => $qq->where('bank_id', $this->filterBank))
                ->when($this->filterAktif !== null && $this->filterAktif !== '',
                    fn ($qq) => $qq->where('is_aktif', $this->filterAktif === 'aktif'))
                ->when($this->search, function ($qq) {
                    $term = '%'.$this->search.'%';
                    $qq->where('nomor_va', 'like', $term);
                });
        };

        $query = Rumah::query()
            ->with([
                'proyek:id,nama_proyek',
                'virtualAccount' => function ($q) use ($vaFilter) {
                    $vaFilter($q);
                    $q->with('bank:id,nama')->orderBy('bank_id');
                },
            ])
            ->whereHas('virtualAccount', $vaFilter)
            ->when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", [$term])
                        ->orWhereHas('virtualAccount', fn ($v) => $v->where('nomor_va', 'like', $term));
                });
            })
            ->orderBy('proyek_id')
            ->orderBy('blok')
            ->orderBy('nomor_unit');

        return [
            'units' => $query->paginate($this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage)),
            'proyeks' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']),
            'banks' => Bank::orderBy('nama')->get(['id', 'nama']),
        ];
    }

    // ============ SINGLE ADD/EDIT ============

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('va-form')->show();
    }

    public function edit(int $id): void
    {
        $va = VirtualAccount::findOrFail($id);
        $this->editId = $va->id;
        $this->rumahId = $va->rumah_id;
        $this->bankId = $va->bank_id;
        $this->nomorVa = $va->nomor_va;
        $this->isAktif = (bool) $va->is_aktif;
        $this->resetErrorBag();
        Flux::modal('va-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'rumahId' => ['required', 'exists:rumah,id'],
            'bankId' => ['required', 'exists:bank,id'],
            'nomorVa' => [
                'required', 'string', 'max:50',
                'unique:virtual_account,nomor_va'.($this->editId ? ','.$this->editId : ''),
            ],
            'isAktif' => ['boolean'],
        ], [], [
            'rumahId' => 'unit rumah',
            'bankId' => 'bank',
            'nomorVa' => 'nomor VA',
            'isAktif' => 'status aktif',
        ]);

        // Cek unique pair (rumah_id, bank_id)
        $exists = VirtualAccount::where('rumah_id', $validated['rumahId'])
            ->where('bank_id', $validated['bankId'])
            ->when($this->editId, fn ($q) => $q->where('id', '!=', $this->editId))
            ->exists();

        if ($exists) {
            $this->addError('bankId', 'Unit ini sudah punya VA di bank tersebut. Edit yang ada atau pilih bank lain.');
            return;
        }

        $rumah = Rumah::findOrFail($validated['rumahId']);

        $va = $this->editId
            ? VirtualAccount::findOrFail($this->editId)
            : new VirtualAccount;

        $va->fill([
            'proyek_id' => $rumah->proyek_id,
            'rumah_id' => $validated['rumahId'],
            'bank_id' => $validated['bankId'],
            'nomor_va' => $validated['nomorVa'],
            'is_aktif' => $validated['isAktif'],
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('va-form')->close();
        $wasEdit = (bool) $this->editId;
        $this->resetForm();

        Flux::toast(variant: 'success', text: $wasEdit ? 'VA diperbarui.' : 'VA ditambahkan.');
    }

    // ============ BULK PASTE ============

    public function openBulk(): void
    {
        $this->reset(['bulkProyekId', 'bulkBankId', 'bulkRows', 'bulkPreview']);
        $this->resetErrorBag();
        Flux::modal('va-bulk')->show();
    }

    public function previewBulk(): void
    {
        $this->validate([
            'bulkProyekId' => ['required', 'exists:proyek,id'],
            'bulkBankId' => ['required', 'exists:bank,id'],
            'bulkRows' => ['required', 'string'],
        ], [], [
            'bulkProyekId' => 'proyek',
            'bulkBankId' => 'bank',
            'bulkRows' => 'data baris',
        ]);

        $lines = collect(preg_split('/\r\n|\r|\n/', trim($this->bulkRows)))
            ->map(fn ($l) => trim($l))
            ->filter()
            ->values();

        $preview = [];
        foreach ($lines as $line) {
            // Format: blok,unit,nomor_va  atau  blok-unit,nomor_va
            $parts = array_map('trim', preg_split('/[\,\;\t]+/', $line));

            if (count($parts) === 2) {
                // "AA-01,99901"
                $kode = $parts[0];
                $nomor = $parts[1];
                if (str_contains($kode, '-')) {
                    [$blok, $unit] = array_pad(explode('-', $kode, 2), 2, '');
                } else {
                    $blok = $kode;
                    $unit = '';
                }
            } elseif (count($parts) >= 3) {
                $blok = $parts[0];
                $unit = $parts[1];
                $nomor = $parts[2];
            } else {
                $preview[] = ['raw' => $line, 'status' => 'error', 'msg' => 'Format tidak dikenali. Pakai "blok,unit,nomor_va" atau "blok-unit,nomor_va".'];
                continue;
            }

            $rumah = Rumah::where('proyek_id', $this->bulkProyekId)
                ->where('blok', $blok)
                ->where('nomor_unit', $unit)
                ->first();

            if (! $rumah) {
                $preview[] = ['raw' => $line, 'blok' => $blok, 'unit' => $unit, 'nomor' => $nomor, 'status' => 'error', 'msg' => "Unit {$blok}-{$unit} tidak ditemukan di proyek terpilih."];
                continue;
            }

            // Cek duplicate
            $existing = VirtualAccount::where('rumah_id', $rumah->id)
                ->where('bank_id', $this->bulkBankId)
                ->first();
            $existsNomor = VirtualAccount::where('nomor_va', $nomor)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();

            if ($existsNomor) {
                $preview[] = ['raw' => $line, 'blok' => $blok, 'unit' => $unit, 'nomor' => $nomor, 'status' => 'error', 'msg' => "Nomor VA '{$nomor}' sudah dipakai unit lain."];
                continue;
            }

            $preview[] = [
                'raw' => $line,
                'rumah_id' => $rumah->id,
                'blok' => $blok,
                'unit' => $unit,
                'nomor' => $nomor,
                'status' => $existing ? 'update' : 'new',
                'existing_id' => $existing?->id,
            ];
        }

        $this->bulkPreview = $preview;
    }

    public function submitBulk(): void
    {
        if (empty($this->bulkPreview)) {
            Flux::toast(variant: 'warning', text: 'Tidak ada data untuk diproses. Klik Preview dulu.');
            return;
        }

        $valid = collect($this->bulkPreview)->whereIn('status', ['new', 'update']);

        if ($valid->isEmpty()) {
            Flux::toast(variant: 'danger', text: 'Tidak ada baris valid yang bisa disimpan.');
            return;
        }

        $countNew = 0;
        $countUpdate = 0;

        DB::transaction(function () use ($valid, &$countNew, &$countUpdate) {
            foreach ($valid as $row) {
                if ($row['status'] === 'update') {
                    VirtualAccount::where('id', $row['existing_id'])->update([
                        'nomor_va' => $row['nomor'],
                        'updated_by_user_id' => Auth::id(),
                    ]);
                    $countUpdate++;
                } else {
                    VirtualAccount::create([
                        'proyek_id' => $this->bulkProyekId,
                        'rumah_id' => $row['rumah_id'],
                        'bank_id' => $this->bulkBankId,
                        'nomor_va' => $row['nomor'],
                        'is_aktif' => true,
                        'updated_by_user_id' => Auth::id(),
                    ]);
                    $countNew++;
                }
            }
        });

        Flux::modal('va-bulk')->close();
        $this->reset(['bulkProyekId', 'bulkBankId', 'bulkRows', 'bulkPreview']);

        Flux::toast(variant: 'success', text: "Bulk VA selesai: {$countNew} baru, {$countUpdate} update.");
    }

    // ============ TOGGLE & DELETE ============

    public function toggleAktif(int $id): void
    {
        $va = VirtualAccount::findOrFail($id);
        $va->update([
            'is_aktif' => ! $va->is_aktif,
            'updated_by_user_id' => Auth::id(),
        ]);

        Flux::toast(variant: 'success', text: 'Status VA: '.($va->is_aktif ? 'aktif' : 'nonaktif'));
    }

    public function confirmDelete(int $id): void
    {
        $va = VirtualAccount::findOrFail($id);
        $this->deleteId = $va->id;
        $this->deleteNomor = $va->nomor_va;
        Flux::modal('va-delete-confirm')->show();
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        VirtualAccount::findOrFail($this->deleteId)->delete();

        Flux::modal('va-delete-confirm')->close();
        $this->deleteId = null;
        $this->deleteNomor = null;

        Flux::toast(variant: 'success', text: 'VA dihapus.');
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'rumahId', 'bankId', 'nomorVa']);
        $this->isAktif = true;
        $this->resetErrorBag();
    }

    /** Untuk select unit di form single (per proyek terpilih atau semua). */
    public function getRumahOptionsProperty()
    {
        return Rumah::with('proyek:id,nama_proyek')
            ->orderBy('proyek_id')
            ->orderBy('blok')
            ->orderBy('nomor_unit')
            ->get(['id', 'proyek_id', 'blok', 'nomor_unit'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'label' => "{$r->proyek?->nama_proyek} · {$r->blok}-{$r->nomor_unit}",
            ]);
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <flux:heading size="xl">{{ __('Master Virtual Account') }}</flux:heading>

            <div class="flex gap-2 self-start sm:self-auto">
                <flux:button variant="filled" icon="document-arrow-up" wire:click="openBulk">
                    {{ __('Bulk Paste') }}
                </flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create">
                    {{ __('Tambah') }}
                </flux:button>
            </div>
        </div>

        {{-- FILTERS --}}
        <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-12">
            <div class="md:col-span-5">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                            :placeholder="__('Cari nomor VA / kode unit (blok-unit)...')" />
            </div>
            <div class="md:col-span-3">
                <flux:select wire:model.live="filterProyek" :placeholder="__('Semua Proyek')">
                    <flux:select.option value="">{{ __('Semua Proyek') }}</flux:select.option>
                    @foreach ($proyeks as $p)
                        <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="md:col-span-2">
                <flux:select wire:model.live="filterBank" :placeholder="__('Semua Bank')">
                    <flux:select.option value="">{{ __('Semua Bank') }}</flux:select.option>
                    @foreach ($banks as $b)
                        <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="md:col-span-2">
                <flux:select wire:model.live="filterAktif" :placeholder="__('Semua Status')">
                    <flux:select.option value="">{{ __('Semua Status') }}</flux:select.option>
                    <flux:select.option value="aktif">{{ __('Aktif') }}</flux:select.option>
                    <flux:select.option value="nonaktif">{{ __('Nonaktif') }}</flux:select.option>
                </flux:select>
            </div>
        </div>

        @if ($search || $filterProyek || $filterBank || $filterAktif)
            <div class="mb-3">
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                    {{ __('Reset filter') }}
                </flux:button>
            </div>
        @endif

        {{-- TABLE (grouped per unit) --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="w-12 px-4 py-2 text-left">{{ __('No') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Proyek') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Unit') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Bank') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Nomor VA') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($units as $unit)
                        @php $vaList = $unit->virtualAccount; $count = $vaList->count(); @endphp
                        @foreach ($vaList as $vIdx => $va)
                            @php
                                $isUnitBoundary = $vIdx === 0 && $loop->parent->index > 0;
                                $borderCls = $isUnitBoundary
                                    ? 'border-t-2 border-t-zinc-200 dark:border-t-zinc-700'
                                    : 'border-t border-zinc-100 dark:border-zinc-800';
                            @endphp
                            <tr class="{{ $borderCls }} hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                @if ($vIdx === 0)
                                    <td rowspan="{{ $count }}" class="whitespace-nowrap px-4 py-2 align-top text-zinc-500">{{ $loop->parent->index + ($units->firstItem() ?? 1) }}</td>
                                    <td rowspan="{{ $count }}" class="px-4 py-2 align-top">{{ $unit->proyek?->nama_proyek ?? '—' }}</td>
                                    <td rowspan="{{ $count }}" class="px-4 py-2 align-top">
                                        <div class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ $unit->kode_unit }}</div>
                                        @if ($count > 1)
                                            <div class="mt-0.5 text-[10px] font-medium text-zinc-500">{{ $count }} {{ __('bank') }}</div>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-4 py-2">{{ $va->bank?->nama ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ $va->nomor_va }}</td>
                                <td class="px-4 py-2">
                                    @if ($va->is_aktif)
                                        <flux:badge color="green" size="sm">{{ __('Aktif') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">{{ __('Nonaktif') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $va->id }})">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="{{ $va->is_aktif ? 'pause-circle' : 'play-circle' }}"
                                                            wire:click="toggleAktif({{ $va->id }})">
                                                {{ $va->is_aktif ? __('Nonaktifkan') : __('Aktifkan') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $va->id }})">
                                                {{ __('Hapus') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-zinc-500">
                                @if ($search || $filterProyek || $filterBank)
                                    {{ __('Tidak ada VA yang cocok dengan filter.') }}
                                @else
                                    {{ __('Belum ada data VA. Klik "Tambah" atau "Bulk Paste" untuk menambahkan.') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.per-page-pagination', ['paginator' => $units])

        {{-- ============ DELETE CONFIRM MODAL ============ --}}
        <flux:modal name="va-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Hapus VA?') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Anda akan menghapus nomor :nomor. Tindakan ini tidak dapat dibatalkan.', ['nomor' => $deleteNomor]) }}
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

        {{-- ============ SINGLE FORM MODAL ============ --}}
        <flux:modal name="va-form" class="md:w-lg" focusable>
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Edit VA') : __('Tambah VA') }}</flux:heading>
                    <flux:subheading>{{ __('Satu unit boleh punya VA di banyak bank, tapi tidak boleh duplikat per bank.') }}</flux:subheading>
                </div>

                <flux:field>
                    <flux:label>{{ __('Unit Rumah') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model="rumahId" :placeholder="__('— Pilih unit —')">
                        <flux:select.option value="">{{ __('— Pilih unit —') }}</flux:select.option>
                        @foreach ($this->rumahOptions as $r)
                            <flux:select.option value="{{ $r['id'] }}">{{ $r['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="rumahId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Bank') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model="bankId" :placeholder="__('— Pilih bank —')">
                        <flux:select.option value="">{{ __('— Pilih bank —') }}</flux:select.option>
                        @foreach ($banks as $b)
                            <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="bankId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Nomor VA') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="nomorVa" required placeholder="contoh: 99901" />
                    <flux:description>{{ __('Maksimal 50 karakter. Harus unique.') }}</flux:description>
                    <flux:error name="nomorVa" />
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

        {{-- ============ BULK PASTE MODAL ============ --}}
        <flux:modal name="va-bulk" class="md:w-2xl" focusable>
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Bulk Paste VA') }}</flux:heading>
                    <flux:subheading>{{ __('Paste daftar VA dari Excel/bank. Satu baris = satu VA.') }}</flux:subheading>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('Proyek') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model="bulkProyekId">
                            <flux:select.option value="">{{ __('— Pilih proyek —') }}</flux:select.option>
                            @foreach ($proyeks as $p)
                                <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="bulkProyekId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Bank') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:select wire:model="bulkBankId">
                            <flux:select.option value="">{{ __('— Pilih bank —') }}</flux:select.option>
                            @foreach ($banks as $b)
                                <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="bulkBankId" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Data Baris') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:textarea wire:model="bulkRows" rows="8"
                                   placeholder="AA-01,99901&#10;AA-02,99902&#10;AE-102,99903" />
                    <flux:description>
                        {{ __('Format per baris: ') }}<code>blok-unit,nomor_va</code> {{ __('atau') }} <code>blok,unit,nomor_va</code>.
                        {{ __('Pemisah: koma, titik koma, atau tab.') }}
                    </flux:description>
                    <flux:error name="bulkRows" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button variant="filled" type="button" wire:click="previewBulk" icon="eye">
                        {{ __('Preview') }}
                    </flux:button>
                </div>

                @if (! empty($bulkPreview))
                    @php
                        $countNew = collect($bulkPreview)->where('status', 'new')->count();
                        $countUpdate = collect($bulkPreview)->where('status', 'update')->count();
                        $countError = collect($bulkPreview)->where('status', 'error')->count();
                    @endphp
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800/50">
                            <span class="font-semibold">{{ __('Preview:') }}</span>
                            <span class="ms-2 text-emerald-700 dark:text-emerald-400">{{ $countNew }} baru</span> ·
                            <span class="text-amber-700 dark:text-amber-400">{{ $countUpdate }} update</span> ·
                            <span class="text-rose-700 dark:text-rose-400">{{ $countError }} error</span>
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <table class="w-full text-xs">
                                <thead class="sticky top-0 bg-zinc-100 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-1.5 text-left">Unit</th>
                                        <th class="px-3 py-1.5 text-left">Nomor VA</th>
                                        <th class="px-3 py-1.5 text-left">Status</th>
                                        <th class="px-3 py-1.5 text-left">Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($bulkPreview as $p)
                                        <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                            <td class="px-3 py-1 font-mono">{{ ($p['blok'] ?? '?').'-'.($p['unit'] ?? '?') }}</td>
                                            <td class="px-3 py-1 font-mono">{{ $p['nomor'] ?? '—' }}</td>
                                            <td class="px-3 py-1">
                                                @if ($p['status'] === 'new')
                                                    <flux:badge color="green" size="sm">Baru</flux:badge>
                                                @elseif ($p['status'] === 'update')
                                                    <flux:badge color="amber" size="sm">Update</flux:badge>
                                                @else
                                                    <flux:badge color="rose" size="sm">Error</flux:badge>
                                                @endif
                                            </td>
                                            <td class="px-3 py-1 text-zinc-600 dark:text-zinc-400">{{ $p['msg'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="button" icon="check" wire:click="submitBulk"
                                 :disabled="empty($bulkPreview)">
                        {{ __('Simpan Baris Valid') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

    </div>
</section>
