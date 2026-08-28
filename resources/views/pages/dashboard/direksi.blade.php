<?php

use App\Models\Master\Coa;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TargetMarketing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard Direksi')] class extends Component
{
    #[Url(as: 'tahun')]
    public int $selectedTahun = 0;

    public function mount(): void
    {
        if (! $this->selectedTahun) {
            $this->selectedTahun = (int) now()->year;
        }
    }

    /**
     * Hitung saldo aset (debit − kredit) atau kewajiban (kredit − debit)
     * untuk semua child COA di bawah parent (by kode prefix).
     */
    protected function saldoParent(string $kodePrefix, string $tipe = 'aset'): float
    {
        $coaIds = Coa::where('kode', 'like', $kodePrefix.'%')
            ->where('is_header', false)->pluck('id');
        if ($coaIds->isEmpty()) {
            return 0;
        }
        $agg = DB::table('jurnal_detail')
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->where('jurnal.status', 'posted')
            ->whereIn('jurnal_detail.coa_id', $coaIds)
            ->selectRaw('COALESCE(SUM(debet),0) as d, COALESCE(SUM(kredit),0) as k')
            ->first();

        return $tipe === 'aset' ? (float) ($agg->d - $agg->k) : (float) ($agg->k - $agg->d);
    }

    /**
     * Matrix Marketing Performance per proyek untuk tahun terpilih.
     * Return: ['proyekList' => [Proyek], 'akadTarget' => [proyekId => n], 'akadReal' => [proyekId => n],
     *          'penjualanTarget' => [...], 'penjualanReal' => [...], 'tahunOptions' => [2024,...]]
     *
     * Target belum ada modul → kosong (0). Real diambil dari SPR YTD tahun terpilih.
     */
    protected function marketingMatrix(): array
    {
        $tahunAwal = CarbonImmutable::create($this->selectedTahun, 1, 1);
        $tahunAkhir = $tahunAwal->endOfYear();

        $proyekList = Proyek::orderBy('nama_proyek')->get();

        // Real akad per proyek — SPR status=akad, tgl_akad dalam tahun ini
        $akadReal = Spr::query()
            ->join('rumah', 'rumah.id', '=', 'spr.rumah_id')
            ->where('spr.status', 'akad')
            ->whereBetween('spr.tgl_akad', [$tahunAwal, $tahunAkhir])
            ->groupBy('rumah.proyek_id')
            ->pluck(DB::raw('COUNT(*)'), 'rumah.proyek_id');

        // Real penjualan per proyek — SPR baru (tanggal_spr) dalam tahun, exclude cancelled
        $penjualanReal = Spr::query()
            ->join('rumah', 'rumah.id', '=', 'spr.rumah_id')
            ->where('spr.status', '!=', 'cancelled')
            ->whereBetween('spr.tanggal_spr', [$tahunAwal, $tahunAkhir])
            ->groupBy('rumah.proyek_id')
            ->pluck(DB::raw('COUNT(*)'), 'rumah.proyek_id');

        // Target dari master TargetMarketing (input manual direktur) — SUM 12 bulan per proyek
        $targetSums = TargetMarketing::where('tahun', $this->selectedTahun)
            ->selectRaw('proyek_id, SUM(target_akad) as sum_akad, SUM(target_penjualan) as sum_penjualan')
            ->groupBy('proyek_id')
            ->get()->keyBy('proyek_id');
        $akadTarget = [];
        $penjualanTarget = [];
        foreach ($proyekList as $p) {
            $akadTarget[$p->id] = (int) ($targetSums->get($p->id)?->sum_akad ?? 0);
            $penjualanTarget[$p->id] = (int) ($targetSums->get($p->id)?->sum_penjualan ?? 0);
        }

        // Options dropdown tahun — dari data SPR yang ada
        $tahunSprMin = Spr::min(DB::raw('YEAR(tanggal_spr)')) ?: (int) now()->year;
        $tahunSprMax = max((int) now()->year, Spr::max(DB::raw('YEAR(tanggal_spr)')) ?: (int) now()->year);
        $tahunOptions = range($tahunSprMax, $tahunSprMin);

        return compact('proyekList', 'akadTarget', 'akadReal', 'penjualanTarget', 'penjualanReal', 'tahunOptions');
    }

    /**
     * Matrix Persediaan Rumah per proyek (state saat ini).
     * Return: ['metrics' => [ [label, icon, color, values_per_proyek, showDetail] ]]
     */
    protected function persediaanMatrix(): array
    {
        $proyekList = Proyek::orderBy('nama_proyek')->get();
        $proyekIds = $proyekList->pluck('id');

        // Base counts per proyek
        $countByProyek = fn (?callable $filter = null) => Rumah::query()
            ->whereIn('proyek_id', $proyekIds)
            ->when($filter, fn ($q) => $filter($q))
            ->groupBy('proyek_id')
            ->pluck(DB::raw('COUNT(*)'), 'proyek_id');

        $totalKavling = $countByProyek();
        $rumahAvailable = $countByProyek(fn ($q) => $q->where('status', 'available'));
        $rumahBooking = $countByProyek(fn ($q) => $q->where('status', 'booking'));
        $rumahTerjual = $countByProyek(fn ($q) => $q->where('status', 'terjual'));
        $rumahSelesai = $countByProyek(fn ($q) => $q->where('progres_fisik', 100));
        $rumahProses = $countByProyek(fn ($q) => $q->whereBetween('progres_fisik', [1, 99]));

        // Akad & UTJ dari SPR (join rumah untuk proyek_id)
        $akadCount = Spr::query()
            ->join('rumah', 'rumah.id', '=', 'spr.rumah_id')
            ->where('spr.status', 'akad')
            ->groupBy('rumah.proyek_id')
            ->pluck(DB::raw('COUNT(*)'), 'rumah.proyek_id');
        $utjBelumAkad = Spr::query()
            ->join('rumah', 'rumah.id', '=', 'spr.rumah_id')
            ->where('spr.status', 'approved')
            ->whereNotNull('spr.utj_confirmed_at')
            ->groupBy('rumah.proyek_id')
            ->pluck(DB::raw('COUNT(*)'), 'rumah.proyek_id');

        $metrics = [
            ['key' => 'total_kavling', 'label' => 'Total Rencana Kavling', 'icon' => 'map', 'color' => 'sky', 'values' => $totalKavling],
            ['key' => 'akad', 'label' => 'Akad', 'icon' => 'check-badge', 'color' => 'emerald', 'values' => $akadCount],
            ['key' => 'utj', 'label' => 'UTJ Belum Akad', 'icon' => 'clock', 'color' => 'amber', 'values' => $utjBelumAkad],
            ['key' => 'stok_berjalan', 'label' => 'Stok Kavling Berjalan', 'icon' => 'squares-2x2', 'color' => 'indigo', 'values' => $rumahAvailable],
            ['key' => 'rumah_selesai', 'label' => 'Stok Rumah Selesai', 'icon' => 'home', 'color' => 'rose', 'values' => $rumahSelesai],
            ['key' => 'rumah_proses', 'label' => 'Rumah Proses Bangun', 'icon' => 'wrench', 'color' => 'orange', 'values' => $rumahProses],
        ];

        return compact('proyekList', 'metrics');
    }

    public function with(): array
    {
        $now = CarbonImmutable::now();
        $bulanIni = $now->startOfMonth();
        $tahunIni = $now->startOfYear();

        // ─── 1. RINGKASAN KEUANGAN (dari COA balance, jurnal posted) ───
        $keuangan = [
            'kas' => $this->saldoParent('1001', 'aset'),
            'bank' => $this->saldoParent('1002', 'aset'),
            'piutang' => $this->saldoParent('1007', 'aset'),
            'hutang' => $this->saldoParent('2001', 'kewajiban') + $this->saldoParent('2002', 'kewajiban'),
        ];

        // ─── 2. STOK UNIT per PROYEK ───
        $stokProyek = Proyek::withCount([
            'rumah as total',
            'rumah as terjual' => fn ($q) => $q->where('status', 'terjual'),
            'rumah as booking' => fn ($q) => $q->where('status', 'booking'),
            'rumah as available' => fn ($q) => $q->where('status', 'available'),
        ])->get();
        $stokTotal = [
            'total' => $stokProyek->sum('total'),
            'terjual' => $stokProyek->sum('terjual'),
            'booking' => $stokProyek->sum('booking'),
            'available' => $stokProyek->sum('available'),
        ];

        // ─── 3. REALISASI PENJUALAN & AKAD ───
        $akadBulanIni = Spr::where('status', 'akad')
            ->whereBetween('tgl_akad', [$bulanIni, $now])->count();
        $akadYtd = Spr::where('status', 'akad')
            ->whereBetween('tgl_akad', [$tahunIni, $now])->count();
        $akadTotal = Spr::where('status', 'akad')->count();

        $sprBaruBulanIni = Spr::whereBetween('tanggal_spr', [$bulanIni, $now])->count();
        $sprBaruYtd = Spr::whereBetween('tanggal_spr', [$tahunIni, $now])->count();

        $prospectTotal = ProspectCustomer::count();
        $prospectFinish = ProspectCustomer::where('status', 'finish')->count();

        // ─── 4. TREND AKAD 12 BULAN TERAKHIR ───
        $trendAkad = collect(range(0, 11))->map(function ($i) use ($now) {
            $bulan = $now->subMonths(11 - $i)->startOfMonth();
            $count = Spr::where('status', 'akad')
                ->whereBetween('tgl_akad', [$bulan, $bulan->endOfMonth()])
                ->count();

            return ['label' => $bulan->translatedFormat('M \'y'), 'count' => $count];
        });
        $trendMaxCount = max($trendAkad->max('count'), 1);

        // ─── 5. REALISASI UM TOTAL BULAN INI ───
        $umBulanIni = (float) SprRealisasiPembayaran::whereIn('jenis', ['bf', 'um'])
            ->whereBetween('tanggal_bayar', [$bulanIni, $now])->sum('jumlah');
        $umYtd = (float) SprRealisasiPembayaran::whereIn('jenis', ['bf', 'um'])
            ->whereBetween('tanggal_bayar', [$tahunIni, $now])->sum('jumlah');

        return [
            'keuangan' => $keuangan,
            'stokProyek' => $stokProyek,
            'stokTotal' => $stokTotal,
            'akadBulanIni' => $akadBulanIni,
            'akadYtd' => $akadYtd,
            'akadTotal' => $akadTotal,
            'sprBaruBulanIni' => $sprBaruBulanIni,
            'sprBaruYtd' => $sprBaruYtd,
            'prospectTotal' => $prospectTotal,
            'prospectFinish' => $prospectFinish,
            'trendAkad' => $trendAkad,
            'trendMaxCount' => $trendMaxCount,
            'umBulanIni' => $umBulanIni,
            'umYtd' => $umYtd,
            'now' => $now,
            'marketing' => $this->marketingMatrix(),
            'persediaan' => $this->persediaanMatrix(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-violet-700 text-white shadow-sm">
                    <flux:icon.building-office-2 class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Dashboard Direksi') }}</flux:heading>
                    <flux:subheading>Ringkasan operasional & keuangan PT Langit Membangun Indonesia</flux:subheading>
                </div>
            </div>
            <div class="text-right text-xs text-zinc-500">
                <div>Data per {{ $now->translatedFormat('l, d F Y H:i') }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wide">Bulan berjalan: {{ $now->translatedFormat('F Y') }}</div>
            </div>
        </div>

        @php
            $fmt = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
            $num = fn ($v) => number_format((int) $v);
        @endphp

        {{-- ═════════════ MARKETING PERFORMANCE (Target vs Real per proyek) ═════════════ --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <flux:icon.table-cells class="size-5 text-emerald-600" />
                        <h2 class="text-base font-bold">Marketing Performance</h2>
                    </div>
                    <div class="mt-0.5 text-[11px] text-zinc-500">Pantau target vs realisasi akad & penjualan per proyek</div>
                </div>
                <div class="sm:w-40">
                    <flux:select wire:model.live="selectedTahun" size="sm" icon="calendar">
                        @foreach ($marketing['tahunOptions'] as $th)
                            <flux:select.option value="{{ $th }}">{{ $th }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            {{-- Matrix Akad YTD --}}
            @php
                $akadRealTotal = collect($marketing['akadReal'])->sum();
                $akadTargetTotal = collect($marketing['akadTarget'])->sum();
                $penjualanRealTotal = collect($marketing['penjualanReal'])->sum();
                $penjualanTargetTotal = collect($marketing['penjualanTarget'])->sum();
            @endphp

            <div class="mb-4 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 bg-emerald-50 px-3 py-2 dark:border-zinc-700 dark:bg-emerald-950/30">
                    <span class="inline-flex items-center gap-1.5 rounded bg-emerald-600 px-2 py-0.5 text-[10px] font-bold uppercase text-white">
                        <flux:icon.tag class="size-3" /> Akad progress
                    </span>
                    <span class="ml-2 text-xs font-semibold text-emerald-900 dark:text-emerald-100">YTD {{ $selectedTahun }}</span>
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="w-32 px-3 py-2 text-left"></th>
                            <th class="px-3 py-2 text-right font-bold">*ALL</th>
                            @foreach ($marketing['proyekList'] as $p)
                                <th class="px-3 py-2 text-right">{{ $p->nama_proyek }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 text-zinc-600">
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon.flag class="size-3.5 text-zinc-400" />
                                    <span class="font-semibold">TARGET</span>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-mono font-bold tabular-nums">{{ $num($akadTargetTotal) }}</td>
                            @foreach ($marketing['proyekList'] as $p)
                                @php $v = (int) ($marketing['akadTarget'][$p->id] ?? 0); @endphp
                                <td class="px-3 py-2 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300' : '' }}">{{ $num($v) }}</td>
                            @endforeach
                        </tr>
                        <tr class="bg-zinc-50/50 dark:bg-zinc-800/30">
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon.user-circle class="size-3.5 text-emerald-500" />
                                    <span class="font-semibold text-emerald-700 dark:text-emerald-400">REAL</span>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $num($akadRealTotal) }}</td>
                            @foreach ($marketing['proyekList'] as $p)
                                @php $v = (int) ($marketing['akadReal'][$p->id] ?? 0); @endphp
                                <td class="px-3 py-2 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300' : 'text-emerald-700 dark:text-emerald-400' }}">{{ $num($v) }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Matrix Penjualan YTD --}}
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 bg-blue-50 px-3 py-2 dark:border-zinc-700 dark:bg-blue-950/30">
                    <span class="inline-flex items-center gap-1.5 rounded bg-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase text-white">
                        <flux:icon.tag class="size-3" /> Penjualan YTD
                    </span>
                    <span class="ml-2 text-xs font-semibold text-blue-900 dark:text-blue-100">YTD {{ $selectedTahun }}</span>
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="w-32 px-3 py-2 text-left"></th>
                            <th class="px-3 py-2 text-right font-bold">*ALL</th>
                            @foreach ($marketing['proyekList'] as $p)
                                <th class="px-3 py-2 text-right">{{ $p->nama_proyek }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 text-zinc-600">
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon.flag class="size-3.5 text-zinc-400" />
                                    <span class="font-semibold">TARGET</span>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-mono font-bold tabular-nums">{{ $num($penjualanTargetTotal) }}</td>
                            @foreach ($marketing['proyekList'] as $p)
                                @php $v = (int) ($marketing['penjualanTarget'][$p->id] ?? 0); @endphp
                                <td class="px-3 py-2 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300' : '' }}">{{ $num($v) }}</td>
                            @endforeach
                        </tr>
                        <tr class="bg-zinc-50/50 dark:bg-zinc-800/30">
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:icon.user-circle class="size-3.5 text-blue-500" />
                                    <span class="font-semibold text-blue-700 dark:text-blue-400">REAL</span>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-blue-700 dark:text-blue-400">{{ $num($penjualanRealTotal) }}</td>
                            @foreach ($marketing['proyekList'] as $p)
                                @php $v = (int) ($marketing['penjualanReal'][$p->id] ?? 0); @endphp
                                <td class="px-3 py-2 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300' : 'text-blue-700 dark:text-blue-400' }}">{{ $num($v) }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ═════════════ PERSEDIAAN RUMAH (matrix per proyek) ═════════════ --}}
        @php
            $metricColorMap = [
                'sky' => 'bg-sky-500', 'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500',
                'indigo' => 'bg-indigo-500', 'rose' => 'bg-rose-500', 'orange' => 'bg-orange-500',
            ];
        @endphp
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.home-modern class="size-5 text-emerald-600" />
                <h2 class="text-base font-bold">Persediaan Rumah</h2>
                <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Proyek to Date</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="w-56 px-3 py-2 text-left"></th>
                            <th class="px-3 py-2 text-right font-bold">*ALL</th>
                            @foreach ($persediaan['proyekList'] as $p)
                                <th class="px-3 py-2 text-right">{{ $p->nama_proyek }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($persediaan['metrics'] as $m)
                            @php $totalRow = collect($m['values'])->sum(); @endphp
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="flex h-7 w-7 items-center justify-center rounded text-white shadow-sm {{ $metricColorMap[$m['color']] ?? 'bg-zinc-500' }}">
                                            <flux:icon :name="$m['icon']" class="size-4" />
                                        </span>
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $m['label'] }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-sm font-bold tabular-nums">{{ $num($totalRow) }}</td>
                                @foreach ($persediaan['proyekList'] as $p)
                                    @php $v = (int) ($m['values'][$p->id] ?? 0); @endphp
                                    <td class="px-3 py-2 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300' : 'text-zinc-800 dark:text-zinc-100' }}">{{ $num($v) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ═════════════ SECTION 1: RINGKASAN KEUANGAN ═════════════ --}}
        <div class="mb-6">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.banknotes class="size-4 text-zinc-500" />
                <h2 class="text-sm font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Ringkasan Keuangan</h2>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {{-- KAS --}}
                <div class="rounded-xl border border-emerald-200 bg-linear-to-br from-emerald-50 to-white p-4 shadow-sm dark:border-emerald-900/40 dark:from-emerald-950/30 dark:to-zinc-900">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Kas</div>
                            <div class="mt-1 font-mono text-xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">{{ $fmt($keuangan['kas']) }}</div>
                        </div>
                        <div class="rounded-lg bg-emerald-600 p-2 text-white shadow-sm">
                            <flux:icon.banknotes class="size-5" />
                        </div>
                    </div>
                    <div class="mt-2 text-[10px] text-zinc-500">Saldo COA 1001 (Kas Pusat, Proyek, Teknik)</div>
                </div>

                {{-- BANK --}}
                <div class="rounded-xl border border-blue-200 bg-linear-to-br from-blue-50 to-white p-4 shadow-sm dark:border-blue-900/40 dark:from-blue-950/30 dark:to-zinc-900">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:text-blue-400">Bank</div>
                            <div class="mt-1 font-mono text-xl font-bold tabular-nums text-blue-900 dark:text-blue-100">{{ $fmt($keuangan['bank']) }}</div>
                        </div>
                        <div class="rounded-lg bg-blue-600 p-2 text-white shadow-sm">
                            <flux:icon.building-library class="size-5" />
                        </div>
                    </div>
                    <div class="mt-2 text-[10px] text-zinc-500">Saldo COA 1002 (BTN, Nobu, BNI, BCA, DKI)</div>
                </div>

                {{-- PIUTANG --}}
                <div class="rounded-xl border border-amber-200 bg-linear-to-br from-amber-50 to-white p-4 shadow-sm dark:border-amber-900/40 dark:from-amber-950/30 dark:to-zinc-900">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-400">Piutang</div>
                            <div class="mt-1 font-mono text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100">{{ $fmt($keuangan['piutang']) }}</div>
                        </div>
                        <div class="rounded-lg bg-amber-600 p-2 text-white shadow-sm">
                            <flux:icon.arrow-trending-up class="size-5" />
                        </div>
                    </div>
                    <div class="mt-2 text-[10px] text-zinc-500">Saldo COA 1007 (BTN, Konsumen, Karyawan, dll)</div>
                </div>

                {{-- HUTANG --}}
                <div class="rounded-xl border border-rose-200 bg-linear-to-br from-rose-50 to-white p-4 shadow-sm dark:border-rose-900/40 dark:from-rose-950/30 dark:to-zinc-900">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wide text-rose-700 dark:text-rose-400">Hutang</div>
                            <div class="mt-1 font-mono text-xl font-bold tabular-nums text-rose-900 dark:text-rose-100">{{ $fmt($keuangan['hutang']) }}</div>
                        </div>
                        <div class="rounded-lg bg-rose-600 p-2 text-white shadow-sm">
                            <flux:icon.arrow-trending-down class="size-5" />
                        </div>
                    </div>
                    <div class="mt-2 text-[10px] text-zinc-500">Saldo COA 2001 + 2002 (Hutang SPK)</div>
                </div>
            </div>
        </div>

        {{-- ═════════════ SECTION 2: STOK UNIT ═════════════ --}}
        <div class="mb-6">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.home-modern class="size-4 text-zinc-500" />
                <h2 class="text-sm font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Ringkasan Stok Unit</h2>
            </div>
            <div class="grid gap-3 lg:grid-cols-3">
                {{-- 3 total card --}}
                <div class="grid grid-cols-2 gap-3 lg:col-span-1 lg:grid-cols-1">
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">Total Unit</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($stokTotal['total']) }}</div>
                        <div class="mt-1 text-[10px] text-zinc-500">Semua unit yang terdaftar di sistem</div>
                    </div>
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm dark:border-emerald-900/40 dark:bg-emerald-950/30">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Terjual</div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-100">{{ number_format($stokTotal['terjual']) }}</div>
                        <div class="mt-1 text-[10px] text-emerald-700 dark:text-emerald-400">
                            {{ $stokTotal['total'] > 0 ? number_format($stokTotal['terjual'] / $stokTotal['total'] * 100, 1) : 0 }}% dari total
                        </div>
                    </div>
                </div>

                {{-- Breakdown per proyek --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                    <div class="mb-3 text-[10px] font-bold uppercase tracking-wide text-zinc-500">Breakdown per Proyek</div>
                    <table class="w-full text-xs">
                        <thead class="text-[10px] uppercase text-zinc-400">
                            <tr>
                                <th class="pb-2 text-left">Proyek</th>
                                <th class="pb-2 text-right">Total</th>
                                <th class="pb-2 text-right">Terjual</th>
                                <th class="pb-2 text-right">Booking</th>
                                <th class="pb-2 text-right">Available</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($stokProyek as $p)
                                <tr>
                                    <td class="py-2 font-semibold">{{ $p->nama_proyek }}</td>
                                    <td class="py-2 text-right font-mono tabular-nums">{{ number_format($p->total) }}</td>
                                    <td class="py-2 text-right font-mono tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($p->terjual) }}</td>
                                    <td class="py-2 text-right font-mono tabular-nums text-blue-700 dark:text-blue-400">{{ number_format($p->booking) }}</td>
                                    <td class="py-2 text-right font-mono tabular-nums text-zinc-500">{{ number_format($p->available) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-center text-zinc-400">Belum ada proyek</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ═════════════ SECTION 3: PENJUALAN & AKAD ═════════════ --}}
        <div class="mb-6">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.document-check class="size-4 text-zinc-500" />
                <h2 class="text-sm font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Penjualan & Akad</h2>
            </div>
            <div class="grid gap-3 lg:grid-cols-3">
                {{-- SPR baru + Akad card --}}
                <div class="space-y-3">
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-center justify-between">
                            <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">SPR Baru Bulan Ini</div>
                            <flux:icon.document-plus class="size-4 text-zinc-400" />
                        </div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($sprBaruBulanIni) }}</div>
                        <div class="mt-1 text-[10px] text-zinc-500">YTD: <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ number_format($sprBaruYtd) }}</span> SPR</div>
                    </div>
                    <div class="rounded-xl border border-violet-200 bg-violet-50 p-4 shadow-sm dark:border-violet-900/40 dark:bg-violet-950/30">
                        <div class="flex items-center justify-between">
                            <div class="text-[10px] font-bold uppercase tracking-wide text-violet-700 dark:text-violet-400">Akad Bulan Ini</div>
                            <flux:icon.check-badge class="size-4 text-violet-500" />
                        </div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-violet-800 dark:text-violet-100">{{ number_format($akadBulanIni) }}</div>
                        <div class="mt-1 text-[10px] text-violet-700 dark:text-violet-400">
                            YTD: <span class="font-semibold">{{ number_format($akadYtd) }}</span> · Total: <span class="font-semibold">{{ number_format($akadTotal) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Grafik trend akad 12 bulan --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">Trend Akad 12 Bulan Terakhir</div>
                        <div class="text-[10px] text-zinc-400">Puncak: {{ $trendMaxCount }} akad/bulan</div>
                    </div>
                    <div class="flex h-40 items-end gap-1">
                        @foreach ($trendAkad as $t)
                            @php
                                $h = $t['count'] > 0 ? max(4, (int) ($t['count'] / $trendMaxCount * 100)) : 2;
                            @endphp
                            <div class="group flex flex-1 flex-col items-center gap-1">
                                <div class="relative flex w-full flex-col items-center">
                                    <span class="mb-1 text-[10px] font-mono font-semibold tabular-nums text-zinc-600 dark:text-zinc-300">
                                        {{ $t['count'] > 0 ? $t['count'] : '' }}
                                    </span>
                                    <div class="w-full rounded-t bg-linear-to-t from-violet-600 to-violet-400 transition group-hover:from-violet-700 group-hover:to-violet-500"
                                        style="height: {{ $h }}px" title="{{ $t['label'] }}: {{ $t['count'] }} akad"></div>
                                </div>
                                <div class="text-[9px] text-zinc-400">{{ $t['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- ═════════════ SECTION 4: REALISASI UM + PROSPECT ═════════════ --}}
        <div class="mb-6 grid gap-3 lg:grid-cols-2">
            {{-- Realisasi UM --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-2 flex items-center gap-2">
                    <flux:icon.currency-dollar class="size-4 text-emerald-500" />
                    <h3 class="text-xs font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Realisasi UM Konsumen</h3>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-[10px] uppercase text-zinc-500">Bulan Ini</div>
                        <div class="mt-1 font-mono text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $fmt($umBulanIni) }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase text-zinc-500">Year-to-Date</div>
                        <div class="mt-1 font-mono text-lg font-bold tabular-nums text-zinc-800 dark:text-zinc-200">{{ $fmt($umYtd) }}</div>
                    </div>
                </div>
                <div class="mt-3 border-t border-zinc-100 pt-2 text-[10px] text-zinc-500 dark:border-zinc-800">
                    Total pembayaran BF (booking fee) + UM (uang muka) yang masuk
                </div>
            </div>

            {{-- Prospect funnel ringkas --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-2 flex items-center gap-2">
                    <flux:icon.users class="size-4 text-blue-500" />
                    <h3 class="text-xs font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Prospect Customer</h3>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-[10px] uppercase text-zinc-500">Total Prospect</div>
                        <div class="mt-1 font-mono text-lg font-bold tabular-nums">{{ number_format($prospectTotal) }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase text-zinc-500">Sudah Finish (Deal)</div>
                        <div class="mt-1 font-mono text-lg font-bold tabular-nums text-blue-700 dark:text-blue-400">{{ number_format($prospectFinish) }}</div>
                    </div>
                </div>
                <div class="mt-3 border-t border-zinc-100 pt-2 text-[10px] text-zinc-500 dark:border-zinc-800">
                    Conversion rate:
                    <span class="font-semibold text-blue-700 dark:text-blue-400">
                        {{ $prospectTotal > 0 ? number_format($prospectFinish / $prospectTotal * 100, 1) : 0 }}%
                    </span>
                </div>
            </div>
        </div>

        {{-- Shortcut set target --}}
        @can('target.kelola')
            <div class="mt-6 flex items-center justify-between rounded-lg border border-violet-200 bg-violet-50/60 p-3 dark:border-violet-900/40 dark:bg-violet-950/20">
                <div class="text-[11px] text-violet-800 dark:text-violet-300">
                    <flux:icon.flag class="mr-1 inline size-3.5" />
                    Target akad & penjualan bulanan tahun {{ $selectedTahun }} bisa di-input di menu <span class="font-bold">Marketing → Target / RAB</span>.
                </div>
                <flux:button size="xs" variant="primary" icon="flag" :href="route('marketing.target.index', ['tahun' => $selectedTahun])" wire:navigate>
                    Set Target
                </flux:button>
            </div>
        @endcan
    </div>
</section>
