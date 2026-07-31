<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Bank;
use App\Models\Master\JenisSales;
use App\Models\Master\Sales;
use App\Models\Master\SalesGrup;
use App\Models\Master\SalesLogPerpindahan;
use App\Support\PhoneNumber;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Master Sales')] class extends Component {
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'nama';
    }

    #[Url(as: 'tab')]
    public string $tab = 'sales';

    #[Url(as: 'per')]
    public string $perPage = '10';

    // ===== TAB: DATA SALES =====
    #[Url(as: 'q')]
    public string $searchSales = '';

    #[Url(as: 'jenis')]
    public string $filterJenis = '';

    #[Url(as: 'grup')]
    public string $filterGrup = '';

    #[Url(as: 'aktif')]
    public string $filterAktif = '';

    public ?int $salesEditId = null;

    public string $salesKode = '';

    public string $salesNama = '';

    public ?string $salesJenisId = null;

    public ?string $salesGrupId = null;

    public ?string $salesAlamat = null;

    public ?string $salesTelepon = null;

    public ?string $salesBankId = null;

    public ?string $salesNomorRekening = null;

    public ?string $salesNamaPemilikRekening = null;

    public bool $salesIsAktif = true;

    public ?string $salesDbosUsername = null;

    public string $salesDbosPassword = '';

    public bool $salesShowPassword = false;

    // ===== TAB: JENIS SALES =====
    #[Url(as: 'qj')]
    public string $searchJenis = '';

    public ?int $jenisEditId = null;

    public string $jenisNama = '';

    public ?string $jenisKeterangan = null;

    // ===== TAB: GROUP SALES =====
    #[Url(as: 'qg')]
    public string $searchGrup = '';

    public ?int $grupEditId = null;

    public string $grupNama = '';

    public ?string $grupPimpinanId = null;

    // ===== DELETE STATE (shared, but key per entity) =====
    public ?string $deleteEntity = null; // 'sales' | 'jenis' | 'grup'

    public ?int $deleteId = null;

    public ?string $deleteNama = null;

    // ===== HISTORY MODAL =====
    public ?int $historySalesId = null;

    public ?string $historySalesNama = null;

    // -------------------------------------------------------------
    // GENERAL
    // -------------------------------------------------------------

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage('salesPage');
        $this->resetPage('jenisPage');
        $this->resetPage('grupPage');
    }

    protected function effectivePerPage(): int
    {
        return $this->perPage === 'all' ? 99999 : max(1, (int) $this->perPage);
    }

    public function with(): array
    {
        $per = $this->effectivePerPage();

        return [
            // shared option lists
            'jenisOptions' => JenisSales::orderBy('nama')->get(['id', 'nama']),
            'grupOptions' => SalesGrup::orderBy('nama')->get(['id', 'nama']),
            'bankOptions' => Bank::orderBy('nama')->get(['id', 'nama']),
            'pimpinanOptions' => Sales::orderBy('nama')->get(['id', 'kode', 'nama']),

            // tab data
            'salesList' => $this->buildSalesQuery()->paginate($per, ['*'], 'salesPage'),
            'jenisList' => $this->buildJenisQuery()->paginate($per, ['*'], 'jenisPage'),
            'grupList' => $this->buildGrupQuery()->paginate($per, ['*'], 'grupPage'),

            'salesCount' => Sales::count(),
            'jenisCount' => JenisSales::count(),
            'grupCount' => SalesGrup::count(),
        ];
    }

    // -------------------------------------------------------------
    // TAB: DATA SALES
    // -------------------------------------------------------------

    protected function buildSalesQuery()
    {
        $q = Sales::query()
            ->with(['jenisSales:id,nama', 'grup:id,nama', 'bank:id,nama', 'updatedByUser:id,name'])
            ->when($this->filterJenis, fn ($q) => $q->where('jenis_sales_id', $this->filterJenis))
            ->when($this->filterGrup !== '', function ($q) {
                if ($this->filterGrup === 'tanpa') {
                    $q->whereNull('sales_grup_id');
                } else {
                    $q->where('sales_grup_id', $this->filterGrup);
                }
            })
            ->when($this->filterAktif !== '', function ($q) {
                $q->where('is_aktif', $this->filterAktif === 'aktif');
            })
            ->when($this->searchSales, function ($q) {
                $term = '%'.$this->searchSales.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nama', 'like', $term)
                        ->orWhere('kode', 'like', $term)
                        ->orWhere('telepon', 'like', $term)
                        ->orWhere('nomor_rekening', 'like', $term)
                        ->orWhere('dbos_username', 'like', $term);
                });
            });

        return $this->applySort($q, ['kode', 'nama', 'telepon', 'is_aktif', 'created_at']);
    }

    public function updatingSearchSales(): void
    {
        $this->resetPage('salesPage');
    }

    public function updatingFilterJenis(): void
    {
        $this->resetPage('salesPage');
    }

    public function updatingFilterGrup(): void
    {
        $this->resetPage('salesPage');
    }

    public function updatingFilterAktif(): void
    {
        $this->resetPage('salesPage');
    }

    // Auto-sanitasi HP sales
    public function updatedSalesTelepon($value): void
    {
        $clean = PhoneNumber::sanitize($value);
        if ($clean !== $this->salesTelepon) {
            $this->salesTelepon = $clean;
        }
    }

    public function createSales(): void
    {
        if (JenisSales::count() === 0) {
            Flux::toast(
                variant: 'warning',
                heading: 'Belum ada jenis sales',
                text: 'Buat minimal satu jenis sales dulu di tab "Jenis Sales".',
            );
            return;
        }

        $this->resetSalesForm();
        $this->salesKode = $this->generateNextSalesKode();
        Flux::modal('sales-form')->show();
    }

    /**
     * Generate kode sales berikutnya dengan format SLS-XXX (3 digit, zero-padded).
     * Cari kode terakhir di DB → +1. Kalau belum ada → SLS-001.
     */
    protected function generateNextSalesKode(): string
    {
        $last = Sales::where('kode', 'like', 'SLS-%')
            ->orderByRaw('CAST(SUBSTRING(kode, 5) AS UNSIGNED) DESC')
            ->value('kode');

        $num = 0;
        if ($last && preg_match('/^SLS-(\d+)$/', $last, $m)) {
            $num = (int) $m[1];
        }

        return 'SLS-'.str_pad((string) ($num + 1), 3, '0', STR_PAD_LEFT);
    }

    public function editSales(int $id): void
    {
        $s = Sales::findOrFail($id);

        $this->salesEditId = $s->id;
        $this->salesKode = $s->kode;
        $this->salesNama = $s->nama;
        $this->salesJenisId = $s->jenis_sales_id ? (string) $s->jenis_sales_id : null;
        $this->salesGrupId = $s->sales_grup_id ? (string) $s->sales_grup_id : null;
        $this->salesAlamat = $s->alamat;
        $this->salesTelepon = $s->telepon;
        $this->salesBankId = $s->bank_id ? (string) $s->bank_id : null;
        $this->salesNomorRekening = $s->nomor_rekening;
        $this->salesNamaPemilikRekening = $s->nama_pemilik_rekening;
        $this->salesIsAktif = (bool) $s->is_aktif;
        $this->salesDbosUsername = $s->dbos_username;
        $this->salesDbosPassword = '';
        $this->salesShowPassword = false;
        $this->resetErrorBag();

        Flux::modal('sales-form')->show();
    }

    public function saveSales(): void
    {
        $validated = $this->validate([
            'salesKode' => [
                'required', 'string', 'max:30',
                'unique:sales,kode'.($this->salesEditId ? ','.$this->salesEditId : ''),
            ],
            'salesNama' => ['required', 'string', 'max:255'],
            'salesJenisId' => ['required', 'exists:jenis_sales,id'],
            'salesGrupId' => ['nullable', 'exists:sales_grup,id'],
            'salesAlamat' => ['nullable', 'string', 'max:500'],
            'salesTelepon' => ['nullable', 'string', 'max:30'],
            'salesBankId' => ['nullable', 'exists:bank,id'],
            'salesNomorRekening' => ['nullable', 'string', 'max:50'],
            'salesNamaPemilikRekening' => ['nullable', 'string', 'max:255'],
            'salesIsAktif' => ['boolean'],
            'salesDbosUsername' => [
                'nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/',
                'unique:sales,dbos_username'.($this->salesEditId ? ','.$this->salesEditId : ''),
            ],
            'salesDbosPassword' => ['nullable', 'string', 'min:6', 'max:100'],
        ], [
            'salesDbosUsername.regex' => 'Username DBOS hanya boleh huruf, angka, titik, underscore, atau strip.',
        ], [
            'salesKode' => 'kode',
            'salesNama' => 'nama',
            'salesJenisId' => 'jenis sales',
            'salesGrupId' => 'grup sales',
            'salesAlamat' => 'alamat',
            'salesTelepon' => 'telepon',
            'salesBankId' => 'bank',
            'salesNomorRekening' => 'nomor rekening',
            'salesNamaPemilikRekening' => 'nama pemilik rekening',
            'salesIsAktif' => 'status aktif',
            'salesDbosUsername' => 'username DBOS',
            'salesDbosPassword' => 'password DBOS',
        ]);

        $newJenisId = (int) $validated['salesJenisId'];
        $newGrupId = $validated['salesGrupId'] ? (int) $validated['salesGrupId'] : null;

        DB::transaction(function () use ($validated, $newJenisId, $newGrupId) {
            $sales = $this->salesEditId
                ? Sales::findOrFail($this->salesEditId)
                : new Sales;

            $oldJenisId = $sales->jenis_sales_id;
            $oldGrupId = $sales->sales_grup_id;

            $payload = [
                'kode' => $validated['salesKode'],
                'nama' => $validated['salesNama'],
                'jenis_sales_id' => $newJenisId,
                'sales_grup_id' => $newGrupId,
                'alamat' => $validated['salesAlamat'] ?: null,
                'telepon' => $validated['salesTelepon'] ?: null,
                'bank_id' => $validated['salesBankId'] ? (int) $validated['salesBankId'] : null,
                'nomor_rekening' => $validated['salesNomorRekening'] ?: null,
                'nama_pemilik_rekening' => $validated['salesNamaPemilikRekening'] ?: null,
                'is_aktif' => (bool) ($validated['salesIsAktif'] ?? true),
                'dbos_username' => $validated['salesDbosUsername'] ?: null,
                'updated_by_user_id' => Auth::id(),
            ];

            // Password DBOS: hanya isi kalau user ngetik. Saat edit + kosong → pertahankan lama.
            if (! empty($validated['salesDbosPassword'])) {
                $payload['dbos_password'] = $validated['salesDbosPassword'];
            } elseif (! $this->salesEditId) {
                $payload['dbos_password'] = null;
            }

            $sales->fill($payload)->save();

            // Auto-log perpindahan kalau ada perubahan grup ATAU jenis (cuma untuk update, bukan create)
            if ($this->salesEditId) {
                $grupBerubah = $oldGrupId !== $newGrupId;
                $jenisBerubah = $oldJenisId !== $newJenisId;

                if ($grupBerubah || $jenisBerubah) {
                    SalesLogPerpindahan::create([
                        'sales_id' => $sales->id,
                        'dari_grup_id' => $grupBerubah ? $oldGrupId : null,
                        'ke_grup_id' => $grupBerubah ? $newGrupId : null,
                        'dari_jenis' => $jenisBerubah && $oldJenisId
                            ? optional(JenisSales::find($oldJenisId))->nama
                            : null,
                        'ke_jenis' => $jenisBerubah
                            ? optional(JenisSales::find($newJenisId))->nama
                            : null,
                        'catatan' => $grupBerubah && $jenisBerubah
                            ? 'Pindah grup & jenis'
                            : ($grupBerubah ? 'Pindah grup' : 'Pindah jenis'),
                        'dipindah_oleh_user_id' => Auth::id(),
                    ]);
                }
            }
        });

        Flux::modal('sales-form')->close();
        $wasEdit = (bool) $this->salesEditId;
        $this->resetSalesForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Data sales diperbarui.'
            : 'Sales baru ditambahkan.');
    }

    public function confirmDeleteSales(int $id): void
    {
        $s = Sales::find($id);
        if (! $s) {
            return;
        }

        $pimpinanCount = SalesGrup::where('pimpinan_id', $id)->count();
        if ($pimpinanCount > 0) {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Sales '{$s->nama}' masih jadi pimpinan {$pimpinanCount} grup. Ganti pimpinan grup dulu.",
            );
            return;
        }

        $this->deleteEntity = 'sales';
        $this->deleteId = $s->id;
        $this->deleteNama = $s->nama;

        Flux::modal('sales-delete-confirm')->show();
    }

    public function showHistory(int $id): void
    {
        $s = Sales::find($id);
        if (! $s) {
            return;
        }

        $this->historySalesId = $s->id;
        $this->historySalesNama = $s->nama;

        Flux::modal('sales-history')->show();
    }

    public function getHistoryRowsProperty()
    {
        if (! $this->historySalesId) {
            return collect();
        }

        return SalesLogPerpindahan::query()
            ->with(['dariGrup:id,nama', 'keGrup:id,nama'])
            ->where('sales_id', $this->historySalesId)
            ->orderByDesc('created_at')
            ->get();
    }

    protected function resetSalesForm(): void
    {
        $this->reset([
            'salesEditId',
            'salesKode',
            'salesNama',
            'salesJenisId',
            'salesGrupId',
            'salesAlamat',
            'salesTelepon',
            'salesBankId',
            'salesNomorRekening',
            'salesNamaPemilikRekening',
            'salesDbosUsername',
            'salesDbosPassword',
            'salesShowPassword',
        ]);
        $this->salesIsAktif = true;
        $this->resetErrorBag();
    }

    public function toggleSalesAktif(int $id): void
    {
        $s = Sales::findOrFail($id);
        $s->is_aktif = ! $s->is_aktif;
        $s->updated_by_user_id = Auth::id();
        $s->save();

        Flux::toast(variant: 'success', text: $s->is_aktif
            ? "Sales '{$s->nama}' diaktifkan."
            : "Sales '{$s->nama}' dinonaktifkan.");
    }

    // -------------------------------------------------------------
    // TAB: JENIS SALES
    // -------------------------------------------------------------

    protected function buildJenisQuery()
    {
        return JenisSales::query()
            ->withCount('sales')
            ->with('updatedByUser:id,name')
            ->when($this->searchJenis, function ($q) {
                $term = '%'.$this->searchJenis.'%';
                $q->where('nama', 'like', $term);
            })
            ->orderBy('nama');
    }

    public function updatingSearchJenis(): void
    {
        $this->resetPage('jenisPage');
    }

    public function createJenis(): void
    {
        $this->resetJenisForm();
        Flux::modal('jenis-form')->show();
    }

    public function editJenis(int $id): void
    {
        $j = JenisSales::findOrFail($id);

        $this->jenisEditId = $j->id;
        $this->jenisNama = $j->nama;
        $this->jenisKeterangan = $j->keterangan;
        $this->resetErrorBag();

        Flux::modal('jenis-form')->show();
    }

    public function saveJenis(): void
    {
        $validated = $this->validate([
            'jenisNama' => [
                'required', 'string', 'max:255',
                'unique:jenis_sales,nama'.($this->jenisEditId ? ','.$this->jenisEditId : ''),
            ],
            'jenisKeterangan' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'jenisNama' => 'nama',
            'jenisKeterangan' => 'keterangan',
        ]);

        $j = $this->jenisEditId
            ? JenisSales::findOrFail($this->jenisEditId)
            : new JenisSales;

        $j->fill([
            'nama' => $validated['jenisNama'],
            'keterangan' => $validated['jenisKeterangan'] ?: null,
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('jenis-form')->close();
        $wasEdit = (bool) $this->jenisEditId;
        $this->resetJenisForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Jenis sales diperbarui.'
            : 'Jenis sales baru ditambahkan.');
    }

    public function confirmDeleteJenis(int $id): void
    {
        $j = JenisSales::withCount('sales')->find($id);
        if (! $j) {
            return;
        }

        if ($j->sales_count > 0) {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Jenis '{$j->nama}' masih dipakai oleh {$j->sales_count} sales. Pindahkan sales ke jenis lain dulu.",
            );
            return;
        }

        $this->deleteEntity = 'jenis';
        $this->deleteId = $j->id;
        $this->deleteNama = $j->nama;

        Flux::modal('sales-delete-confirm')->show();
    }

    protected function resetJenisForm(): void
    {
        $this->reset(['jenisEditId', 'jenisNama', 'jenisKeterangan']);
        $this->resetErrorBag();
    }

    // -------------------------------------------------------------
    // TAB: GROUP SALES
    // -------------------------------------------------------------

    protected function buildGrupQuery()
    {
        return SalesGrup::query()
            ->withCount('anggota')
            ->with(['pimpinan:id,nama,kode', 'updatedByUser:id,name'])
            ->when($this->searchGrup, function ($q) {
                $term = '%'.$this->searchGrup.'%';
                $q->where('nama', 'like', $term);
            })
            ->orderBy('nama');
    }

    public function updatingSearchGrup(): void
    {
        $this->resetPage('grupPage');
    }

    public function createGrup(): void
    {
        $this->resetGrupForm();
        Flux::modal('grup-form')->show();
    }

    public function editGrup(int $id): void
    {
        $g = SalesGrup::findOrFail($id);

        $this->grupEditId = $g->id;
        $this->grupNama = $g->nama;
        $this->grupPimpinanId = $g->pimpinan_id ? (string) $g->pimpinan_id : null;
        $this->resetErrorBag();

        Flux::modal('grup-form')->show();
    }

    public function saveGrup(): void
    {
        $validated = $this->validate([
            'grupNama' => ['required', 'string', 'max:255'],
            'grupPimpinanId' => ['nullable', 'exists:sales,id'],
        ], [], [
            'grupNama' => 'nama',
            'grupPimpinanId' => 'pimpinan',
        ]);

        $g = $this->grupEditId
            ? SalesGrup::findOrFail($this->grupEditId)
            : new SalesGrup;

        $g->fill([
            'nama' => $validated['grupNama'],
            'pimpinan_id' => $validated['grupPimpinanId'] ? (int) $validated['grupPimpinanId'] : null,
            'updated_by_user_id' => Auth::id(),
        ])->save();

        Flux::modal('grup-form')->close();
        $wasEdit = (bool) $this->grupEditId;
        $this->resetGrupForm();

        Flux::toast(variant: 'success', text: $wasEdit
            ? 'Grup sales diperbarui.'
            : 'Grup sales baru ditambahkan.');
    }

    public function confirmDeleteGrup(int $id): void
    {
        $g = SalesGrup::withCount('anggota')->find($id);
        if (! $g) {
            return;
        }

        if ($g->anggota_count > 0) {
            Flux::toast(
                variant: 'danger',
                heading: 'Tidak bisa hapus',
                text: "Grup '{$g->nama}' masih punya {$g->anggota_count} anggota. Pindahkan anggota dulu.",
            );
            return;
        }

        $this->deleteEntity = 'grup';
        $this->deleteId = $g->id;
        $this->deleteNama = $g->nama;

        Flux::modal('sales-delete-confirm')->show();
    }

    protected function resetGrupForm(): void
    {
        $this->reset(['grupEditId', 'grupNama', 'grupPimpinanId']);
        $this->resetErrorBag();
    }

    // -------------------------------------------------------------
    // SHARED DELETE
    // -------------------------------------------------------------

    public function delete(): void
    {
        if (! $this->deleteEntity || ! $this->deleteId) {
            return;
        }

        match ($this->deleteEntity) {
            'sales' => Sales::findOrFail($this->deleteId)->delete(),
            'jenis' => JenisSales::findOrFail($this->deleteId)->delete(),
            'grup' => SalesGrup::findOrFail($this->deleteId)->delete(),
            default => null,
        };

        $label = match ($this->deleteEntity) {
            'sales' => 'Sales',
            'jenis' => 'Jenis sales',
            'grup' => 'Grup sales',
            default => 'Data',
        };

        $this->deleteEntity = null;
        $this->deleteId = null;
        $this->deleteNama = null;

        Flux::modal('sales-delete-confirm')->close();
        Flux::toast(variant: 'success', text: "{$label} dihapus.");
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6">
            <flux:heading size="xl">{{ __('Master Sales') }}</flux:heading>
        </div>

        {{-- TAB SWITCHER --}}
        @php
            $tabs = [
                ['key' => 'sales', 'label' => __('Data Sales'),  'count' => $salesCount, 'icon' => 'user'],
                ['key' => 'jenis', 'label' => __('Jenis Sales'), 'count' => $jenisCount, 'icon' => 'tag'],
                ['key' => 'grup',  'label' => __('Group Sales'), 'count' => $grupCount,  'icon' => 'user-group'],
            ];
        @endphp
        <div class="mb-5 flex flex-wrap items-center gap-2 border-b border-zinc-200 dark:border-zinc-700">
            @foreach ($tabs as $t)
                @php
                    $isActive = $tab === $t['key'];
                    $btnClass = $isActive
                        ? 'border-blue-500 text-blue-700 dark:border-blue-400 dark:text-blue-300'
                        : 'border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-white';
                    $badgeClass = $isActive
                        ? 'bg-blue-500 text-white'
                        : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
                @endphp
                <button type="button" wire:click="setTab('{{ $t['key'] }}')"
                        @class([
                            'inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition -mb-px',
                            $btnClass,
                        ])>
                    @switch($t['icon'])
                        @case('user')       <flux:icon.user class="size-4" /> @break
                        @case('tag')        <flux:icon.tag class="size-4" /> @break
                        @case('user-group') <flux:icon.user-group class="size-4" /> @break
                    @endswitch
                    {{ $t['label'] }}
                    <span @class([
                        'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold',
                        $badgeClass,
                    ])>{{ $t['count'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- ============================================= --}}
        {{-- TAB CONTENT: DATA SALES --}}
        {{-- ============================================= --}}
        @if ($tab === 'sales')
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:subheading>{{ __('Daftar tenaga sales lapangan. Edit untuk pindah grup/jenis (otomatis dicatat di riwayat).') }}</flux:subheading>
                <flux:button variant="primary" icon="plus" wire:click="createSales" class="self-start sm:self-auto">
                    {{ __('Tambah Sales') }}
                </flux:button>
            </div>

            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                <flux:input wire:model.live.debounce.300ms="searchSales" icon="magnifying-glass"
                            :placeholder="__('Cari nama, kode, telp, username...')" />

                <flux:select wire:model.live="filterJenis" placeholder="{{ __('Filter jenis sales') }}">
                    <flux:select.option value="">{{ __('Semua Jenis') }}</flux:select.option>
                    @foreach ($jenisOptions as $j)
                        <flux:select.option value="{{ $j->id }}">{{ $j->nama }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="filterGrup" placeholder="{{ __('Filter grup sales') }}">
                    <flux:select.option value="">{{ __('Semua Grup') }}</flux:select.option>
                    <flux:select.option value="tanpa">{{ __('Tanpa Grup') }}</flux:select.option>
                    @foreach ($grupOptions as $g)
                        <flux:select.option value="{{ $g->id }}">{{ $g->nama }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="filterAktif" placeholder="{{ __('Filter status') }}">
                    <flux:select.option value="">{{ __('Semua Status') }}</flux:select.option>
                    <flux:select.option value="aktif">{{ __('Aktif') }}</flux:select.option>
                    <flux:select.option value="nonaktif">{{ __('Non-Aktif') }}</flux:select.option>
                </flux:select>
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <flux:table class="px-4">
                        <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                            <x-sortable-column field="kode" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kode') }}</x-sortable-column>
                            <x-sortable-column field="nama" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Nama') }}</x-sortable-column>
                            <x-sortable-column field="is_aktif" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Status') }}</x-sortable-column>
                            <flux:table.column>{{ __('Jenis') }}</flux:table.column>
                            <flux:table.column>{{ __('Grup') }}</flux:table.column>
                            <x-sortable-column field="telepon" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Kontak') }}</x-sortable-column>
                            <flux:table.column>{{ __('Alamat') }}</flux:table.column>
                            <flux:table.column>{{ __('Rekening') }}</flux:table.column>
                            <flux:table.column>{{ __('Akun DBOS') }}</flux:table.column>
                            <x-sortable-column field="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Diupdate') }}</x-sortable-column>
                            <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($salesList as $row)
                                <flux:table.row :key="'sales-'.$row->id">
                                    <flux:table.cell class="text-zinc-500">{{ $loop->index + ($salesList->firstItem() ?? 1) }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap font-mono text-xs">{{ $row->kode }}</flux:table.cell>
                                    <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $row->nama }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap">
                                        @if ($row->is_aktif)
                                            <flux:badge color="green" size="sm" icon="check-circle">{{ __('Aktif') }}</flux:badge>
                                        @else
                                            <flux:badge color="zinc" size="sm" icon="x-circle">{{ __('Non-Aktif') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap">
                                        @if ($row->jenisSales)
                                            <flux:badge color="blue" size="sm">{{ $row->jenisSales->nama }}</flux:badge>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap">{{ $row->grup?->nama ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-xs">
                                        @if ($row->telepon)
                                            <div class="font-mono">{{ $row->telepon }}</div>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </flux:table.cell>
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
                                            <div class="text-zinc-400">a/n {{ $row->nama_pemilik_rekening ?: $row->nama }}</div>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-xs">
                                        @if ($row->dbos_username)
                                            <div class="font-mono">{{ $row->dbos_username }}</div>
                                            @if ($row->dbos_password)
                                                <div class="inline-flex items-center gap-1 text-green-600 dark:text-green-400">
                                                    <flux:icon.lock-closed class="size-3" />
                                                    <span>{{ __('password set') }}</span>
                                                </div>
                                            @else
                                                <div class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                                    <flux:icon.exclamation-triangle class="size-3" />
                                                    <span>{{ __('tanpa password') }}</span>
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-zinc-400">{{ __('belum dibuat') }}</span>
                                        @endif
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
                                                <flux:menu.item icon="pencil-square" wire:click="editSales({{ $row->id }})">
                                                    {{ __('Edit') }}
                                                </flux:menu.item>
                                                <flux:menu.item :icon="$row->is_aktif ? 'pause-circle' : 'play-circle'" wire:click="toggleSalesAktif({{ $row->id }})">
                                                    {{ $row->is_aktif ? __('Nonaktifkan') : __('Aktifkan') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="clock" wire:click="showHistory({{ $row->id }})">
                                                    {{ __('Riwayat') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteSales({{ $row->id }})">
                                                    {{ __('Hapus') }}
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="12" class="py-8 text-center text-zinc-500">
                                        @if ($searchSales || $filterJenis || $filterGrup || $filterAktif)
                                            {{ __('Tidak ada sales yang cocok dengan filter.') }}
                                        @else
                                            {{ __('Belum ada data sales. Klik "Tambah Sales" untuk menambahkan.') }}
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>

            @include('partials.per-page-pagination', ['paginator' => $salesList])
        @endif

        {{-- ============================================= --}}
        {{-- TAB CONTENT: JENIS SALES --}}
        {{-- ============================================= --}}
        @if ($tab === 'jenis')
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:subheading>{{ __('Master jenis sales (mis. internal, eksternal, agen). Dipakai untuk kategorisasi & komisi.') }}</flux:subheading>
                <flux:button variant="primary" icon="plus" wire:click="createJenis" class="self-start sm:self-auto">
                    {{ __('Tambah Jenis') }}
                </flux:button>
            </div>

            <div class="mb-4">
                <flux:input wire:model.live.debounce.300ms="searchJenis" icon="magnifying-glass"
                            :placeholder="__('Cari nama jenis...')" />
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <flux:table.column>{{ __('Nama') }}</flux:table.column>
                        <flux:table.column>{{ __('Keterangan') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Jumlah Sales') }}</flux:table.column>
                        <flux:table.column>{{ __('Diupdate') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($jenisList as $row)
                            <flux:table.row :key="'jenis-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($jenisList->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell variant="strong">{{ $row->nama }}</flux:table.cell>
                                <flux:table.cell class="max-w-md truncate" title="{{ $row->keterangan }}">
                                    {{ $row->keterangan ?: '—' }}
                                </flux:table.cell>
                                <flux:table.cell align="center" class="font-semibold">{{ $row->sales_count }}</flux:table.cell>
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
                                            <flux:menu.item icon="pencil-square" wire:click="editJenis({{ $row->id }})">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteJenis({{ $row->id }})">
                                                {{ __('Hapus') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                    @if ($searchJenis)
                                        {{ __('Tidak ada jenis sales yang cocok.') }}
                                    @else
                                        {{ __('Belum ada jenis sales. Klik "Tambah Jenis".') }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>

            @include('partials.per-page-pagination', ['paginator' => $jenisList])
        @endif

        {{-- ============================================= --}}
        {{-- TAB CONTENT: GROUP SALES --}}
        {{-- ============================================= --}}
        @if ($tab === 'grup')
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:subheading>{{ __('Grup tim sales. Pimpinan diambil dari salah satu sales yang sudah terdaftar.') }}</flux:subheading>
                <flux:button variant="primary" icon="plus" wire:click="createGrup" class="self-start sm:self-auto">
                    {{ __('Tambah Grup') }}
                </flux:button>
            </div>

            <div class="mb-4">
                <flux:input wire:model.live.debounce.300ms="searchGrup" icon="magnifying-glass"
                            :placeholder="__('Cari nama grup...')" />
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('No') }}</flux:table.column>
                        <flux:table.column>{{ __('Nama Grup') }}</flux:table.column>
                        <flux:table.column>{{ __('Pimpinan') }}</flux:table.column>
                        <flux:table.column align="center">{{ __('Jumlah Anggota') }}</flux:table.column>
                        <flux:table.column>{{ __('Diupdate') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($grupList as $row)
                            <flux:table.row :key="'grup-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($grupList->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell variant="strong">{{ $row->nama }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    @if ($row->pimpinan)
                                        <span class="font-mono text-xs text-zinc-400">{{ $row->pimpinan->kode }}</span>
                                        <span class="ml-1">{{ $row->pimpinan->nama }}</span>
                                    @else
                                        <span class="text-zinc-400">{{ __('Belum ditentukan') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="center" class="font-semibold">{{ $row->anggota_count }}</flux:table.cell>
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
                                            <flux:menu.item icon="pencil-square" wire:click="editGrup({{ $row->id }})">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteGrup({{ $row->id }})">
                                                {{ __('Hapus') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                    @if ($searchGrup)
                                        {{ __('Tidak ada grup yang cocok.') }}
                                    @else
                                        {{ __('Belum ada grup sales. Klik "Tambah Grup".') }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>

            @include('partials.per-page-pagination', ['paginator' => $grupList])
        @endif

        {{-- ============================================= --}}
        {{-- MODAL: FORM SALES --}}
        {{-- ============================================= --}}
        <flux:modal name="sales-form" class="md:w-2xl" focusable>
            <form wire:submit="saveSales" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $salesEditId ? __('Edit Sales') : __('Tambah Sales') }}</flux:heading>
                    <flux:subheading>{{ __('Data tenaga sales. Edit ke grup/jenis lain akan dicatat otomatis di riwayat.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Identitas') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Kode Sales') }}</flux:label>
                            <flux:input wire:model="salesKode" readonly
                                        class="bg-zinc-50! font-mono dark:bg-zinc-800!" />
                            <flux:description>{{ __('Otomatis di-generate, tidak bisa diubah.') }}</flux:description>
                            <flux:error name="salesKode" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Nama Sales') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input wire:model="salesNama" required />
                            <flux:error name="salesNama" />
                        </flux:field>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Jenis Sales') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:select wire:model="salesJenisId" required>
                                <flux:select.option value="">{{ __('-- Pilih jenis --') }}</flux:select.option>
                                @foreach ($jenisOptions as $j)
                                    <flux:select.option value="{{ $j->id }}">{{ $j->nama }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="salesJenisId" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Grup Sales') }}</flux:label>
                            <flux:select wire:model="salesGrupId">
                                <flux:select.option value="">{{ __('-- Tanpa grup --') }}</flux:select.option>
                                @foreach ($grupOptions as $g)
                                    <flux:select.option value="{{ $g->id }}">{{ $g->nama }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>{{ __('Opsional. Bisa diatur belakangan dari tab Group Sales.') }}</flux:description>
                            <flux:error name="salesGrupId" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Kontak') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Telepon / HP') }}</flux:label>
                            <flux:input wire:model.blur="salesTelepon" placeholder="08xxxxxxxxxx" />
                            <flux:error name="salesTelepon" />
                        </flux:field>
                    </div>

                    <flux:textarea wire:model="salesAlamat" :label="__('Alamat')" rows="2"
                                   :description="__('Opsional.')" />
                    <flux:error name="salesAlamat" />
                </div>

                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Rekening (untuk transfer komisi)') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Bank') }}</flux:label>
                        <flux:select wire:model="salesBankId">
                            <flux:select.option value="">{{ __('-- Pilih bank --') }}</flux:select.option>
                            @foreach ($bankOptions as $b)
                                <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="salesBankId" />
                    </flux:field>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Nomor Rekening') }}</flux:label>
                            <flux:input wire:model="salesNomorRekening" />
                            <flux:error name="salesNomorRekening" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Nama Pemilik Rekening') }}</flux:label>
                            <flux:input wire:model="salesNamaPemilikRekening" />
                            <flux:description>{{ __('Kosongkan jika sama dengan nama sales.') }}</flux:description>
                            <flux:error name="salesNamaPemilikRekening" />
                        </flux:field>
                    </div>
                </div>

                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="sm" class="text-zinc-500 uppercase tracking-wider">{{ __('Akun DBOS') }}</flux:heading>
                    <flux:subheading class="text-xs">{{ __('Kredensial login untuk aplikasi DBOS (Data Booking Order Sales). Bisa di-set sekarang atau belakangan.') }}</flux:subheading>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Username DBOS') }}</flux:label>
                            <flux:input wire:model="salesDbosUsername" placeholder="budi.santoso" />
                            <flux:description>{{ __('Hanya huruf, angka, titik, underscore, strip. Harus unik antar sales.') }}</flux:description>
                            <flux:error name="salesDbosUsername" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Password DBOS') }}</flux:label>
                            <div class="flex items-center gap-2">
                                <flux:input wire:model="salesDbosPassword"
                                            :type="$salesShowPassword ? 'text' : 'password'"
                                            placeholder="{{ $salesEditId ? __('Kosongkan = tidak diubah') : __('Min. 6 karakter') }}"
                                            class="flex-1" />
                                <flux:button type="button" size="sm" variant="filled"
                                             :icon="$salesShowPassword ? 'eye-slash' : 'eye'"
                                             wire:click="$toggle('salesShowPassword')" />
                            </div>
                            <flux:description>
                                @if ($salesEditId)
                                    {{ __('Biarkan kosong jika tidak ingin mengubah password lama.') }}
                                @else
                                    {{ __('Opsional. Bisa diset belakangan via Edit.') }}
                                @endif
                            </flux:description>
                            <flux:error name="salesDbosPassword" />
                        </flux:field>
                    </div>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <flux:label class="font-semibold">{{ __('Sales Aktif') }}</flux:label>
                                <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                    {{ __('Mengontrol akses sistem secara keseluruhan: non-aktif berarti tidak bisa login DBOS, tidak muncul di pilihan DBOS/SPR/akad baru, dan diperlakukan sebagai resign di laporan komisi.') }}
                                </p>
                            </div>
                            <flux:switch wire:model="salesIsAktif" class="shrink-0" />
                        </div>
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

        {{-- ============================================= --}}
        {{-- MODAL: FORM JENIS SALES --}}
        {{-- ============================================= --}}
        <flux:modal name="jenis-form" class="md:w-lg" focusable>
            <form wire:submit="saveJenis" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $jenisEditId ? __('Edit Jenis Sales') : __('Tambah Jenis Sales') }}</flux:heading>
                    <flux:subheading>{{ __('Kategori jenis sales.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <flux:field>
                    <flux:label>{{ __('Nama') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="jenisNama" required placeholder="In House" />
                    <flux:error name="jenisNama" />
                </flux:field>

                <flux:textarea wire:model="jenisKeterangan" :label="__('Keterangan')" rows="2"
                               :description="__('Opsional.')" />
                <flux:error name="jenisKeterangan" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- ============================================= --}}
        {{-- MODAL: FORM GROUP SALES --}}
        {{-- ============================================= --}}
        <flux:modal name="grup-form" class="md:w-lg" focusable>
            <form wire:submit="saveGrup" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $grupEditId ? __('Edit Grup Sales') : __('Tambah Grup Sales') }}</flux:heading>
                    <flux:subheading>{{ __('Tim sales. Pimpinan bisa diisi setelah anggota dibuat.') }}</flux:subheading>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Field bertanda') }} <span class="ms-1 text-red-500">*</span> {{ __('wajib diisi.') }}
                    </p>
                </div>

                <flux:field>
                    <flux:label>{{ __('Nama Grup') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:input wire:model="grupNama" required placeholder="Tim Alpha" />
                    <flux:error name="grupNama" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Pimpinan') }}</flux:label>
                    <flux:select wire:model="grupPimpinanId">
                        <flux:select.option value="">{{ __('-- Belum ditentukan --') }}</flux:select.option>
                        @foreach ($pimpinanOptions as $s)
                            <flux:select.option value="{{ $s->id }}">{{ $s->kode }} — {{ $s->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description>{{ __('Sales yang menjadi koordinator/leader grup ini. Opsional.') }}</flux:description>
                    <flux:error name="grupPimpinanId" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- ============================================= --}}
        {{-- MODAL: DELETE CONFIRM (shared) --}}
        {{-- ============================================= --}}
        <flux:modal name="sales-delete-confirm" class="md:w-96">
            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
                        <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <flux:heading size="lg">
                            @switch($deleteEntity)
                                @case('sales') {{ __('Hapus Sales?') }} @break
                                @case('jenis') {{ __('Hapus Jenis Sales?') }} @break
                                @case('grup')  {{ __('Hapus Grup Sales?') }} @break
                                @default      {{ __('Hapus?') }}
                            @endswitch
                        </flux:heading>
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

        {{-- ============================================= --}}
        {{-- MODAL: HISTORY PERPINDAHAN --}}
        {{-- ============================================= --}}
        <flux:modal name="sales-history" class="md:w-2xl">
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Riwayat Perpindahan') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Sales:') }} <span class="font-semibold">{{ $historySalesNama ?? '—' }}</span>
                    </flux:subheading>
                </div>

                @php $rows = $this->historyRows; @endphp
                @if ($rows->isEmpty())
                    <div class="rounded-md border-2 border-dashed border-zinc-200 px-4 py-10 text-center text-zinc-500 dark:border-zinc-700">
                        <flux:icon.clock class="mx-auto size-10 text-zinc-400" />
                        <p class="mt-2 text-sm">{{ __('Belum ada perpindahan dicatat untuk sales ini.') }}</p>
                    </div>
                @else
                    <ol class="relative ms-2 space-y-4 border-s border-zinc-200 ps-5 dark:border-zinc-700">
                        @foreach ($rows as $r)
                            <li class="relative">
                                <span class="absolute -start-[26px] mt-1.5 flex h-3 w-3 items-center justify-center rounded-full bg-blue-500 ring-4 ring-white dark:ring-zinc-900"></span>
                                <div class="text-xs text-zinc-500">
                                    {{ $r->created_at?->format('d/m/Y H:i') ?? '—' }}
                                </div>
                                <div class="mt-0.5 text-sm">
                                    <span class="font-semibold">{{ $r->catatan ?? __('Perpindahan') }}</span>
                                </div>
                                <div class="mt-1 space-y-0.5 text-sm">
                                    @if ($r->dari_grup_id !== null || $r->ke_grup_id !== null)
                                        <div>
                                            <span class="text-zinc-500">{{ __('Grup:') }}</span>
                                            <span class="font-medium">{{ $r->dariGrup?->nama ?? __('Tanpa grup') }}</span>
                                            <flux:icon.arrow-right class="inline size-3 text-zinc-400" />
                                            <span class="font-medium">{{ $r->keGrup?->nama ?? __('Tanpa grup') }}</span>
                                        </div>
                                    @endif
                                    @if ($r->dari_jenis !== null || $r->ke_jenis !== null)
                                        <div>
                                            <span class="text-zinc-500">{{ __('Jenis:') }}</span>
                                            <span class="font-medium">{{ $r->dari_jenis ?? '—' }}</span>
                                            <flux:icon.arrow-right class="inline size-3 text-zinc-400" />
                                            <span class="font-medium">{{ $r->ke_jenis ?? '—' }}</span>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>

    </div>
</section>
