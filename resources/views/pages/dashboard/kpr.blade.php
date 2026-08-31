<?php

use App\Models\Master\Spr;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard Admin KPR — fokus pada berkas yang perlu ditindaklanjuti.
 *
 * Sengaja TIDAK menampilkan diagram tahapan pemberkasan (BM → WCR → SP3K → LPA).
 * Data historis hanya memuat tanggal SP3K; tahap lain kosong bukan karena dilewati
 * tapi karena belum pernah dicatat. Grafik yang menampilkan "BM: 0" akan terbaca
 * sebagai kelalaian, padahal cuma data yang belum ada.
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

    /** SPR aktif (sudah disetujui, belum akad) — ini yang jadi tanggung jawab admin KPR. */
    protected function sprAktif()
    {
        return Spr::query()
            ->where('spr.status', 'approved')
            ->when($this->filterProyek, fn ($q) => $q->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)));
    }

    public function with(): array
    {
        $hariIni = now()->startOfDay();
        $batas30 = $hariIni->copy()->addDays(30);

        $tanpaBerkas = (clone $this->sprAktif())
            ->whereDoesntHave('pemberkasan')
            ->count();

        $adaBerkas = (clone $this->sprAktif())->whereHas('pemberkasan')->count();

        $berkas = fn () => DB::table('spr_pemberkasan as pb')
            ->join('spr as s', 's.id', '=', 'pb.spr_id')
            ->when($this->filterProyek, fn ($q) => $q->join('rumah as rf', 'rf.id', '=', 's.rumah_id')->where('rf.proyek_id', $this->filterProyek))
            ->where('s.status', 'approved');

        $sp3kLewat = (clone $berkas())->whereNotNull('pb.sp3k_expired')->where('pb.sp3k_expired', '<', $hariIni)->count();
        $sp3kSegera = (clone $berkas())->whereNotNull('pb.sp3k_expired')->whereBetween('pb.sp3k_expired', [$hariIni, $batas30])->count();
        $belumSp3k = (clone $berkas())->whereNull('pb.sp3k_tanggal')->count();

        $perluTindakan = (clone $berkas())
            ->join('rumah as r', 'r.id', '=', 's.rumah_id')
            ->leftJoin('prospect_customer as pc', 'pc.id', '=', 's.prospect_customer_id')
            ->whereNotNull('pb.sp3k_expired')
            ->where('pb.sp3k_expired', '<=', $batas30)
            ->orderBy('pb.sp3k_expired')
            ->limit(10)
            ->get([
                DB::raw("CONCAT(r.blok,'-',r.nomor_unit) as unit"),
                'pc.nama_lengkap as customer',
                'pb.bank_kode',
                'pb.sp3k_expired',
            ]);

        $perBank = (clone $berkas())
            ->groupBy('pb.bank_kode')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['pb.bank_kode', DB::raw('COUNT(*) as jml')]);

        return compact(
            'tanpaBerkas', 'adaBerkas', 'sp3kLewat', 'sp3kSegera', 'belumSp3k',
            'perluTindakan', 'perBank'
        );
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                <flux:subheading>Pemberkasan KPR yang perlu ditindaklanjuti</flux:subheading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-dashboard-switcher current="kpr" />
            </div>
        </div>

        {{-- KARTU UTAMA --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-rose-200 bg-linear-to-br from-rose-50 to-white p-4 shadow-sm dark:border-rose-900/40 dark:from-rose-950/30 dark:to-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-rose-700 dark:text-rose-400">Belum Ada Berkas</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-rose-900 dark:text-rose-100">{{ number_format($tanpaBerkas) }}</div>
                    </div>
                    <div class="rounded-lg bg-rose-600 p-2 text-white shadow-sm">
                        <flux:icon.folder-open class="size-5" />
                    </div>
                </div>
                <div class="mt-2 text-[10px] text-zinc-500">SPR disetujui tapi pemberkasannya belum dibuka</div>
            </div>

            <div class="rounded-xl border border-amber-200 bg-linear-to-br from-amber-50 to-white p-4 shadow-sm dark:border-amber-900/40 dark:from-amber-950/30 dark:to-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400">SP3K Lewat Tanggal</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-amber-900 dark:text-amber-100">{{ number_format($sp3kLewat) }}</div>
                    </div>
                    <div class="rounded-lg bg-amber-600 p-2 text-white shadow-sm">
                        <flux:icon.x-circle class="size-5" />
                    </div>
                </div>
                <div class="mt-2 text-[10px] text-zinc-500">Harus diurus ulang ke bank sebelum akad</div>
            </div>

            <div class="rounded-xl border border-orange-200 bg-linear-to-br from-orange-50 to-white p-4 shadow-sm dark:border-orange-900/40 dark:from-orange-950/30 dark:to-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-orange-700 dark:text-orange-400">Jatuh Tempo 30 Hari</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-orange-900 dark:text-orange-100">{{ number_format($sp3kSegera) }}</div>
                    </div>
                    <div class="rounded-lg bg-orange-600 p-2 text-white shadow-sm">
                        <flux:icon.clock class="size-5" />
                    </div>
                </div>
                <div class="mt-2 text-[10px] text-zinc-500">Masih bisa dikejar akadnya</div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">Berkas Berjalan</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($adaBerkas) }}</div>
                    </div>
                    <div class="rounded-lg bg-zinc-600 p-2 text-white shadow-sm">
                        <flux:icon.document-text class="size-5" />
                    </div>
                </div>
                <div class="mt-2 text-[10px] text-zinc-500">{{ number_format($belumSp3k) }} di antaranya belum ada tanggal SP3K</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- DAFTAR PERLU TINDAKAN --}}
            <div class="lg:col-span-2 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.exclamation-triangle class="size-5 text-rose-600" />
                    <h2 class="text-base font-bold">Paling Mendesak</h2>
                    <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-zinc-500">SP3K ≤ 30 hari</span>
                </div>

                @if ($perluTindakan->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Tidak ada berkas yang mendesak.</div>
                @else
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold">Unit</th>
                                    <th class="px-3 py-2 text-left font-semibold">Konsumen</th>
                                    <th class="px-3 py-2 text-left font-semibold">Bank</th>
                                    <th class="px-3 py-2 text-left font-semibold">Berlaku Sampai</th>
                                    <th class="px-3 py-2 text-right font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($perluTindakan as $b)
                                    @php
                                        $tgl = \Carbon\CarbonImmutable::parse($b->sp3k_expired);
                                        $sisa = (int) now()->startOfDay()->diffInDays($tgl->startOfDay(), false);
                                    @endphp
                                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                        <td class="whitespace-nowrap px-3 py-2 font-semibold">{{ $b->unit }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $b->customer ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-zinc-500">{{ $b->bank_kode ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-zinc-600 dark:text-zinc-400">{{ $tgl->translatedFormat('d M Y') }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                                'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400' => $sisa < 0,
                                                'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400' => $sisa >= 0,
                                            ])>{{ $sisa < 0 ? 'lewat '.abs($sisa).' hari' : 'sisa '.$sisa.' hari' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- SEBARAN BANK --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.building-library class="size-5 text-blue-600" />
                    <h2 class="text-base font-bold">Sebaran Bank</h2>
                </div>
                @if ($perBank->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Belum ada berkas.</div>
                @else
                    <div class="space-y-2">
                        @foreach ($perBank as $b)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                <span class="text-sm font-semibold">{{ $b->bank_kode ?? 'Belum ditentukan' }}</span>
                                <span class="font-mono text-sm font-bold tabular-nums">{{ number_format($b->jml) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

    </div>
</section>
