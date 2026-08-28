<?php

use App\Models\Master\Proyek;
use App\Models\Master\TargetMarketing;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Target Marketing (RAB)')] class extends Component
{
    #[Url(as: 'tahun')]
    public int $selectedTahun = 0;

    #[Url(as: 'proyek')]
    public ?int $selectedProyekId = null;

    /** @var array<int, array{target_akad: int, target_penjualan: int, catatan: ?string}> */
    public array $rows = [];

    public const BULAN_LABEL = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function mount(): void
    {
        if (! $this->selectedTahun) {
            $this->selectedTahun = (int) now()->year;
        }
        if (! $this->selectedProyekId) {
            $this->selectedProyekId = Proyek::orderBy('nama_proyek')->value('id');
        }
        $this->loadRows();
    }

    public function updatedSelectedTahun(): void
    {
        $this->loadRows();
    }

    public function updatedSelectedProyekId(): void
    {
        $this->loadRows();
    }

    protected function loadRows(): void
    {
        $this->rows = [];
        if (! $this->selectedProyekId) {
            return;
        }

        $existing = TargetMarketing::where('proyek_id', $this->selectedProyekId)
            ->where('tahun', $this->selectedTahun)
            ->get()->keyBy('bulan');

        for ($b = 1; $b <= 12; $b++) {
            $t = $existing->get($b);
            $this->rows[$b] = [
                'target_akad' => $t?->target_akad ?? 0,
                'target_penjualan' => $t?->target_penjualan ?? 0,
                'catatan' => $t?->catatan ?? '',
            ];
        }
    }

    public function simpan(): void
    {
        abort_unless(Auth::user()?->can('target.kelola'), 403);
        abort_unless($this->selectedProyekId, 400, 'Pilih proyek dulu');

        $this->validate([
            'rows.*.target_akad' => ['required', 'integer', 'min:0', 'max:10000'],
            'rows.*.target_penjualan' => ['required', 'integer', 'min:0', 'max:10000'],
            'rows.*.catatan' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () {
            foreach ($this->rows as $bulan => $row) {
                TargetMarketing::updateOrCreate(
                    [
                        'proyek_id' => $this->selectedProyekId,
                        'tahun' => $this->selectedTahun,
                        'bulan' => (int) $bulan,
                    ],
                    [
                        'target_akad' => (int) $row['target_akad'],
                        'target_penjualan' => (int) $row['target_penjualan'],
                        'catatan' => $row['catatan'] ?: null,
                        'updated_by_user_id' => Auth::id(),
                    ]
                );
            }
        });

        Flux::toast(variant: 'success', text: "Target {$this->selectedTahun} disimpan.");
    }

    /** Copy target akad dari bulan 1 ke semua bulan (bulk fill). */
    public function isiSemuaAkad(int $nilai): void
    {
        for ($b = 1; $b <= 12; $b++) {
            $this->rows[$b]['target_akad'] = $nilai;
        }
    }

    public function isiSemuaPenjualan(int $nilai): void
    {
        for ($b = 1; $b <= 12; $b++) {
            $this->rows[$b]['target_penjualan'] = $nilai;
        }
    }

    public function with(): array
    {
        $proyekList = Proyek::orderBy('nama_proyek')->get();
        $tahunMin = min((int) now()->year - 2, TargetMarketing::min('tahun') ?: (int) now()->year - 2);
        $tahunMax = (int) now()->year + 3;

        $totalAkad = collect($this->rows)->sum('target_akad');
        $totalPenjualan = collect($this->rows)->sum('target_penjualan');

        return [
            'proyekList' => $proyekList,
            'tahunOptions' => range($tahunMax, $tahunMin),
            'canEdit' => Auth::user()?->can('target.kelola'),
            'totalAkad' => (int) $totalAkad,
            'totalPenjualan' => (int) $totalPenjualan,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-violet-700 text-white shadow-sm">
                    <flux:icon.flag class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Target Marketing (RAB)') }}</flux:heading>
                    <flux:subheading>Set target akad & penjualan per bulan — breakdown per proyek per tahun</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:select wire:model.live="selectedProyekId" size="sm" icon="building-office-2">
                    @foreach ($proyekList as $p)
                        <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="selectedTahun" size="sm" icon="calendar">
                    @foreach ($tahunOptions as $th)
                        <flux:select.option value="{{ $th }}">Tahun {{ $th }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- Total Card --}}
        <div class="mb-4 grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/30">
                <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Total Target Akad {{ $selectedTahun }}</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-100">{{ number_format($totalAkad) }}</div>
            </div>
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/40 dark:bg-blue-950/30">
                <div class="text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:text-blue-400">Total Target Penjualan {{ $selectedTahun }}</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-blue-800 dark:text-blue-100">{{ number_format($totalPenjualan) }}</div>
            </div>
        </div>

        {{-- TABLE INPUT 12 BULAN --}}
        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon.table-cells class="size-4 text-zinc-500" />
                    <span class="text-sm font-bold">Target Bulanan {{ $selectedTahun }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr>
                            <th class="w-32 px-4 py-2 text-left">Bulan</th>
                            <th class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    Target Akad
                                    @if ($canEdit)
                                        <button type="button" wire:click="isiSemuaAkad({{ $rows[1]['target_akad'] ?? 0 }})"
                                            title="Copy nilai Januari ke semua bulan"
                                            class="rounded p-1 text-emerald-600 hover:bg-emerald-100 dark:hover:bg-emerald-950">
                                            <flux:icon.arrow-down class="size-3" />
                                        </button>
                                    @endif
                                </div>
                            </th>
                            <th class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    Target Penjualan
                                    @if ($canEdit)
                                        <button type="button" wire:click="isiSemuaPenjualan({{ $rows[1]['target_penjualan'] ?? 0 }})"
                                            title="Copy nilai Januari ke semua bulan"
                                            class="rounded p-1 text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-950">
                                            <flux:icon.arrow-down class="size-3" />
                                        </button>
                                    @endif
                                </div>
                            </th>
                            <th class="px-4 py-2 text-left">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach (self::BULAN_LABEL as $bulan => $label)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 font-semibold">
                                    <span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-zinc-100 text-[10px] font-mono font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $bulan }}</span>
                                    {{ $label }}
                                </td>
                                <td class="px-4 py-2">
                                    <flux:input type="number" min="0" max="10000"
                                        wire:model="rows.{{ $bulan }}.target_akad"
                                        :disabled="! $canEdit"
                                        class="text-right font-mono" />
                                </td>
                                <td class="px-4 py-2">
                                    <flux:input type="number" min="0" max="10000"
                                        wire:model="rows.{{ $bulan }}.target_penjualan"
                                        :disabled="! $canEdit"
                                        class="text-right font-mono" />
                                </td>
                                <td class="px-4 py-2">
                                    <flux:input wire:model="rows.{{ $bulan }}.catatan"
                                        :disabled="! $canEdit"
                                        placeholder="opsional" size="sm" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 font-bold dark:border-zinc-600 dark:bg-zinc-800">
                        <tr>
                            <td class="px-4 py-3 text-sm">TOTAL SETAHUN</td>
                            <td class="px-4 py-3 text-right font-mono text-base tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($totalAkad) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-base tabular-nums text-blue-700 dark:text-blue-400">{{ number_format($totalPenjualan) }}</td>
                            <td class="px-4 py-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($canEdit)
                <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="text-[11px] text-zinc-500">
                        <flux:icon.information-circle class="mr-1 inline size-3.5" />
                        Tip: klik ikon ↓ di header kolom untuk copy nilai Januari ke semua bulan.
                    </div>
                    <flux:button variant="primary" icon="check" wire:click="simpan">Simpan Target</flux:button>
                </div>
            @endif
        </div>
    </div>
</section>
