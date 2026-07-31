<?php

use App\Exports\LaporanExport;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sales;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\TipeRumah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new #[Title('Laporan')] class extends Component
{
    use WithPagination;

    #[Url(as: 'c')]
    public string $category = 'penjualan';

    #[Url(as: 't')]
    public string $tab = 'penjualan';

    #[Url(as: 'p')]
    public string $period = 'all';

    /** Diambil dari session global 'active_proyek_id' (dipilih di sidebar). */
    public ?int $filterProyek = null;

    #[Url(as: 'sales')]
    public ?int $filterSales = null;

    #[Url(as: 'tipe')]
    public ?int $filterTipe = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    #[Url(as: 'sort')]
    public string $sortCol = '';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    #[Url(as: 'pp')]
    public int $perPage = 10;

    public function mount(): void
    {
        $this->filterProyek = session('active_proyek_id');
        $this->syncTabToCategory();
    }

    public function updatedCategory(): void
    {
        $this->syncTabToCategory();
    }

    private function syncTabToCategory(): void
    {
        $currentTabCategory = self::TABS[$this->tab][3] ?? null;
        if ($currentTabCategory !== $this->category) {
            $firstTab = collect(self::TABS)->filter(fn ($v) => $v[3] === $this->category)->keys()->first();
            if ($firstTab) $this->tab = $firstTab;
        }
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->filterProyek = $proyekId;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->filterSales = null;
        $this->filterTipe = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->period = 'all';
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFilterSales(): void { $this->resetPage(); }
    public function updatingFilterTipe(): void { $this->resetPage(); }
    public function updatingDateFrom(): void { $this->resetPage(); }
    public function updatingDateTo(): void { $this->resetPage(); }
    public function updatingPerPage(): void { $this->resetPage(); }

    private function effectivePerPage(): int
    {
        // 0 = "Semua" — pakai angka besar biar semua row masuk 1 page
        return $this->perPage > 0 ? $this->perPage : 10000;
    }

    public function setSort(string $col): void
    {
        if ($this->sortCol === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortCol = $col;
            $this->sortDir = 'desc';
        }
        $this->resetPage();
    }

    /**
     * Terapkan sort ke Eloquent query berdasarkan $this->sortCol.
     * $map = ['sortKey' => 'sql_column' | ['sql_column1', 'sql_column2']]
     */
    private function applySort($query, array $map, string $defaultCol, string $defaultDir = 'desc')
    {
        $col = array_key_exists($this->sortCol, $map) ? $this->sortCol : $defaultCol;
        $dir = in_array($this->sortDir, ['asc', 'desc']) ? $this->sortDir : $defaultDir;
        $target = $map[$col];
        foreach ((array) $target as $c) {
            $query->orderBy($c, $dir);
        }
        return $query;
    }

    public function exportExcel()
    {
        if (! $this->filterProyek) {
            $this->dispatch('toast', message: 'Pilih proyek dulu dari sidebar', variant: 'warning');
            return;
        }

        [$from, $to] = $this->periodRange();
        $proyek = Proyek::find($this->filterProyek);
        $tabName = self::TABS[$this->tab][0] ?? 'Laporan';
        $filename = sprintf(
            'laporan-%s-%s-%s.xlsx',
            \Illuminate\Support\Str::slug($tabName),
            \Illuminate\Support\Str::slug($proyek?->nama_proyek ?? 'all'),
            now()->format('Ymd-His'),
        );

        return Excel::download(
            new LaporanExport(
                tab: $this->tab,
                proyekId: $this->filterProyek,
                salesId: $this->filterSales,
                tipeId: $this->filterTipe,
                search: $this->search ?: null,
                from: $from,
                to: $to,
            ),
            $filename,
        );
    }

    public const TABS = [
        'penjualan'    => ['Penjualan',        'chart-bar',        'emerald', 'penjualan'],
        'stock'        => ['Stok Unit',        'cube',             'blue',    'penjualan'],
        'pembatalan'   => ['Pembatalan',       'x-circle',         'rose',    'penjualan'],
        'performance'  => ['Peringkat Sales',  'trophy',           'indigo',  'penjualan'],
        'realisasi'    => ['Kwitansi Masuk',   'banknotes',        'purple',  'keuangan'],
        'outstanding'  => ['Tunggakan UM',     'clock',            'amber',   'keuangan'],
    ];

    public const CATEGORIES = [
        'penjualan' => ['Penjualan', 'chart-bar', 'emerald'],
        'keuangan'  => ['Keuangan',  'banknotes', 'purple'],
    ];

    public const PERIODS = [
        'mtd' => 'Bulan Ini',
        'qtd' => '3 Bulan Terakhir',
        'ytd' => 'Tahun Berjalan',
        'all' => 'Semua Data',
    ];

    public function setTab(string $t): void
    {
        if (array_key_exists($t, self::TABS)) {
            $this->tab = $t;
            $this->category = self::TABS[$t][3];
        }
    }

public function setPeriod(string $p): void
    {
        if (array_key_exists($p, self::PERIODS)) $this->period = $p;
    }

    private function periodRange(): array
    {
        // Date range custom override preset period.
        if ($this->dateFrom || $this->dateTo) {
            $from = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::create(2020, 1, 1);
            $to = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : now()->endOfDay();
            return [$from, $to];
        }
        return match ($this->period) {
            'mtd' => [now()->startOfMonth(), now()->endOfMonth()],
            'qtd' => [now()->subMonths(3)->startOfDay(), now()->endOfDay()],
            'ytd' => [now()->startOfYear(), now()->endOfYear()],
            default => [Carbon::create(2020, 1, 1), now()->endOfDay()],
        };
    }

    public function with(): array
    {
        [$from, $to] = $this->periodRange();

        $proyekAktif = $this->filterProyek ? Proyek::find($this->filterProyek) : null;
        $salesList = Sales::where('is_aktif', true)->orderBy('nama')->get(['id', 'kode', 'nama']);
        $tipeList = TipeRumah::when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek))->orderBy('tipe')->get(['id', 'tipe', 'nama_tipe']);

        // Kalau belum pilih proyek, jangan render data apapun.
        if (! $this->filterProyek) {
            return compact('proyekAktif', 'salesList', 'tipeList');
        }

        $data = match ($this->tab) {
            'penjualan' => $this->dataPenjualan($from, $to),
            'stock' => $this->dataStock(),
            'realisasi' => $this->dataRealisasi($from, $to),
            'outstanding' => $this->dataOutstanding(),
            'pembatalan' => $this->dataPembatalan($from, $to),
            'performance' => $this->dataPerformance($from, $to),
            default => [],
        };

        return array_merge($data, compact('proyekAktif', 'salesList', 'tipeList'));
    }

    private function baseSprQuery(?array $statusFilter = null)
    {
        $q = Spr::query()->with(['prospectCustomer', 'rumah.tipeRumah', 'rumah.proyek', 'sales']);
        if ($statusFilter) $q->whereIn('spr.status', $statusFilter);
        if ($this->filterProyek) $q->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));
        if ($this->filterSales) $q->where('spr.sales_id', $this->filterSales);
        if ($this->filterTipe) $q->whereHas('rumah', fn ($r) => $r->where('tipe_rumah_id', $this->filterTipe));
        if ($this->search !== '') {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('spr.nomor_spr', 'like', "%{$s}%")
                    ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%"))
                    ->orWhereHas('rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", ["%{$s}%"]));
            });
        }
        return $q;
    }

    private function dataPenjualan($from, $to): array
    {
        $query = $this->baseSprQuery(['approved', 'akad'])
            ->whereBetween('spr.tanggal_spr', [$from, $to]);

        $sortMap = [
            'tanggal_spr' => 'spr.tanggal_spr',
            'nomor_spr' => 'spr.nomor_spr',
            'total_harga' => 'spr.total_harga',
            'nilai_kpr' => 'spr.nilai_kpr',
        ];
        $sprs = $this->applySort((clone $query), $sortMap, 'tanggal_spr', 'desc')
            ->paginate($this->effectivePerPage());

        $totalUnit = (clone $query)->count();
        $totalNilai = (float) (clone $query)->sum('total_harga');
        $totalKpr = (float) (clone $query)->sum('nilai_kpr');
        $avgTicket = $totalUnit > 0 ? $totalNilai / $totalUnit : 0;

        // Per tipe rumah
        $perTipe = (clone $query)->join('rumah', 'rumah.id', '=', 'spr.rumah_id')
            ->join('tipe_rumah', 'tipe_rumah.id', '=', 'rumah.tipe_rumah_id')
            ->selectRaw('tipe_rumah.tipe as tipe, COUNT(*) as cnt, SUM(spr.total_harga) as nilai')
            ->groupBy('tipe_rumah.tipe')
            ->get();

        return compact('sprs', 'totalUnit', 'totalNilai', 'totalKpr', 'avgTicket', 'perTipe');
    }

    private function dataStock(): array
    {
        $q = Rumah::query()->with(['tipeRumah', 'proyek']);
        if ($this->filterProyek) $q->where('proyek_id', $this->filterProyek);
        if ($this->filterTipe) $q->where('tipe_rumah_id', $this->filterTipe);
        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn ($qq) => $qq->where('blok', 'like', "%{$s}%")->orWhere('nomor_unit', 'like', "%{$s}%")->orWhereRaw("CONCAT(blok,'-',nomor_unit) like ?", ["%{$s}%"]));
        }

        $totalUnit = (clone $q)->count();
        $terjual = (clone $q)->where('status', 'terjual')->count();
        $booking = (clone $q)->where('status', 'booking')->count();
        $available = (clone $q)->where('status', 'available')->count();
        $draft = (clone $q)->where('status', 'draft')->count();

        // Per tipe
        $perTipe = TipeRumah::query()
            ->withCount([
                'rumah',
                'rumah as terjual_cnt' => fn ($q) => $q->where('status', 'terjual'),
                'rumah as available_cnt' => fn ($q) => $q->where('status', 'available'),
                'rumah as booking_cnt' => fn ($q) => $q->where('status', 'booking'),
            ])
            ->when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek))
            ->get();

        // Per blok
        $perBlok = (clone $q)->selectRaw('blok, COUNT(*) as cnt,
                SUM(CASE WHEN status="terjual" THEN 1 ELSE 0 END) as terjual_cnt,
                SUM(CASE WHEN status="available" THEN 1 ELSE 0 END) as available_cnt,
                SUM(CASE WHEN status="booking" THEN 1 ELSE 0 END) as booking_cnt')
            ->groupBy('blok')
            ->orderBy('blok')
            ->get();

        $sortMap = [
            'blok' => ['blok', 'nomor_unit'],
            'nomor_unit' => 'nomor_unit',
            'status' => 'status',
            'harga_jual' => 'harga_jual',
        ];
        $rumahList = $this->applySort((clone $q), $sortMap, 'blok', 'asc')
            ->paginate($this->effectivePerPage());

        return compact('rumahList', 'totalUnit', 'terjual', 'booking', 'available', 'draft', 'perTipe', 'perBlok');
    }

    private function dataRealisasi($from, $to): array
    {
        $q = SprRealisasiPembayaran::query()->with(['spr.prospectCustomer', 'spr.sales', 'spr.rumah'])
            ->whereBetween('tanggal_bayar', [$from, $to]);
        if ($this->filterProyek) {
            $q->whereHas('spr.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));
        }
        if ($this->filterSales) {
            $q->whereHas('spr', fn ($s) => $s->where('sales_id', $this->filterSales));
        }
        if ($this->filterTipe) {
            $q->whereHas('spr.rumah', fn ($r) => $r->where('tipe_rumah_id', $this->filterTipe));
        }
        if ($this->search !== '') {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('nomor_kwitansi', 'like', "%{$s}%")
                    ->orWhereHas('spr', fn ($sp) => $sp->where('nomor_spr', 'like', "%{$s}%"))
                    ->orWhereHas('spr.prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%"));
            });
        }

        $totalRealisasi = (clone $q)->count();
        $totalNilai = (float) (clone $q)->sum('jumlah');
        $utjNilai = (float) (clone $q)->where('jenis', 'bf')->sum('jumlah');
        $umNilai = (float) (clone $q)->where('jenis', 'um')->sum('jumlah');

        $sortMap = [
            'tanggal_bayar' => ['tanggal_bayar', 'id'],
            'nomor_kwitansi' => 'nomor_kwitansi',
            'jenis' => 'jenis',
            'jumlah' => 'jumlah',
        ];
        $realisasi = $this->applySort((clone $q), $sortMap, 'tanggal_bayar', 'desc')
            ->paginate($this->effectivePerPage());

        // Per jenis breakdown (UTJ/UM/SBUM/KPR)
        $perJenis = (clone $q)->selectRaw('jenis, COUNT(*) as cnt, SUM(jumlah) as total')
            ->groupBy('jenis')->get();

        // Per metode (Transfer / Tunai)
        $perMetode = (clone $q)->selectRaw('metode, COUNT(*) as cnt, SUM(jumlah) as total')
            ->groupBy('metode')->get();

        // Per bank tujuan — approximate: untuk metode transfer, ambil VA rumah pertama & bank-nya.
        // Untuk tunai, bucket "Kas / Tunai".
        $realisasiList = (clone $q)->with(['spr.rumah.virtualAccount.bank'])->get();
        $perBankMap = [];
        foreach ($realisasiList as $r) {
            if ($r->metode === 'tunai') {
                $key = 'Kas / Tunai';
            } else {
                $va = $r->spr?->rumah?->virtualAccount?->firstWhere('is_aktif', true)
                    ?? $r->spr?->rumah?->virtualAccount?->first();
                $key = $va?->bank?->nama ? "{$va->bank->nama} ({$va->nomor_va})" : 'Tidak Diketahui';
            }
            if (! isset($perBankMap[$key])) {
                $perBankMap[$key] = ['nama' => $key, 'cnt' => 0, 'total' => 0.0];
            }
            $perBankMap[$key]['cnt']++;
            $perBankMap[$key]['total'] += (float) $r->jumlah;
        }
        $perBank = collect(array_values($perBankMap))->sortByDesc('total')->values();

        return compact('realisasi', 'totalRealisasi', 'totalNilai', 'utjNilai', 'umNilai', 'perJenis', 'perMetode', 'perBank');
    }

    private function dataOutstanding(): array
    {
        // SPR approved dengan sisa UM > 0
        $sprs = $this->baseSprQuery(['approved'])->get();
        $rows = [];
        $totalOutstanding = 0.0;
        $totalUmNet = 0.0;
        $totalDibayar = 0.0;

        foreach ($sprs as $spr) {
            $umNet = (float) $spr->um_net;
            $totalUmNet += $umNet;
            $dibayar = (float) SprRealisasiPembayaran::where('spr_id', $spr->id)
                ->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
            $totalDibayar += $dibayar;
            $sisa = max(0, $umNet - $dibayar);
            if ($sisa <= 0) continue;
            $totalOutstanding += $sisa;

            $tglAwal = $spr->utj_tanggal_transaksi ?: $spr->tanggal_spr;
            $ageDays = $tglAwal ? $tglAwal->diffInDays(now()) : 0;
            $ageBucket = match (true) {
                $ageDays <= 30 => '0-30',
                $ageDays <= 60 => '31-60',
                $ageDays <= 90 => '61-90',
                default => '>90',
            };

            $rows[] = (object) [
                'spr' => $spr,
                'um_net' => $umNet,
                'dibayar' => $dibayar,
                'sisa' => $sisa,
                'progress' => $umNet > 0 ? (int) round(($dibayar / $umNet) * 100) : 0,
                'age_days' => $ageDays,
                'age_bucket' => $ageBucket,
            ];
        }

        $sortCol = $this->sortCol;
        $sortDir = $this->sortDir;
        $accessor = fn ($r) => match ($sortCol) {
            'customer' => $r->spr->prospectCustomer?->nama_lengkap ?? '',
            'unit' => ($r->spr->rumah?->blok ?? '').'-'.($r->spr->rumah?->nomor_unit ?? ''),
            'sales' => $r->spr->sales?->nama ?? '',
            'um_net' => $r->um_net,
            'dibayar' => $r->dibayar,
            'progress' => $r->progress,
            'age_days' => $r->age_days,
            'nomor_spr' => $r->spr->nomor_spr,
            default => $r->sisa,
        };
        usort($rows, function ($a, $b) use ($accessor, $sortDir) {
            $cmp = $accessor($a) <=> $accessor($b);
            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        $ageBuckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '>90' => 0];
        foreach ($rows as $r) $ageBuckets[$r->age_bucket] += $r->sisa;

        // Manual paginate array
        $page = request()->input('page', 1);
        $pp = $this->effectivePerPage();
        $total = count($rows);
        $pagedRows = array_slice($rows, ($page - 1) * $pp, $pp);
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $pagedRows, $total, $pp, $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'outstandingRows' => $paginator,
            'totalOutstanding' => $totalOutstanding,
            'totalUmNet' => $totalUmNet,
            'totalDibayarUm' => $totalDibayar,
            'ageBuckets' => $ageBuckets,
        ];
    }

    private function dataPembatalan($from, $to): array
    {
        $query = $this->baseSprQuery(['cancelled'])
            ->with('alasanPembatalan')
            ->whereBetween('spr.tanggal_spr', [$from, $to]);
        $sortMap = [
            'cancelled_at' => 'spr.cancelled_at',
            'nomor_spr' => 'spr.nomor_spr',
            'refund_amount' => 'spr.refund_amount',
            'refund_status' => 'spr.refund_status',
        ];
        $sprs = $this->applySort((clone $query), $sortMap, 'cancelled_at', 'desc')
            ->paginate($this->effectivePerPage());

        $totalBatal = (clone $query)->count();
        $totalRefund = (float) (clone $query)->sum('refund_amount');
        $refundSelesai = (clone $query)->where('refund_status', 'full')->count();
        $refundPending = (clone $query)->where('refund_status', 'pending')->count();
        $tidakAdaRefund = (clone $query)->where('refund_status', 'tidak_ada_refund')->count();

        $perAlasan = (clone $query)->join('alasan_pembatalan', 'alasan_pembatalan.id', '=', 'spr.alasan_pembatalan_id')
            ->selectRaw('alasan_pembatalan.nama as alasan, COUNT(*) as cnt')
            ->groupBy('alasan_pembatalan.nama')
            ->get();

        return compact('sprs', 'totalBatal', 'totalRefund', 'refundSelesai', 'refundPending', 'tidakAdaRefund', 'perAlasan');
    }

    private function dataPerformance($from, $to): array
    {
        $baseQuery = Spr::query()
            ->whereIn('spr.status', ['approved', 'akad'])
            ->whereBetween('spr.tanggal_spr', [$from, $to]);
        if ($this->filterProyek) $baseQuery->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));

        $sprIds = (clone $baseQuery)->pluck('id');

        $rankingQuery = Sales::query()
            ->leftJoin('spr', function ($j) use ($sprIds) {
                $j->on('spr.sales_id', '=', 'sales.id')->whereIn('spr.id', $sprIds);
            })
            ->selectRaw('sales.id, sales.kode, sales.nama')
            ->selectRaw('COUNT(spr.id) as spr_count')
            ->selectRaw('COALESCE(SUM(spr.total_harga), 0) as total_nilai')
            ->selectRaw('COALESCE(SUM(spr.nilai_kpr), 0) as total_kpr')
            ->groupBy('sales.id', 'sales.kode', 'sales.nama');

        $sortMap = [
            'nama' => 'sales.nama',
            'spr_count' => 'spr_count',
            'total_nilai' => 'total_nilai',
            'total_kpr' => 'total_kpr',
        ];
        $ranking = $this->applySort($rankingQuery, $sortMap, 'total_nilai', 'desc')->get();

        // Realisasi per sales
        $realisasiPerSales = SprRealisasiPembayaran::query()
            ->join('spr', 'spr.id', '=', 'spr_realisasi_pembayaran.spr_id')
            ->whereBetween('spr_realisasi_pembayaran.tanggal_bayar', [$from, $to])
            ->when($this->filterProyek, fn ($q) => $q->join('rumah', 'rumah.id', '=', 'spr.rumah_id')->where('rumah.proyek_id', $this->filterProyek))
            ->selectRaw('spr.sales_id, SUM(spr_realisasi_pembayaran.jumlah) as total_masuk')
            ->groupBy('spr.sales_id')
            ->pluck('total_masuk', 'sales_id');

        return compact('ranking', 'realisasiPerSales');
    }
}; ?>

