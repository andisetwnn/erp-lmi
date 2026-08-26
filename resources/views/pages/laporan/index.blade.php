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

    #[Url(as: 'kat')]
    public ?string $filterKategori = null;

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
        $this->filterKategori = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->period = 'all';
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFilterSales(): void { $this->resetPage(); }
    public function updatingFilterTipe(): void { $this->resetPage(); }
    public function updatingFilterKategori(): void { $this->resetPage(); }
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
        'pindah'       => ['Pindah Kavling',   'arrows-right-left','blue',    'penjualan'],
        'performance'  => ['Peringkat Sales',  'trophy',           'indigo',  'penjualan'],
        'realisasi'    => ['Kwitansi Masuk',   'banknotes',        'purple',  'keuangan'],
        'outstanding'  => ['Tunggakan UM',     'clock',            'amber',   'keuangan'],
        // Tab 'biayatambahan' tidak didaftarkan lagi — nilainya sudah ada sebagai kolom
        // di Master Data. Kodenya masih utuh kalau perlu dihidupkan kembali.
        // Satu laporan lengkap saja: susunan kolomnya mengikuti sheet SOP buku manual.
        // Tab 'rekap' lama sengaja tidak didaftarkan lagi — kodenya masih ada kalau
        // sewaktu-waktu perlu dihidupkan kembali.
        'sop'          => ['Master Data',      'table-cells',      'emerald', 'keuangan'],
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
            'pindah' => $this->dataPindah($from, $to),
            'performance' => $this->dataPerformance($from, $to),
            'biayatambahan' => $this->dataBiayaTambahan($from, $to),
            'rekap' => $this->dataRekap($from, $to),
            'sop' => $this->dataSop($from, $to),
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
        if ($this->filterKategori) $q->where('spr.kategori', $this->filterKategori);
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
        if ($this->filterKategori) $q->whereHas('tipeRumah', fn ($t) => $t->where('kategori', $this->filterKategori));
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
        if ($this->filterKategori) {
            $q->whereHas('spr', fn ($s) => $s->where('kategori', $this->filterKategori));
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
                'progress' => $umNet > 0
                    ? ($dibayar > 0 ? max(1, (int) round(($dibayar / $umNet) * 100)) : 0)
                    : 0,
                'age_days' => $ageDays,
                'age_bucket' => $ageBucket,
            ];
        }

        $sortCol = $this->sortCol;
        $sortDir = $this->sortDir;
        $accessor = fn ($r) => match ($sortCol) {
            'customer' => $r->spr->prospectCustomer?->nama_lengkap ?? '',
            'unit' => ($r->spr->rumah?->kode_unit ?? ''),
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

    /**
     * Laporan Lengkap — all-in-one view per SPR.
     * UI tampil 12 kolom ringkas, Excel export tampil 54 kolom penuh (di LaporanExport).
     * Grain: 1 SPR = 1 row, kwitansi diaggregate jadi kolom summary.
     */
    private function dataRekap($from, $to): array
    {
        $query = $this->baseSprQuery(); // semua status
        if ($this->dateFrom || $this->dateTo) {
            $query->whereBetween('spr.tanggal_spr', [$from, $to]);
        }

        // Sort: DB-columns (fast). Untuk agregat (total_bayar/sisa/progress) → post-fetch.
        $needJoinCustomer = in_array($this->sortCol, ['customer', 'nik']);
        $needJoinRumah = $this->sortCol === 'unit';
        $needJoinSales = $this->sortCol === 'sales';
        if ($needJoinCustomer) {
            $query->join('prospect_customer as pc', 'pc.id', '=', 'spr.prospect_customer_id')
                ->select('spr.*');
        }
        if ($needJoinRumah) {
            $query->join('rumah as rm', 'rm.id', '=', 'spr.rumah_id')->select('spr.*');
        }
        if ($needJoinSales) {
            $query->join('sales as sl', 'sl.id', '=', 'spr.sales_id')->select('spr.*');
        }

        $sortMap = [
            'nomor_spr' => 'spr.nomor_spr',
            'tanggal_spr' => 'spr.tanggal_spr',
            'total_harga' => 'spr.total_harga',
            'utj_nominal' => 'spr.utj_nominal',
            'status' => 'spr.status',
            'customer' => 'pc.nama_lengkap',
            'nik' => 'pc.nik',
            'unit' => ['rm.blok', 'rm.nomor_unit'],
            'sales' => 'sl.nama',
        ];
        $sprs = $this->applySort((clone $query), $sortMap, 'nomor_spr', 'asc')
            ->paginate($this->effectivePerPage());

        // Pre-aggregate kwitansi per SPR utk page ini saja
        $sprIds = collect($sprs->items())->pluck('id')->all();
        $kwtAgg = SprRealisasiPembayaran::query()
            ->selectRaw('spr_id,
                SUM(CASE WHEN jenis="bf" THEN jumlah ELSE 0 END) as total_bf,
                SUM(CASE WHEN jenis="um" THEN jumlah ELSE 0 END) as total_um,
                COUNT(*) as jumlah_kwt,
                MAX(tanggal_bayar) as tgl_terakhir')
            ->whereIn('spr_id', $sprIds)
            ->groupBy('spr_id')
            ->get()
            ->keyBy('spr_id');

        // Pre-aggregate biaya tambahan per rumah_id (untuk SPR yg ada rumah_id).
        // Nominal dari rumah.biaya_tambahan, terbayar dari sum realisasi (non-refunded).
        $rumahIds = collect($sprs->items())->pluck('rumah_id')->filter()->unique()->all();
        $btNominalMap = \App\Models\Master\Rumah::whereIn('id', $rumahIds)
            ->pluck('biaya_tambahan', 'id');
        $btTerbayarMap = \App\Models\Master\BiayaTambahanRealisasi::query()
            ->whereIn('rumah_id', $rumahIds)
            ->where('is_refunded', false)
            ->selectRaw('rumah_id, SUM(jumlah) as total')
            ->groupBy('rumah_id')
            ->pluck('total', 'rumah_id');

        // Summary total (all data, tidak per page)
        $totalSpr = (clone $query)->count();
        $totalNilai = (float) (clone $query)->sum('total_harga');
        $totalUmNet = (float) (clone $query)->sum('um_net');
        $totalBayarAll = (float) SprRealisasiPembayaran::whereIn('spr_id', (clone $query)->pluck('spr.id'))
            ->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
        // Total biaya tambahan periode
        $allRumahIds = (clone $query)->pluck('spr.rumah_id')->filter()->unique();
        $totalBiayaTambahan = (float) \App\Models\Master\Rumah::whereIn('id', $allRumahIds)->sum('biaya_tambahan');
        $totalBiayaTambahanBayar = (float) \App\Models\Master\BiayaTambahanRealisasi::query()
            ->whereIn('rumah_id', $allRumahIds)
            ->where('is_refunded', false)
            ->sum('jumlah');

        return compact('sprs', 'kwtAgg', 'btNominalMap', 'btTerbayarMap',
            'totalSpr', 'totalNilai', 'totalUmNet', 'totalBayarAll',
            'totalBiayaTambahan', 'totalBiayaTambahanBayar');
    }

    private function dataPindah($from, $to): array
    {
        $query = \App\Models\Master\SprSwitching::query()
            ->with([
                'sprLamaA.rumah.tipeRumah', 'sprLamaA.prospectCustomer:id,nama_lengkap',
                'sprBaruA.rumah',
                'sprLamaB.rumah.tipeRumah', 'sprLamaB.prospectCustomer:id,nama_lengkap',
                'sprBaruB.rumah',
                'processedBy:id,name',
            ])
            ->whereBetween('processed_at', [$from, $to]);

        if ($this->filterProyek) {
            $query->whereHas('sprLamaA.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));
        }

        if ($this->search !== '') {
            $s = "%{$this->search}%";
            $query->where(function ($q) use ($s) {
                $q->where('nomor_switching', 'like', $s)
                    ->orWhere('alasan', 'like', $s)
                    ->orWhereHas('sprLamaA.prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', $s))
                    ->orWhereHas('sprLamaB.prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', $s))
                    ->orWhereHas('sprLamaA.rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", [$s]))
                    ->orWhereHas('sprLamaB.rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", [$s]));
            });
        }

        $switchings = (clone $query)->orderByDesc('processed_at')->paginate($this->effectivePerPage());

        $totalPindah = (clone $query)->where('tipe', 'pindah')->count();
        $totalSwap = (clone $query)->where('tipe', 'swap')->count();
        $totalEvent = $totalPindah + $totalSwap;
        $totalRefundPindah = (float) \App\Models\Master\SprRealisasiPembayaran::where('jenis', 'refund_pindah')
            ->whereHas('switching', fn ($q) => $q->whereBetween('processed_at', [$from, $to])
                ->when($this->filterProyek, fn ($qq) => $qq->whereHas('sprLamaA.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek))))
            ->sum('jumlah');

        return compact('switchings', 'totalPindah', 'totalSwap', 'totalEvent', 'totalRefundPindah');
    }

    private function dataBiayaTambahan($from, $to): array
    {
        // Semua unit di proyek yg dipilih dengan biaya_tambahan > 0.
        $rumahQ = \App\Models\Master\Rumah::query()
            ->with(['proyek:id,nama_proyek', 'tipeRumah:id,tipe,nama_tipe',
                'biayaTambahanRealisasi' => fn ($q) => $q->with('inputBy'),
            ])
            ->where('biaya_tambahan', '>', 0)
            ->when($this->filterProyek, fn ($q) => $q->where('proyek_id', $this->filterProyek))
            ->when($this->filterTipe, fn ($q) => $q->where('tipe_rumah_id', $this->filterTipe))
            ->orderBy('blok')->orderBy('nomor_unit');

        if ($this->search !== '') {
            $rumahQ->where(function ($q) {
                $q->where('blok', 'like', "%{$this->search}%")
                    ->orWhere('nomor_unit', 'like', "%{$this->search}%");
            });
        }

        $units = $rumahQ->get();

        // Ambil SPR aktif per rumah (approved / akad — bukan cancelled),
        // ambil yang terbaru per rumah_id (latest id).
        $sprMap = \App\Models\Master\Spr::query()
            ->select('spr.id', 'spr.nomor_spr', 'spr.rumah_id', 'spr.status', 'spr.prospect_customer_id')
            ->with('prospectCustomer:id,nama_lengkap')
            ->whereIn('rumah_id', $units->pluck('id'))
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('id')
            ->get()
            ->unique('rumah_id')
            ->keyBy('rumah_id');

        $rows = $units->map(function ($r) use ($sprMap) {
            $nominal = (float) $r->biaya_tambahan;
            $terbayar = (float) $r->biayaTambahanRealisasi->where('is_refunded', false)->sum('jumlah');
            $refunded = (float) $r->biayaTambahanRealisasi->where('is_refunded', true)->sum('jumlah');
            $sisa = max(0, $nominal - $terbayar);
            $status = match (true) {
                $sisa <= 0.01 && $nominal > 0 => 'Lunas',
                $terbayar > 0 => 'Cicil',
                default => 'Belum Bayar',
            };
            $spr = $sprMap->get($r->id);

            return [
                'rumah' => $r,
                'spr' => $spr,
                'nominal' => $nominal,
                'terbayar' => $terbayar,
                'sisa' => $sisa,
                'refunded' => $refunded,
                'status' => $status,
                'realisasi_count' => $r->biayaTambahanRealisasi->where('is_refunded', false)->count(),
            ];
        });

        $totalNominal = $rows->sum('nominal');
        $totalTerbayar = $rows->sum('terbayar');
        $totalSisa = $rows->sum('sisa');
        $totalRefunded = $rows->sum('refunded');
        $countLunas = $rows->where('status', 'Lunas')->count();
        $countCicil = $rows->where('status', 'Cicil')->count();
        $countBelum = $rows->where('status', 'Belum Bayar')->count();

        return compact(
            'rows', 'totalNominal', 'totalTerbayar', 'totalSisa', 'totalRefunded',
            'countLunas', 'countCicil', 'countBelum'
        );
    }

    /**
     * Laporan dengan susunan kolom mengikuti sheet SOP buku manual, supaya hasil sistem
     * bisa disandingkan langsung dengan file aslinya. Kolomnya disusun di
     * LaporanSopFormat agar tampilan layar dan export Excel tidak pernah lepas sinkron.
     */
    private function dataSop($from, $to): array
    {
        $query = $this->baseSprQuery()->with(\App\Support\LaporanSopFormat::relasi());
        if ($this->dateFrom || $this->dateTo) {
            $query->whereBetween('spr.tanggal_spr', [$from, $to]);
        }

        $paginator = $query->orderBy('spr.nomor_spr')->paginate($this->perPage);

        $nomorAwal = ($paginator->currentPage() - 1) * $paginator->perPage() + 1;

        return [
            'sopHeaders' => \App\Support\LaporanSopFormat::headers(),
            'sopRows' => \App\Support\LaporanSopFormat::rows(collect($paginator->items()), $nomorAwal),
            'sopPaginator' => $paginator,
            'sopKolomBeku' => \App\Support\LaporanSopFormat::KOLOM_BEKU,
        ];
    }

    private function dataPembatalan($from, $to): array
    {
        // Exclude SPR yang cancelled karena Pindah Kavling — dilaporkan di tab terpisah.
        $pindahAlasanId = \App\Models\Master\AlasanPembatalan::where('nama', 'Pindah Kavling')->value('id');

        $query = $this->baseSprQuery(['cancelled'])
            ->with(['alasanPembatalan', 'realisasiPembayaran:id,spr_id,jenis,jumlah', 'rumah.tipeRumah:id,tipe,nama_tipe'])
            ->whereBetween('spr.tanggal_spr', [$from, $to])
            ->when($pindahAlasanId, fn ($q) => $q->where(function ($qq) use ($pindahAlasanId) {
                $qq->whereNull('spr.alasan_pembatalan_id')
                    ->orWhere('spr.alasan_pembatalan_id', '!=', $pindahAlasanId);
            }));

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
        if ($this->filterKategori) $baseQuery->where('kategori', $this->filterKategori);

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
        @php
            $searchPlaceholder = match ($tab) {
                'stock' => 'Cari blok / nomor unit...',
                'realisasi' => 'Cari nomor kwitansi / nomor SPR...',
                'penjualan', 'pembatalan', 'outstanding', 'rekap', 'sop' => 'Cari nomor SPR / nama customer / blok...',
                'pindah' => 'Cari nomor transaksi (PK/...) / nama customer / blok...',
                default => null,
            };
            $showSales = in_array($tab, ['penjualan', 'realisasi', 'performance', 'outstanding', 'pembatalan', 'pindah', 'rekap', 'sop']);
            $showPerpage = $tab !== 'performance';
            $inputCls = 'h-9 rounded-lg border border-zinc-200 bg-white px-2.5 text-xs shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white';
        @endphp
        <div class="mb-5 space-y-2">
            {{-- Row 1: Search + Filter dropdowns + Export --}}
            <div class="flex flex-wrap items-center gap-2">
                @if ($searchPlaceholder)
                    <div class="relative min-w-52 flex-1 max-w-sm">
                        <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                        <input type="search" wire:model.live.debounce.400ms="search"
                               placeholder="{{ $searchPlaceholder }}"
                               class="{{ $inputCls }} block w-full pl-9" />
                    </div>
                @endif

                <select wire:model.live="filterKategori" class="{{ $inputCls }}">
                    <option value="">Semua Kategori</option>
                    <option value="subsidi">Subsidi</option>
                    <option value="komersial">Komersial</option>
                </select>

                <select wire:model.live="filterTipe" class="{{ $inputCls }}">
                    <option value="">Semua Tipe</option>
                    @foreach ($tipeList as $t)
                        <option value="{{ $t->id }}">{{ $t->tipe }} {{ $t->nama_tipe }}</option>
                    @endforeach
                </select>

                @if ($showSales)
                    <select wire:model.live="filterSales" class="{{ $inputCls }}">
                        <option value="">Semua Sales</option>
                        @foreach ($salesList as $s)
                            <option value="{{ $s->id }}">{{ $s->kode }} - {{ $s->nama }}</option>
                        @endforeach
                    </select>
                @endif

                @if ($showPerpage)
                    <select wire:model.live="perPage" class="{{ $inputCls }}">
                        @foreach ([10, 25, 50, 100, 0] as $pp)
                            <option value="{{ $pp }}">{{ $pp === 0 ? 'Semua' : $pp.' baris' }}</option>
                        @endforeach
                    </select>
                @endif

                <div class="ml-auto flex items-center gap-2">
                    @if ($search || $filterSales || $filterTipe || $filterKategori || $dateFrom || $dateTo || $period !== 'all')
                        <button type="button" wire:click="resetFilters"
                                class="inline-flex h-9 items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 text-[11px] font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                            <flux:icon.x-mark class="size-3" />
                            Reset
                        </button>
                    @endif
                    <button type="button" wire:click="exportExcel" wire:loading.attr="disabled"
                            class="inline-flex h-9 items-center gap-2 rounded-lg border border-emerald-600 bg-emerald-600 px-3 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50">
                        <flux:icon.arrow-down-tray class="size-4" wire:loading.remove wire:target="exportExcel" />
                        <flux:icon.arrow-path class="size-4 animate-spin" wire:loading wire:target="exportExcel" />
                        <span wire:loading.remove wire:target="exportExcel">Export Excel</span>
                        <span wire:loading wire:target="exportExcel">Menyiapkan...</span>
                    </button>
                </div>
            </div>

            {{-- Row 2: Period preset + Date range --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex items-center rounded-lg border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($this::PERIODS as $k => $lbl)
                        @php $active = $period === $k && ! $dateFrom && ! $dateTo; @endphp
                        <button type="button" wire:click="setPeriod('{{ $k }}')"
                                @class([
                                    'inline-flex items-center rounded-md px-3 py-1 text-xs font-semibold transition',
                                    'bg-emerald-600 text-white shadow' => $active,
                                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $active,
                                ])>{{ $lbl }}</button>
                    @endforeach
                </div>

                <div class="flex items-center gap-1">
                    <input type="date" wire:model.live="dateFrom" class="{{ $inputCls }} py-1" />
                    <span class="text-xs text-zinc-500">s/d</span>
                    <input type="date" wire:model.live="dateTo" class="{{ $inputCls }} py-1" />
                </div>
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
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Unit Terjual</div>
                        <x-info-button title="Total Unit Terjual">Jumlah unit rumah yang sudah punya SPR aktif (approved/akad) pada periode terpilih.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($totalUnit) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Nilai Kontrak</div>
                        <x-info-button title="Total Nilai Kontrak">Total harga jual seluruh unit (Total Harga All-in) berdasarkan SPR aktif pada periode. Catatan: nilai kontrak bukan pendapatan — pendapatan diakui saat akad kredit.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalNilai) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Nilai KPR</div>
                        <x-info-button title="Total Nilai KPR">Total nilai KPR yang diajukan/disetujui bank dari SPR aktif. Sub-total dari Nilai Kontrak.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalKpr) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Rata-rata Nilai Kontrak</div>
                        <x-info-button title="Rata-rata Nilai Kontrak">Rata-rata harga jual per unit = Total Nilai Kontrak ÷ Total Unit Terjual.</x-info-button>
                    </div>
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
                                    <td class="px-3 py-2 font-mono">{{ $spr->nomor_display }}</td>
                                    <td class="px-3 py-2">{{ $spr->tanggal_spr?->format('d/m/y') }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $spr->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $spr->rumah?->kode_unit }}</td>
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
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Total Unit</div>
                        <x-info-button title="Total Unit">Jumlah seluruh unit rumah di proyek terpilih (semua status).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format($totalUnit) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-emerald-700">Terjual</div>
                        <x-info-button title="Terjual">Unit yang sudah punya SPR aktif (approved/akad).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-300">{{ number_format($terjual) }}</div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-amber-700">Booking</div>
                        <x-info-button title="Booking">Unit yang sedang dalam proses booking/NUP tapi belum SPR.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800 dark:text-amber-300">{{ number_format($booking) }}</div>
                </div>
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-blue-700">Tersedia</div>
                        <x-info-button title="Tersedia">Unit yang belum ada booking atau SPR — siap dijual.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-blue-800 dark:text-blue-300">{{ number_format($available) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Draft</div>
                        <x-info-button title="Draft">Unit dengan status draft (belum ready dijual/masih dalam persiapan).</x-info-button>
                    </div>
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
                                    <td class="px-3 py-2 font-mono font-semibold">{{ $r->kode_unit }}</td>
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
                                    <td class="px-3 py-2 font-mono text-[10px]">{{ $r->spr?->nomor_display ?? '—' }}</td>
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
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-amber-700">Total Tunggakan UM</div>
                        <x-info-button title="Total Tunggakan UM">Total sisa UM yang belum dibayar konsumen = Total UM Netto − Sudah Terbayar. Hanya SPR approved.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800 dark:text-amber-300">Rp {{ $fmtJt($totalOutstanding) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Total UM Netto</div>
                        <x-info-button title="Total UM Netto">Total target UM (Uang Muka bersih) dari semua SPR approved. Sudah exclude subsidi BBA/SBUM.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalUmNet) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-emerald-700">Sudah Terbayar</div>
                        <x-info-button title="Sudah Terbayar">Total UTJ + cicilan UM yang sudah masuk dari konsumen (untuk SPR yang belum lunas).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-300">Rp {{ $fmtJt($totalDibayarUm) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">SPR Belum Lunas UM</div>
                        <x-info-button title="SPR Belum Lunas UM">Jumlah SPR yang UM-nya belum lunas (masih ada sisa tunggakan).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">{{ number_format(count($outstandingRows)) }}</div>
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
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($outstandingRows as $row)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono">{{ $row->spr->nomor_display }}</td>
                                    <td class="px-3 py-2 font-semibold">{{ $row->spr->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $row->spr->rumah?->kode_unit }}</td>
                                    <td class="px-3 py-2">{{ $row->spr->sales?->nama }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($row->um_net) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-emerald-700">{{ $fmt($row->dibayar) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-amber-700">{{ $fmt($row->sisa) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row->progress }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-zinc-400">Semua SPR lunas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $outstandingRows->links() }}</div>
            </div>
        @endif

        @if ($tab === 'biayatambahan')
            {{-- KPI Cards --}}
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <div class="text-[10px] font-bold uppercase text-amber-700">Total Nominal</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800 dark:text-amber-300">Rp {{ $fmtJt($totalNominal) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="text-[10px] font-bold uppercase text-emerald-700">Terbayar</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-300">Rp {{ $fmtJt($totalTerbayar) }}</div>
                </div>
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-950/30">
                    <div class="text-[10px] font-bold uppercase text-rose-700">Sisa</div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-rose-800 dark:text-rose-300">Rp {{ $fmtJt($totalSisa) }}</div>
                </div>
                @php $totalUnit = $rows->count(); @endphp
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-indigo-700">Total Unit</div>
                        <span class="rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">{{ $totalUnit }}</span>
                    </div>
                    <div class="mt-2 space-y-1 text-[11px]">
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><span class="size-1.5 rounded-full bg-emerald-500"></span> Lunas</span>
                            <span class="font-mono font-bold tabular-nums text-emerald-800 dark:text-emerald-300">{{ $countLunas }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1 text-blue-700 dark:text-blue-400"><span class="size-1.5 rounded-full bg-blue-500"></span> Cicil</span>
                            <span class="font-mono font-bold tabular-nums text-blue-800 dark:text-blue-300">{{ $countCicil }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1 text-amber-700 dark:text-amber-400"><span class="size-1.5 rounded-full bg-amber-500"></span> Belum Bayar</span>
                            <span class="font-mono font-bold tabular-nums text-amber-800 dark:text-amber-300">{{ $countBelum }}</span>
                        </div>
                        @if ($totalRefunded > 0)
                            <div class="mt-1 flex items-center justify-between border-t border-indigo-200 pt-1 dark:border-indigo-800">
                                <span class="text-rose-700 dark:text-rose-400">Refunded</span>
                                <span class="font-mono tabular-nums text-rose-800 dark:text-rose-300">Rp {{ $fmtJt($totalRefunded) }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Table --}}
            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-3 py-2">Unit</th>
                                <th class="px-3 py-2">Tipe</th>
                                <th class="px-3 py-2">No SPR</th>
                                <th class="px-3 py-2">Customer</th>
                                <th class="px-3 py-2 text-right">Nominal</th>
                                <th class="px-3 py-2 text-right">Terbayar</th>
                                <th class="px-3 py-2 text-right">Sisa</th>
                                <th class="px-3 py-2 text-center">Realisasi</th>
                                <th class="px-3 py-2 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2 font-mono">{{ $r['rumah']->kode_unit }}</td>
                                    <td class="px-3 py-2">{{ $r['rumah']->tipeRumah?->tipe }}</td>
                                    <td class="px-3 py-2 font-mono">
                                        @if ($r['spr'])
                                            <a href="{{ route('marketing.spr.show', $r['spr']->id) }}" class="text-blue-600 hover:underline dark:text-blue-400" wire:navigate>
                                                #{{ $r['spr']->nomor_display ?? $r['spr']->nomor_spr }}
                                            </a>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $r['spr']?->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $fmt($r['nominal']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-emerald-700">{{ $r['terbayar'] > 0 ? $fmt($r['terbayar']) : '-' }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-rose-700 font-semibold">{{ $r['sisa'] > 0 ? $fmt($r['sisa']) : '-' }}</td>
                                    <td class="px-3 py-2 text-center">{{ $r['realisasi_count'] }}×</td>
                                    <td class="px-3 py-2 text-center">
                                        @php
                                            $cls = match ($r['status']) {
                                                'Lunas' => 'text-emerald-700 bg-emerald-50 dark:text-emerald-400 dark:bg-emerald-950/30',
                                                'Cicil' => 'text-blue-700 bg-blue-50 dark:text-blue-400 dark:bg-blue-950/30',
                                                default => 'text-amber-700 bg-amber-50 dark:text-amber-400 dark:bg-amber-950/30',
                                            };
                                        @endphp
                                        <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase {{ $cls }}">{{ $r['status'] }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-8 text-center text-zinc-400">Tidak ada unit dengan biaya tambahan &gt; 0.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($tab === 'rekap')
            {{-- KPI Cards --}}
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-emerald-700">Total SPR</div>
                        <x-info-button title="Total SPR">Jumlah seluruh SPR (termasuk approved, akad, dan cancelled) di periode terpilih.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800">{{ number_format($totalSpr) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Total Nilai</div>
                        <x-info-button title="Total Nilai">Total harga jual seluruh SPR (all-in, termasuk cancelled). Untuk lihat nilai kontrak SPR aktif saja, cek tab Penjualan.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalNilai) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Total UM Netto</div>
                        <x-info-button title="Total UM Netto">Total target UM (Uang Muka bersih) dari semua SPR di periode. Sudah exclude BBA subsidi.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalUmNet) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-emerald-700">Total Bayar Masuk</div>
                        <x-info-button title="Total Bayar Masuk">Total UTJ + cicilan UM yang sudah masuk dari semua SPR (termasuk cancelled yang belum di-refund).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800">Rp {{ $fmtJt($totalBayarAll) }}</div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-amber-700">Biaya Tambahan</div>
                        <x-info-button title="Biaya Tambahan Unit">Total biaya tambahan unit (kavling hook, view, dll) yg terpisah dari SPR + total yg sudah terbayar dari konsumen.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800 dark:text-amber-300">Rp {{ $fmtJt($totalBiayaTambahan) }}</div>
                    <div class="mt-1 text-[10px] text-amber-700 dark:text-amber-400">Terbayar: Rp {{ $fmtJt($totalBiayaTambahanBayar) }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-2 py-2">{!! $thBtn('nomor_spr', 'No SPR') !!}</th>
                                <th class="px-2 py-2">{!! $thBtn('tanggal_spr', 'Tgl') !!}</th>
                                <th class="px-2 py-2">{!! $thBtn('customer', 'Nama Konsumen') !!}</th>
                                <th class="px-2 py-2">{!! $thBtn('nik', 'NIK') !!}</th>
                                <th class="px-2 py-2">{!! $thBtn('unit', 'Unit') !!}</th>
                                <th class="px-2 py-2">{!! $thBtn('sales', 'Sales') !!}</th>
                                <th class="px-2 py-2 text-right">{!! $thBtn('total_harga', 'Total', 'right') !!}</th>
                                <th class="px-2 py-2 text-right">{!! $thBtn('utj_nominal', 'UTJ', 'right') !!}</th>
                                <th class="px-2 py-2 text-right">Total Bayar</th>
                                <th class="px-2 py-2 text-right">Sisa UM</th>
                                <th class="px-2 py-2 text-right">Progress</th>
                                <th class="px-2 py-2 text-right border-l border-amber-200 dark:border-amber-900/40 bg-amber-50/40 dark:bg-amber-950/10">B.Tambahan</th>
                                <th class="px-2 py-2 text-right bg-amber-50/40 dark:bg-amber-950/10">Terbayar B.T</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sprs as $spr)
                                @php
                                    $agg = $kwtAgg->get($spr->id);
                                    $totalBf = (float) ($agg->total_bf ?? 0);
                                    $totalUm = (float) ($agg->total_um ?? 0);
                                    $totalBayar = $totalBf + $totalUm;
                                    $umNet = (float) $spr->um_net;
                                    $sisaUm = max(0, $umNet - $totalBayar);
                                    $pct = $umNet > 0
                                        ? ($totalBayar > 0 ? max(1, (int) round($totalBayar / $umNet * 100)) : 0)
                                        : 0;
                                    $btNominal = (float) ($btNominalMap[$spr->rumah_id] ?? 0);
                                    $btTerbayar = (float) ($btTerbayarMap[$spr->rumah_id] ?? 0);
                                @endphp
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-2 py-2 font-mono">{{ $spr->nomor_display }}</td>
                                    <td class="px-2 py-2">{{ $spr->tanggal_spr?->format('d/m/y') }}</td>
                                    <td class="px-2 py-2 font-semibold">{{ $spr->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-2 py-2 font-mono text-[10px]">{{ $spr->prospectCustomer?->nik }}</td>
                                    <td class="px-2 py-2 font-mono">{{ $spr->rumah?->kode_unit }}</td>
                                    <td class="px-2 py-2">{{ $spr->sales?->nama }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums">{{ $fmt($spr->total_harga) }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums">{{ $fmt($spr->utj_nominal) }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums text-emerald-700">{{ $fmt($totalBayar) }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums text-amber-700">{{ $fmt($sisaUm) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums font-semibold">{{ $pct }}%</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums border-l border-amber-200 dark:border-amber-900/40 bg-amber-50/20 dark:bg-amber-950/10">{{ $btNominal > 0 ? $fmt($btNominal) : '-' }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums text-emerald-700 bg-amber-50/20 dark:bg-amber-950/10">{{ $btTerbayar > 0 ? $fmt($btTerbayar) : '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="13" class="px-3 py-6 text-center text-zinc-400">Belum ada data SPR.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $sprs->links() }}</div>
            </div>
        @endif

        @if ($tab === 'sop')
            @php
                // Kolom uang & angka dirender rata kanan; sisanya rata kiri.
                $sopAngka = function (string $h) {
                    return in_array($h, ['NO', 'LOT', 'LB', 'LT', '% UM'], true)
                        || str_starts_with($h, 'UM')
                        || in_array($h, [
                            'HARGA JUAL AWAL', 'BIAYA TAMBAHAN', 'BIAYA LAIN2', 'TOTAL HARGA JUAL',
                            'PERMOHONAN KPR', 'ACC KPR', 'TOTAL U.M', 'SBUM', 'UM SETELAH SBUM',
                            'BF/UTJ', 'AKUMULASI UANG MUKA', 'SISA UANG MUKA',
                        ], true);
                };
                $sopUang = function (string $h) {
                    return str_starts_with($h, 'UM') && $h !== '% UM'
                        || in_array($h, [
                            'HARGA JUAL AWAL', 'BIAYA TAMBAHAN', 'BIAYA LAIN2', 'TOTAL HARGA JUAL',
                            'PERMOHONAN KPR', 'ACC KPR', 'TOTAL U.M', 'SBUM', 'UM SETELAH SBUM',
                            'BF/UTJ', 'AKUMULASI UANG MUKA', 'SISA UANG MUKA',
                        ], true);
                };
            @endphp

            @php
                // Kolom beku WAJIB punya lebar pasti. Kalau lebarnya dibiarkan mengikuti isi,
                // offset `left` tidak pernah cocok dan kolom di bawahnya mengintip lewat celah
                // — teks alamat/telepon/NPWP bocor menembus kolom yang seharusnya menutupi.
                // Lebar dikunci di th maupun td, dan isi yang kepanjangan dipotong elipsis.
                $sopKiri = ['0', '3rem', '8.5rem'];
                $sopLebar = ['3rem', '5.5rem', '14rem'];
                $sopGayaBeku = function (int $i) use ($sopKiri, $sopLebar) {
                    $w = $sopLebar[$i];

                    return "left: {$sopKiri[$i]}; width: {$w}; min-width: {$w}; max-width: {$w};";
                };
            @endphp

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs" style="width: max-content">
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/60">
                                @foreach ($sopHeaders as $i => $h)
                                    <th @class([
                                            'whitespace-nowrap px-2.5 py-2 font-bold uppercase tracking-wide text-[10px] text-zinc-600 dark:text-zinc-300',
                                            'text-right' => $sopAngka($h),
                                            'text-left' => ! $sopAngka($h),
                                            'sticky z-10 bg-zinc-50 dark:bg-zinc-800' => $i < $sopKolomBeku,
                                            'border-r border-zinc-300 dark:border-zinc-600' => $i === $sopKolomBeku - 1,
                                        ])
                                        @if ($i < $sopKolomBeku) style="{{ $sopGayaBeku($i) }}" @endif>
                                        @if ($i < $sopKolomBeku)
                                            {{-- Elemen dalam yang memotong isi: tanpa ini, tabel
                                                 auto-layout melebarkan sel mengikuti teks terpanjang
                                                 dan lebar yang dikunci di atas jadi percuma. --}}
                                            <div class="overflow-hidden text-ellipsis whitespace-nowrap">{{ $h }}</div>
                                        @else
                                            {{ $h }}
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sopRows as $row)
                                <tr class="border-b border-zinc-100 last:border-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40">
                                    @foreach ($row as $i => $nilai)
                                        @php
                                            $h = $sopHeaders[$i] ?? '';
                                            $kosong = $nilai === null || $nilai === '';
                                            $teks = $kosong ? '–' : ($sopUang($h) ? number_format((float) $nilai, 0, ',', '.') : $nilai);
                                            $beku = $i < $sopKolomBeku;
                                        @endphp
                                        <td @class([
                                                'whitespace-nowrap px-2.5 py-1.5',
                                                'text-right tabular-nums' => $sopAngka($h),
                                                'text-zinc-400' => $kosong,
                                                'sticky z-10 bg-white dark:bg-zinc-900' => $beku,
                                                'font-medium' => $i === 0,
                                                'font-mono' => $i === 1,
                                                'border-r border-zinc-200 dark:border-zinc-700' => $i === $sopKolomBeku - 1,
                                            ])
                                            @if ($beku) style="{{ $sopGayaBeku($i) }}" title="{{ $nilai }}" @endif>
                                            @if ($beku)
                                                <div class="overflow-hidden text-ellipsis whitespace-nowrap">{{ $teks }}</div>
                                            @else
                                                {{ $teks }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($sopHeaders) }}" class="px-4 py-10 text-center text-zinc-500">
                                        Tidak ada SPR pada filter ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $sopPaginator->links() }}</div>
            </div>
        @endif

        @if ($tab === 'pembatalan')
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-rose-700">SPR Dibatalkan</div>
                        <x-info-button title="SPR Dibatalkan">Jumlah SPR yang dibatalkan pada periode (customer mundur, pindah kavling, atau alasan lain).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-rose-800 dark:text-rose-300">{{ number_format($totalBatal) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-500">Total Pengembalian</div>
                        <x-info-button title="Total Pengembalian">Total uang yang di-refund ke konsumen atas SPR yang dibatalkan.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums">Rp {{ $fmtJt($totalRefund) }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-emerald-700">Pengembalian Selesai</div>
                        <x-info-button title="Pengembalian Selesai">Jumlah SPR yang refund-nya sudah selesai diproses (full atau partial).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-emerald-800">{{ number_format($refundSelesai) }}</div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-amber-700">Pengembalian Tertunda</div>
                        <x-info-button title="Pengembalian Tertunda">Jumlah SPR cancelled dengan refund_status pending (menunggu pencairan).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-amber-800">{{ number_format($refundPending) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-zinc-600">UTJ Hangus</div>
                        <x-info-button title="UTJ Hangus">Jumlah SPR cancelled tanpa refund — UTJ menjadi hak developer (customer tidak dapat pengembalian).</x-info-button>
                    </div>
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
                                <th class="px-2 py-2 text-center">No</th>
                                <th class="px-2 py-2">Nama</th>
                                <th class="px-2 py-2">Sales</th>
                                <th class="px-2 py-2">{!! $thBtn('cancelled_at', 'Tgl Jual') !!}</th>
                                <th class="px-2 py-2">Type</th>
                                <th class="px-2 py-2">Blok</th>
                                <th class="px-2 py-2 text-center">No Unit</th>
                                <th class="px-2 py-2 text-right">Total Harga Jual</th>
                                <th class="px-2 py-2 text-right">Total UM</th>
                                <th class="px-2 py-2 text-right">Akumulasi</th>
                                <th class="px-2 py-2 text-right">Penalti</th>
                                <th class="px-2 py-2 text-right">{!! $thBtn('refund_amount', 'Kembali', 'right') !!}</th>
                                <th class="px-2 py-2">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sprs as $spr)
                                @php
                                    $bfMasuk = (float) $spr->realisasiPembayaran->where('jenis', 'bf')->sum('jumlah');
                                    $umMasuk = (float) $spr->realisasiPembayaran->where('jenis', 'um')->sum('jumlah');
                                    $akumulasi = $bfMasuk + $umMasuk;
                                    $kembali = (float) ($spr->refund_amount ?? 0);
                                    $penalti = max(0, $akumulasi - $kembali);
                                @endphp
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-2 py-2 text-center text-zinc-500">{{ $loop->index + ($sprs->firstItem() ?? 1) }}</td>
                                    <td class="px-2 py-2 font-semibold">{{ $spr->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="px-2 py-2">{{ $spr->sales?->nama }}</td>
                                    <td class="whitespace-nowrap px-2 py-2">{{ $spr->tanggal_spr?->translatedFormat('d M Y') }}</td>
                                    <td class="whitespace-nowrap px-2 py-2">{{ $spr->rumah?->tipeRumah?->tipe ?? '—' }}</td>
                                    <td class="px-2 py-2 font-mono">{{ $spr->rumah?->blok }}</td>
                                    <td class="px-2 py-2 text-center font-mono">{{ $spr->rumah?->nomor_unit }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums">{{ $fmt($spr->total_harga) }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums">{{ $umMasuk > 0 ? $fmt($umMasuk) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums font-semibold">{{ $fmt($akumulasi) }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums text-rose-700 dark:text-rose-400">{{ $penalti > 0 ? $fmt($penalti) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums text-emerald-700 dark:text-emerald-400">{{ $kembali > 0 ? $fmt($kembali) : '—' }}</td>
                                    <td class="whitespace-nowrap px-2 py-2 text-zinc-700 dark:text-zinc-300">{{ $spr->alasanPembatalan?->nama ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="13" class="px-3 py-6 text-center text-zinc-400">Belum ada pembatalan.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $sprs->links() }}</div>
            </div>
        @endif

        @if ($tab === 'pindah')
            {{-- KPI cards --}}
            <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-blue-700">Total Transaksi</div>
                        <x-info-button title="Total Transaksi">Total transaksi pindah + tukar kavling pada periode.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-blue-800 dark:text-blue-300">{{ number_format($totalEvent) }}</div>
                </div>
                <div class="rounded-xl border border-blue-200 bg-white p-4 dark:border-blue-900 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-blue-700">Pindah Unit</div>
                        <x-info-button title="Pindah Unit">Transaksi 1 customer pindah dari unit A ke unit B (unit A jadi tersedia lagi).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-blue-700">{{ number_format($totalPindah) }}</div>
                </div>
                <div class="rounded-xl border border-indigo-200 bg-white p-4 dark:border-indigo-900 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-indigo-700">Tukar Unit</div>
                        <x-info-button title="Tukar Unit">Transaksi 2 customer bertukar unit (customer A ke unit B, customer B ke unit A).</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-indigo-700">{{ number_format($totalSwap) }}</div>
                </div>
                <div class="rounded-xl border border-rose-200 bg-white p-4 dark:border-rose-900 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div class="text-[10px] font-bold uppercase text-rose-700">Total Pengembalian Kelebihan</div>
                        <x-info-button title="Total Pengembalian Kelebihan">Total refund selisih harga saat customer pindah ke unit yang lebih murah dari unit asal.</x-info-button>
                    </div>
                    <div class="mt-2 text-2xl font-bold tabular-nums text-rose-700">Rp {{ $fmtJt($totalRefundPindah) }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                                <th class="px-2 py-2 text-center">No</th>
                                <th class="px-2 py-2">Nomor Transaksi</th>
                                <th class="px-2 py-2">Tanggal</th>
                                <th class="px-2 py-2">Tipe</th>
                                <th class="px-2 py-2">Customer &amp; Perpindahan</th>
                                <th class="px-2 py-2 text-right">Selisih Harga</th>
                                <th class="px-2 py-2">Alasan</th>
                                <th class="px-2 py-2">Diproses Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($switchings as $sw)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-2 py-2 text-center text-zinc-500">{{ $loop->index + ($switchings->firstItem() ?? 1) }}</td>
                                    <td class="whitespace-nowrap px-2 py-2 font-mono font-bold text-emerald-700 dark:text-emerald-400">
                                        <a href="{{ route('marketing.spr-pindah.show', $sw->id) }}" wire:navigate class="underline-offset-2 hover:underline">
                                            {{ $sw->nomor_switching }}
                                        </a>
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-2">{{ $sw->processed_at?->translatedFormat('d M Y') }}</td>
                                    <td class="whitespace-nowrap px-2 py-2">
                                        @if ($sw->tipe === 'swap')
                                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold uppercase text-indigo-700">Tukar</span>
                                        @else
                                            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase text-blue-700">Pindah</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold">{{ $sw->sprLamaA?->prospectCustomer?->nama_lengkap }}</span>
                                            <span class="font-mono text-zinc-500">{{ $sw->sprLamaA?->rumah?->kode_unit }}</span>
                                            <flux:icon.arrow-right class="size-3 text-zinc-400" />
                                            <span class="font-mono font-semibold text-emerald-700 dark:text-emerald-400">{{ $sw->sprBaruA?->rumah?->kode_unit }}</span>
                                        </div>
                                        @if ($sw->tipe === 'swap' && $sw->sprLamaB)
                                            <div class="mt-1 flex items-center gap-2">
                                                <span class="font-semibold">{{ $sw->sprLamaB?->prospectCustomer?->nama_lengkap }}</span>
                                                <span class="font-mono text-zinc-500">{{ $sw->sprLamaB?->rumah?->kode_unit }}</span>
                                                <flux:icon.arrow-right class="size-3 text-zinc-400" />
                                                <span class="font-mono font-semibold text-emerald-700 dark:text-emerald-400">{{ $sw->sprBaruB?->rumah?->kode_unit }}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 text-right font-mono tabular-nums">
                                        @php $selA = (float) $sw->selisih_a; @endphp
                                        <span @class([
                                            'text-amber-700 dark:text-amber-400' => $selA > 0,
                                            'text-emerald-700 dark:text-emerald-400' => $selA < 0,
                                            'text-zinc-500' => $selA == 0,
                                        ])>{{ $selA > 0 ? '+' : '' }}{{ $fmt($selA) }}</span>
                                        @if ($sw->tipe === 'swap' && (float) $sw->selisih_b != 0)
                                            @php $selB = (float) $sw->selisih_b; @endphp
                                            <div @class([
                                                'text-amber-700 dark:text-amber-400' => $selB > 0,
                                                'text-emerald-700 dark:text-emerald-400' => $selB < 0,
                                            ])>{{ $selB > 0 ? '+' : '' }}{{ $fmt($selB) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 text-zinc-700 dark:text-zinc-300">{{ $sw->alasan }}</td>
                                    <td class="whitespace-nowrap px-2 py-2 text-zinc-600 dark:text-zinc-400">{{ $sw->processedBy?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-zinc-400">Belum ada perpindahan kavling di periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $switchings->links() }}</div>
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
