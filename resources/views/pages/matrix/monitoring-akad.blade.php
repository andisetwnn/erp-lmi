<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Laporan Monitoring Akad')] class extends Component {
    use Sortable;

    /** mikro | makro | non-lot | akad */
    #[Url(as: 'tab')]
    public string $tab = 'mikro';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $seksi = '';

    #[Url(as: 'min')]
    public string $progMin = '';

    #[Url(as: 'max')]
    public string $progMax = '';

    #[Url(as: 'blok')]
    public string $blok = '';

    #[Url(as: 'tipe')]
    public string $tipe = '';

    /**
     * Rentang progres bawaan tiap tab.
     *
     * Ketiga tab membaca kumpulan unit yang sama dan menampilkan kolom yang sama —
     * yang membedakan hanya rentang progres pembangunan yang dipasang di awal.
     * Rentangnya tetap bisa diubah, bahkan dikosongkan untuk melihat semua unit.
     */
    public const BAWAAN_PERSEN = [
        'mikro' => ['80', '100'],
        'makro' => ['0', '70'],
        'non-lot' => ['', ''],
        'akad' => ['', ''],
    ];

    /**
     * Kolom yang boleh diurutkan. Daftar putih ini juga yang menjaga agar nilai
     * `sort` dari alamat halaman tidak bisa dipakai menyusupi query.
     *
     * @return array<int|string, string|callable>
     */
    private function kolomSort(): array
    {
        return [
            'nama', 'seksi', 'progres', 'sales', 'tipe', 'lot', 'subcon', 'tanggal',
            'total_harga_jual', 'bba', 'total_um', 'akumulasi_um', 'persen_um', 'sisa_um',
            'bm', 'wcr', 'sp3k', 'exp_sp3k', 'lpa', 'rencana_akad',

            // Nomor unit disimpan sebagai teks, jadi diurutkan per panjang dulu
            // supaya AE-9 tidak mendahului AE-10.
            'unit' => function ($query, string $arah) {
                $arah = $arah === 'desc' ? 'desc' : 'asc';

                return $query->orderBy('blok', $arah)
                    ->orderByRaw("LENGTH(nomor_unit) $arah")
                    ->orderBy('nomor_unit', $arah);
            },
        ];
    }

    public function mount(): void
    {
        // Kalau rentangnya sudah dibawa lewat alamat halaman, itu yang dipakai.
        if ($this->progMin === '' && $this->progMax === '') {
            $this->pasangBawaanPersen();
        }
    }

    public function pilihTab(string $tab): void
    {
        if (! array_key_exists($tab, self::BAWAAN_PERSEN)) {
            return;
        }

        $this->tab = $tab;

        // Filter tahap & blok melekat pada tab sebelumnya — kalau ikut terbawa,
        // tabel bisa tampak kosong tanpa alasan yang jelas.
        $this->reset('seksi', 'blok', 'tipe');
        $this->pasangBawaanPersen();
    }

    private function pasangBawaanPersen(): void
    {
        [$this->progMin, $this->progMax] = self::BAWAAN_PERSEN[$this->tab] ?? ['', ''];
    }

    public function bersihkan(): void
    {
        $this->reset('search', 'seksi', 'progMin', 'progMax', 'blok', 'tipe');
    }

    public function pakaiSeksi(string $seksi): void
    {
        $this->seksi = $this->seksi === $seksi ? '' : $seksi;
    }

    /**
     * Non Lot dan Akad memang daftar tersendiri — yang satu belum diajukan LOT,
     * yang satu sudah selesai akad. Mikro dan Makro sebaliknya: sumbernya sama,
     * hanya jendela progresnya yang berbeda.
     *
     * @return list<string>
     */
    public function kategoriDipakai(): array
    {
        return match ($this->tab) {
            'non-lot' => ['non_lot'],
            'akad' => ['akad'],
            default => ['mikro', 'makro_a', 'makro_b'],
        };
    }

    public function judul(): string
    {
        return match ($this->tab) {
            'makro' => __('Laporan Makro'),
            'non-lot' => __('Laporan Non Lot'),
            'akad' => __('Laporan Akad'),
            default => __('Laporan Mikro'),
        };
    }

    /** Keterangan mengikuti rentang yang benar-benar sedang dipakai, bukan bawaannya. */
    public function keterangan(): string
    {
        if ($this->tab === 'non-lot') {
            return __('Rekap unit yang belum masuk pengajuan LOT');
        }

        if ($this->tab === 'akad') {
            return __('Rekap unit yang akadnya sudah selesai');
        }

        return match (true) {
            $this->progMin !== '' && $this->progMax !== '' => __('Rekap unit progres :min–:maks%', [
                'min' => $this->progMin, 'maks' => $this->progMax,
            ]),
            $this->progMin !== '' => __('Rekap unit progres ≥ :min%', ['min' => $this->progMin]),
            $this->progMax !== '' => __('Rekap unit progres ≤ :maks%', ['maks' => $this->progMax]),
            default => __('Rekap seluruh unit, tanpa batas progres'),
        };
    }

    /** Filter persentase hanya masuk akal untuk unit yang punya progres bangunan. */
    public function pakaiFilterPersen(): bool
    {
        return ! in_array($this->tab, ['non-lot', 'akad'], true);
    }

    public function adaFilter(): bool
    {
        return $this->search !== '' || $this->seksi !== '' || $this->progMin !== ''
            || $this->progMax !== '' || $this->blok !== '' || $this->tipe !== '';
    }

    public function with(): array
    {
        $import = MatrixImport::terakhir();

        if (! $import) {
            return [
                'import' => null, 'unit' => collect(), 'jumlahTahap' => collect(),
                'totalSemua' => 0, 'daftarBlok' => [], 'daftarTipe' => [],
            ];
        }

        // Semua filter kecuali tahap — dipakai untuk menghitung angka di kartu,
        // supaya kartu tetap menunjukkan sebaran meski satu tahap sedang dipilih.
        $tanpaTahap = MatrixUnit::query()
            ->where('matrix_import_id', $import->id)
            ->kategori($this->kategoriDipakai())
            ->when($this->blok !== '', fn ($q) => $q->where('blok', $this->blok))
            ->when($this->tipe !== '', fn ($q) => $q->where('tipe', $this->tipe))
            ->when($this->pakaiFilterPersen() && ($this->progMin !== '' || $this->progMax !== ''),
                function ($q) {
                    $min = $this->progMin === '' ? 0.0 : (float) $this->progMin;
                    $maks = $this->progMax === '' ? 100.0 : (float) $this->progMax;

                    $q->where(function ($w) use ($min, $maks) {
                        $w->where(fn ($p) => $p->whereNotNull('progres')
                            ->whereBetween('progres', [$min, $maks]));

                        // Unit yang progresnya belum diisi ikut dinilai dari kisaran
                        // sheet asalnya, supaya tidak lenyap dari semua tab.
                        $sheetCocok = MatrixUnit::kategoriDalamRentang($min, $maks);

                        if ($sheetCocok !== []) {
                            $w->orWhere(fn ($p) => $p->whereNull('progres')
                                ->whereIn('kategori', $sheetCocok));
                        }
                    });
                })
            ->when($this->search !== '', function ($q) {
                $cari = '%'.$this->search.'%';
                $q->where(fn ($w) => $w->where('nama', 'like', $cari)
                    ->orWhere('blok', 'like', $cari)
                    ->orWhere('nomor_unit', 'like', $cari)
                    ->orWhere('sales', 'like', $cari)
                    ->orWhere('tipe', 'like', $cari)
                    ->orWhere('subcon', 'like', $cari));
            });

        $jumlahTahap = (clone $tanpaTahap)
            ->selectRaw('seksi, COUNT(*) as jumlah')
            ->groupBy('seksi')
            ->pluck('jumlah', 'seksi');

        $terurut = (clone $tanpaTahap)
            ->when($this->seksi !== '', fn ($q) => $q->where('seksi', $this->seksi));

        if ($this->sortBy === '') {
            // Tanpa pilihan pengguna, urutannya mengikuti berkas: per sheet, per
            // tahap, lalu nomor urut aslinya.
            $terurut->orderBy('kategori')->orderBy('seksi')->orderBy('urutan');
        } else {
            $this->applySort($terurut, $this->kolomSort());
        }

        $unit = $terurut->get();

        $semuaKategori = MatrixUnit::query()
            ->where('matrix_import_id', $import->id)
            ->kategori($this->kategoriDipakai());

        return [
            'import' => $import,
            'unit' => $unit,
            'jumlahTahap' => $jumlahTahap,
            'totalSemua' => (clone $tanpaTahap)->count(),
            'daftarBlok' => (clone $semuaKategori)->whereNotNull('blok')->distinct()
                ->orderBy('blok')->pluck('blok')->all(),
            'daftarTipe' => (clone $semuaKategori)->whereNotNull('tipe')->distinct()
                ->orderBy('tipe')->pluck('tipe')->all(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- ===== HEADER ===== --}}
        <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-zinc-500">{{ __('Monitoring Akad') }}</p>
                <flux:heading size="xl" class="mt-1">{{ $this->judul() }}</flux:heading>
                <flux:subheading>{{ $this->keterangan() }}</flux:subheading>
            </div>

            @if ($import)
                <div class="text-xs text-zinc-500 sm:text-right">
                    <div class="font-semibold text-zinc-700 dark:text-zinc-300">
                        {{ $import->proyek ?: '—' }} · {{ $import->minggu_ke ?: '—' }}
                    </div>
                    <div>{{ $import->periode }}</div>
                    <div>{{ __('Sumber:') }} {{ $import->nama_file }}</div>
                    {{-- Umur data perlu terlihat: Matrix disusun mingguan, dan angka
                         yang sudah lewat seminggu gampang disangka masih terkini. --}}
                    <div class="mt-1 text-zinc-400">
                        {{ __('Data per') }}
                        {{ $import->created_at->translatedFormat('d M Y, H:i') }}
                        · {{ __('unggahan terakhir') }}
                        ({{ $import->created_at->diffForHumans() }})
                    </div>
                </div>
            @endif
        </div>

        {{-- ===== TAB ===== --}}
        <div class="mb-5 inline-flex rounded-full border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach (['mikro' => __('Mikro'), 'makro' => __('Makro'), 'non-lot' => __('Non Lot'), 'akad' => __('Akad')] as $kode => $label)
                <button type="button" wire:click="pilihTab('{{ $kode }}')"
                        @class([
                            'rounded-full px-6 py-2 text-xs font-semibold transition-colors',
                            'bg-violet-700 text-white shadow-sm' => $tab === $kode,
                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $tab !== $kode,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if (! $import)
            <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-14 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.document-arrow-up class="mx-auto size-10 text-zinc-300" />
                <p class="mt-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    {{ __('Belum ada berkas Matrix yang diunggah.') }}
                </p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Laporan ini terisi setelah berkas Matrix diunggah.') }}</p>
                @can('matrix.kelola')
                    <flux:button class="mt-4" variant="primary" icon="arrow-up-tray"
                                 :href="route('matrix.upload')" wire:navigate>
                        {{ __('Unggah Matrix') }}
                    </flux:button>
                @endcan
            </div>
        @else
            {{-- ===== KARTU TAHAP (klik untuk menyaring) ===== --}}
            @php
                $warnaKartu = [
                    'SUDAH AKAD' => 'text-violet-600 dark:text-violet-400',
                    'ACC SP3K' => 'text-emerald-600 dark:text-emerald-400',
                    'CASH' => 'text-emerald-600 dark:text-emerald-400',
                    'WAWANCARA' => 'text-blue-600 dark:text-blue-400',
                    'BERKAS BELUM' => 'text-amber-600 dark:text-amber-400',
                    'TOLAK BANK' => 'text-rose-600 dark:text-rose-400',
                    'BATAL' => 'text-rose-600 dark:text-rose-400',
                    'STOCK' => 'text-zinc-600 dark:text-zinc-400',
                ];
                $tahapTampil = collect(App\Models\Matrix\MatrixUnit::URUTAN_SEKSI)
                    ->filter(fn ($s) => $jumlahTahap->has($s))
                    ->values();
            @endphp

            {{-- Lebar kartu dibiarkan menyesuaikan jumlah tahap yang benar-benar ada
                 di data — tiap unggahan Matrix belum tentu memakai tahap yang sama. --}}
            <div class="mb-4 grid grid-cols-2 gap-2.5 sm:grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))]">
                <button type="button" wire:click="$set('seksi', '')"
                        @class([
                            'rounded-2xl border bg-white p-4 text-left transition-all dark:bg-zinc-900',
                            'border-violet-500 ring-2 ring-violet-500/30' => $seksi === '',
                            'border-zinc-200 hover:border-violet-300 dark:border-zinc-700' => $seksi !== '',
                        ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Jumlah') }}</span>
                        @if ($seksi === '')
                            <flux:icon.check-circle class="size-4 text-violet-600" />
                        @endif
                    </div>
                    <div class="mt-1 font-mono text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">
                        {{ number_format($totalSemua, 0, ',', '.') }}
                    </div>
                    <div class="mt-0.5 text-[10px] text-zinc-500">
                        {{ $seksi === '' ? __('semua tahap') : __('klik untuk semua') }}
                    </div>
                </button>

                @foreach ($tahapTampil as $namaTahap)
                    @php $aktif = $seksi === $namaTahap; @endphp
                    <button type="button" wire:click="pakaiSeksi('{{ $namaTahap }}')"
                            @class([
                                'rounded-2xl border bg-white p-3.5 text-left transition-all dark:bg-zinc-900',
                                'border-violet-500 ring-2 ring-violet-500/30' => $aktif,
                                'border-zinc-200 hover:border-violet-300 dark:border-zinc-700' => ! $aktif,
                            ])>
                        <div class="flex items-start justify-between gap-1.5">
                            <span class="text-[10px] font-bold uppercase leading-tight tracking-wider text-zinc-500">
                                {{ $namaTahap }}
                            </span>
                            @if ($aktif)
                                <flux:icon.check-circle class="size-4 shrink-0 text-violet-600" />
                            @endif
                        </div>
                        <div class="mt-1 font-mono text-2xl font-bold tabular-nums {{ $warnaKartu[$namaTahap] ?? 'text-zinc-700' }}">
                            {{ number_format($jumlahTahap[$namaTahap] ?? 0, 0, ',', '.') }}
                        </div>
                    </button>
                @endforeach
            </div>

            {{-- ===== FILTER ===== --}}
            <div class="mb-4 flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                                    :placeholder="__('Cari konsumen, unit, marketing…')" />
                    </div>
                    @if ($this->adaFilter())
                        <flux:button variant="subtle" icon="x-mark" wire:click="bersihkan">
                            {{ __('Bersihkan') }}
                        </flux:button>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    @if ($this->pakaiFilterPersen())
                        <flux:input type="number" min="0" max="100" wire:model.live.debounce.500ms="progMin"
                                    :label="__('Prog min %')" placeholder="0" />
                        <flux:input type="number" min="0" max="100" wire:model.live.debounce.500ms="progMax"
                                    :label="__('Prog maks %')" placeholder="100" />
                    @endif

                    <flux:select wire:model.live="blok" :label="__('Blok')">
                        <flux:select.option value="">{{ __('Semua') }}</flux:select.option>
                        @foreach ($daftarBlok as $b)
                            <flux:select.option :value="$b">{{ $b }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="tipe" :label="__('Tipe')">
                        <flux:select.option value="">{{ __('Semua') }}</flux:select.option>
                        @foreach ($daftarTipe as $t)
                            <flux:select.option :value="$t">{{ $t }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="seksi" :label="__('Tahap')">
                        <flux:select.option value="">{{ __('Semua tahap') }}</flux:select.option>
                        @foreach ($jumlahTahap->keys()->filter() as $s)
                            <flux:select.option :value="$s">{{ $s }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($this->pakaiFilterPersen())
                    <p class="text-[11px] text-zinc-500">
                        {{ __('Bawaan') }} <b>{{ __('Mikro') }} 80–100%</b> ·
                        <b>{{ __('Makro') }} 0–70%</b>. {{ __('Kosongkan = semua unit.') }}
                    </p>
                @endif
            </div>

            {{-- ===== DETAIL UNIT ===== --}}
            <div class="mb-3">
                <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Detail Unit') }}</h2>
                <p class="text-xs text-zinc-500">
                    {{ number_format($unit->count(), 0, ',', '.') }} {{ __('unit ditampilkan') }}
                    @if ($unit->count() !== $totalSemua)
                        {{ __('dari') }} {{ number_format($totalSemua, 0, ',', '.') }}
                    @endif
                </p>
            </div>

            <x-matrix-tabel :unit="$unit" :non-lot="$tab === 'non-lot'" :sort-by="$sortBy" :sort-dir="$sortDir" />
        @endif
    </div>
</section>
