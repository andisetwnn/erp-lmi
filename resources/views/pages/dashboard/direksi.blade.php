<?php

use App\Models\Master\Coa;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\TargetMarketing;
use App\Models\Matrix\MatrixImport;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    #[Url(as: 'tahun')]
    public int $selectedTahun = 0;

    /** Rincian matriks marketing: 'proyek' (setahun per proyek) atau 'bulan' (Jan-Des). */
    #[Url(as: 'rincian')]
    public string $rincianMatriks = 'proyek';

    /**
     * Persempit rincian bulanan ke satu proyek. Null = semua proyek.
     *
     * Hanya berlaku pada rincian bulanan: di sana tiap proyek memakan tiga baris,
     * jadi belasan proyek membuat tabelnya terlalu panjang untuk dibaca. Pada rincian
     * per proyek tidak dipakai — justru perbandingan antar kolomnya yang dicari.
     */
    #[Url(as: 'proyek')]
    public ?int $proyekMatriks = null;

    public function mount(): void
    {
        if (! $this->selectedTahun) {
            $this->selectedTahun = (int) now()->year;
        }
    }

    public function setRincian(string $mode): void
    {
        if (in_array($mode, ['proyek', 'bulan'], true)) {
            $this->rincianMatriks = $mode;
        }
    }

    /** Proyek pilihan yang benar-benar ada; kalau tidak, jatuh kembali ke semua proyek. */
    protected function proyekTerpilih($proyekList): ?int
    {
        return $this->proyekMatriks && $proyekList->contains('id', $this->proyekMatriks)
            ? $this->proyekMatriks
            : null;
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

        // Rincian per bulan — dipakai kalau direktur memilih tampilan bulanan.
        // Angka setahun menyembunyikan bulan yang meleset: satu bulan panen bisa
        // menutupi lima bulan kosong.
        $bulanKosong = array_fill(1, 12, 0);

        $akadRealBulan = $this->perBulanPerProyek(
            Spr::query()->where('spr.status', 'akad')->whereBetween('spr.tgl_akad', [$tahunAwal, $tahunAkhir]),
            'spr.tgl_akad'
        );
        $penjualanRealBulan = $this->perBulanPerProyek(
            Spr::query()->where('spr.status', '!=', 'cancelled')->whereBetween('spr.tanggal_spr', [$tahunAwal, $tahunAkhir]),
            'spr.tanggal_spr'
        );

        $akadTargetBulan = [];
        $penjualanTargetBulan = [];
        foreach ($proyekList as $p) {
            $akadTargetBulan[$p->id] = $bulanKosong;
            $penjualanTargetBulan[$p->id] = $bulanKosong;
            $akadRealBulan[$p->id] ??= $bulanKosong;
            $penjualanRealBulan[$p->id] ??= $bulanKosong;
        }
        foreach (TargetMarketing::where('tahun', $this->selectedTahun)->get() as $t) {
            if (! isset($akadTargetBulan[$t->proyek_id])) {
                continue;
            }
            $akadTargetBulan[$t->proyek_id][$t->bulan] = (int) $t->target_akad;
            $penjualanTargetBulan[$t->proyek_id][$t->bulan] = (int) $t->target_penjualan;
        }

        // Options dropdown tahun — dari data SPR yang ada
        $tahunSql = $this->ekspresiTahun('tanggal_spr');
        $tahunSprMin = Spr::min(DB::raw($tahunSql)) ?: (int) now()->year;
        $tahunSprMax = max((int) now()->year, Spr::max(DB::raw($tahunSql)) ?: (int) now()->year);
        $tahunOptions = range($tahunSprMax, $tahunSprMin);

        $proyekTerpilih = $this->proyekTerpilih($proyekList);

        return compact(
            'proyekList', 'akadTarget', 'akadReal', 'penjualanTarget', 'penjualanReal', 'tahunOptions',
            'akadTargetBulan', 'akadRealBulan', 'penjualanTargetBulan', 'penjualanRealBulan',
            'proyekTerpilih',
        );
    }

    /**
     * Ekspresi SQL untuk mengambil tahun dari kolom tanggal.
     *
     * YEAR() dan MONTH() hanya ada di MySQL — SQLite yang dipakai test suite tidak
     * mengenalnya, jadi dashboard ini dulu tidak bisa diuji sama sekali.
     */
    protected function ekspresiTahun(string $kolom): string
    {
        return $this->pakaiMysql() ? "YEAR($kolom)" : "CAST(strftime('%Y', $kolom) AS INTEGER)";
    }

    protected function ekspresiBulan(string $kolom): string
    {
        return $this->pakaiMysql() ? "MONTH($kolom)" : "CAST(strftime('%m', $kolom) AS INTEGER)";
    }

    protected function pakaiMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Hitung jumlah SPR per proyek per bulan (1-12) untuk tahun terpilih.
     *
     * @return array<int, array<int, int>> [proyek_id => [bulan => jumlah]]
     */
    protected function perBulanPerProyek($query, string $kolomTanggal): array
    {
        $bulan = $this->ekspresiBulan($kolomTanggal);

        $baris = $query
            ->join('rumah', 'rumah.id', '=', 'spr.rumah_id')
            ->selectRaw("rumah.proyek_id, $bulan as bulan, COUNT(*) as jml")
            ->groupBy('rumah.proyek_id', DB::raw($bulan))
            ->get();

        $hasil = [];
        foreach ($baris as $b) {
            $hasil[$b->proyek_id] ??= array_fill(1, 12, 0);
            $hasil[$b->proyek_id][(int) $b->bulan] = (int) $b->jml;
        }

        return $hasil;
    }

    /** Kartu Persediaan Rumah yang bisa dibuka rinciannya. */
    public const KARTU_PERSEDIAAN = [
        'total_kavling', 'akad', 'sudah_dibangun', 'belum_dibangun',
        'konsumen_aktif', 'konsumen_non_lot', 'stok_awal', 'stok_berjalan',
    ];

    /** Kartu Persediaan Rumah yang rinciannya sedang dibuka. */
    public ?string $rincianPersediaan = null;

    public string $cariRincian = '';

    public function bukaRincianPersediaan(string $kunci): void
    {
        if (! in_array($kunci, self::KARTU_PERSEDIAAN, true)) {
            return;
        }

        $this->rincianPersediaan = $kunci;
        $this->cariRincian = '';
        Flux::modal('rincian-persediaan')->show();
    }

    public function tutupRincianPersediaan(): void
    {
        $this->reset('rincianPersediaan', 'cariRincian');
    }

    /**
     * Penyaring unit untuk tiap kartu Persediaan Rumah. Dipakai untuk angka di kartu
     * sekaligus daftar rinciannya, supaya jumlah baris selalu sama dengan angkanya.
     *
     * - Tahap pembangunan mengikuti LOT (gelombang pembangunan): unit yang sudah turun
     *   LOT sedang/sudah dibangun. Progres fisik tidak dipakai karena belum diperbarui
     *   rutin oleh admin teknik. Akad, dibangun, dan belum dibangun membagi habis kavling.
     * - Stok kavling = total dikurangi akad, bukan unit berstatus "available": unit
     *   ditandai terjual begitu SPR disetujui, padahal menurut manajemen selama belum
     *   akad ia masih stok. Stok awal tahun hanya dikurangi akad sebelum tahun terpilih,
     *   menurut tanggal akad (bukan tanggal SPR) — SPR Desember yang akad Februari
     *   termasuk penjualan tahun berikutnya.
     */
    protected function saringPersediaan(string $kunci, Builder $q): Builder
    {
        $akad = Spr::query()->select('rumah_id')->where('status', 'akad');
        $dibeli = Spr::query()->select('rumah_id')->whereIn('status', ['submitted', 'approved']);
        $akadSebelumTahunIni = Spr::query()->select('rumah_id')->where('status', 'akad')
            ->whereNotNull('tgl_akad')
            ->where('tgl_akad', '<', CarbonImmutable::create($this->selectedTahun, 1, 1)->toDateString());

        return match ($kunci) {
            'akad' => $q->whereIn('id', $akad),
            'sudah_dibangun' => $q->whereNotNull('lot')->whereNotIn('id', $akad),
            'belum_dibangun' => $q->whereNull('lot')->whereNotIn('id', $akad),
            'konsumen_aktif' => $q->whereIn('id', $dibeli)->whereNotIn('id', $akad),
            'konsumen_non_lot' => $q->whereIn('id', $dibeli)->whereNotIn('id', $akad)->whereNull('lot'),
            'stok_awal' => $q->whereNotIn('id', $akadSebelumTahunIni),
            'stok_berjalan' => $q->whereNotIn('id', $akad),
            default => $q,
        };
    }

    /**
     * Unit di balik kartu yang dibuka, beserta pembelinya bila ada.
     *
     * @return Collection<int, array{unit: string, blok: string, nomor: int, tipe: ?string, lot: ?int, progres: int, konsumen: ?string, sales: ?string, tahap: ?string, tanggal: mixed}>
     */
    protected function daftarRincianPersediaan(): Collection
    {
        $rumah = $this->saringPersediaan($this->rincianPersediaan, Rumah::query()->whereIn('proyek_id', Proyek::pluck('id')))
            ->with('tipeRumah:id,tipe')
            ->get(['id', 'tipe_rumah_id', 'blok', 'nomor_unit', 'lot', 'progres_fisik']);

        $spr = Spr::whereIn('rumah_id', $rumah->pluck('id'))
            ->whereIn('status', ['submitted', 'approved', 'akad'])
            ->with(['prospectCustomer:id,nama_lengkap', 'sales:id,nama'])
            ->orderByDesc('id')
            ->get()
            ->unique('rumah_id')
            ->keyBy('rumah_id');

        $cari = mb_strtolower(trim($this->cariRincian));

        return $rumah
            ->map(function (Rumah $r) use ($spr) {
                $s = $spr[$r->id] ?? null;

                return [
                    'unit' => $r->kode_unit, 'blok' => $r->blok, 'nomor' => (int) $r->nomor_unit,
                    'tipe' => $r->tipeRumah?->tipe, 'lot' => $r->lot, 'progres' => (int) $r->progres_fisik,
                    'konsumen' => $s?->prospectCustomer?->nama_lengkap, 'sales' => $s?->sales?->nama,
                    'tahap' => match ($s?->status) {
                        'akad' => 'Akad', 'approved' => 'Disetujui', 'submitted' => 'Diajukan', default => null
                    },
                    'tanggal' => $s ? ($s->status === 'akad' ? $s->tgl_akad : $s->tanggal_spr) : null,
                ];
            })
            ->filter(fn ($b) => $cari === '' || str_contains(mb_strtolower($b['unit'].' '.$b['konsumen'].' '.$b['sales']), $cari))
            ->sortBy([['blok', 'asc'], ['nomor', 'asc']])
            ->values();
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
        $rumahBooking = $countByProyek(fn ($q) => $q->where('status', 'booking'));
        $rumahTerjual = $countByProyek(fn ($q) => $q->where('status', 'terjual'));
        $rumahSelesai = $countByProyek(fn ($q) => $q->where('progres_fisik', 100));
        $rumahProses = $countByProyek(fn ($q) => $q->whereBetween('progres_fisik', [1, 99]));

        $per = fn (string $kunci) => $countByProyek(fn ($q) => $this->saringPersediaan($kunci, $q));
        $akadUnit = $per('akad');
        $sudahDibangun = $per('sudah_dibangun');
        $belumDibangun = $per('belum_dibangun');
        $konsumenAktif = $per('konsumen_aktif');
        $konsumenNonLot = $per('konsumen_non_lot');
        $stokAwal = $per('stok_awal');
        $stokBerjalan = $per('stok_berjalan');

        $komposisi = $proyekList->map(fn ($p) => [
            'nama' => $p->nama_proyek,
            'total' => (int) ($totalKavling[$p->id] ?? 0),
            'akad' => (int) ($akadUnit[$p->id] ?? 0),
            'dibangun' => (int) ($sudahDibangun[$p->id] ?? 0),
            'belum' => (int) ($belumDibangun[$p->id] ?? 0),
        ])->filter(fn ($k) => $k['total'] > 0)->values();

        $metrics = [
            [
                'key' => 'total_kavling', 'label' => 'Total Rencana Kavling', 'icon' => 'map', 'color' => 'sky', 'values' => $totalKavling,
                'info' => 'Seluruh unit yang terdaftar di master rumah, apa pun statusnya. Ini pembagi untuk persentase di baris lain.',
            ],
            [
                'key' => 'akad', 'label' => 'Akad', 'icon' => 'check-badge', 'color' => 'emerald', 'values' => $akadUnit, 'persen' => true,
                'info' => 'Unit yang SPR-nya sudah berstatus akad — serah terima selesai. Persentasenya terhadap Total Rencana Kavling.',
            ],
            [
                'key' => 'sudah_dibangun', 'label' => 'Sudah Dibangun, Belum Akad', 'icon' => 'wrench-screwdriver', 'color' => 'orange', 'values' => $sudahDibangun,
                'info' => 'Unit yang sudah turun LOT (masuk pembangunan) tapi belum akad, baik sudah ada pembelinya maupun belum.',
            ],
            [
                'key' => 'belum_dibangun', 'label' => 'Belum Dibangun', 'icon' => 'map-pin', 'color' => 'zinc', 'values' => $belumDibangun,
                'info' => 'Sisa kavling yang belum turun LOT dan belum akad, baik sudah ada pembelinya maupun belum.',
            ],
            [
                'key' => 'konsumen_aktif', 'label' => 'Konsumen Aktif', 'icon' => 'user-group', 'color' => 'amber', 'values' => $konsumenAktif,
                'info' => 'Konsumen yang sudah membeli — SPR-nya diajukan atau disetujui — tapi belum akad.',
            ],
            [
                'key' => 'konsumen_non_lot', 'label' => 'Konsumen Non LOT', 'icon' => 'clock', 'color' => 'rose', 'values' => $konsumenNonLot,
                'info' => 'Konsumen aktif yang unitnya belum turun LOT — sudah membeli, tapi rumahnya belum mulai dibangun.',
            ],
            [
                'key' => 'stok_awal', 'label' => 'Stok Kavling Awal '.$this->selectedTahun, 'icon' => 'calendar', 'color' => 'violet', 'values' => $stokAwal, 'persen' => true,
                'info' => 'Total kavling dikurangi akad tahun-tahun sebelum '.$this->selectedTahun.'. Menunjukkan berapa banyak yang tersisa untuk dijual saat tahun itu dimulai.',
            ],
            [
                'key' => 'stok_berjalan', 'label' => 'Stok Kavling Berjalan', 'icon' => 'squares-2x2', 'color' => 'indigo', 'values' => $stokBerjalan,
                'info' => 'Total kavling dikurangi seluruh akad sejak proyek berdiri. Unit yang SPR-nya sudah disetujui tapi belum akad masih terhitung stok di sini.',
            ],
            // Dua baris berikut sengaja disembunyikan dulu: angkanya bersandar pada
            // progres fisik yang belum diisi lengkap oleh admin teknik, jadi belum
            // layak dibaca direksi. Hapus 'sembunyi' untuk menampilkannya lagi.
            [
                'key' => 'rumah_selesai', 'label' => 'Stok Rumah', 'icon' => 'home', 'color' => 'rose', 'values' => $rumahSelesai,
                'sembunyi' => true,
                'info' => 'Unit yang progres fisik bangunannya sudah 100 persen, terlepas dari sudah terjual atau belum.',
            ],
            [
                'key' => 'rumah_proses', 'label' => 'Rumah Proses Bangun', 'icon' => 'wrench', 'color' => 'orange', 'values' => $rumahProses,
                'sembunyi' => true,
                'info' => 'Unit yang progres fisiknya antara 1 sampai 99 persen — sedang dikerjakan. Diperbarui admin teknik lewat menu Teknik.',
            ],
        ];

        // Tahap pembangunan hanya sesegar data LOT-nya, yang datang dari unggahan Matrix
        // atau diisi admin teknik di menu Teknik.
        $lotDiperbarui = Rumah::whereIn('proyek_id', $proyekIds)->max('progres_updated_at');
        $matrixTerakhir = MatrixImport::latest('id')->first(['nama_file', 'created_at']);

        return compact('proyekList', 'metrics', 'totalKavling', 'komposisi', 'lotDiperbarui', 'matrixTerakhir');
    }

    public function with(): array
    {
        $now = CarbonImmutable::now();

        // ─── 1. RINGKASAN KEUANGAN (dari COA balance, jurnal posted) ───
        // Hanya kas dan bank. Piutang & hutang sengaja tidak ditampilkan di dashboard ini —
        // rinciannya ada di modul laporan keuangan.
        $keuangan = [
            'kas' => $this->saldoParent('1001', 'aset'),
            'bank' => $this->saldoParent('1002', 'aset'),
        ];

        return [
            'keuangan' => $keuangan,
            'now' => $now,
            'marketing' => $this->marketingMatrix(),
            'persediaan' => $this->persediaanMatrix(),
            'rincian' => $this->rincianPersediaan ? $this->daftarRincianPersediaan() : collect(),
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
                    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                </div>
            </div>
            <div class="flex flex-col items-end gap-2 text-right text-xs text-zinc-500">
                <x-dashboard-switcher current="direksi" />
                <div>Data per {{ $now->translatedFormat('l, d F Y H:i') }}</div>
                <div class="mt-0.5 text-[10px] uppercase tracking-wide">Bulan berjalan: {{ $now->translatedFormat('F Y') }}</div>
            </div>
        </div>

        @php
            $fmt = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
            $num = fn ($v) => number_format((int) $v);
        @endphp

        {{-- ═════════════ SECTION 1: RINGKASAN KEUANGAN ═════════════ --}}
        <div class="mb-6">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.banknotes class="size-4 text-zinc-500" />
                <h2 class="text-sm font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">Ringkasan Keuangan</h2>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
                </div>
            </div>
        </div>

        {{-- ═════════════ PERSEDIAAN RUMAH (matrix per proyek) ═════════════ --}}
        @php
            $metricColorMap = [
                'sky' => 'bg-sky-500', 'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500',
                'indigo' => 'bg-indigo-500', 'rose' => 'bg-rose-500', 'orange' => 'bg-orange-500',
                'violet' => 'bg-violet-500', 'zinc' => 'bg-zinc-400',
            ];
        @endphp
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.home-modern class="size-5 text-emerald-600" />
                <h2 class="text-base font-bold">Persediaan Rumah</h2>

                {{-- Penjelasan ditaruh di judul, bukan per baris: isi tabel berada di dalam
                     kotak yang bisa digeser menyamping, dan apa pun yang muncul dari dalam
                     sana ikut terpotong oleh kotak itu. --}}
                <x-info-button title="Persediaan Rumah">
                    <p class="mb-3">Arti tiap baris, supaya angka yang mirip tidak tertukar:</p>
                    <dl class="space-y-2.5">
                        @foreach ($persediaan['metrics'] as $m)
                            @continue($m['sembunyi'] ?? false)
                            <div>
                                <dt class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $m['label'] }}</dt>
                                <dd class="text-zinc-600 dark:text-zinc-400">{{ $m['info'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-info-button>

                <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Proyek to Date</span>
            </div>

            {{-- Kartu ringkas: seluruh kavling terbagi habis menjadi akad, sudah dibangun
                 (sudah turun LOT), dan belum dibangun; lalu konsumen yang belum akad.
                 Angkanya gabungan semua proyek; tabel rincian per proyek baru muncul kalau
                 proyeknya lebih dari satu, supaya angka yang sama tidak tampil dua kali. --}}
            @php
                $k = [
                    'total' => $persediaan['komposisi']->sum('total'),
                    'akad' => $persediaan['komposisi']->sum('akad'),
                    'dibangun' => $persediaan['komposisi']->sum('dibangun'),
                    'belum' => $persediaan['komposisi']->sum('belum'),
                ];
                $nilaiMetrik = fn (string $kunci) => (int) collect(collect($persediaan['metrics'])->firstWhere('key', $kunci)['values'] ?? [])->sum();
                $aktif = $nilaiMetrik('konsumen_aktif');
                $nonLot = $nilaiMetrik('konsumen_non_lot');
                $persen = fn (int $v) => $k['total'] > 0 ? round($v / $k['total'] * 100).'% dari total kavling' : '';
                $kartu = [
                    ['Total Kavling', $k['total'], 'Seluruh unit di master rumah', 'map', 'bg-sky-500', 'total_kavling'],
                    ['Akad', $k['akad'], $persen($k['akad']), 'check-badge', 'bg-emerald-500', 'akad'],
                    ['Sudah Dibangun, Belum Akad', $k['dibangun'], $num($aktif - $nonLot).' sudah terjual · '.$num($k['dibangun'] - ($aktif - $nonLot)).' belum terjual', 'wrench-screwdriver', 'bg-orange-500', 'sudah_dibangun'],
                    ['Belum Dibangun', $k['belum'], $num($nonLot).' sudah terjual · '.$num($k['belum'] - $nonLot).' belum terjual', 'map-pin', 'bg-zinc-400', 'belum_dibangun'],
                    ['Konsumen Aktif', $aktif, 'Sudah beli, belum akad', 'user-group', 'bg-amber-500', 'konsumen_aktif'],
                    ['Konsumen Non LOT', $nonLot, 'Sudah beli, belum turun LOT', 'clock', 'bg-rose-500', 'konsumen_non_lot'],
                    ['Stok Kavling Awal '.$this->selectedTahun, $nilaiMetrik('stok_awal'), $persen($nilaiMetrik('stok_awal')), 'calendar', 'bg-violet-500', 'stok_awal'],
                    ['Stok Kavling Berjalan', $nilaiMetrik('stok_berjalan'), 'Total kavling dikurangi seluruh akad', 'squares-2x2', 'bg-indigo-500', 'stok_berjalan'],
                ];
            @endphp
            @if ($k['total'] > 0)
                <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ($kartu as [$label, $nilai, $keterangan, $ikon, $warna, $kunci])
                        <button type="button" wire:click="bukaRincianPersediaan('{{ $kunci }}')" title="{{ __('Lihat unit') }}"
                                class="cursor-pointer rounded-lg border border-zinc-200 bg-white p-3 text-left transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex items-center gap-2">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg text-white shadow-sm {{ $warna }}">
                                    <flux:icon :name="$ikon" class="size-4" />
                                </span>
                                <span class="text-[10px] font-bold uppercase leading-tight tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
                                <flux:icon.chevron-right class="ml-auto size-3.5 shrink-0 text-zinc-400" />
                            </div>
                            <div class="mt-2 font-mono text-2xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">{{ $num($nilai) }}</div>
                            <div class="mt-0.5 text-[11px] leading-tight text-zinc-500 dark:text-zinc-400">{{ $keterangan }}</div>
                        </button>
                    @endforeach
                </div>
                <p @class(['-mt-2 text-[11px] text-zinc-500 dark:text-zinc-400', 'mb-4' => $persediaan['komposisi']->count() > 1])>
                    {{ __('Sudah/belum dibangun mengikuti LOT.') }}
                    @if ($persediaan['lotDiperbarui'])
                        {{ __('Data LOT terakhir diperbarui') }} <b>{{ \Illuminate\Support\Carbon::parse($persediaan['lotDiperbarui'])->translatedFormat('j M Y') }}</b>.
                    @endif
                    @if ($persediaan['matrixTerakhir'])
                        {{ __('Matrix terakhir diunggah') }}: {{ $persediaan['matrixTerakhir']->nama_file }} ({{ $persediaan['matrixTerakhir']->created_at->translatedFormat('j M Y') }}).
                    @endif
                </p>
            @endif

            {{-- Rincian kartu: daftar unit di balik angka yang diklik --}}
            <flux:modal name="rincian-persediaan" class="w-full max-w-5xl" @close="tutupRincianPersediaan">
                @php $judulRincian = collect($kartu)->firstWhere(5, $rincianPersediaan); @endphp
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ $judulRincian[0] ?? __('Rincian') }} · {{ $num($judulRincian[1] ?? 0) }} {{ __('unit') }}</flux:heading>
                        <flux:subheading>{{ $judulRincian[2] ?? '' }}</flux:subheading>
                    </div>
                    <flux:input wire:model.live.debounce.300ms="cariRincian" icon="magnifying-glass" :placeholder="__('Cari unit, konsumen, atau sales')" />
                    <div class="max-h-[60vh] overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ __('Unit') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Tipe') }}</th>
                                    <th class="px-3 py-2 text-center">{{ __('LOT') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Progres') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Konsumen') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Tahap') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Tanggal') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Sales') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @forelse ($rincian as $b)
                                    <tr wire:key="rincian-{{ $b['unit'] }}">
                                        <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold">{{ $b['unit'] }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $b['tipe'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-center font-mono">{{ $b['lot'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $b['progres'] }}%</td>
                                        <td @class(['px-3 py-2', 'text-zinc-400' => ! $b['konsumen']])>{{ $b['konsumen'] ?? __('Belum terjual') }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $b['tahap'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 font-mono">{{ $b['tanggal'] ? \Illuminate\Support\Carbon::parse($b['tanggal'])->format('d/m/Y') : '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $b['sales'] ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-3 py-8 text-center text-zinc-400">{{ __('Tidak ada unit yang cocok.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[11px] text-zinc-500">{{ $num($rincian->count()) }} {{ __('unit ditampilkan') }}{{ $cariRincian !== '' ? ' '.__('dari').' '.$num($judulRincian[1] ?? 0) : '' }}.</p>
                </div>
            </flux:modal>

            @if ($persediaan['komposisi']->count() > 1)
            <div class="overflow-x-auto" data-rincian="persediaan-per-proyek">
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
                            @continue($m['sembunyi'] ?? false)
                            @php
                                $totalRow = collect($m['values'])->sum();
                                // Persentase terhadap total kavling, seperti laporan lama.
                                $totalSemuaKavling = collect($persediaan['totalKavling'])->sum();
                                $persenRow = ! empty($m['persen']) && $totalSemuaKavling > 0
                                    ? (int) round($totalRow / $totalSemuaKavling * 100)
                                    : null;
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="flex h-7 w-7 items-center justify-center rounded text-white shadow-sm {{ $metricColorMap[$m['color']] ?? 'bg-zinc-500' }}">
                                            <flux:icon :name="$m['icon']" class="size-4" />
                                        </span>
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $m['label'] }}</span>
                                        @if ($persenRow !== null)
                                            <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $persenRow }}%</span>
                                        @endif
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
            @endif
        </div>

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

            {{-- Pemilih rincian: setahun per proyek, atau dipecah Jan-Des --}}
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-700">
                    @foreach (['proyek' => 'Per Proyek', 'bulan' => 'Per Bulan'] as $mode => $labelMode)
                        <button type="button" wire:click="setRincian('{{ $mode }}')"
                                @class([
                                    'rounded-md px-3 py-1.5 text-xs font-semibold transition',
                                    'bg-emerald-600 text-white' => $rincianMatriks === $mode,
                                    'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $rincianMatriks !== $mode,
                                ])>{{ $labelMode }}</button>
                    @endforeach
                </div>

                {{-- Penyaring proyek: hanya berguna pada rincian bulanan, dan baru muncul
                     kalau proyeknya lebih dari satu. --}}
                @if ($rincianMatriks === 'bulan' && count($marketing['proyekList']) > 1)
                    <flux:select wire:model.live="proyekMatriks" size="sm" class="max-w-56">
                        <flux:select.option value="">Semua Proyek</flux:select.option>
                        @foreach ($marketing['proyekList'] as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            {{-- Matriks target vs realisasi per proyek, mengikuti bentuk laporan lama --}}
            @php
                $metrikList = [
                    [
                        'judul' => 'Penjualan progress',
                        'label' => 'Penjualan YTD',
                        'target' => $marketing['penjualanTarget'],
                        'real' => $marketing['penjualanReal'],
                        'targetBulan' => $marketing['penjualanTargetBulan'],
                        'realBulan' => $marketing['penjualanRealBulan'],
                        'kelasBadge' => 'bg-blue-600',
                        'kelasLabel' => 'text-blue-700 dark:text-blue-400',
                    ],
                    [
                        'judul' => 'Akad progress',
                        'label' => 'Akad YTD',
                        'target' => $marketing['akadTarget'],
                        'real' => $marketing['akadReal'],
                        'targetBulan' => $marketing['akadTargetBulan'],
                        'realBulan' => $marketing['akadRealBulan'],
                        'kelasBadge' => 'bg-emerald-600',
                        'kelasLabel' => 'text-emerald-700 dark:text-emerald-400',
                    ],
                ];
            @endphp

            @foreach ($metrikList as $m)
                @php
                    $totalTarget = collect($m['target'])->sum();
                    $totalReal = collect($m['real'])->sum();
                    $selisih = $totalReal - $totalTarget;
                @endphp

                <div class="mb-4 last:mb-0">
                    <div class="mb-2 flex items-center gap-2">
                        <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase text-white {{ $m['kelasBadge'] }}">YTD</span>
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ $m['judul'] }}</span>
                    </div>

                    @if ($rincianMatriks === 'bulan')
                        {{-- Rincian Jan-Des: menampakkan bulan yang meleset, yang tertutup
                             oleh angka setahun. --}}
                        @php
                            $namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                            $jumlahkanBulan = function (array $perProyek) {
                                $hasil = array_fill(1, 12, 0);
                                foreach ($perProyek as $bulanan) {
                                    foreach ($bulanan as $b => $n) {
                                        $hasil[$b] += (int) $n;
                                    }
                                }

                                return $hasil;
                            };
                            // Kalau satu proyek dipilih, tabelnya menampilkan proyek itu saja —
                            // tanpa baris *ALL, karena isinya akan persis sama.
                            $proyekDipilih = $marketing['proyekTerpilih'];
                            $daftarProyek = $proyekDipilih
                                ? $marketing['proyekList']->where('id', $proyekDipilih)
                                : $marketing['proyekList'];
                            $banyakProyek = ! $proyekDipilih && count($marketing['proyekList']) > 1;

                            // Label nama proyek tetap ditampilkan saat disaring, supaya tabel
                            // menyebut sedang menampilkan proyek yang mana.
                            $tampilkanLabelGrup = $banyakProyek || (bool) $proyekDipilih;

                            $barisGrup = [];
                            if ($banyakProyek) {
                                $barisGrup[] = [
                                    'nama' => '*ALL',
                                    'target' => $jumlahkanBulan($m['targetBulan']),
                                    'real' => $jumlahkanBulan($m['realBulan']),
                                ];
                            }
                            foreach ($daftarProyek as $p) {
                                $barisGrup[] = [
                                    'nama' => $p->nama_proyek,
                                    'target' => $m['targetBulan'][$p->id] ?? array_fill(1, 12, 0),
                                    'real' => $m['realBulan'][$p->id] ?? array_fill(1, 12, 0),
                                ];
                            }
                        @endphp

                        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="w-full text-xs" style="min-width: 56rem">
                                <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-2 text-left" style="width: 9rem">
                                            <span class="inline-flex items-center gap-1.5 {{ $m['kelasLabel'] }}">
                                                <flux:icon.tag class="size-3" />
                                                <span class="font-bold">{{ $m['label'] }}</span>
                                            </span>
                                        </th>
                                        @foreach ($namaBulan as $nb)
                                            <th class="px-2 py-2 text-right font-semibold">{{ $nb }}</th>
                                        @endforeach
                                        <th class="border-l border-zinc-200 px-3 py-2 text-right font-bold dark:border-zinc-700">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($barisGrup as $g)
                                        @if ($tampilkanLabelGrup)
                                            <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                                <td colspan="14" class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide text-zinc-500">{{ $g['nama'] }}</td>
                                            </tr>
                                        @endif

                                        <tr>
                                            <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <flux:icon.clipboard-document-list class="size-4 text-zinc-400" />
                                                    <span class="font-semibold">TARGET</span>
                                                </span>
                                            </td>
                                            @for ($b = 1; $b <= 12; $b++)
                                                @php $v = (int) ($g['target'][$b] ?? 0); @endphp
                                                <td class="px-2 py-2 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300 dark:text-zinc-600' : '' }}">{{ $num($v) }}</td>
                                            @endfor
                                            <td class="border-l border-zinc-200 px-3 py-2 text-right font-mono font-bold tabular-nums dark:border-zinc-700">{{ $num(array_sum($g['target'])) }}</td>
                                        </tr>

                                        <tr class="bg-zinc-50/50 dark:bg-zinc-800/30">
                                            <td class="whitespace-nowrap px-3 py-2">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <flux:icon.home-modern class="size-4 {{ $m['kelasLabel'] }}" />
                                                    <span class="font-semibold {{ $m['kelasLabel'] }}">REAL</span>
                                                </span>
                                            </td>
                                            @for ($b = 1; $b <= 12; $b++)
                                                @php $v = (int) ($g['real'][$b] ?? 0); @endphp
                                                <td class="px-2 py-2 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300 dark:text-zinc-600' : $m['kelasLabel'] }}">{{ $num($v) }}</td>
                                            @endfor
                                            <td class="border-l border-zinc-200 px-3 py-2 text-right font-mono font-bold tabular-nums dark:border-zinc-700 {{ $m['kelasLabel'] }}">{{ $num(array_sum($g['real'])) }}</td>
                                        </tr>

                                        <tr>
                                            <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                                <span class="inline-flex items-center gap-1.5">
                                                    <flux:icon.arrows-up-down class="size-4 text-zinc-400" />
                                                    <span class="font-semibold">SELISIH</span>
                                                </span>
                                            </td>
                                            @for ($b = 1; $b <= 12; $b++)
                                                @php
                                                    $d = (int) ($g['real'][$b] ?? 0) - (int) ($g['target'][$b] ?? 0);
                                                    $kelasD = $d > 0 ? 'text-emerald-600' : ($d < 0 ? 'text-rose-600' : 'text-zinc-300 dark:text-zinc-600');
                                                @endphp
                                                <td class="px-2 py-2 text-right font-mono tabular-nums {{ $kelasD }}">{{ $d > 0 ? '+'.$num($d) : $num($d) }}</td>
                                            @endfor
                                            @php
                                                $dTotal = array_sum($g['real']) - array_sum($g['target']);
                                                $kelasDTotal = $dTotal > 0 ? 'text-emerald-600' : ($dTotal < 0 ? 'text-rose-600' : 'text-zinc-400');
                                            @endphp
                                            <td class="border-l border-zinc-200 px-3 py-2 text-right font-mono font-bold tabular-nums dark:border-zinc-700 {{ $kelasDTotal }}">{{ $dTotal > 0 ? '+'.$num($dTotal) : $num($dTotal) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="whitespace-nowrap px-3 py-2 text-left" style="min-width: 11rem">
                                        <span class="inline-flex items-center gap-1.5 {{ $m['kelasLabel'] }}">
                                            <flux:icon.tag class="size-3" />
                                            <span class="font-bold">{{ $m['label'] }}</span>
                                        </span>
                                    </th>
                                    <th class="px-3 py-2 text-right font-bold text-zinc-700 dark:text-zinc-200" style="min-width: 5rem">*ALL</th>
                                    @foreach ($marketing['proyekList'] as $p)
                                        <th class="px-3 py-2 text-right font-semibold" style="min-width: 6rem">{{ $p->nama_proyek }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-zinc-600 dark:text-zinc-400">
                                        <span class="inline-flex items-center gap-1.5">
                                            <flux:icon.clipboard-document-list class="size-4 text-zinc-400" />
                                            <span class="font-semibold">TARGET</span>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono text-sm font-bold tabular-nums">{{ $num($totalTarget) }}</td>
                                    @foreach ($marketing['proyekList'] as $p)
                                        @php $v = (int) ($m['target'][$p->id] ?? 0); @endphp
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300 dark:text-zinc-600' : '' }}">{{ $num($v) }}</td>
                                    @endforeach
                                </tr>
                                <tr class="bg-zinc-50/50 dark:bg-zinc-800/30">
                                    <td class="whitespace-nowrap px-3 py-2.5">
                                        <span class="inline-flex items-center gap-1.5">
                                            <flux:icon.home-modern class="size-4 {{ $m['kelasLabel'] }}" />
                                            <span class="font-semibold {{ $m['kelasLabel'] }}">REAL</span>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-mono text-sm font-bold tabular-nums {{ $m['kelasLabel'] }}">{{ $num($totalReal) }}</td>
                                    @foreach ($marketing['proyekList'] as $p)
                                        @php $v = (int) ($m['real'][$p->id] ?? 0); @endphp
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums {{ $v === 0 ? 'text-zinc-300 dark:text-zinc-600' : $m['kelasLabel'] }}">{{ $num($v) }}</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    @endif

                    {{-- Penanda arah: sekali lihat ketahuan di atas atau di bawah target --}}
                    @if ($totalTarget > 0)
                        @php
                            $persen = (int) round($totalReal / $totalTarget * 100);
                            $kurang = $selisih < 0;
                        @endphp
                        <div class="mt-2 flex items-center gap-2 text-xs">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold {{ $kurang ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400' }}">
                                @if ($kurang)
                                    <flux:icon.arrow-down class="size-3" />
                                @else
                                    <flux:icon.arrow-up class="size-3" />
                                @endif
                                {{ $selisih > 0 ? '+'.$num($selisih) : $num($selisih) }}
                            </span>
                            <span class="text-zinc-500">{{ $persen }}% dari target {{ $selectedTahun }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>


    </div>
</section>
