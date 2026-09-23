<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\RumahProgresLog;
use App\Models\Master\Subcon;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Data Rumah — Teknik')] class extends Component
{
    use WithPagination;

    #[Url(as: 'proyek')]
    public ?int $selectedProyekId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'blok')]
    public string $filterBlok = '';

    #[Url(as: 'pmin')]
    public int $progresMin = 0;

    #[Url(as: 'pmax')]
    public int $progresMax = 100;

    #[Url(as: 'per')]
    public string $perPage = '25';

    // Modal update state
    public ?int $editId = null;

    public ?string $editKode = null;

    public int $val_progres = 0;

    public ?int $val_lot = null;

    /** Subkontraktor pembangun. Sementara ikut di sini sampai Teknik punya modulnya sendiri. */
    public ?int $val_subcon_id = null;

    /** @var array<int, int> id rumah yang dicentang untuk diubah sekaligus */
    public array $terpilih = [];

    public ?int $massal_subcon_id = null;

    public ?int $massal_progres = null;

    /**
     * LOT diisi BERURUTAN dari nomor ini, bukan nilai yang sama untuk semua.
     * LOT adalah nomor sertifikat — menyeragamkannya akan membuat data ganda.
     */
    public ?int $massal_lot_mulai = null;

    public string $val_catatan = '';

    // Drawer history log
    public ?int $logRumahId = null;

    public function mount(): void
    {
        if (! $this->selectedProyekId && $sess = session('active_proyek_id')) {
            $this->selectedProyekId = (int) $sess;
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
        if (in_array($property, ['search', 'filterBlok', 'progresMin', 'progresMax', 'perPage', 'selectedProyekId'])) {
            // Guard: min tidak boleh melebihi max — auto adjust
            if ($this->progresMin > $this->progresMax) {
                if ($property === 'progresMin') {
                    $this->progresMax = $this->progresMin;
                } else {
                    $this->progresMin = $this->progresMax;
                }
            }
            $this->resetPage();
        }
    }

    public function bersihkanPilihan(): void
    {
        $this->terpilih = [];
    }

    /** Centang semua unit yang sedang tampil di halaman ini. */
    public function pilihSemuaHalaman(array $ids): void
    {
        $this->terpilih = array_values(array_unique(array_merge($this->terpilih, $ids)));
    }

    public function openMassal(): void
    {
        abort_unless(Auth::user()?->can('teknik.rumah.update'), 403);

        if (! $this->terpilih) {
            Flux::toast(variant: 'warning', text: 'Belum ada unit yang dipilih.');

            return;
        }

        $this->reset(['massal_subcon_id', 'massal_progres', 'massal_lot_mulai']);
        $this->val_catatan = '';
        $this->resetErrorBag();
        Flux::modal('update-massal')->show();
    }

    /**
     * Ubah beberapa unit sekaligus. Isian yang dikosongkan tidak menimpa apa pun —
     * jadi bisa memperbarui subcont saja tanpa menyentuh progres.
     */
    public function simpanMassal(): void
    {
        abort_unless(Auth::user()?->can('teknik.rumah.update'), 403);

        $this->validate([
            'massal_subcon_id' => ['nullable', 'exists:subcon,id'],
            'massal_progres' => ['nullable', 'integer', 'min:0', 'max:100'],
            'massal_lot_mulai' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'val_catatan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'massal_subcon_id' => 'subcon',
            'massal_progres' => 'progres fisik',
            'massal_lot_mulai' => 'LOT mulai dari',
        ]);

        if ($this->massal_subcon_id === null && $this->massal_progres === null && $this->massal_lot_mulai === null) {
            Flux::toast(variant: 'warning', text: 'Tidak ada yang diisi — tidak ada yang diubah.');

            return;
        }

        // Urutkan seperti tampilan supaya penomoran LOT mengikuti urutan blok-unit.
        $daftar = Rumah::whereIn('id', $this->terpilih)
            ->orderBy('blok')->orderBy('nomor_unit')
            ->get();

        $lot = $this->massal_lot_mulai;
        $jumlahLog = 0;

        DB::transaction(function () use ($daftar, &$lot, &$jumlahLog) {
            foreach ($daftar as $r) {
                $ubah = [];

                if ($this->massal_subcon_id !== null) {
                    $ubah['subcon_id'] = $this->massal_subcon_id;
                }

                if ($this->massal_lot_mulai !== null) {
                    $ubah['lot'] = $lot++;
                }

                $progresLama = (int) $r->progres_fisik;

                if ($this->massal_progres !== null) {
                    $ubah['progres_fisik'] = $this->massal_progres;
                }

                $r->update($ubah);

                // Jejak audit hanya untuk progres yang benar-benar berubah.
                if ($this->massal_progres !== null && $progresLama !== $this->massal_progres) {
                    RumahProgresLog::create([
                        'rumah_id' => $r->id,
                        'progres_dari' => $progresLama,
                        'progres_ke' => $this->massal_progres,
                        'catatan' => $this->val_catatan ?: null,
                        'updated_by_user_id' => Auth::id(),
                        'created_at' => now(),
                    ]);
                    $jumlahLog++;
                }
            }
        });

        Flux::modal('update-massal')->close();
        Flux::toast(variant: 'success', text: $daftar->count().' unit diperbarui.');

        $this->reset(['terpilih', 'massal_subcon_id', 'massal_progres', 'massal_lot_mulai', 'val_catatan']);
    }

    public function openUpdate(int $rumahId): void
    {
        abort_unless(Auth::user()?->can('teknik.rumah.update'), 403);

        $r = Rumah::findOrFail($rumahId);
        $this->editId = $r->id;
        $this->editKode = $r->kode_unit;
        $this->val_progres = (int) $r->progres_fisik;
        $this->val_lot = $r->lot;
        $this->val_subcon_id = $r->subcon_id;
        $this->val_catatan = '';
        $this->resetErrorBag();
        Flux::modal('update-progres')->show();
    }

    public function simpanProgres(): void
    {
        abort_unless(Auth::user()?->can('teknik.rumah.update'), 403);

        $this->validate([
            'val_progres' => ['required', 'integer', 'min:0', 'max:100'],
            'val_lot' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'val_subcon_id' => ['nullable', 'exists:subcon,id'],
            'val_catatan' => ['nullable', 'string', 'max:500'],
        ]);

        $r = Rumah::findOrFail($this->editId);
        $progresLama = (int) $r->progres_fisik;
        $progresBaru = (int) $this->val_progres;

        $r->update([
            'progres_fisik' => $progresBaru,
            'lot' => $this->val_lot,
            'subcon_id' => $this->val_subcon_id,
        ]);

        // Log entry hanya kalau progres benar-benar berubah
        if ($progresLama !== $progresBaru) {
            RumahProgresLog::create([
                'rumah_id' => $r->id,
                'progres_dari' => $progresLama,
                'progres_ke' => $progresBaru,
                'catatan' => $this->val_catatan ?: null,
                'updated_by_user_id' => Auth::id(),
                'created_at' => now(),
            ]);
        }

        Flux::modal('update-progres')->close();
        Flux::toast(variant: 'success', text: "Progres {$this->editKode} diperbarui.");
        $this->reset(['editId', 'editKode', 'val_progres', 'val_lot', 'val_subcon_id', 'val_catatan']);
    }

    public function openLog(int $rumahId): void
    {
        $this->logRumahId = $rumahId;
        Flux::modal('history-log')->show();
    }

    public function resetAllFilters(): void
    {
        $this->reset(['search', 'filterBlok', 'progresMin', 'progresMax']);
        $this->resetPage();
    }

    /** Preset quick-pick — set range slider ke rentang tertentu. */
    public function setPreset(string $preset): void
    {
        [$this->progresMin, $this->progresMax] = match ($preset) {
            'belum' => [0, 0],
            'proses' => [1, 99],
            'selesai' => [100, 100],
            default => [0, 100],
        };
        $this->resetPage();
    }

    public function with(): array
    {
        $baseQuery = fn () => Rumah::query()
            ->when($this->selectedProyekId, fn ($q) => $q->where('proyek_id', $this->selectedProyekId))
            ->when($this->search !== '', function ($q) {
                $s = "%{$this->search}%";
                $q->where(function ($qq) use ($s) {
                    $qq->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", [$s])
                        ->orWhere('lot', 'like', $s)
                        ->orWhereHas('subcon', fn ($c) => $c->where('nama', 'like', $s));
                });
            })
            ->when($this->filterBlok !== '', fn ($q) => $q->where('blok', $this->filterBlok));

        // Count per kategori progres — untuk chip filter (ignore filterProgres itu sendiri)
        $counts = $baseQuery()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN progres_fisik = 0 THEN 1 ELSE 0 END) as belum,
                SUM(CASE WHEN progres_fisik BETWEEN 1 AND 99 THEN 1 ELSE 0 END) as proses,
                SUM(CASE WHEN progres_fisik = 100 THEN 1 ELSE 0 END) as selesai
            ')->first();

        // Query dgn filter progres applied untuk listing (range slider min-max)
        $q = $baseQuery()->with(['tipeRumah:id,nama_tipe,luas_bangunan,luas_tanah', 'progresUpdatedBy:id,name', 'subcon:id,nama']);
        $q->whereBetween('progres_fisik', [$this->progresMin, $this->progresMax]);

        $blokList = Rumah::query()
            ->when($this->selectedProyekId, fn ($q) => $q->where('proyek_id', $this->selectedProyekId))
            ->distinct()->orderBy('blok')->pluck('blok');

        return [
            'rumahs' => $q->orderBy('blok')->orderByRaw('CAST(nomor_unit AS UNSIGNED) ASC')->paginate((int) $this->perPage),
            'proyekList' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']),
            'blokList' => $blokList,
            // Yang non aktif tidak ditawarkan — tapi yang sudah terlanjur terpasang tetap tampil.
            'subconList' => Subcon::where('is_aktif', true)->orderBy('nama')->get(['id', 'nama']),
            'counts' => $counts,
            'logs' => $this->logRumahId
                ? RumahProgresLog::with('updatedBy:id,name')
                    ->where('rumah_id', $this->logRumahId)
                    ->orderByDesc('created_at')->limit(50)->get()
                : collect(),
            'logRumah' => $this->logRumahId ? Rumah::find($this->logRumahId) : null,
        ];
    }

}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-cyan-500 to-cyan-700 text-white shadow-sm">
                    <flux:icon.wrench-screwdriver class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Data Rumah — Teknik') }}</flux:heading>
                    <flux:subheading>Progres pembangunan fisik, LOT sertifikat, & subcon per unit</flux:subheading>
                </div>
            </div>
        </div>

        {{-- FILTER RANGE SLIDER — Progres Fisik dari X% sampai Y% --}}
        @php
            $totalHasil = (int) $counts?->total;
            $totalDitampilkan = $totalHasil; // count sudah filter search+blok, blm progres range — hitung range terpisah
            // Preset: [label, min, max, color]
            $presets = [
                ['label' => 'Semua', 'min' => 0, 'max' => 100, 'count' => (int) $counts?->total],
                ['label' => 'Belum Mulai (0%)', 'min' => 0, 'max' => 0, 'count' => (int) $counts?->belum],
                ['label' => 'Dalam Proses (1-99%)', 'min' => 1, 'max' => 99, 'count' => (int) $counts?->proses],
                ['label' => 'Selesai (100%)', 'min' => 100, 'max' => 100, 'count' => (int) $counts?->selesai],
            ];
        @endphp
        <div class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:icon.adjustments-horizontal class="size-4 text-zinc-500" />
                    <span class="text-sm font-semibold">Filter Progres Fisik</span>
                </div>
                <div class="font-mono text-sm font-bold text-cyan-700 dark:text-cyan-400">
                    {{ $progresMin }}% – {{ $progresMax }}%
                </div>
            </div>

            {{-- Dual-range slider: 2 handle di 1 track --}}
            <div class="px-2">
                <div class="relative h-6">
                    {{-- Track background --}}
                    <div class="absolute inset-x-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                    {{-- Active range fill --}}
                    <div class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-cyan-500"
                        style="left: {{ $progresMin }}%; right: {{ 100 - $progresMax }}%"></div>
                    {{-- Min handle --}}
                    <input type="range" min="0" max="100" step="1" wire:model.live="progresMin"
                        class="dual-range-slider absolute inset-0 w-full" />
                    {{-- Max handle --}}
                    <input type="range" min="0" max="100" step="1" wire:model.live="progresMax"
                        class="dual-range-slider absolute inset-0 w-full" />
                </div>
                {{-- Label bawah --}}
                <div class="mt-1 flex justify-between text-[10px] text-zinc-400">
                    <span>0%</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span>
                </div>
            </div>

            <style>
                .dual-range-slider {
                    -webkit-appearance: none;
                    appearance: none;
                    background: transparent;
                    pointer-events: none;
                    height: 24px;
                    margin: 0;
                    padding: 0;
                }
                .dual-range-slider::-webkit-slider-thumb {
                    -webkit-appearance: none;
                    appearance: none;
                    pointer-events: auto;
                    width: 18px;
                    height: 18px;
                    border-radius: 9999px;
                    background: rgb(8 145 178);
                    border: 2px solid white;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
                    cursor: grab;
                    transition: transform 0.1s;
                }
                .dual-range-slider::-webkit-slider-thumb:active {
                    cursor: grabbing;
                    transform: scale(1.15);
                }
                .dual-range-slider::-moz-range-thumb {
                    pointer-events: auto;
                    width: 18px;
                    height: 18px;
                    border-radius: 9999px;
                    background: rgb(8 145 178);
                    border: 2px solid white;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
                    cursor: grab;
                }
                .dual-range-slider::-webkit-slider-runnable-track {
                    background: transparent;
                    height: 24px;
                }
                .dual-range-slider::-moz-range-track {
                    background: transparent;
                    height: 24px;
                }
            </style>

            {{-- Preset shortcut chips --}}
            <div class="mt-3 flex flex-wrap gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                @foreach ($presets as $p)
                    @php $isActive = $progresMin === $p['min'] && $progresMax === $p['max']; @endphp
                    <button type="button"
                        wire:click="setPreset('{{ $p['min'] === 0 && $p['max'] === 100 ? '' : ($p['max'] === 0 ? 'belum' : ($p['min'] === 100 ? 'selesai' : 'proses')) }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition',
                            'border-cyan-500 bg-cyan-50 text-cyan-800 dark:bg-cyan-950/40 dark:text-cyan-300' => $isActive,
                            'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => ! $isActive,
                        ])>
                        <span>{{ $p['label'] }}</span>
                        <span class="rounded-full bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] font-bold tabular-nums dark:bg-zinc-800">
                            {{ number_format($p['count']) }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- FILTER LAINNYA (search + blok + perpage) --}}
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="min-w-64 flex-1">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Cari blok-unit, LOT, atau subcon..." icon="magnifying-glass" />
            </div>
            <div class="min-w-32">
                <flux:select wire:model.live="filterBlok" placeholder="Semua Blok" size="sm">
                    <flux:select.option value="">Semua Blok</flux:select.option>
                    @foreach ($blokList as $b)
                        <flux:select.option value="{{ $b }}">Blok {{ $b }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            @if ($search || $filterBlok || $progresMin > 0 || $progresMax < 100)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="resetAllFilters">Reset</flux:button>
            @endif
            <flux:select wire:model.live="perPage" size="sm">
                <flux:select.option value="25">25 baris</flux:select.option>
                <flux:select.option value="50">50 baris</flux:select.option>
                <flux:select.option value="100">100 baris</flux:select.option>
                <flux:select.option value="500">500 baris</flux:select.option>
            </flux:select>
        </div>

        @if (! $selectedProyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                Pilih proyek dulu di picker atas untuk menampilkan data rumah.
            </div>
        @else
            {{-- BILAH AKSI MASSAL --}}
            @if ($terpilih && Auth::user()?->can('teknik.rumah.update'))
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-cyan-200 bg-cyan-50 px-4 py-2.5 dark:border-cyan-900/40 dark:bg-cyan-950/30">
                    <span class="text-sm font-medium text-cyan-900 dark:text-cyan-200">
                        {{ count($terpilih) }} unit dipilih
                    </span>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="bersihkanPilihan">Batalkan Pilihan</flux:button>
                        <flux:button size="sm" variant="primary" icon="pencil-square" wire:click="openMassal">
                            Ubah Sekaligus
                        </flux:button>
                    </div>
                </div>
            @endif

            {{-- TABLE --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                            <tr class="text-left uppercase text-[10px] font-semibold text-zinc-500">
                                @if (Auth::user()?->can('teknik.rumah.update'))
                                    <th class="w-8 px-3 py-2.5">
                                        <input type="checkbox"
                                               wire:click="pilihSemuaHalaman({{ Illuminate\Support\Js::from($rumahs->pluck('id')) }})"
                                               title="Pilih semua di halaman ini"
                                               class="rounded border-zinc-300 text-cyan-600 focus:ring-cyan-500 dark:border-zinc-600" />
                                    </th>
                                @endif
                                <th class="px-3 py-2.5">Kavling</th>
                                <th class="px-3 py-2.5">Tipe</th>
                                <th class="px-3 py-2.5 text-center">LB/LT</th>
                                <th class="px-3 py-2.5 text-center">LOT</th>
                                <th class="px-3 py-2.5">Subcon</th>
                                <th class="px-3 py-2.5">Progres Fisik</th>
                                <th class="px-3 py-2.5">Update Terakhir</th>
                                <th class="px-3 py-2.5 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @php
                                $canUpdate = Auth::user()?->can('teknik.rumah.update');
                                // Warna progress bar dari nilai persen (visual saja, bukan status).
                                $progBarCls = fn ($p) => match (true) {
                                    $p == 0 => 'bg-zinc-300 dark:bg-zinc-700',
                                    $p < 50 => 'bg-amber-500',
                                    $p < 100 => 'bg-blue-500',
                                    default => 'bg-emerald-500',
                                };
                            @endphp
                            @forelse ($rumahs as $r)
                                <tr wire:key="rumah-{{ $r->id }}"
                                    @class(['bg-cyan-50/60 dark:bg-cyan-950/20' => in_array($r->id, $terpilih, true)])>
                                    @if ($canUpdate)
                                        <td class="px-3 py-2">
                                            <input type="checkbox" value="{{ $r->id }}" wire:model.live="terpilih"
                                                   class="rounded border-zinc-300 text-cyan-600 focus:ring-cyan-500 dark:border-zinc-600" />
                                        </td>
                                    @endif
                                    <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold">{{ $r->kode_unit }}</td>
                                    <td class="px-3 py-2">{{ $r->tipeRumah?->nama_tipe ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center text-zinc-600 dark:text-zinc-400">
                                        {{ (int) ($r->tipeRumah?->luas_bangunan ?? 0) }}/{{ (int) ($r->tipeRumah?->luas_tanah ?? 0) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center font-mono">{{ $r->lot ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ $r->subcon?->nama ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                                <div class="h-full {{ $progBarCls($r->progres_fisik) }}" style="width: {{ $r->progres_fisik }}%"></div>
                                            </div>
                                            <span class="font-mono text-xs font-semibold tabular-nums">{{ $r->progres_fisik }}%</span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-[11px] text-zinc-500">
                                        @if ($r->progres_updated_at)
                                            {{ $r->progres_updated_at->format('d/m/y H:i') }}
                                            <div class="text-[10px] text-zinc-400">{{ $r->progresUpdatedBy?->name ?? '—' }}</div>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            @if ($canUpdate)
                                                <flux:button size="xs" variant="primary" icon="pencil-square" wire:click="openUpdate({{ $r->id }})">Update</flux:button>
                                            @endif
                                            <flux:button size="xs" variant="ghost" icon="clock" wire:click="openLog({{ $r->id }})" title="Riwayat perubahan"></flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ Auth::user()?->can('teknik.rumah.update') ? 9 : 8 }}" class="px-4 py-12 text-center text-zinc-400">
                                        Tidak ada rumah di proyek ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    {{ $rumahs->links() }}
                </div>
            </div>
        @endif
    </div>

    {{-- MODAL UPDATE PROGRES --}}
    {{-- MODAL UBAH SEKALIGUS --}}
    <flux:modal name="update-massal" class="md:w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Ubah {{ count($terpilih) }} Unit Sekaligus</flux:heading>
                <flux:subheading>Isian yang dikosongkan tidak akan menimpa apa pun.</flux:subheading>
            </div>

            <flux:select wire:model="massal_subcon_id" label="Subcon (Pemborong)">
                <option value="">- tidak diubah -</option>
                @foreach ($subconList as $sc)
                    <option value="{{ $sc->id }}">{{ $sc->nama }}</option>
                @endforeach
            </flux:select>
            @error('massal_subcon_id') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:input type="number" min="0" max="100" wire:model="massal_progres"
                        label="Progres Fisik (%)" placeholder="kosongkan kalau tidak diubah" />
            @error('massal_progres') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <div>
                <flux:input type="number" min="0" max="9999" wire:model="massal_lot_mulai"
                            label="LOT — mulai dari nomor" placeholder="kosongkan kalau tidak diubah" />
                <p class="mt-1 text-xs text-zinc-500">
                    LOT diisi <strong>berurutan</strong> mengikuti urutan blok-unit, bukan nomor
                    yang sama untuk semua &mdash; nomor sertifikat tidak boleh kembar.
                </p>
                @error('massal_lot_mulai') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            <flux:textarea wire:model="val_catatan" rows="2" label="Catatan Perubahan"
                           placeholder="tercatat di riwayat kalau progres berubah" />

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanMassal">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="update-progres" class="md:w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Update Progres — {{ $editKode }}</flux:heading>
                <flux:subheading>Perubahan otomatis tercatat di riwayat</flux:subheading>
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium">Progres Fisik</label>

                {{-- Geser untuk perkiraan cepat, ketik kalau angkanya sudah pasti.
                     Langkahnya 1, bukan 5: progres dari Matrix bernilai ganjil seperti
                     43% atau 94%, dan dengan langkah 5 angka itu langsung hilang
                     begitu slidernya tersenggol. --}}
                <div class="flex items-center gap-3">
                    <input type="range" min="0" max="100" step="1" wire:model.live="val_progres"
                           class="min-w-0 flex-1 accent-cyan-600" />
                    <div class="flex shrink-0 items-center gap-1">
                        <input type="number" min="0" max="100" step="1" wire:model.live="val_progres"
                               class="w-20 rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-right font-mono text-sm tabular-nums focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 dark:border-zinc-600 dark:bg-zinc-800" />
                        <span class="text-sm font-medium text-zinc-500">%</span>
                    </div>
                </div>

                <div class="mt-1 flex justify-between pr-24 text-[10px] text-zinc-400">
                    <span>0%</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span>
                </div>

                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ([0, 25, 50, 75, 100] as $preset)
                        <flux:button size="xs"
                                     :variant="(int) $val_progres === $preset ? 'primary' : 'outline'"
                                     wire:click="$set('val_progres', {{ $preset }})">
                            {{ $preset }}%
                        </flux:button>
                    @endforeach
                </div>

                @error('val_progres') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            <flux:input type="number" min="0" max="9999" wire:model="val_lot" label="LOT (Nomor Sertifikat)" placeholder="opsional" />
            @error('val_lot') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:select wire:model="val_subcon_id" label="Subcon (Pemborong)">
                <option value="">- belum ditentukan -</option>
                @foreach ($subconList as $sc)
                    <option value="{{ $sc->id }}">{{ $sc->nama }}</option>
                @endforeach
            </flux:select>
            @error('val_subcon_id') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <flux:textarea wire:model="val_catatan" label="Catatan Perubahan" placeholder="Mis. selesai pengecoran, atap terpasang, dll" rows="3" />
            @error('val_catatan') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanProgres">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL HISTORY LOG --}}
    <flux:modal name="history-log" class="md:w-xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Riwayat Progres — {{ $logRumah?->kode_unit }}</flux:heading>
                <flux:subheading>50 perubahan terakhir</flux:subheading>
            </div>

            @if ($logs->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/40">
                    Belum ada riwayat perubahan.
                </div>
            @else
                <div class="max-h-96 overflow-y-auto">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-zinc-100 dark:bg-zinc-800">
                            <tr class="text-left uppercase text-[10px] font-semibold text-zinc-500">
                                <th class="px-3 py-2">Tanggal</th>
                                <th class="px-3 py-2">Perubahan</th>
                                <th class="px-3 py-2">Catatan</th>
                                <th class="px-3 py-2">Oleh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($logs as $log)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-600">{{ $log->created_at?->format('d/m/y H:i') }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono">
                                        <span class="text-zinc-400">{{ $log->progres_dari ?? 0 }}%</span>
                                        <flux:icon.arrow-right class="mx-1 inline size-3 text-zinc-400" />
                                        <span class="font-semibold text-cyan-700 dark:text-cyan-400">{{ $log->progres_ke }}%</span>
                                    </td>
                                    <td class="px-3 py-2 text-zinc-600">{{ $log->catatan ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-500">{{ $log->updatedBy?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Tutup</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
