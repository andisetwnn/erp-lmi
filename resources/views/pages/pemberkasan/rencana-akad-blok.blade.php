<?php

use App\Models\Master\Bank;
use App\Models\Master\Notaris;
use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkadUnit;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Rencana Akad — Berdasarkan Blok')] class extends Component
{
    // Filter atas — sengaja bulan (Y-m), bukan tanggal bebas, supaya cocok
    // dengan lembar Aju Dana yang selalu per bulan.
    #[Url(as: 'bulan')]
    public string $bulan = '';

    #[Url(as: 'proyek')]
    public ?int $proyekId = null;

    #[Url(as: 'blok')]
    public string $blok = '';

    #[Url(as: 'bank')]
    public ?int $bankId = null;

    #[Url(as: 'notaris')]
    public ?int $notarisId = null;

    #[Url(as: 'q')]
    public string $cari = '';

    public function mount(): void
    {
        if ($this->bulan === '') {
            $this->bulan = now()->format('Y-m');
        }
        $this->proyekId ??= Proyek::query()->value('id');
    }

    /** Daftar blok yang tersedia di proyek — untuk dropdown filter. */
    public function getBlokTersediaProperty(): Collection
    {
        if (! $this->proyekId) {
            return collect();
        }

        return Rumah::query()
            ->where('proyek_id', $this->proyekId)
            ->select('blok')
            ->distinct()
            ->orderBy('blok')
            ->pluck('blok')
            ->filter()
            ->values();
    }

    /**
     * Baris tabel: 1 baris = 1 SPR yang unitnya di proyek+bulan+blok terpilih.
     * Diambil dari SPR (bukan RencanaAkadUnit) supaya SPR yang belum dijadwalkan
     * tetap muncul — persis konsep Excel legacy yang jadi acuan.
     */
    public function getBarisProperty(): Collection
    {
        if (! $this->proyekId || $this->bulan === '') {
            return collect();
        }

        [$tahun, $bulan] = array_pad(explode('-', $this->bulan), 2, null);
        if (! $tahun || ! $bulan) {
            return collect();
        }

        $sprQuery = Spr::query()
            ->with([
                'rumah.tipeRumah',
                'rumah.proyek',
                'prospectCustomer',
                'sales',
                'bankKpr',
                'terminPembayaran',
                'realisasiPembayaran',
                'rencanaAkadUnit.rencanaAkad.notaris',
                'rencanaAkadUnit.rencanaAkad.bank',
            ])
            ->whereHas('rumah', fn ($q) => $q->where('proyek_id', $this->proyekId))
            ->whereIn('status', ['approved', 'akad']);

        if ($this->blok !== '') {
            $sprQuery->whereHas('rumah', fn ($q) => $q->where('blok', $this->blok));
        }

        // Filter berdasarkan bulan rencana akad yang terkait — bukan tanggal SPR.
        // SPR tanpa rencana ikut ditampilkan supaya kelihatan mana yang belum dijadwalkan.
        $sprQuery->where(function ($q) use ($tahun, $bulan) {
            $q->whereHas('rencanaAkadUnit.rencanaAkad', function ($r) use ($tahun, $bulan) {
                $r->whereYear('tanggal_rencana', $tahun)->whereMonth('tanggal_rencana', $bulan)
                    ->orWhere(function ($rf) use ($tahun, $bulan) {
                        $rf->whereYear('tanggal_fix', $tahun)->whereMonth('tanggal_fix', $bulan);
                    });
            });
        });

        if ($this->bankId) {
            $sprQuery->whereHas('rencanaAkadUnit.rencanaAkad', fn ($r) => $r->where('bank_id', $this->bankId));
        }

        if ($this->notarisId) {
            $sprQuery->whereHas('rencanaAkadUnit.rencanaAkad', fn ($r) => $r->where('notaris_id', $this->notarisId));
        }

        if ($this->cari !== '') {
            $s = trim($this->cari);
            $sprQuery->where(function ($q) use ($s) {
                $q->where('nomor_spr', 'like', "%{$s}%")
                    ->orWhereHas('rumah', fn ($r) => $r->where('blok', 'like', "%{$s}%")->orWhere('nomor_unit', 'like', "%{$s}%"))
                    ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%"));
            });
        }

        return $sprQuery->get()->map(fn ($spr) => $this->bentukBaris($spr))
            ->sortBy(['blok', 'lot'])
            ->values();
    }

    /** Ringkas SPR + relasinya jadi 1 baris siap tampil. */
    private function bentukBaris(Spr $spr): array
    {
        $unit = $spr->rencanaAkadUnit; // hasOne — RencanaAkadUnit yang aktif
        $rumah = $spr->rumah;
        $tipe = $rumah?->tipeRumah;
        $prospect = $spr->prospectCustomer;

        // Realisasi UM (tanpa BF, tanpa SBUM). BF/SBUM terpisah kolomnya.
        $setoranUm = (float) $spr->realisasiPembayaran->where('jenis', 'um')->sum('jumlah');

        // UM seharusnya = harga jual bersih - nilai KPR (kewajiban riil konsumen)
        $umSeharusnya = max(0, (float) $spr->total_harga - (float) $spr->nilai_kpr);

        $kurangUm = max(0, $umSeharusnya - $setoranUm - (float) ($unit->promo_um ?? 0));
        $lebihUm = max(0, $setoranUm + (float) ($unit->promo_um ?? 0) - $umSeharusnya);

        return [
            'spr_id' => $spr->id,
            'unit_id' => $unit?->id,
            'gol' => strtoupper($unit?->jenis_akad ?? '-'),
            'nomor_spr' => $spr->nomor_display,
            'nomor_rencana' => $unit?->rencanaAkad?->nomor,
            'blok' => $rumah?->blok,
            'unit' => $rumah ? trim(($rumah->blok ?? '').'-'.($rumah->nomor_unit ?? '')) : null,
            'lot' => $rumah?->lot,
            'proyek' => $rumah?->proyek?->kode_surat ?? $rumah?->proyek?->nama_proyek,
            'persen_rmh' => (int) ($rumah?->progres_fisik ?? 0),
            'hgb' => $unit?->hgb,
            'tipe' => $tipe?->nama_tipe,
            'nama' => $prospect?->nama_lengkap,
            'tlp' => $prospect?->hp,
            'npwp' => $prospect?->npwp,
            'harga_jual' => (float) $spr->harga_jual,
            'diskon' => (float) $spr->diskon,
            'ppn' => (float) $spr->ppn,
            'harga_net' => (float) $spr->total_harga,
            'acc_kpr' => (float) $spr->nilai_kpr,
            'bp2bt' => (float) ($unit->bp2bt ?? 0),
            'um_seharusnya' => $umSeharusnya,
            'setoran_um' => $setoranUm,
            'setoran_ajb' => (float) ($unit->setoran_ajb ?? 0),
            'setoran_bphtb' => (float) ($unit->setoran_bphtb ?? 0),
            'promo_um' => (float) ($unit->promo_um ?? 0),
            'promo_ajb' => (float) ($unit->promo_ajb ?? 0),
            'promo_bphtb' => (float) ($unit->promo_bphtb ?? 0),
            'kurang_um' => $kurangUm,
            'lebih_um' => $lebihUm,
            'bayar_ps4a2' => (float) ($unit->bayar_ps4a2 ?? 0),
            'bayar_bi_notaris' => (float) ($unit->bayar_bi_notaris ?? 0),
            'bayar_bphtb' => (float) ($unit->bayar_bphtb ?? 0),
            'bayar_ppjb' => (float) ($unit->nominal_ppjb ?? 0),
            'marketing' => $spr->sales?->nama,
            'notaris' => $unit?->rencanaAkad?->notaris?->nama,
            'bank' => $unit?->rencanaAkad?->bank?->nama,
            'status_rencana' => $unit?->rencanaAkad?->status,
            'tanggal_ppjb' => $unit?->tanggal_ppjb,
            'tanggal_ajb' => $unit?->tanggal_ajb,
            'ntpn' => $unit?->ntpn,
            'rencana_akad_id' => $unit?->rencana_akad_id,
        ];
    }

    /**
     * Stats untuk kartu di atas tabel.
     *
     * @return array{jumlah_rumah:int, sudah_akad:int, belum_akad:int}
     */
    public function getStatsProperty(): array
    {
        $baris = $this->baris;
        $jumlah = $baris->count();
        $sudah = $baris->filter(fn ($b) => $b['tanggal_ajb'] !== null || $b['tanggal_ppjb'] !== null)->count();

        return [
            'jumlah_rumah' => $jumlah,
            'sudah_akad' => $sudah,
            'belum_akad' => $jumlah - $sudah,
        ];
    }

    public function pindahKeNomor(): void
    {
        $this->redirect(route('pemberkasan.rencana-akad.index', [
            'bulan' => $this->bulan,
            'proyek' => $this->proyekId,
            'bank' => $this->bankId,
            'notaris' => $this->notarisId,
        ]), navigate: true);
    }

    public function with(): array
    {
        return [
            'daftarProyek' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek', 'kode_surat']),
            'daftarBank' => Bank::orderBy('nama')->get(['id', 'nama']),
            'daftarNotaris' => Notaris::orderBy('nama')->get(['id', 'nama']),
            'blokTersedia' => $this->blokTersedia,
            'baris' => $this->baris,
            'stats' => $this->stats,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                    <flux:icon.building-library class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Rencana Akad — Berdasarkan Blok') }}</flux:heading>
                    <flux:subheading>{{ __('Rincian unit per blok dalam satu bulan akad.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" icon="arrow-left" wire:click="pindahKeNomor">
                    {{ __('Berdasarkan Nomor') }}
                </flux:button>
            </div>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3 lg:grid-cols-6">
                <flux:input type="month" wire:model.live="bulan" label="Bulan" />

                <flux:select wire:model.live="proyekId" label="Proyek">
                    @foreach ($daftarProyek as $p)
                        <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="blok" label="Blok">
                    <flux:select.option value="">Semua Blok</flux:select.option>
                    @foreach ($blokTersedia as $b)
                        <flux:select.option value="{{ $b }}">{{ $b }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="bankId" label="Bank">
                    <flux:select.option value="">Semua Bank</flux:select.option>
                    @foreach ($daftarBank as $b)
                        <flux:select.option value="{{ $b->id }}">{{ $b->nama }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="notarisId" label="Notaris">
                    <flux:select.option value="">Semua Notaris</flux:select.option>
                    @foreach ($daftarNotaris as $n)
                        <flux:select.option value="{{ $n->id }}">{{ $n->nama }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model.live.debounce.400ms="cari" label="Cari" icon="magnifying-glass"
                            placeholder="SPR / nama / NIK..." />
            </div>
        </div>

        {{-- STATS CARD --}}
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <flux:icon.home class="size-5" />
                </div>
                <div>
                    <div class="text-xs text-zinc-500">Jml Rumah</div>
                    <div class="text-xl font-bold tabular-nums">{{ number_format($stats['jumlah_rumah']) }}</div>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-emerald-600 text-white">
                    <flux:icon.check class="size-5" />
                </div>
                <div>
                    <div class="text-xs text-emerald-700 dark:text-emerald-300">Sudah Akad</div>
                    <div class="text-xl font-bold tabular-nums text-emerald-900 dark:text-emerald-100">
                        {{ number_format($stats['sudah_akad']) }}
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-amber-500 text-white">
                    <flux:icon.exclamation-triangle class="size-5" />
                </div>
                <div>
                    <div class="text-xs text-amber-700 dark:text-amber-300">Belum Akad</div>
                    <div class="text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100">
                        {{ number_format($stats['belum_akad']) }}
                    </div>
                </div>
            </div>
        </div>

        {{-- TABEL DETAIL --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="min-w-[2400px] w-full text-xs">
                    {{--
                        Setiap group punya warna background sendiri di baris data,
                        supaya mata langsung tahu kolom mana masuk SETORAN, PROMO,
                        KURANG/LEBIH BAYAR, atau PEMBAYARAN. Tanpa ini, group header
                        cuma menempel di atas tanpa penunjuk visual ke kolomnya.
                    --}}
                    <thead>
                        {{-- Baris group header --}}
                        <tr class="text-[10px] font-bold uppercase tracking-wider">
                            <th class="border-b border-zinc-200 bg-zinc-50 px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-800"></th>
                            <th colspan="9" class="border-b-2 border-l border-zinc-300 bg-zinc-100 px-2 py-1.5 text-center text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">INFO UNIT</th>
                            <th colspan="2" class="border-b-2 border-l border-indigo-300 bg-indigo-100 px-2 py-1.5 text-center text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200">KONSUMEN</th>
                            <th colspan="2" class="border-b-2 border-l border-emerald-300 bg-emerald-100 px-2 py-1.5 text-center text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">HARGA</th>
                            <th colspan="2" class="border-b-2 border-l border-blue-300 bg-blue-100 px-2 py-1.5 text-center text-blue-800 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-200">SETORAN</th>
                            <th colspan="3" class="border-b-2 border-l border-violet-300 bg-violet-100 px-2 py-1.5 text-center text-violet-800 dark:border-violet-800 dark:bg-violet-950/50 dark:text-violet-200">PROMO</th>
                            <th colspan="3" class="border-b-2 border-l border-amber-300 bg-amber-100 px-2 py-1.5 text-center dark:border-amber-800 dark:bg-amber-950/50">
                                <span class="text-rose-700 dark:text-rose-300">KURANG</span>/<span class="text-emerald-700 dark:text-emerald-300">LEBIH</span> <span class="text-amber-800 dark:text-amber-200">BAYAR</span>
                            </th>
                            <th colspan="4" class="border-b-2 border-l border-orange-300 bg-orange-100 px-2 py-1.5 text-center text-orange-800 dark:border-orange-800 dark:bg-orange-950/50 dark:text-orange-200">PEMBAYARAN</th>
                            <th colspan="4" class="border-b-2 border-l border-slate-300 bg-slate-100 px-2 py-1.5 text-center text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">PARA PIHAK &amp; STATUS</th>
                            <th colspan="3" class="border-b-2 border-l border-zinc-300 bg-zinc-100 px-2 py-1.5 text-center text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">AKAD</th>
                        </tr>
                        <tr class="border-b border-zinc-300 bg-zinc-50 text-left font-semibold text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                            <th class="whitespace-nowrap px-2 py-2">#</th>
                            {{-- INFO UNIT --}}
                            <th class="whitespace-nowrap border-l border-zinc-300 px-2 py-2 dark:border-zinc-600">Gol</th>
                            <th class="whitespace-nowrap px-2 py-2">SPR</th>
                            <th class="whitespace-nowrap px-2 py-2">No Rencana</th>
                            <th class="whitespace-nowrap px-2 py-2">Unit</th>
                            <th class="whitespace-nowrap px-2 py-2">Proyek</th>
                            <th class="whitespace-nowrap px-2 py-2">Lot</th>
                            <th class="whitespace-nowrap px-2 py-2">%Rmh</th>
                            <th class="whitespace-nowrap px-2 py-2">HGB</th>
                            <th class="whitespace-nowrap px-2 py-2">Tipe</th>
                            {{-- KONSUMEN --}}
                            <th class="whitespace-nowrap border-l border-indigo-200 bg-indigo-50 px-2 py-2 text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950/30 dark:text-indigo-200">Nama</th>
                            <th class="whitespace-nowrap bg-indigo-50 px-2 py-2 text-indigo-800 dark:bg-indigo-950/30 dark:text-indigo-200">Tlp</th>
                            {{-- HARGA --}}
                            <th class="whitespace-nowrap border-l border-emerald-200 bg-emerald-50 px-2 py-2 text-right text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">Harga Jual</th>
                            <th class="whitespace-nowrap bg-emerald-50 px-2 py-2 text-right text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">Acc KPR</th>
                            {{-- SETORAN --}}
                            <th class="whitespace-nowrap border-l border-blue-200 bg-blue-50 px-2 py-2 text-right text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-200">UM</th>
                            <th class="whitespace-nowrap bg-blue-50 px-2 py-2 text-right text-blue-800 dark:bg-blue-950/30 dark:text-blue-200">AJB</th>
                            {{-- PROMO --}}
                            <th class="whitespace-nowrap border-l border-violet-200 bg-violet-50 px-2 py-2 text-right text-violet-800 dark:border-violet-800 dark:bg-violet-950/30 dark:text-violet-200">UM</th>
                            <th class="whitespace-nowrap bg-violet-50 px-2 py-2 text-right text-violet-800 dark:bg-violet-950/30 dark:text-violet-200">AJB</th>
                            <th class="whitespace-nowrap bg-violet-50 px-2 py-2 text-right text-violet-800 dark:bg-violet-950/30 dark:text-violet-200">BPHTB</th>
                            {{-- KURANG/LEBIH BAYAR --}}
                            <th class="whitespace-nowrap border-l border-amber-200 bg-amber-50 px-2 py-2 text-right text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">UM</th>
                            <th class="whitespace-nowrap bg-amber-50 px-2 py-2 text-right text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">AJB</th>
                            <th class="whitespace-nowrap bg-amber-50 px-2 py-2 text-right text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">BPHTB</th>
                            {{-- PEMBAYARAN --}}
                            <th class="whitespace-nowrap border-l border-orange-200 bg-orange-50 px-2 py-2 text-right text-orange-800 dark:border-orange-800 dark:bg-orange-950/30 dark:text-orange-200">PS4a2</th>
                            <th class="whitespace-nowrap bg-orange-50 px-2 py-2 text-right text-orange-800 dark:bg-orange-950/30 dark:text-orange-200">BI Not.</th>
                            <th class="whitespace-nowrap bg-orange-50 px-2 py-2 text-right text-orange-800 dark:bg-orange-950/30 dark:text-orange-200">BPHTB</th>
                            <th class="whitespace-nowrap bg-orange-50 px-2 py-2 text-right text-orange-800 dark:bg-orange-950/30 dark:text-orange-200">PPJB</th>
                            {{-- PARA PIHAK & STATUS --}}
                            <th class="whitespace-nowrap border-l border-slate-200 bg-slate-50 px-2 py-2 text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200">Marketing</th>
                            <th class="whitespace-nowrap bg-slate-50 px-2 py-2 text-slate-700 dark:bg-slate-900/40 dark:text-slate-200">NPWP</th>
                            <th class="whitespace-nowrap bg-slate-50 px-2 py-2 text-slate-700 dark:bg-slate-900/40 dark:text-slate-200">Notaris</th>
                            <th class="whitespace-nowrap bg-slate-50 px-2 py-2 text-slate-700 dark:bg-slate-900/40 dark:text-slate-200">Status</th>
                            {{-- AKAD — hanya tanggal & bukti akad --}}
                            <th class="whitespace-nowrap border-l border-zinc-300 px-2 py-2 dark:border-zinc-600">PPJB</th>
                            <th class="whitespace-nowrap px-2 py-2">AJB</th>
                            <th class="whitespace-nowrap px-2 py-2">NTPN</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($baris as $i => $b)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-2 py-2 text-center">{{ $i + 1 }}</td>
                                {{-- INFO UNIT --}}
                                <td class="border-l border-zinc-200 px-2 py-2 font-mono text-[11px] dark:border-zinc-700">{{ $b['gol'] }}</td>
                                <td class="px-2 py-2 font-mono text-[11px]">{{ $b['nomor_spr'] }}</td>
                                <td class="whitespace-nowrap px-2 py-2 font-mono text-[11px] text-zinc-600 dark:text-zinc-300">
                                    @if ($b['nomor_rencana'])
                                        <a href="{{ route('pemberkasan.rencana-akad.show', $b['rencana_akad_id']) }}"
                                           class="text-indigo-700 hover:underline dark:text-indigo-300"
                                           wire:navigate>{{ $b['nomor_rencana'] }}</a>
                                    @else
                                        <span class="text-zinc-300">—</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2">
                                    <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800">
                                        {{ $b['unit'] ?: '—' }}
                                    </span>
                                </td>
                                <td class="px-2 py-2">{{ $b['proyek'] }}</td>
                                <td class="px-2 py-2 text-center">{{ $b['lot'] ?? '—' }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $b['persen_rmh'] }}</td>
                                <td class="px-2 py-2 font-mono text-[11px]">{{ $b['hgb'] ?? '—' }}</td>
                                <td class="px-2 py-2">{{ $b['tipe'] ?? '—' }}</td>
                                {{-- KONSUMEN --}}
                                <td class="border-l border-indigo-100 bg-indigo-50/40 px-2 py-2 dark:border-indigo-900 dark:bg-indigo-950/20">{{ $b['nama'] ?? '—' }}</td>
                                <td class="bg-indigo-50/40 px-2 py-2 font-mono text-[11px] dark:bg-indigo-950/20">{{ $b['tlp'] ?? '—' }}</td>
                                {{-- HARGA --}}
                                <td class="border-l border-emerald-100 bg-emerald-50/40 px-2 py-2 text-right font-mono tabular-nums text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300">{{ number_format($b['harga_jual']) }}</td>
                                <td class="bg-emerald-50/40 px-2 py-2 text-right font-mono tabular-nums text-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300">{{ number_format($b['acc_kpr']) }}</td>
                                {{-- SETORAN --}}
                                <td class="border-l border-blue-100 bg-blue-50/40 px-2 py-2 text-right font-mono tabular-nums text-blue-800 dark:border-blue-900 dark:bg-blue-950/20 dark:text-blue-300">{{ $b['setoran_um'] > 0 ? number_format($b['setoran_um']) : '—' }}</td>
                                <td class="bg-blue-50/40 px-2 py-2 text-right font-mono tabular-nums text-blue-800 dark:bg-blue-950/20 dark:text-blue-300">{{ $b['setoran_ajb'] > 0 ? number_format($b['setoran_ajb']) : '—' }}</td>
                                {{-- PROMO --}}
                                <td class="border-l border-violet-100 bg-violet-50/40 px-2 py-2 text-right font-mono tabular-nums text-violet-800 dark:border-violet-900 dark:bg-violet-950/20 dark:text-violet-300">{{ $b['promo_um'] > 0 ? number_format($b['promo_um']) : '—' }}</td>
                                <td class="bg-violet-50/40 px-2 py-2 text-right font-mono tabular-nums text-violet-800 dark:bg-violet-950/20 dark:text-violet-300">{{ $b['promo_ajb'] > 0 ? number_format($b['promo_ajb']) : '—' }}</td>
                                <td class="bg-violet-50/40 px-2 py-2 text-right font-mono tabular-nums text-violet-800 dark:bg-violet-950/20 dark:text-violet-300">{{ $b['promo_bphtb'] > 0 ? number_format($b['promo_bphtb']) : '—' }}</td>
                                {{-- KURANG/LEBIH BAYAR --}}
                                <td class="border-l border-amber-100 bg-amber-50/40 px-2 py-2 text-right font-mono tabular-nums dark:border-amber-900 dark:bg-amber-950/20">
                                    @if ($b['kurang_um'] > 0)
                                        <span class="text-rose-600 font-semibold">{{ number_format($b['kurang_um']) }}</span>
                                    @elseif ($b['lebih_um'] > 0)
                                        <span class="text-emerald-600 font-semibold">{{ number_format($b['lebih_um']) }}</span>
                                    @else
                                        <span class="text-zinc-400">0</span>
                                    @endif
                                </td>
                                <td class="bg-amber-50/40 px-2 py-2 text-right font-mono tabular-nums text-zinc-400 dark:bg-amber-950/20">0</td>
                                <td class="bg-amber-50/40 px-2 py-2 text-right font-mono tabular-nums text-zinc-400 dark:bg-amber-950/20">0</td>
                                {{-- PEMBAYARAN --}}
                                <td class="border-l border-orange-100 bg-orange-50/40 px-2 py-2 text-right font-mono tabular-nums text-orange-800 dark:border-orange-900 dark:bg-orange-950/20 dark:text-orange-300">{{ $b['bayar_ps4a2'] > 0 ? number_format($b['bayar_ps4a2']) : '—' }}</td>
                                <td class="bg-orange-50/40 px-2 py-2 text-right font-mono tabular-nums text-orange-800 dark:bg-orange-950/20 dark:text-orange-300">{{ $b['bayar_bi_notaris'] > 0 ? number_format($b['bayar_bi_notaris']) : '—' }}</td>
                                <td class="bg-orange-50/40 px-2 py-2 text-right font-mono tabular-nums text-orange-800 dark:bg-orange-950/20 dark:text-orange-300">{{ $b['bayar_bphtb'] > 0 ? number_format($b['bayar_bphtb']) : '—' }}</td>
                                <td class="bg-orange-50/40 px-2 py-2 text-right font-mono tabular-nums text-orange-800 dark:bg-orange-950/20 dark:text-orange-300">{{ $b['bayar_ppjb'] > 0 ? number_format($b['bayar_ppjb']) : '—' }}</td>
                                {{-- PARA PIHAK & STATUS --}}
                                <td class="border-l border-slate-200 bg-slate-50/40 px-2 py-2 dark:border-slate-700 dark:bg-slate-900/20">{{ $b['marketing'] ?? '—' }}</td>
                                <td class="bg-slate-50/40 px-2 py-2 font-mono text-[11px] dark:bg-slate-900/20">{{ $b['npwp'] ?? '—' }}</td>
                                <td class="bg-slate-50/40 px-2 py-2 dark:bg-slate-900/20">{{ $b['notaris'] ?? '—' }}</td>
                                <td class="bg-slate-50/40 px-2 py-2 dark:bg-slate-900/20">
                                    @if ($b['status_rencana'] === 'fix')
                                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800">Selesai</span>
                                    @elseif ($b['status_rencana'])
                                        <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[11px] font-semibold text-blue-800">{{ ucfirst($b['status_rencana']) }}</span>
                                    @else
                                        <span class="text-zinc-400 text-[11px]">—</span>
                                    @endif
                                </td>
                                {{-- AKAD (tanggal PPJB/AJB + NTPN) --}}
                                <td class="border-l border-zinc-200 px-2 py-2 whitespace-nowrap dark:border-zinc-700">{{ $b['tanggal_ppjb']?->translatedFormat('d/m/Y') ?? '—' }}</td>
                                <td class="px-2 py-2 whitespace-nowrap">{{ $b['tanggal_ajb']?->translatedFormat('d/m/Y') ?? '—' }}</td>
                                <td class="px-2 py-2 font-mono text-[11px]">{{ $b['ntpn'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="32" class="px-4 py-12 text-center text-zinc-400">
                                    Belum ada unit di saringan ini. Coba ganti bulan atau blok.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3 text-xs text-zinc-500">
            <p><strong>Catatan:</strong> Kolom yang bernilai 0 ditampilkan sebagai <span class="font-mono">—</span> supaya angka
            yang benar-benar terpakai lebih menonjol. Kolom <span class="font-mono">Kurang/Lebih Bayar</span> AJB dan BPHTB
            selalu 0 untuk sekarang — bakal ke-isi otomatis setelah data setoran AJB/BPHTB terkumpul di sistem.</p>
        </div>
    </div>
</section>
