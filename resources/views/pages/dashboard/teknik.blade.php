<?php

use App\Models\Master\Rumah;
use App\Models\Master\RumahProgresLog;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard Admin Teknik — progres fisik bangunan dan nomor LOT.
 *
 * Tidak menampilkan harga, uang muka, maupun data keuangan apa pun: role ini hanya
 * punya izin melihat dan memperbarui progres rumah.
 *
 * Unit dengan progres 0 DIBEDAKAN dari "belum dibangun" — sebagian besar bernilai 0
 * karena kolomnya belum pernah diisi sejak import, bukan karena pekerjaannya belum
 * dimulai. Menyebutnya "belum dibangun" akan menyesatkan.
 */
new #[Title('Dashboard')] class extends Component
{
    public ?int $filterProyek = null;

    public function mount(): void
    {
        $this->filterProyek = session('active_proyek_id');
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->filterProyek = $proyekId;
    }

    protected function unit()
    {
        return Rumah::query()->when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek));
    }

    public function with(): array
    {
        $total = (clone $this->unit())->count();
        $belumDicatat = (clone $this->unit())->where(fn ($q) => $q->whereNull('progres_fisik')->orWhere('progres_fisik', 0))->count();
        $proses = (clone $this->unit())->whereBetween('progres_fisik', [1, 99])->count();
        $selesai = (clone $this->unit())->where('progres_fisik', 100)->count();
        $lotKosong = (clone $this->unit())->where(fn ($q) => $q->whereNull('lot')->orWhere('lot', 0))->count();

        $sedangDikerjakan = (clone $this->unit())
            ->whereBetween('progres_fisik', [1, 99])
            ->with('tipeRumah:id,nama_tipe')
            ->orderBy('progres_fisik')
            ->limit(10)
            ->get(['id', 'blok', 'nomor_unit', 'tipe_rumah_id', 'progres_fisik', 'lot', 'progres_updated_at']);

        $perubahanTerakhir = RumahProgresLog::with(['rumah:id,blok,nomor_unit', 'updatedBy:id,name'])
            ->latest('created_at')
            ->limit(8)
            ->get();

        return compact(
            'total', 'belumDicatat', 'proses', 'selesai', 'lotKosong',
            'sedangDikerjakan', 'perubahanTerakhir'
        );
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                <flux:subheading>Progres fisik bangunan &amp; nomor LOT</flux:subheading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-dashboard-switcher current="teknik" />
                @can('teknik.rumah.lihat')
                    <flux:button size="sm" variant="primary" icon="wrench" :href="route('teknik.rumah.index')" wire:navigate>
                        Data Rumah
                    </flux:button>
                @endcan
            </div>
        </div>

        {{-- KARTU UTAMA --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">Total Unit</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($total) }}</div>
                <div class="mt-2 text-[10px] text-zinc-500">Seluruh unit terdaftar</div>
            </div>

            <div class="rounded-xl border border-orange-200 bg-linear-to-br from-orange-50 to-white p-4 shadow-sm dark:border-orange-900/40 dark:from-orange-950/30 dark:to-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-orange-700 dark:text-orange-400">Sedang Dikerjakan</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-orange-900 dark:text-orange-100">{{ number_format($proses) }}</div>
                    </div>
                    <div class="rounded-lg bg-orange-600 p-2 text-white shadow-sm">
                        <flux:icon.wrench class="size-5" />
                    </div>
                </div>
                <div class="mt-2 text-[10px] text-zinc-500">Progres 1–99 persen</div>
            </div>

            <div class="rounded-xl border border-emerald-200 bg-linear-to-br from-emerald-50 to-white p-4 shadow-sm dark:border-emerald-900/40 dark:from-emerald-950/30 dark:to-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Selesai</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">{{ number_format($selesai) }}</div>
                    </div>
                    <div class="rounded-lg bg-emerald-600 p-2 text-white shadow-sm">
                        <flux:icon.check-badge class="size-5" />
                    </div>
                </div>
                <div class="mt-2 text-[10px] text-zinc-500">Progres 100 persen</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">Belum Dicatat</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($belumDicatat) }}</div>
                    </div>
                    <x-info-button title="Belum Dicatat">
                        Unit yang progres fisiknya masih nol. Sebagian besar bukan berarti pembangunannya
                        belum dimulai, tapi karena progresnya belum pernah diisi sejak data lama dipindahkan.
                        Angka ini akan turun seiring pencatatan lewat menu Data Rumah.
                    </x-info-button>
                </div>
                <div class="mt-2 text-[10px] text-zinc-500">{{ number_format($lotKosong) }} unit nomor LOT-nya masih kosong</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- SEDANG DIKERJAKAN --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.wrench class="size-5 text-orange-600" />
                    <h2 class="text-base font-bold">Sedang Dikerjakan</h2>
                    <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Progres terendah dulu</span>
                </div>
                @if ($sedangDikerjakan->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Tidak ada unit yang sedang dalam proses pembangunan.</div>
                @else
                    <div class="space-y-2">
                        @foreach ($sedangDikerjakan as $r)
                            <div class="rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-semibold">{{ $r->blok }}-{{ $r->nomor_unit }}</span>
                                    <span class="font-mono text-xs font-bold tabular-nums text-orange-700 dark:text-orange-400">{{ $r->progres_fisik }}%</span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-full rounded-full bg-orange-500" style="width: {{ $r->progres_fisik }}%"></div>
                                </div>
                                <div class="mt-1 text-[10px] text-zinc-500">
                                    {{ $r->tipeRumah?->nama_tipe ?? 'Tipe tidak diketahui' }}
                                    @if ($r->lot) · LOT {{ $r->lot }} @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- PERUBAHAN TERAKHIR --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.clock class="size-5 text-zinc-500" />
                    <h2 class="text-base font-bold">Perubahan Terakhir</h2>
                </div>
                @if ($perubahanTerakhir->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Belum ada perubahan progres yang tercatat.</div>
                @else
                    <div class="space-y-2">
                        @foreach ($perubahanTerakhir as $log)
                            <div class="rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-semibold">{{ $log->rumah?->blok }}-{{ $log->rumah?->nomor_unit }}</span>
                                    <span class="font-mono text-xs tabular-nums">
                                        <span class="text-zinc-400">{{ $log->progres_dari }}%</span>
                                        <span class="text-zinc-400">→</span>
                                        <span class="font-bold text-emerald-700 dark:text-emerald-400">{{ $log->progres_ke }}%</span>
                                    </span>
                                </div>
                                <div class="mt-0.5 text-[10px] text-zinc-500">
                                    {{ $log->created_at?->diffForHumans() }}
                                    @if ($log->updatedBy) · {{ $log->updatedBy->name }} @endif
                                </div>
                                @if ($log->catatan)
                                    <div class="mt-1 text-[11px] italic text-zinc-600 dark:text-zinc-400">{{ $log->catatan }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

    </div>
</section>
