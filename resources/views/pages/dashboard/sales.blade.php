<?php

use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard Admin Sales — SPR yang sedang berjalan dan biaya tambahan unit.
 *
 * Tidak menampilkan saldo kas, bank, maupun piutang: role ini tidak punya izin
 * keuangan, jadi angka tersebut tidak boleh muncul di sini.
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

    protected function batasiProyek($q, string $relasi = 'rumah')
    {
        return $this->filterProyek
            ? $q->whereHas($relasi, fn ($r) => $r->where('proyek_id', $this->filterProyek))
            : $q;
    }

    public function with(): array
    {
        $bulanIni = now()->startOfMonth();

        $sprApproved = $this->batasiProyek(Spr::where('status', 'approved'))->count();
        $sprAkad = $this->batasiProyek(Spr::where('status', 'akad'))->count();
        $sprBulanIni = $this->batasiProyek(Spr::where('tanggal_spr', '>=', $bulanIni))->count();

        // Biaya tambahan unit: plafon dari master rumah, realisasi dari kuitansinya.
        $rumahQ = Rumah::where('biaya_tambahan', '>', 0)
            ->when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek));

        $plafon = (float) (clone $rumahQ)->sum('biaya_tambahan');
        $unitAdaBiaya = (clone $rumahQ)->count();

        $terbayar = (float) DB::table('biaya_tambahan_realisasi as bt')
            ->join('rumah as r', 'r.id', '=', 'bt.rumah_id')
            ->when($this->filterProyek, fn ($q) => $q->where('r.proyek_id', $this->filterProyek))
            ->sum('bt.jumlah');

        // Unit yang biaya tambahannya belum tertagih sama sekali
        $belumTertagih = (clone $rumahQ)
            ->whereDoesntHave('biayaTambahanRealisasi')
            ->orderBy('blok')->orderBy('nomor_unit')
            ->limit(10)
            ->get(['id', 'blok', 'nomor_unit', 'biaya_tambahan']);

        $sprTerbaru = $this->batasiProyek(Spr::with(['rumah', 'prospectCustomer', 'sales']))
            ->orderByDesc('tanggal_spr')->orderByDesc('id')
            ->limit(8)->get();

        return compact(
            'sprApproved', 'sprAkad', 'sprBulanIni',
            'plafon', 'terbayar', 'unitAdaBiaya', 'belumTertagih', 'sprTerbaru'
        );
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                <flux:subheading>SPR berjalan &amp; biaya tambahan unit</flux:subheading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-dashboard-switcher current="sales" />
            </div>
        </div>

        @php $sisaBiaya = max(0, $plafon - $terbayar); @endphp

        {{-- KARTU UTAMA --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">SPR Berjalan</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($sprApproved) }}</div>
                <div class="mt-2 text-[10px] text-zinc-500">Disetujui, belum akad</div>
            </div>

            <div class="rounded-xl border border-emerald-200 bg-linear-to-br from-emerald-50 to-white p-4 shadow-sm dark:border-emerald-900/40 dark:from-emerald-950/30 dark:to-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Sudah Akad</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">{{ number_format($sprAkad) }}</div>
                <div class="mt-2 text-[10px] text-zinc-500">Serah terima selesai</div>
            </div>

            <div class="rounded-xl border border-blue-200 bg-linear-to-br from-blue-50 to-white p-4 shadow-sm dark:border-blue-900/40 dark:from-blue-950/30 dark:to-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:text-blue-400">SPR Bulan Ini</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-blue-900 dark:text-blue-100">{{ number_format($sprBulanIni) }}</div>
                <div class="mt-2 text-[10px] text-zinc-500">{{ now()->translatedFormat('F Y') }}</div>
            </div>

            <div class="rounded-xl border border-amber-200 bg-linear-to-br from-amber-50 to-white p-4 shadow-sm dark:border-amber-900/40 dark:from-amber-950/30 dark:to-zinc-900">
                <div class="text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400">Biaya Tambahan Belum Tertagih</div>
                <div class="mt-1 font-mono text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100">Rp {{ number_format($sisaBiaya, 0, ',', '.') }}</div>
                <div class="mt-2 text-[10px] text-zinc-500">dari plafon Rp {{ number_format($plafon, 0, ',', '.') }} · {{ number_format($unitAdaBiaya) }} unit</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- SPR TERBARU --}}
            <div class="lg:col-span-2 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.document-text class="size-5 text-blue-600" />
                    <h2 class="text-base font-bold">SPR Terbaru</h2>
                </div>
                @if ($sprTerbaru->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Belum ada SPR.</div>
                @else
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold">No SPR</th>
                                    <th class="px-3 py-2 text-left font-semibold">Unit</th>
                                    <th class="px-3 py-2 text-left font-semibold">Konsumen</th>
                                    <th class="px-3 py-2 text-left font-semibold">Sales</th>
                                    <th class="px-3 py-2 text-right font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($sprTerbaru as $s)
                                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                        <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold">{{ $s->nomor_display }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $s->rumah?->blok }}-{{ $s->rumah?->nomor_unit }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $s->prospectCustomer?->nama_lengkap ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-zinc-500">{{ $s->sales?->nama ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase',
                                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400' => $s->status === 'akad',
                                                'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400' => $s->status !== 'akad',
                                            ])>{{ $s->status }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- BIAYA TAMBAHAN BELUM TERTAGIH --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.banknotes class="size-5 text-amber-600" />
                    <h2 class="text-base font-bold">Belum Tertagih</h2>
                </div>
                @if ($belumTertagih->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Semua biaya tambahan sudah ada kuitansinya.</div>
                @else
                    <div class="space-y-2">
                        @foreach ($belumTertagih as $r)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                <span class="text-sm font-semibold">{{ $r->blok }}-{{ $r->nomor_unit }}</span>
                                <span class="font-mono text-xs tabular-nums text-amber-700 dark:text-amber-400">Rp {{ number_format((float) $r->biaya_tambahan, 0, ',', '.') }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

    </div>
</section>