@php
    $fmtJt = function ($v) {
        $v = (float) $v;
        if ($v >= 1_000_000_000) return number_format($v / 1_000_000_000, 2, ',', '.').' M';
        if ($v >= 1_000_000) return number_format($v / 1_000_000, 1, ',', '.').' jt';
        if ($v >= 1_000) return number_format($v / 1_000, 0, ',', '.').' rb';
        return number_format($v, 0, ',', '.');
    };
    $fmt = fn ($v) => number_format((float) $v, 0, ',', '.');
    $arrow = fn ($col, $active, $dir) => $active === $col ? ($dir === 'asc' ? '↑' : '↓') : '';
    $thBtn = function ($col, $label, $align = 'left') use ($sortCol, $sortDir, $arrow) {
        $active = $sortCol === $col;
        $alignClass = $align === 'right' ? 'justify-end text-right' : 'justify-start text-left';
        $colorClass = $active ? 'text-emerald-600' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200';
        return '<button type="button" wire:click="setSort(\''.$col.'\')" class="inline-flex items-center gap-1 w-full '.$alignClass.' '.$colorClass.'">'.
            e($label).' <span class="text-[9px]">'.$arrow($col, $sortCol, $sortDir).'</span></button>';
    };
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        @php $catLabel = $this::CATEGORIES[$category][0] ?? 'Penjualan'; @endphp
        <div class="mb-5">
            <flux:heading size="xl">{{ __('Laporan :cat', ['cat' => $catLabel]) }}</flux:heading>
            <flux:subheading>
                @if ($proyekAktif)
                    <span class="font-semibold">{{ $proyekAktif->nama_proyek }}</span>
                @else
                    {{ __('Pilih proyek dari sidebar untuk melihat laporan.') }}
                @endif
            </flux:subheading>
        </div>

        {{-- Empty state kalau belum pilih proyek --}}
        @if (! $filterProyek)
            <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.home-modern class="mx-auto mb-3 size-12 text-zinc-400" />
                <div class="mb-1 text-base font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Belum ada proyek dipilih') }}</div>
                <div class="text-sm text-zinc-500">{{ __('Pilih proyek dari picker di sidebar untuk mulai melihat laporan.') }}</div>
            </div>
        @else

        {{-- FILTER BAR --}}
        <div class="mb-5 space-y-3">
            {{-- Row 1: Search + Export button --}}
            <div class="flex flex-wrap items-center gap-3">
                @php
                    $searchPlaceholder = match ($tab) {
                        'stock' => 'Cari blok / nomor unit...',
                        'realisasi' => 'Cari nomor kwitansi / nomor SPR...',
                        'penjualan', 'pembatalan', 'outstanding' => 'Cari nomor SPR / nama customer / blok...',
                        default => null,
                    };
                @endphp
                @if ($searchPlaceholder)
                    <div class="relative flex-1 min-w-50 max-w-md">
                        <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                        <input type="search" wire:model.live.debounce.400ms="search"
                               placeholder="{{ $searchPlaceholder }}"
                               class="block h-9 w-full rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-xs shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    </div>
                @endif

                <button type="button" wire:click="exportExcel" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg border border-emerald-600 bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50">
                    <flux:icon.arrow-down-tray class="size-4" wire:loading.remove wire:target="exportExcel" />
                    <flux:icon.arrow-path class="size-4 animate-spin" wire:loading wire:target="exportExcel" />
                    <span wire:loading.remove wire:target="exportExcel">Export Excel</span>
                    <span wire:loading wire:target="exportExcel">Menyiapkan...</span>
                </button>

                @if ($search || $filterSales || $filterTipe || $dateFrom || $dateTo)
                    <button type="button" wire:click="resetFilters"
                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-[10px] font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                        <flux:icon.x-mark class="size-3" />
                        Reset
                    </button>
                @endif
            </div>

            {{-- Row 2: Period + Date range + Tipe + Sales --}}
            <div class="flex flex-wrap items-center gap-3">
                {{-- Period preset --}}
                <div class="inline-flex items-center rounded-lg border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($this::PERIODS as $k => $lbl)
                        @php $active = $period === $k && ! $dateFrom && ! $dateTo; @endphp
                        <button type="button" wire:click="setPeriod('{{ $k }}')"
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition',
                                    'bg-emerald-600 text-white shadow' => $active,
                                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $active,
                                ])>{{ $lbl }}</button>
                    @endforeach
                </div>

                {{-- Date Range custom --}}
                <div class="flex items-center gap-1">
                    <input type="date" wire:model.live="dateFrom"
                           class="rounded-lg border border-zinc-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    <span class="text-xs text-zinc-500">s/d</span>
                    <input type="date" wire:model.live="dateTo"
                           class="rounded-lg border border-zinc-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                </div>

                {{-- Tipe filter --}}
                <select wire:model.live="filterTipe" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    <option value="">— Semua Tipe —</option>
                    @foreach ($tipeList as $t)
                        <option value="{{ $t->id }}">{{ $t->tipe }} {{ $t->nama_tipe }}</option>
                    @endforeach
                </select>

                {{-- Sales filter --}}
                @if (in_array($tab, ['penjualan', 'realisasi', 'performance', 'outstanding', 'pembatalan']))
                    <select wire:model.live="filterSales" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                        <option value="">— Semua Sales —</option>
                        @foreach ($salesList as $s)
                            <option value="{{ $s->id }}">{{ $s->kode }} - {{ $s->nama }}</option>
                        @endforeach
                    </select>
                @endif

                {{-- Page size --}}
                @if ($tab !== 'performance')
                    <select wire:model.live="perPage" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                        @foreach ([10, 25, 50, 100, 0] as $pp)
                            <option value="{{ $pp }}">{{ $pp === 0 ? 'Semua' : $pp.' baris' }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>

        @php
            $colorMap = [
                'emerald' => 'border-emerald-600 text-emerald-700 dark:text-emerald-400',
                'blue' => 'border-blue-600 text-blue-700 dark:text-blue-400',
                'purple' => 'border-purple-600 text-purple-700 dark:text-purple-400',
                'amber' => 'border-amber-600 text-amber-700 dark:text-amber-400',
                'rose' => 'border-rose-600 text-rose-700 dark:text-rose-400',
                'indigo' => 'border-indigo-600 text-indigo-700 dark:text-indigo-400',
            ];
        @endphp

        {{-- TABS (filtered by category dari URL / sidebar) --}}
        <div class="mb-5 flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700">
            @foreach ($this::TABS as $key => [$label, $icon, $color, $cat])
                @continue ($cat !== $category)
                @php $active = $tab === $key; @endphp
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @class([
                            'flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition -mb-px',
                            $colorMap[$color] => $active,
                            'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200' => ! $active,
                        ])>
                    <flux:icon :name="$icon" class="size-4" />
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- ============ CONTENT PER TAB ============ --}}

        @if ($tab === 'penjualan')
            {{-- KPI Cards --}}
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Unit Terjual</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($totalUnit) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Nilai Kontrak</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalNilai) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Nilai KPR</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalKpr) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Rata-rata Nilai Kontrak</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($avgTicket) }}</div>
                </div>
            </div>

            {{-- Per Tipe --}}
            @if ($perTipe->isNotEmpty())
                <div class="mb-5 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 text-sm font-bold text-zinc-900 dark:text-white">Per Tipe Rumah</div>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        @foreach ($perTipe as $t)
                            <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                                <div class="text-xs font-semibold text-zinc-600">{{ $t->tipe }}</div>
                                <div class="mt-1 text-lg font-bold tabular-nums">{{ $t->cnt }} unit · Rp {{ $fmtJt($t->nilai) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Table --}}
            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-3 py-2">{!! $thBtn('nomor_spr', 'Nomor SPR') !!}</th>
                                <th class="px-3 py-2">{!! $thBtn('tanggal_spr', 'Tanggal') !!}</th>
                                <th class="px-3 py-2">Nama Konsumen</th>
                                <th class="px-3 py-2">Unit</th>
                                <th class="px-3 py-2">Tipe</th>
                                <th class="px-3 py-2">Sales</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('total_harga', 'Harga Jual', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('nilai_kpr', 'Nilai KPR', 'right') !!}</th>
                                <th class="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sprs as $spr)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono">{{ $spr->nomor_spr }}</td>
                                    <td class="px-3 py-2">{{ $spr->tanggal_spr?->format('d/m/y') }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $spr->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $spr->rumah?->blok }}-{{ $spr->rumah?->nomor_unit }}</td>
                                    <td class="px-3 py-2">{{ $spr->rumah?->tipeRumah?->tipe }}</td>
                                    <td class="px-3 py-2">{{ $spr->sales?->nama }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($spr->total_harga) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($spr->nilai_kpr) }}</td>
                                    <td class="px-3 py-2">
                                        @if ($spr->status === 'akad')
                                            <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700">Akad</span>
                                        @elseif ($spr->spr_finalized_at)
                                            <span class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700">Selesai</span>
                                        @else
                                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">Diproses</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-3 py-6 text-center text-zinc-400">Belum ada data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $sprs->links() }}</div>
            </div>
        @endif

        @if ($tab === 'stock')
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total Unit</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($totalUnit) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="text-[10px] font-bold uppercase text-emerald-700">Terjual</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-300">{{ number_format($terjual) }}</div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <div class="text-[10px] font-bold uppercase text-amber-700">Booking</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800 dark:text-amber-300">{{ number_format($booking) }}</div>
                </div>
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                    <div class="text-[10px] font-bold uppercase text-blue-700">Tersedia</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-blue-800 dark:text-blue-300">{{ number_format($available) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Draft</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($draft) }}</div>
                </div>
            </div>

            <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 text-sm font-bold">Per Tipe</div>
                    <table class="w-full text-xs">
                        <thead><tr class="text-left font-bold text-[10px] uppercase text-zinc-500">
                            <th class="py-1">Tipe</th><th class="py-1 text-right">Total</th><th class="py-1 text-right">Terjual</th><th class="py-1 text-right">Booking</th><th class="py-1 text-right">Tersedia</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($perTipe as $t)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="py-1.5 font-semibold">{{ $t->tipe }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums">{{ $t->rumah_count }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums text-emerald-700">{{ $t->terjual_cnt }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums text-amber-700">{{ $t->booking_cnt }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums text-blue-700">{{ $t->available_cnt }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 text-sm font-bold">Per Blok</div>
                    <table class="w-full text-xs">
                        <thead><tr class="text-left font-bold text-[10px] uppercase text-zinc-500">
                            <th class="py-1">Blok</th><th class="py-1 text-right">Total</th><th class="py-1 text-right">Terjual</th><th class="py-1 text-right">Booking</th><th class="py-1 text-right">Tersedia</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($perBlok as $b)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="py-1.5 font-mono font-semibold">{{ $b->blok }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums">{{ $b->cnt }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums text-emerald-700">{{ $b->terjual_cnt }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums text-amber-700">{{ $b->booking_cnt }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums text-blue-700">{{ $b->available_cnt }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-3 py-2">{!! $thBtn('blok', 'Blok-No') !!}</th>
                                <th class="px-3 py-2">Tipe</th>
                                <th class="px-3 py-2">Proyek</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('harga_jual', 'Harga Standar', 'right') !!}</th>
                                <th class="px-3 py-2">{!! $thBtn('status', 'Status') !!}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rumahList as $r)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono font-semibold">{{ $r->blok }}-{{ $r->nomor_unit }}</td>
                                    <td class="px-3 py-2">{{ $r->tipeRumah?->tipe }}</td>
                                    <td class="px-3 py-2">{{ $r->proyek?->nama_proyek }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($r->tipeRumah?->harga_jual ?? 0) }}</td>
                                    <td class="px-3 py-2">
                                        @php
                                            $color = ['terjual' => 'emerald', 'booking' => 'amber', 'available' => 'blue', 'draft' => 'zinc'][$r->status] ?? 'zinc';
                                            $statusLabel = ['terjual' => 'Terjual', 'booking' => 'Booking', 'available' => 'Tersedia', 'draft' => 'Draft'][$r->status] ?? ucfirst($r->status);
                                        @endphp
                                        <span class="rounded bg-{{ $color }}-100 px-1.5 py-0.5 text-[10px] font-semibold text-{{ $color }}-700">{{ $statusLabel }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $rumahList->links() }}</div>
            </div>
        @endif

        @if ($tab === 'realisasi')
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Jumlah Kwitansi</div>
                        <x-info-button title="Jumlah Kwitansi">Total kwitansi penerimaan yang tercatat pada periode terpilih.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($totalRealisasi) }}</div>
                </div>
                <div class="rounded-xl border border-purple-200 bg-purple-50 p-4 dark:border-purple-800 dark:bg-purple-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-purple-700">Total Uang Masuk</div>
                        <x-info-button title="Total Uang Masuk">Total nominal seluruh kwitansi (UTJ + Cicilan UM + SBUM + KPR). Catatan: uang masuk bukan pendapatan — pendapatan baru diakui saat akad kredit.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-purple-800 dark:text-purple-300">Rp {{ $fmtJt($totalNilai) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Total UTJ Diterima</div>
                        <x-info-button title="Total UTJ Diterima">Total Uang Tanda Jadi (booking fee) dari konsumen yang telah dikonfirmasi Keuangan.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($utjNilai) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Total Cicilan UM</div>
                        <x-info-button title="Total Cicilan UM">Total cicilan Uang Muka dari konsumen sebelum akad kredit.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($umNilai) }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-3 py-2">{!! $thBtn('nomor_kwitansi', 'Kwitansi') !!}</th>
                                <th class="px-3 py-2">{!! $thBtn('tanggal_bayar', 'Tanggal') !!}</th>
                                <th class="px-3 py-2">Nama Konsumen</th>
                                <th class="px-3 py-2">Nomor SPR</th>
                                <th class="px-3 py-2">Sales</th>
                                <th class="px-3 py-2">{!! $thBtn('jenis', 'Jenis') !!}</th>
                                <th class="px-3 py-2">Metode</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('jumlah', 'Nominal', 'right') !!}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($realisasi as $r)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono">{{ $r->nomor_kwitansi ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $r->tanggal_bayar?->format('d/m/y') }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $r->spr?->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-3 py-2 font-mono text-[10px]">{{ $r->spr?->nomor_spr }}</td>
                                    <td class="px-3 py-2">{{ $r->spr?->sales?->nama }}</td>
                                    <td class="px-3 py-2">
                                        @php
                                            $badge = $r->jenis === 'bf' ? 'purple' : ($r->jenis === 'um' ? 'blue' : ($r->jenis === 'sbum' ? 'emerald' : 'indigo'));
                                            $jenisLabel = ['bf' => 'UTJ', 'um' => 'UM', 'sbum' => 'SBUM', 'kpr' => 'KPR'][$r->jenis] ?? strtoupper($r->jenis);
                                        @endphp
                                        <span class="rounded bg-{{ $badge }}-100 px-1.5 py-0.5 text-[10px] font-semibold text-{{ $badge }}-700">{{ $jenisLabel }}</span>
                                    </td>
                                    <td class="px-3 py-2">{{ ucfirst($r->metode ?? '—') }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-bold tabular-nums">{{ $fmt($r->jumlah) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $realisasi->links() }}</div>
            </div>
        @endif

        @if ($tab === 'outstanding')
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <div class="text-[10px] font-bold uppercase text-amber-700">Total Tunggakan UM</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800 dark:text-amber-300">Rp {{ $fmtJt($totalOutstanding) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total UM Netto</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalUmNet) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="text-[10px] font-bold uppercase text-emerald-700">Sudah Terbayar</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-300">Rp {{ $fmtJt($totalDibayarUm) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">SPR Belum Lunas UM</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format(count($outstandingRows)) }}</div>
                </div>
            </div>

            <div class="mb-5 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 text-sm font-bold">Umur Tunggakan</div>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach ($ageBuckets as $b => $val)
                        @php $color = match($b) { '0-30' => 'emerald', '31-60' => 'amber', '61-90' => 'orange', '>90' => 'rose' }; @endphp
                        <div class="rounded-lg bg-{{ $color }}-50 p-3 dark:bg-{{ $color }}-950/30">
                            <div class="text-[10px] font-bold uppercase text-{{ $color }}-700">{{ $b }} hari</div>
                            <div class="mt-1 text-lg font-bold tabular-nums text-{{ $color }}-800">Rp {{ $fmtJt($val) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-3 py-2">{!! $thBtn('nomor_spr', 'Nomor SPR') !!}</th>
                                <th class="px-3 py-2">{!! $thBtn('customer', 'Nama Konsumen') !!}</th>
                                <th class="px-3 py-2">{!! $thBtn('unit', 'Unit') !!}</th>
                                <th class="px-3 py-2">{!! $thBtn('sales', 'Sales') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('um_net', 'UM Netto', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('dibayar', 'Terbayar', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('sisa', 'Sisa', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('progress', '%', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('age_days', 'Umur', 'right') !!}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($outstandingRows as $row)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono">{{ $row->spr->nomor_spr }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $row->spr->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $row->spr->rumah?->blok }}-{{ $row->spr->rumah?->nomor_unit }}</td>
                                    <td class="px-3 py-2">{{ $row->spr->sales?->nama }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($row->um_net) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-emerald-700">{{ $fmt($row->dibayar) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-amber-700">{{ $fmt($row->sisa) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row->progress }}%</td>
                                    <td class="px-3 py-2 text-right">
                                        @php $ageColor = match($row->age_bucket) { '0-30' => 'emerald', '31-60' => 'amber', '61-90' => 'orange', '>90' => 'rose' }; @endphp
                                        <span class="rounded bg-{{ $ageColor }}-100 px-1.5 py-0.5 text-[10px] font-semibold text-{{ $ageColor }}-700">{{ $row->age_bucket }} h</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-3 py-6 text-center text-zinc-400">Semua SPR lunas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $outstandingRows->links() }}</div>
            </div>
        @endif

        @if ($tab === 'pembatalan')
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-950/30">
                    <div class="text-[10px] font-bold uppercase text-rose-700">SPR Dibatalkan</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-rose-800 dark:text-rose-300">{{ number_format($totalBatal) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase text-zinc-500">Total Pengembalian</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalRefund) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="text-[10px] font-bold uppercase text-emerald-700">Pengembalian Selesai</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800">{{ number_format($refundSelesai) }}</div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <div class="text-[10px] font-bold uppercase text-amber-700">Pengembalian Tertunda</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800">{{ number_format($refundPending) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="text-[10px] font-bold uppercase text-zinc-600">UTJ Hangus</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-zinc-700">{{ number_format($tidakAdaRefund) }}</div>
                </div>
            </div>

            @if ($perAlasan->isNotEmpty())
                <div class="mb-5 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 text-sm font-bold">Per Alasan</div>
                    <div class="space-y-2">
                        @foreach ($perAlasan as $a)
                            <div class="flex items-center justify-between text-xs">
                                <span>{{ $a->alasan }}</span>
                                <span class="font-bold tabular-nums">{{ $a->cnt }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-3 py-2">{!! $thBtn('nomor_spr', 'Nomor SPR') !!}</th>
                                <th class="px-3 py-2">Nama Konsumen</th>
                                <th class="px-3 py-2">Unit</th>
                                <th class="px-3 py-2">Sales</th>
                                <th class="px-3 py-2">Alasan</th>
                                <th class="px-3 py-2">{!! $thBtn('cancelled_at', 'Tgl Batal') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('refund_amount', 'Pengembalian', 'right') !!}</th>
                                <th class="px-3 py-2">{!! $thBtn('refund_status', 'Status') !!}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sprs as $spr)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono">{{ $spr->nomor_spr }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $spr->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $spr->rumah?->blok }}-{{ $spr->rumah?->nomor_unit }}</td>
                                    <td class="px-3 py-2">{{ $spr->sales?->nama }}</td>
                                    <td class="px-3 py-2">{{ $spr->alasanPembatalan?->nama }}</td>
                                    <td class="px-3 py-2">{{ $spr->cancelled_at?->format('d/m/y') }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($spr->refund_amount ?? 0) }}</td>
                                    <td class="px-3 py-2">
                                        @php
                                            $c = ['full' => 'emerald', 'pending' => 'amber', 'partial' => 'blue', 'tidak_ada_refund' => 'zinc'][$spr->refund_status ?? 'pending'] ?? 'zinc';
                                            $refundLabel = ['full' => 'Selesai', 'pending' => 'Tertunda', 'partial' => 'Sebagian', 'tidak_ada_refund' => 'Tidak Ada'][$spr->refund_status ?? 'pending'] ?? '—';
                                        @endphp
                                        <span class="rounded bg-{{ $c }}-100 px-1.5 py-0.5 text-[10px] font-semibold text-{{ $c }}-700">{{ $refundLabel }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-zinc-400">Belum ada pembatalan.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $sprs->links() }}</div>
            </div>
        @endif

        @if ($tab === 'performance')
            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="text-sm font-bold">Peringkat Sales</div>
                    <div class="text-[10px] text-zinc-500">Periode: {{ $this::PERIODS[$period] ?? '' }}</div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-3 py-2">Peringkat</th>
                                <th class="px-3 py-2">{!! $thBtn('nama', 'Sales') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('spr_count', 'Jumlah SPR', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('total_nilai', 'Total Nilai Kontrak', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">{!! $thBtn('total_kpr', 'Total Nilai KPR', 'right') !!}</th>
                                <th class="px-3 py-2 text-right">Uang Masuk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ranking as $idx => $s)
                                @php
                                    $cashIn = $realisasiPerSales[$s->id] ?? 0;
                                    $rankBg = match ($idx) {
                                        0 => 'bg-amber-100 text-amber-800',
                                        1 => 'bg-zinc-200 text-zinc-700',
                                        2 => 'bg-orange-100 text-orange-800',
                                        default => 'bg-zinc-100 text-zinc-500',
                                    };
                                @endphp
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold {{ $rankBg }}">{{ $idx + 1 }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="font-semibold">{{ $s->nama }}</div>
                                        <div class="font-mono text-[10px] text-zinc-500">{{ $s->kode }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ number_format($s->spr_count) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-bold tabular-nums">Rp {{ $fmtJt($s->total_nilai) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">Rp {{ $fmtJt($s->total_kpr) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-purple-700">Rp {{ $fmtJt($cashIn) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @endif {{-- endif filterProyek not null --}}

    </div>
</section>
