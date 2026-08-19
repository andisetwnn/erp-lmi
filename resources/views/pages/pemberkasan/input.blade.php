<?php

use App\Models\Master\Proyek;
use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Input Pemberkasan')] class extends Component
{
    use WithFileUploads, WithPagination;

    #[Url(as: 'proyek')]
    public ?int $selectedProyekId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    #[Url(as: 'bank')]
    public string $filterBank = '';

    #[Url(as: 'sales')]
    public string $filterSales = '';

    #[Url(as: 'akad')]
    public string $filterAkadPeriode = ''; // '' | 'week' | 'month' | 'passed'

    #[Url(as: 'per')]
    public string $perPage = '25';

    // Universal edit-field modal state
    public ?int $editSprId = null;

    public ?int $editPemberkasanId = null;

    /** Field yang sedang di-edit: bank|bm|wcr|sp3k|lpa */
    public ?string $editingField = null;

    /** Nomor SPR untuk title modal. */
    public ?string $editingSprLabel = null;

    // Field values (dynamic based on editingField)
    public ?string $val_bank_kode = null;

    public ?string $val_bm_tanggal = null;

    public ?string $val_wcr_tanggal = null;

    public ?string $val_sp3k_tanggal = null;

    public ?string $val_sp3k_nomor = null;

    public ?string $val_sp3k_expired = null;

    public string $val_sp3k_nominal = '0';

    public ?string $val_lpa_tanggal = null;

    // Upload (per file — dipakai kalau editingField = bm/sp3k/lpa)
    public $uploadFile = null;

    public ?string $existingFileName = null;

    // Inline NPWP edit (modal terpisah)
    public ?int $editNpwpProspectId = null;

    public ?string $editNpwpValue = null;

    public ?string $editNpwpCustomerName = null;

    public function mount(): void
    {
        if (! $this->selectedProyekId && $sessionProyek = session('active_proyek_id')) {
            $this->selectedProyekId = (int) $sessionProyek;
        }
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->selectedProyekId = $proyekId;
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'filterStatus', 'filterBank', 'filterSales', 'filterAkadPeriode', 'perPage', 'selectedProyekId'])) {
            $this->resetPage();
        }
        // Auto-fill sp3k_expired = sp3k_tanggal + 90 hari kalau belum di-set
        if ($property === 'val_sp3k_tanggal' && $this->val_sp3k_tanggal && ! $this->val_sp3k_expired) {
            $this->val_sp3k_expired = \Carbon\Carbon::parse($this->val_sp3k_tanggal)->addDays(90)->toDateString();
        }
        // Auto-format Nominal SP3K dengan separator ribuan (179000000 -> 179.000.000)
        if ($property === 'val_sp3k_nominal') {
            $digits = preg_replace('/\D/', '', (string) $this->val_sp3k_nominal);
            $this->val_sp3k_nominal = $digits !== '' ? number_format((int) $digits, 0, ',', '.') : '';
        }
    }

    /**
     * Buka modal edit field spesifik.
     * $field: bank | bm | wcr | sp3k | lpa (rencana akad diisi dari menu terpisah, bukan di sini)
     */
    public function openField(int $sprId, string $field): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);

        $spr = Spr::with('pemberkasan')->findOrFail($sprId);
        $p = $spr->pemberkasan;

        // Guard: LPA cuma untuk CBN
        if ($field === 'lpa' && $p?->bank_kode !== 'CBN') {
            Flux::toast(variant: 'warning', text: 'LPA hanya wajib untuk Bank BTN (CBN). Pilih bank CBN dulu.');

            return;
        }

        $this->editSprId = $spr->id;
        $this->editPemberkasanId = $p?->id;
        $this->editingField = $field;
        $this->editingSprLabel = $spr->nomor_display;
        $this->uploadFile = null;
        $this->existingFileName = null;

        // Load current value(s) sesuai field
        match ($field) {
            'bank' => $this->val_bank_kode = $p?->bank_kode,
            'bm' => [
                $this->val_bm_tanggal = $p?->bm_tanggal?->toDateString(),
                $this->existingFileName = $p?->bm_file_original_name,
            ],
            'wcr' => $this->val_wcr_tanggal = $p?->wcr_tanggal?->toDateString(),
            'sp3k' => [
                $this->val_sp3k_tanggal = $p?->sp3k_tanggal?->toDateString(),
                $this->val_sp3k_nomor = $p?->sp3k_nomor,
                $this->val_sp3k_expired = $p?->sp3k_expired?->toDateString(),
                $this->val_sp3k_nominal = $p && (float) $p->sp3k_nominal > 0
                    ? number_format((float) $p->sp3k_nominal, 0, ',', '.')
                    : '',
            ],
            'lpa' => $this->val_lpa_tanggal = $p?->lpa_tanggal?->toDateString(),
            default => null,
        };

        $this->resetErrorBag();
        Flux::modal('edit-field')->show();
    }

    public function simpanField(): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);

        // Validate per field. Upload berkas hanya untuk BM (Berkas Masuk).
        $rules = match ($this->editingField) {
            'bank' => ['val_bank_kode' => ['required', 'in:CBN,BSN,NBU,BCA']],
            'bm' => ['val_bm_tanggal' => ['required', 'date'], 'uploadFile' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png']],
            'wcr' => ['val_wcr_tanggal' => ['required', 'date']],
            'sp3k' => [
                'val_sp3k_tanggal' => ['required', 'date'],
                'val_sp3k_nomor' => ['nullable', 'string', 'max:50'],
                'val_sp3k_expired' => ['nullable', 'date'],
                'val_sp3k_nominal' => ['required', 'string'],
            ],
            'lpa' => ['val_lpa_tanggal' => ['required', 'date']],
            default => [],
        };
        $this->validate($rules);

        $spr = Spr::findOrFail($this->editSprId);

        // Prep data field yang berubah
        $data = ['updated_by_user_id' => Auth::id()];
        $dir = "pemberkasan/{$spr->id}";
        match ($this->editingField) {
            'bank' => $data['bank_kode'] = $this->val_bank_kode,
            'bm' => [
                $data['bm_tanggal'] = $this->val_bm_tanggal,
                $this->uploadFile ? ($data['bm_file_path'] = $this->uploadFile->store($dir, 'private'))
                    && ($data['bm_file_original_name'] = $this->uploadFile->getClientOriginalName()) : null,
            ],
            'wcr' => $data['wcr_tanggal'] = $this->val_wcr_tanggal,
            'sp3k' => [
                $data['sp3k_tanggal'] = $this->val_sp3k_tanggal,
                $data['sp3k_nomor'] = $this->val_sp3k_nomor,
                $data['sp3k_expired'] = $this->val_sp3k_expired,
                // Strip dot separator sebelum cast ("179.000.000" -> 179000000)
                $data['sp3k_nominal'] = (float) preg_replace('/\D/', '', (string) $this->val_sp3k_nominal),
            ],
            'lpa' => $data['lpa_tanggal'] = $this->val_lpa_tanggal,
            default => null,
        };

        if ($this->editPemberkasanId) {
            SprPemberkasan::findOrFail($this->editPemberkasanId)->update($data);
        } else {
            $data['spr_id'] = $spr->id;
            $data['input_by_user_id'] = Auth::id();
            SprPemberkasan::create($data);
        }

        Flux::modal('edit-field')->close();
        Flux::toast(variant: 'success', text: "SPR {$spr->nomor_display} — {$this->fieldLabel()} disimpan.");
        $this->resetFieldState();
    }

    protected function fieldLabel(): string
    {
        return match ($this->editingField) {
            'bank' => 'Bank',
            'bm' => 'Berkas Lengkap',
            'wcr' => 'Wawancara',
            'sp3k' => 'SP3K',
            'lpa' => 'LPA',
            default => '',
        };
    }

    protected function resetFieldState(): void
    {
        $this->reset([
            'editSprId', 'editPemberkasanId', 'editingField', 'editingSprLabel',
            'val_bank_kode', 'val_bm_tanggal', 'val_wcr_tanggal',
            'val_sp3k_tanggal', 'val_sp3k_nomor', 'val_sp3k_expired', 'val_sp3k_nominal',
            'val_lpa_tanggal',
            'uploadFile', 'existingFileName',
        ]);
    }

    public function openEditNpwp(int $prospectId, ?string $currentNpwp, ?string $customerName = null): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);
        $this->editNpwpProspectId = $prospectId;
        $this->editNpwpValue = $currentNpwp ?? '';
        $this->editNpwpCustomerName = $customerName;
        Flux::modal('edit-npwp')->show();
    }

    public function saveNpwp(): void
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola'), 403);
        $this->validate([
            'editNpwpValue' => ['nullable', 'string', 'max:30'],
        ]);
        \App\Models\Master\ProspectCustomer::where('id', $this->editNpwpProspectId)
            ->update(['npwp' => $this->editNpwpValue ?: null]);
        Flux::modal('edit-npwp')->close();
        Flux::toast(variant: 'success', text: 'NPWP customer diperbarui.');
        $this->reset(['editNpwpProspectId', 'editNpwpValue', 'editNpwpCustomerName']);
    }

    /** Download berkas BM (satu-satunya yg punya upload). */
    public function downloadBmFile(int $pemberkasanId): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(Auth::user()?->can('pemberkasan.kelola') || Auth::user()?->can('pemberkasan.lihat'), 403);
        $p = SprPemberkasan::findOrFail($pemberkasanId);
        if (! $p->bm_file_path || ! Storage::disk('private')->exists($p->bm_file_path)) {
            Flux::toast(variant: 'warning', text: 'File tidak ditemukan.');

            return null;
        }

        return Storage::disk('private')->download($p->bm_file_path, $p->bm_file_original_name ?? basename($p->bm_file_path));
    }

    public function resetAllFilters(): void
    {
        $this->reset(['search', 'filterStatus', 'filterBank', 'filterSales', 'filterAkadPeriode']);
        $this->resetPage();
    }

    public function with(): array
    {
        $q = Spr::query()
            ->with(['prospectCustomer:id,nama_lengkap,nik,npwp', 'sales:id,nama',
                'rumah:id,proyek_id,blok,nomor_unit', 'pemberkasan',
                'realisasiPembayaran:id,spr_id,jenis,jumlah'])
            ->whereIn('status', ['approved', 'akad'])
            ->where('jenis_pembayaran', 'kpr');

        if ($this->selectedProyekId) {
            $q->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->selectedProyekId));
        }

        if ($this->search !== '') {
            $s = "%{$this->search}%";
            $q->where(function ($qq) use ($s) {
                $qq->where('nomor_spr', 'like', $s)
                    ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', $s)->orWhere('nik', 'like', $s))
                    ->orWhereHas('rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", [$s]));
            });
        }

        // Filter bank
        if ($this->filterBank !== '') {
            if ($this->filterBank === '__EMPTY__') {
                $q->where(function ($qq) {
                    $qq->whereDoesntHave('pemberkasan')
                        ->orWhereHas('pemberkasan', fn ($p) => $p->whereNull('bank_kode'));
                });
            } else {
                $q->whereHas('pemberkasan', fn ($p) => $p->where('bank_kode', $this->filterBank));
            }
        }

        // Filter sales
        if ($this->filterSales !== '') {
            $q->where('sales_id', $this->filterSales);
        }

        // Filter status tahapan (per stage)
        match ($this->filterStatus) {
            'belum_berkas' => $q->where(function ($qq) {
                $qq->whereDoesntHave('pemberkasan')
                    ->orWhereHas('pemberkasan', fn ($p) => $p->whereNull('bm_tanggal'));
            }),
            'belum_wawancara' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('bm_tanggal')
                ->whereNull('wcr_tanggal')),
            'belum_sp3k' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('wcr_tanggal')
                ->whereNull('sp3k_tanggal')),
            'sudah_sp3k' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('sp3k_tanggal')),
            'expiring' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('sp3k_expired')
                ->whereBetween('sp3k_expired', [now(), now()->addDays(30)])),
            'sp3k_expired' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('sp3k_expired')
                ->where('sp3k_expired', '<', now())),
            'lpa_wajib' => $q->whereHas('pemberkasan', fn ($p) => $p->where('bank_kode', 'CBN')
                ->whereNotNull('sp3k_tanggal')->whereNull('lpa_tanggal')),
            'akad_diset' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('rencana_akad_tanggal')),
            default => null,
        };

        // Filter rencana akad periode
        match ($this->filterAkadPeriode) {
            'week' => $q->whereHas('pemberkasan', fn ($p) => $p->whereBetween('rencana_akad_tanggal', [now(), now()->addDays(7)])),
            'month' => $q->whereHas('pemberkasan', fn ($p) => $p->whereBetween('rencana_akad_tanggal', [now(), now()->addDays(30)])),
            'passed' => $q->whereHas('pemberkasan', fn ($p) => $p->whereNotNull('rencana_akad_tanggal')
                ->where('rencana_akad_tanggal', '<', now())),
            default => null,
        };

        return [
            'sprs' => $q->orderByDesc('tanggal_spr')->orderByDesc('id')->paginate((int) $this->perPage),
            'proyekList' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']),
            'salesList' => \App\Models\Master\Sales::where('is_aktif', true)->orderBy('nama')->get(['id', 'nama']),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                    <flux:icon.folder-open class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Input Pemberkasan') }}</flux:heading>
                </div>
            </div>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            {{-- Row 1: Search + Reset + Per Page --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-64 flex-1">
                    <flux:input wire:model.live.debounce.300ms="search" placeholder="Cari SPR / customer / NIK / blok..." icon="magnifying-glass" />
                </div>
                @if ($search || $filterStatus || $filterBank || $filterSales || $filterAkadPeriode)
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="resetAllFilters">Reset</flux:button>
                @endif
                <flux:select wire:model.live="perPage" size="sm">
                    <flux:select.option value="25">25 baris</flux:select.option>
                    <flux:select.option value="50">50 baris</flux:select.option>
                    <flux:select.option value="100">100 baris</flux:select.option>
                    <flux:select.option value="500">500 baris</flux:select.option>
                </flux:select>
            </div>

            {{-- Row 2: Dropdown filters --}}
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-40">
                    <flux:select wire:model.live="filterBank" placeholder="Semua Bank" size="sm">
                        <flux:select.option value="">Semua Bank</flux:select.option>
                        <flux:select.option value="__EMPTY__">— (Belum di-set)</flux:select.option>
                        @foreach (\App\Models\Master\SprPemberkasan::BANK_OPTIONS as $kode => $label)
                            <flux:select.option value="{{ $kode }}">{{ $kode }} — {{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="min-w-40">
                    <flux:select wire:model.live="filterSales" placeholder="Semua Sales" size="sm">
                        <flux:select.option value="">Semua Sales</flux:select.option>
                        @foreach ($salesList as $sl)
                            <flux:select.option value="{{ $sl->id }}">{{ $sl->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="min-w-48">
                    <flux:select wire:model.live="filterStatus" placeholder="Semua Status Tahap" size="sm">
                        <flux:select.option value="">Semua Status Tahap</flux:select.option>
                        <flux:select.option value="belum_berkas">Belum Berkas Lengkap</flux:select.option>
                        <flux:select.option value="belum_wawancara">Sudah Berkas / Belum Wawancara</flux:select.option>
                        <flux:select.option value="belum_sp3k">Sudah Wawancara / Belum SP3K</flux:select.option>
                        <flux:select.option value="sudah_sp3k">Sudah SP3K</flux:select.option>
                        <flux:select.option value="expiring">SP3K Expiring ≤ 30h</flux:select.option>
                        <flux:select.option value="sp3k_expired">SP3K Sudah Expired</flux:select.option>
                        <flux:select.option value="lpa_wajib">LPA Wajib (BTN — belum diisi)</flux:select.option>
                        <flux:select.option value="akad_diset">Rencana Akad Sudah Diset</flux:select.option>
                    </flux:select>
                </div>
                <div class="min-w-44">
                    <flux:select wire:model.live="filterAkadPeriode" placeholder="Rencana Akad" size="sm">
                        <flux:select.option value="">Rencana Akad — Semua</flux:select.option>
                        <flux:select.option value="week">Minggu Ini (7h)</flux:select.option>
                        <flux:select.option value="month">Bulan Ini (30h)</flux:select.option>
                        <flux:select.option value="passed">Sudah Lewat</flux:select.option>
                    </flux:select>
                </div>
            </div>
        </div>

        @if (! $selectedProyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                Pilih proyek dulu di picker atas untuk menampilkan data pemberkasan.
            </div>
        @else
            {{-- TABLE — setiap cell editable (klik langsung) --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            {{-- Row 1: Group category --}}
                            <tr class="border-b border-zinc-200 bg-zinc-100 text-center uppercase text-[10px] font-bold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                <th colspan="5" class="px-3 py-1.5">Identitas</th>
                                <th colspan="5" class="border-l border-zinc-300 px-3 py-1.5 dark:border-zinc-600">Harga &amp; UM</th>
                                <th colspan="3" class="border-l border-zinc-300 px-3 py-1.5 dark:border-zinc-600">KPR</th>
                                <th colspan="6" class="border-l border-zinc-300 px-3 py-1.5 dark:border-zinc-600">Tahapan Pemberkasan</th>
                            </tr>
                            {{-- Row 2: Detail column --}}
                            <tr class="border-b border-zinc-200 bg-zinc-50 text-left uppercase text-[10px] font-semibold text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                                <th class="px-3 py-2.5">SPR</th>
                                <th class="px-3 py-2.5">Blok</th>
                                <th class="px-3 py-2.5">Customer</th>
                                <th class="px-3 py-2.5">NPWP</th>
                                <th class="px-3 py-2.5">Sales</th>
                                <th class="border-l border-zinc-300 px-3 py-2.5 text-right dark:border-zinc-600">Harga Jual AJB</th>
                                <th class="px-3 py-2.5 text-right">Harga All In</th>
                                <th class="px-3 py-2.5 text-right">Besaran UM</th>
                                <th class="px-3 py-2.5 text-right">UM Dibayar</th>
                                <th class="px-3 py-2.5 text-right">%</th>
                                <th class="border-l border-zinc-300 px-3 py-2.5 dark:border-zinc-600">Bank</th>
                                <th class="px-3 py-2.5 text-right">Pengajuan KPR</th>
                                <th class="px-3 py-2.5 text-right">Setuju Bank</th>
                                <th class="border-l border-zinc-300 px-3 py-2.5 dark:border-zinc-600">Berkas Lengkap</th>
                                <th class="px-3 py-2.5">Wawancara</th>
                                <th class="px-3 py-2.5">SP3K</th>
                                <th class="px-3 py-2.5">LPA</th>
                                <th class="px-3 py-2.5">Rencana Akad</th>
                                <th class="px-3 py-2.5">Akad</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @php
                                $fmt = fn ($v) => number_format((float) $v, 0, ',', '.');
                                $canEdit = Auth::user()?->can('pemberkasan.kelola');
                                $editableCell = 'group relative cursor-pointer transition hover:bg-blue-50 dark:hover:bg-blue-950/20';
                                $editPencil = '<svg xmlns="http://www.w3.org/2000/svg" class="ml-1 inline size-3 opacity-0 group-hover:opacity-60" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>';
                            @endphp
                            @forelse ($sprs as $s)
                                @php
                                    $p = $s->pemberkasan;
                                    $sp3kExpIn = $p?->sp3kExpiredIn();
                                    $sp3kCls = match (true) {
                                        $sp3kExpIn === null => '',
                                        $sp3kExpIn < 0 => 'text-rose-700 bg-rose-50 dark:text-rose-400 dark:bg-rose-950/30',
                                        $sp3kExpIn <= 7 => 'text-rose-700 bg-rose-50 dark:text-rose-400 dark:bg-rose-950/30',
                                        $sp3kExpIn <= 14 => 'text-orange-700 bg-orange-50 dark:text-orange-400 dark:bg-orange-950/30',
                                        $sp3kExpIn <= 30 => 'text-amber-700 bg-amber-50 dark:text-amber-400 dark:bg-amber-950/30',
                                        default => 'text-zinc-700',
                                    };
                                    // Progress UM (BF + UM) — bantu admin cek DP sebelum acc SP3K
                                    $umTarget = (float) $s->um_net;
                                    $umTerbayar = (float) $s->realisasiPembayaran->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
                                    $umPersen = $umTarget > 0 ? round($umTerbayar / $umTarget * 100, 1) : 0;
                                    $umPersenCls = match (true) {
                                        $umPersen >= 100 => 'text-emerald-700 dark:text-emerald-400',
                                        $umPersen >= 50 => 'text-blue-700 dark:text-blue-400',
                                        $umPersen > 0 => 'text-amber-700 dark:text-amber-400',
                                        default => 'text-zinc-400',
                                    };
                                @endphp
                                <tr>
                                    {{-- SPR (link ke detail) --}}
                                    <td class="whitespace-nowrap px-3 py-2 font-mono">
                                        <a href="{{ route('marketing.spr.show', $s->id) }}" class="text-blue-600 hover:underline dark:text-blue-400" wire:navigate>
                                            {{ $s->nomor_display }}
                                        </a>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono">{{ $s->rumah?->kode_unit }}</td>
                                    <td class="px-3 py-2">{{ $s->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                    {{-- NPWP: auto keisi dari master customer, admin bisa edit inline kalau kosong / mau update --}}
                                    <td @class(['whitespace-nowrap px-3 py-2 font-mono', $editableCell => $canEdit && $s->prospect_customer_id])
                                        @if ($canEdit && $s->prospect_customer_id)
                                            wire:click="openEditNpwp({{ $s->prospect_customer_id }}, '{{ addslashes((string) ($s->prospectCustomer?->npwp ?? '')) }}', '{{ addslashes((string) ($s->prospectCustomer?->nama_lengkap ?? '')) }}')"
                                            title="Klik untuk edit NPWP"
                                        @endif>
                                        {{ $s->prospectCustomer?->npwp ?: '—' }}
                                        @if ($canEdit && $s->prospect_customer_id) {!! $editPencil !!} @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $s->sales?->nama ?? '—' }}</td>

                                    {{-- Harga Jual AJB (read-only dari spr.harga_jual) — awal grup Harga & UM --}}
                                    <td class="whitespace-nowrap border-l border-zinc-200 px-3 py-2 text-right font-mono tabular-nums dark:border-zinc-700">{{ $fmt($s->harga_jual) }}</td>

                                    {{-- Harga All In (read-only dari spr.total_harga) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums font-semibold">{{ $fmt($s->total_harga) }}</td>

                                    {{-- Besaran UM (target UM dari spr.um_net) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">{{ $umTarget > 0 ? $fmt($umTarget) : '—' }}</td>

                                    {{-- UM Sudah Dibayar (jumlah realisasi BF + UM) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums {{ $umTerbayar > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-400' }}">{{ $umTerbayar > 0 ? $fmt($umTerbayar) : '—' }}</td>

                                    {{-- Persen progress UM --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums font-semibold {{ $umPersenCls }}">
                                        {{ $umTarget > 0 ? number_format($umPersen, 1, ',', '.').'%' : '—' }}
                                    </td>

                                    {{-- Bank (editable) — awal grup KPR --}}
                                    <td @class(['whitespace-nowrap border-l border-zinc-200 px-3 py-2 dark:border-zinc-700', $editableCell => $canEdit])
                                        @if ($canEdit) wire:click="openField({{ $s->id }}, 'bank')" title="Klik untuk pilih bank" @endif>
                                        @if ($p?->bank_kode)
                                            <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ $p->bank_kode }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                        @if ($canEdit) {!! $editPencil !!} @endif
                                    </td>

                                    {{-- Pengajuan KPR (spr.nilai_kpr) --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($s->nilai_kpr) }}</td>

                                    {{-- KPR Setuju Bank (nominal SP3K — pindah ke grup KPR) --}}
                                    <td @class(['whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums', $editableCell => $canEdit])
                                        @if ($canEdit) wire:click="openField({{ $s->id }}, 'sp3k')" title="Klik untuk edit nominal SP3K" @endif>
                                        {{ $p && (float) $p->sp3k_nominal > 0 ? $fmt($p->sp3k_nominal) : '—' }}
                                        @if ($canEdit) {!! $editPencil !!} @endif
                                    </td>

                                    {{-- Berkas Lengkap (editable) — awal grup Tahapan Pemberkasan --}}
                                    <td @class(['whitespace-nowrap border-l border-zinc-200 px-3 py-2 dark:border-zinc-700', $editableCell => $canEdit])
                                        @if ($canEdit) wire:click="openField({{ $s->id }}, 'bm')" title="Klik untuk input berkas lengkap" @endif>
                                        @if (! $p?->bm_tanggal)
                                            <span class="text-zinc-400">—</span>
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @else
                                            <span>{{ $p->bm_tanggal?->format('d/m/y') }}</span>
                                            @if ($p->bm_file_path)
                                                <button type="button" wire:click.stop="downloadBmFile({{ $p->id }})" title="{{ $p->bm_file_original_name }}" class="ml-1 text-blue-500 hover:text-blue-700">
                                                    <flux:icon.paper-clip class="inline size-3" />
                                                </button>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @endif
                                    </td>

                                    {{-- WCR (editable) --}}
                                    <td @class(['whitespace-nowrap px-3 py-2', $editableCell => $canEdit])
                                        @if ($canEdit) wire:click="openField({{ $s->id }}, 'wcr')" title="Klik untuk input Wawancara" @endif>
                                        @if ($p?->wcr_tanggal)
                                            {{ $p->wcr_tanggal->format('d/m/y') }}
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @else
                                            <span class="text-zinc-400">—</span>
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @endif
                                    </td>

                                    {{-- SP3K (editable) — tanggal + expired badge --}}
                                    <td @class(['whitespace-nowrap px-3 py-2', $editableCell => $canEdit])
                                        @if ($canEdit) wire:click="openField({{ $s->id }}, 'sp3k')" title="Klik untuk input SP3K" @endif>
                                        @if ($p?->sp3k_tanggal)
                                            <div>{{ $p->sp3k_tanggal->format('d/m/y') }}</div>
                                            @if ($p->sp3k_expired && $sp3kExpIn !== null)
                                                <div class="text-[10px] rounded px-1 inline-block {{ $sp3kCls }}">
                                                    @if ($sp3kExpIn < 0)
                                                        Expired {{ abs($sp3kExpIn) }}h lalu
                                                    @else
                                                        Exp {{ $sp3kExpIn }}h lagi
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @else
                                            <span class="text-zinc-400">—</span>
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @endif
                                    </td>

                                    {{-- LPA — cuma untuk CBN (editable) --}}
                                    <td @class(['whitespace-nowrap px-3 py-2', $editableCell => $canEdit && $p?->bank_kode === 'CBN'])
                                        @if ($canEdit && $p?->bank_kode === 'CBN') wire:click="openField({{ $s->id }}, 'lpa')" title="Klik untuk input LPA" @endif>
                                        @if ($p?->bank_kode === 'CBN')
                                            @if ($p->lpa_tanggal)
                                                {{ $p->lpa_tanggal->format('d/m/y') }}
                                            @else
                                                <span class="text-zinc-400">—</span>
                                            @endif
                                            @if ($canEdit) {!! $editPencil !!} @endif
                                        @else
                                            <span class="text-zinc-400" title="LPA hanya untuk BTN (CBN)">N/A</span>
                                        @endif
                                    </td>

                                    {{-- Rencana Akad — READONLY (auto-fill dari menu Rencana Akad, dibuat terpisah) --}}
                                    <td class="whitespace-nowrap px-3 py-2" title="Diisi otomatis dari menu Rencana Akad">
                                        @if ($p?->rencana_akad_tanggal)
                                            {{ $p->rencana_akad_tanggal->format('d/m/y') }}
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>

                                    {{-- Akad — READONLY (auto-fill dari SPR.tgl_akad setelah akad AJB dilakukan) --}}
                                    <td class="whitespace-nowrap px-3 py-2" title="Diisi otomatis dari tanggal akad AJB di SPR">
                                        @if ($s->tgl_akad)
                                            <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $s->tgl_akad->format('d/m/y') }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="19" class="px-4 py-12 text-center text-zinc-400">
                                        Tidak ada SPR KPR yang butuh pemberkasan di proyek ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    {{ $sprs->links() }}
                </div>
            </div>
        @endif
    </div>

    {{-- MODAL EDIT PER FIELD --}}
    <flux:modal name="edit-field" class="md:w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Edit {{ $this->fieldLabel() }}</flux:heading>
                <flux:subheading>SPR {{ $editingSprLabel }}</flux:subheading>
            </div>

            {{-- BANK --}}
            @if ($editingField === 'bank')
                <flux:select wire:model="val_bank_kode" label="Bank KPR" required>
                    <flux:select.option value="">-- Pilih Bank --</flux:select.option>
                    @foreach (\App\Models\Master\SprPemberkasan::BANK_OPTIONS as $kode => $label)
                        <flux:select.option value="{{ $kode }}">{{ $kode }} — {{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                @error('val_bank_kode') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            @endif

            {{-- Berkas Lengkap --}}
            @if ($editingField === 'bm')
                <flux:input type="date" wire:model="val_bm_tanggal" label="Tanggal Berkas Lengkap Diterima" required />
                @error('val_bm_tanggal') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
                <div>
                    <label class="mb-1 block text-sm font-medium">Upload Berkas Lengkap (PDF/JPG, max 5MB, opsional)</label>
                    <input type="file" wire:model="uploadFile" accept=".pdf,.jpg,.jpeg,.png" class="text-xs" />
                    @if ($existingFileName)
                        <div class="mt-1 text-[11px] text-zinc-600 dark:text-zinc-400">
                            <flux:icon.paper-clip class="inline size-3" /> {{ $existingFileName }} (upload baru akan replace)
                        </div>
                    @endif
                    @error('uploadFile') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
                </div>
            @endif

            {{-- WCR --}}
            @if ($editingField === 'wcr')
                <flux:input type="date" wire:model="val_wcr_tanggal" label="Tanggal Wawancara" required />
                @error('val_wcr_tanggal') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            @endif

            {{-- SP3K (tanpa upload — cuma tanggal + nomor + expired + nominal) --}}
            @if ($editingField === 'sp3k')
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <flux:input type="date" wire:model.live="val_sp3k_tanggal" label="Tanggal SP3K" required />
                    <flux:input wire:model="val_sp3k_nomor" label="Nomor SP3K" placeholder="opsional" />
                    <flux:input type="date" wire:model="val_sp3k_expired" label="Expired (auto +90h)" />
                    <flux:input wire:model.live.debounce.300ms="val_sp3k_nominal" label="Nominal KPR Disetujui (Rp)" placeholder="0" required inputmode="numeric" description="Ketik angka saja, separator dot otomatis." />
                </div>
            @endif

            {{-- LPA (tanpa upload — cuma tanggal) --}}
            @if ($editingField === 'lpa')
                <flux:input type="date" wire:model="val_lpa_tanggal" label="Tanggal LPA" required />
                @error('val_lpa_tanggal') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            @endif

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanField">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL EDIT NPWP CUSTOMER --}}
    <flux:modal name="edit-npwp" class="md:w-md">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Edit NPWP Customer</flux:heading>
                @if ($editNpwpCustomerName)
                    <flux:subheading>{{ $editNpwpCustomerName }}</flux:subheading>
                @endif
            </div>
            <flux:input wire:model="editNpwpValue" label="NPWP" placeholder="16 digit atau kosong" maxlength="30" />
            <flux:description>Perubahan akan langsung ter-update di master Customer.</flux:description>
            @error('editNpwpValue') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveNpwp">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
