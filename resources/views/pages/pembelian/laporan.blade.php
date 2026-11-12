<?php

use App\Exports\LaporanPembelianExport;
use App\Models\Master\PermintaanPembelian;
use App\Models\Master\PesananPembelian;
use App\Models\Master\Proyek;
use App\Models\Master\Supplier;
use App\Services\LaporanPembelianService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Laporan Pembelian')] class extends Component
{
    /** Izin yang boleh membuka laporan — sama dengan pintu masuk di routes. */
    public const IZIN = ['pembelian.review', 'pembelian.anggaran', 'pembelian.po', 'pembelian.bayar', 'pembelian.lihat'];

    /** realisasi / pembayaran / lead-time */
    #[Url(as: 'tab')]
    public string $tab = 'realisasi';

    /** '' semua, 'pusat' kantor pusat, atau id proyek. */
    #[Url]
    public string $proyek = '';

    #[Url]
    public string $dari = '';

    #[Url]
    public string $sampai = '';

    #[Url(as: 'bagian')]
    public string $departemen = '';

    #[Url]
    public string $supplier = '';

    /** Tab pembayaran: belum / sebagian / lunas. */
    #[Url(as: 'bayar')]
    public string $statusBayar = 'belum';

    /** Tab lead time: '' atau kunci LaporanPembelianService::LABEL_TAHAP. */
    #[Url]
    public string $tahap = '';

    #[Url(as: 'q')]
    public string $cari = '';

    /** Tab lead time: hanya barang dengan lead time minimal sekian hari. */
    #[Url(as: 'min')]
    public string $leadTimeMin = '';

    public bool $grafikTerbuka = false;

    public int $tahunGrafik = 0;

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(self::IZIN), 403);

        $this->dari = $this->tanggalSah($this->dari) ?? CarbonImmutable::today()->startOfMonth()->toDateString();
        $this->sampai = $this->tanggalSah($this->sampai) ?? CarbonImmutable::today()->toDateString();
        $this->tahunGrafik = CarbonImmutable::parse($this->sampai)->year;
    }

    public function bukaGrafik(): void
    {
        $this->grafikTerbuka = true;
        Flux::modal('grafik-tahunan')->show();
    }

    public function tutupGrafik(): void
    {
        $this->grafikTerbuka = false;
    }

    public function unduhExcel(): BinaryFileResponse
    {
        abort_unless(Auth::user()->canAny(self::IZIN), 403);

        ['judul' => $judul, 'kolom' => $kolom, 'baris' => $baris] = app(LaporanPembelianService::class)
            ->excel($this->tab, $this->saringan(), $this->opsi());

        return Excel::download(
            new LaporanPembelianExport($judul, $kolom, $baris),
            'laporan-pembelian_'.$this->tab.'_'.now()->format('Y-m-d_His').'.xlsx',
        );
    }

    /** @return array{proyek: string, departemen: string, supplier: string, dari: string, sampai: string} */
    private function saringan(): array
    {
        $dari = $this->tanggalSah($this->dari) ?? CarbonImmutable::today()->startOfMonth()->toDateString();
        $sampai = $this->tanggalSah($this->sampai) ?? CarbonImmutable::today()->toDateString();
        if ($dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return [
            'proyek' => $this->proyek === 'pusat' || ctype_digit($this->proyek) ? $this->proyek : '',
            'departemen' => in_array($this->departemen, PermintaanPembelian::DEPARTEMEN, true) ? $this->departemen : '',
            'supplier' => ctype_digit($this->supplier) ? $this->supplier : '',
            'dari' => $dari,
            'sampai' => $sampai,
        ];
    }

    /** @return array{bayar: string, tahap: string, cari: string, min: ?int} */
    private function opsi(): array
    {
        return [
            'bayar' => array_key_exists($this->statusBayar, LaporanPembelianService::LABEL_BAYAR) ? $this->statusBayar : 'belum',
            'tahap' => array_key_exists($this->tahap, LaporanPembelianService::LABEL_TAHAP) ? $this->tahap : '',
            'cari' => $this->cari,
            'min' => ctype_digit($this->leadTimeMin) ? (int) $this->leadTimeMin : null,
        ];
    }

    private function tanggalSah(string $nilai): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) && strtotime($nilai) !== false ? $nilai : null;
    }

    public function with(): array
    {
        $laporan = app(LaporanPembelianService::class);
        $saring = $this->saringan();
        $opsi = $this->opsi();
        $proyekList = Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']);
        $tahunAwal = PesananPembelian::min('tanggal');

        return [
            'saring' => $saring,
            'opsi' => $opsi,
            'proyekList' => $proyekList,
            'supplierList' => Supplier::orderBy('nama')->get(['id', 'nama']),
            'namaProyek' => match (true) {
                $saring['proyek'] === 'pusat' => 'Kantor pusat',
                $saring['proyek'] !== '' => $proyekList->firstWhere('id', (int) $saring['proyek'])?->nama_proyek ?? 'Semua proyek',
                default => 'Semua proyek',
            },
            'realisasi' => $this->tab === 'realisasi' ? $laporan->realisasi($saring) : collect(),
            'pembayaran' => $this->tab === 'pembayaran' ? $laporan->pembayaran($saring) : null,
            'leadTime' => $this->tab === 'lead-time' ? $laporan->leadTime($saring, $opsi) : collect(),
            'grafik' => $this->grafikTerbuka ? $laporan->grafikTahunan($this->tahunGrafik, $saring) : [],
            'pilihanTahun' => range(min((int) now()->year, $tahunAwal ? CarbonImmutable::parse($tahunAwal)->year : (int) now()->year), (int) now()->year),
        ];
    }
}; ?>

@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $tgl = fn ($t) => $t ? $t->translatedFormat('d M Y') : '—';
    $warnaBayar = ['belum' => 'rose', 'sebagian' => 'amber', 'lunas' => 'emerald', 'kelebihan' => 'rose'];
    $warnaBarang = ['belum' => 'zinc', 'sebagian' => 'amber', 'lengkap' => 'emerald', 'ditutup' => 'zinc'];
    $warnaTahap = ['persetujuan' => 'violet', 'belum-po' => 'zinc', 'menunggu' => 'amber', 'sebagian' => 'amber', 'lengkap' => 'emerald'];
    $warnaSla = ['tepat' => 'emerald', 'terlambat' => 'rose', 'proses' => 'zinc'];
    $batasLama = \App\Services\LaporanPembelianService::BATAS_LAMA_PROSES_HARI;
    $periode = \Carbon\CarbonImmutable::parse($saring['dari'])->translatedFormat('j M Y').' – '.\Carbon\CarbonImmutable::parse($saring['sampai'])->translatedFormat('j M Y');
    $tabs = ['realisasi' => 'Rekap Realisasi', 'pembayaran' => 'Status Pembayaran', 'lead-time' => 'Lead Time Pengadaan'];
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                <flux:icon.chart-bar class="size-6" />
            </div>
            <div>
                <flux:heading size="xl">{{ __('Laporan Pembelian') }}</flux:heading>
                <flux:subheading>{{ __('Realisasi PO, status pembayaran ke supplier, dan lama pengadaan barang.') }}</flux:subheading>
            </div>
            <x-tombol-panduan modul="pembelian" class="ml-auto self-start" />
        </div>

        <div class="mb-4 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-1 overflow-x-auto">
                @foreach ($tabs as $kunci => $label)
                    <button type="button" wire:click="$set('tab', '{{ $kunci }}')"
                            @class([
                                'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition',
                                'border-orange-600 text-orange-700 dark:border-orange-400 dark:text-orange-300' => $tab === $kunci,
                                'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400' => $tab !== $kunci,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Saringan --}}
        <div class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <flux:select wire:model.live="proyek" :label="__('Proyek')">
                    <flux:select.option value="">{{ __('Semua proyek') }}</flux:select.option>
                    <flux:select.option value="pusat">{{ __('Kantor pusat') }}</flux:select.option>
                    @foreach ($proyekList as $p)
                        <flux:select.option value="{{ $p->id }}">{{ $p->nama_proyek }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($tab !== 'pembayaran')
                    <flux:input type="date" wire:model.live="dari" :label="$tab === 'lead-time' ? __('Tanggal PR dari') : __('Tanggal PO dari')" />
                    <flux:input type="date" wire:model.live="sampai" :label="__('Sampai')" />
                @endif
                <flux:select wire:model.live="departemen" :label="__('Bagian')">
                    <flux:select.option value="">{{ __('Semua bagian') }}</flux:select.option>
                    @foreach (\App\Models\Master\PermintaanPembelian::DEPARTEMEN as $d)
                        <flux:select.option value="{{ $d }}">{{ $d }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="supplier" :label="__('Supplier')">
                    <flux:select.option value="">{{ __('Semua supplier') }}</flux:select.option>
                    @foreach ($supplierList as $s)
                        <flux:select.option value="{{ $s->id }}">{{ $s->nama }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($tab === 'lead-time')
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <flux:select wire:model.live="tahap" :label="__('Tahap')">
                        <flux:select.option value="">{{ __('Semua tahap') }}</flux:select.option>
                        @foreach (\App\Services\LaporanPembelianService::LABEL_TAHAP as $k => $l)
                            <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="lg:col-span-2">
                        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" :label="__('Cari')" :placeholder="__('Nomor PR, nomor PO, barang, atau supplier')" />
                    </div>
                    <flux:input type="number" min="0" wire:model.live.debounce.300ms="leadTimeMin" :label="__('Lead time minimal (hari)')" :placeholder="__('mis. 30')" />
                </div>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <p class="min-w-0 grow text-xs text-zinc-500 dark:text-zinc-400" style="flex-basis: 16rem">
                    @if ($tab === 'realisasi')
                        {{ __('Menurut tanggal PO. PO batal tidak ikut. Lama proses = hari dari PR diajukan sampai PO terbit, merah bila lebih dari :n hari.', ['n' => $batasLama]) }}
                    @elseif ($tab === 'pembayaran')
                        {{ __('Semua PO yang tidak batal, berapa pun umurnya — supaya PO lama yang belum lunas tetap terlihat.') }}
                    @else
                        {{ __('Satu baris = satu barang PR, menurut tanggal PR dibuat. Lead time = hari dari PR dibuat sampai barang lengkap; yang belum lengkap dihitung sampai hari ini.') }}
                    @endif
                </p>
                <div class="flex shrink-0 gap-2">
                    <flux:button size="sm" icon="chart-bar" wire:click="bukaGrafik">{{ __('Grafik tahunan') }}</flux:button>
                    <flux:button size="sm" variant="primary" icon="arrow-down-tray" wire:click="unduhExcel">{{ __('Unduh Excel') }}</flux:button>
                </div>
            </div>
        </div>

        {{-- ═════════════ REKAP REALISASI ═════════════ --}}
        @if ($tab === 'realisasi')
            <div class="mb-3">
                <h2 class="text-base font-bold">{{ __('Rekap Pembelian') }}</h2>
                <p class="text-xs text-zinc-500">{{ $periode }} · {{ $namaProyek }}</p>
            </div>
            <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    [__('Jumlah PO'), number_format($realisasi->count(), 0, ',', '.')],
                    [__('Nilai PO'), $rp($realisasi->sum('total'))],
                    [__('Sudah dibayar'), $rp($realisasi->sum('dibayar'))],
                    [__('Belum dibayar'), $rp($realisasi->sum('sisa'))],
                ] as [$label, $nilai])
                    <div class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">{{ $label }}</div>
                        <div class="mt-1 font-mono text-lg font-bold tabular-nums">{{ $nilai }}</div>
                    </div>
                @endforeach
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                <th class="px-3 py-3">{{ __('No') }}</th>
                                <th class="px-3 py-3">{{ __('No PO') }}</th>
                                <th class="px-3 py-3">{{ __('Proyek') }}</th>
                                <th class="px-3 py-3">{{ __('Supplier') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Jumlah') }}</th>
                                <th class="px-3 py-3 text-center">{{ __('Lama proses') }}</th>
                                <th class="px-3 py-3">{{ __('Pembayaran') }}</th>
                                <th class="px-3 py-3">{{ __('Barang') }}</th>
                                <th class="px-3 py-3">{{ __('Bagian') }}</th>
                                <th class="px-3 py-3">{{ __('Keperluan') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($realisasi as $n => $b)
                                <tr wire:key="realisasi-{{ $b['id'] }}" class="align-top hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="px-3 py-3 text-zinc-500">{{ $n + 1 }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">
                                        <a href="{{ route('pembelian.po.show', $b['id']) }}" wire:navigate class="font-mono font-semibold text-orange-700 hover:underline dark:text-orange-300">{{ $b['nomor'] }}</a>
                                        <div class="text-xs text-zinc-500">{{ $tgl($b['tanggal']) }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3">{{ $b['proyek'] }}</td>
                                    <td class="px-3 py-3">{{ $b['supplier'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums">{{ $rp($b['total']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-center">
                                        <flux:badge size="sm" :color="$b['lama_proses'] > $batasLama ? 'rose' : 'zinc'">{{ $b['lama_proses'] }} {{ __('hari') }}</flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3">
                                        <flux:badge size="sm" :color="$warnaBayar[$b['status_bayar']]">{{ \App\Services\LaporanPembelianService::LABEL_BAYAR[$b['status_bayar']] }}</flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3">
                                        <flux:badge size="sm" :color="$warnaBarang[$b['status_barang']]">{{ \App\Services\LaporanPembelianService::LABEL_BARANG[$b['status_barang']] }}</flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3">{{ $b['departemen'] }}</td>
                                    <td class="min-w-64 px-3 py-3 text-zinc-600 dark:text-zinc-400">{{ $b['keperluan'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-4 py-10 text-center text-zinc-500">{{ __('Belum ada PO pada periode dan saringan ini.') }}</td></tr>
                            @endforelse
                        </tbody>
                        @if ($realisasi->isNotEmpty())
                            <tfoot class="bg-zinc-50 font-semibold dark:bg-zinc-800/50">
                                <tr>
                                    <td colspan="4" class="px-3 py-3 text-right">{{ __('Total') }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums">{{ $rp($realisasi->sum('total')) }}</td>
                                    <td colspan="5"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif

        {{-- ═════════════ STATUS PEMBAYARAN ═════════════ --}}
        @if ($tab === 'pembayaran')
            @php $daftarBayar = $pembayaran[$opsi['bayar']]; @endphp
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach (\App\Services\LaporanPembelianService::LABEL_BAYAR as $k => $l)
                    <button type="button" wire:click="$set('statusBayar', '{{ $k }}')"
                            @class([
                                'rounded-lg border px-3 py-2 text-left text-sm transition',
                                'border-orange-500 bg-orange-50 text-orange-800 dark:bg-zinc-800 dark:text-orange-300' => $opsi['bayar'] === $k,
                                'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => $opsi['bayar'] !== $k,
                            ])>
                        <span class="font-semibold">{{ $l }}</span>
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 text-xs dark:bg-zinc-700">{{ $pembayaran[$k]->count() }}</span>
                        <span class="block text-xs"><span class="font-mono tabular-nums">{{ $rp(match ($k) { 'lunas' => $pembayaran[$k]->sum('total'), 'kelebihan' => $pembayaran[$k]->sum('kelebihan_bayar'), default => $pembayaran[$k]->sum('sisa') }) }}</span>{{ match ($k) { 'lunas' => '', 'kelebihan' => ' '.__('belum kembali'), default => ' '.__('belum dibayar') } }}</span>
                    </button>
                @endforeach
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                <th class="px-3 py-3">{{ __('No PO') }}</th>
                                <th class="px-3 py-3">{{ __('Supplier') }}</th>
                                <th class="px-3 py-3">{{ __('Syarat bayar') }}</th>
                                <th class="px-3 py-3">{{ __('Proyek') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Total') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Dibayar') }}</th>
                                <th class="px-3 py-3 text-right">{{ $opsi['bayar'] === 'kelebihan' ? __('Belum kembali') : __('Sisa') }}</th>
                                @if ($opsi['bayar'] === 'lunas')
                                    <th class="px-3 py-3">{{ __('Tanggal lunas') }}</th>
                                @else
                                    <th class="px-3 py-3">{{ __('Bayar terakhir') }}</th>
                                    <th class="px-3 py-3 text-right">{{ __('Umur PO') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($daftarBayar as $b)
                                <tr wire:key="bayar-{{ $b['id'] }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="whitespace-nowrap px-3 py-3">
                                        <a href="{{ route('pembelian.po.show', $b['id']) }}" wire:navigate class="font-mono font-semibold text-orange-700 hover:underline dark:text-orange-300">{{ $b['nomor'] }}</a>
                                        <div class="text-xs text-zinc-500">{{ $tgl($b['tanggal']) }}</div>
                                    </td>
                                    <td class="px-3 py-3">{{ $b['supplier'] }}</td>
                                    <td class="px-3 py-3 text-zinc-600 dark:text-zinc-400">{{ $b['syarat_bayar'] ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3">{{ $b['proyek'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums">{{ $rp($b['total']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums">{{ $rp($b['dibayar']) }}</td>
                                    @php $kurang = $opsi['bayar'] === 'kelebihan' ? $b['kelebihan_bayar'] : $b['sisa']; @endphp
                                    <td @class(['whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums', 'font-semibold text-rose-600' => $kurang > 0])>{{ $rp($kurang) }}</td>
                                    @if ($opsi['bayar'] === 'lunas')
                                        <td class="whitespace-nowrap px-3 py-3">{{ $tgl($b['bayar_terakhir']) }}</td>
                                    @else
                                        <td class="whitespace-nowrap px-3 py-3">{{ $tgl($b['bayar_terakhir']) }}</td>
                                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ $b['umur'] }} {{ __('hari') }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-4 py-10 text-center text-zinc-500">{{ __('Tidak ada PO dengan status ini.') }}</td></tr>
                            @endforelse
                        </tbody>
                        @if ($daftarBayar->isNotEmpty())
                            <tfoot class="bg-zinc-50 font-semibold dark:bg-zinc-800/50">
                                <tr>
                                    <td colspan="4" class="px-3 py-3 text-right">{{ __('Total') }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums">{{ $rp($daftarBayar->sum('total')) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums">{{ $rp($daftarBayar->sum('dibayar')) }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-right font-mono tabular-nums">{{ $rp($daftarBayar->sum($opsi['bayar'] === 'kelebihan' ? 'kelebihan_bayar' : 'sisa')) }}</td>
                                    <td colspan="{{ $opsi['bayar'] === 'lunas' ? 1 : 2 }}"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif

        {{-- ═════════════ LEAD TIME PENGADAAN ═════════════ --}}
        @if ($tab === 'lead-time')
            <div class="mb-3 flex flex-wrap items-end gap-x-6 gap-y-1">
                <div>
                    <h2 class="text-base font-bold">{{ __('Lead Time Pengadaan') }}</h2>
                    <p class="text-xs text-zinc-500">{{ $periode }} · {{ $namaProyek }}</p>
                </div>
                @if ($leadTime->isNotEmpty())
                    <p class="text-xs text-zinc-600 dark:text-zinc-400">
                        {{ $leadTime->count() }} {{ __('barang') }} ·
                        {{ __('rata-rata') }} <b>{{ number_format($leadTime->avg('lead_time'), 1, ',', '.') }} {{ __('hari') }}</b> ·
                        {{ $leadTime->where('tahap', 'lengkap')->count() }} {{ __('sudah lengkap') }} ·
                        <span class="text-rose-600">{{ $leadTime->where('sla', 'terlambat')->count() }} {{ __('terlambat') }}</span>
                    </p>
                @endif
            </div>

            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-xs dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                                <th class="px-3 py-3">{{ __('Bagian') }}</th>
                                <th class="px-3 py-3">{{ __('No PR') }}</th>
                                <th class="px-3 py-3">{{ __('Barang') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Jumlah') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Harga perkiraan') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Harga PO') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Selisih') }}</th>
                                <th class="px-3 py-3">{{ __('PR dibuat') }}</th>
                                <th class="px-3 py-3">{{ __('Dibutuhkan') }}</th>
                                <th class="px-3 py-3">{{ __('Disetujui') }}</th>
                                <th class="px-3 py-3">{{ __('PO terbit') }}</th>
                                <th class="px-3 py-3">{{ __('Lunas') }}</th>
                                <th class="px-3 py-3">{{ __('Barang lengkap') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('Lead time') }}</th>
                                <th class="px-3 py-3">{{ __('Tahap') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($leadTime as $b)
                                <tr wire:key="lead-{{ $b['id'] }}" class="align-top hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="whitespace-nowrap px-3 py-2">
                                        {{ $b['departemen'] }}
                                        <div class="text-zinc-500">{{ $b['pemohon'] ?? '—' }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <a href="{{ route('pembelian.permintaan.show', $b['pr_id']) }}" wire:navigate class="font-mono font-semibold text-orange-700 hover:underline dark:text-orange-300">{{ $b['nomor_pr'] }}</a>
                                        <div class="text-zinc-500">{{ $b['proyek'] }}</div>
                                    </td>
                                    <td class="min-w-48 px-3 py-2">
                                        <span class="font-medium">{{ $b['barang'] }}</span>
                                        @if ($b['spesifikasi'])
                                            <div class="text-zinc-500">{{ $b['spesifikasi'] }}</div>
                                        @endif
                                        @if ($b['supplier'])
                                            <div class="text-zinc-500">{{ $b['supplier'] }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format($b['jumlah'], 2, ',', '.'), '0'), ',') }} {{ $b['satuan'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">{{ $rp($b['harga_perkiraan']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">{{ $b['harga_po'] !== null ? $rp($b['harga_po']) : '—' }}</td>
                                    <td @class([
                                        'whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums',
                                        'text-rose-600' => ($b['selisih'] ?? 0) > 0,
                                        'text-emerald-600' => ($b['selisih'] ?? 0) < 0,
                                    ])>{{ $b['selisih'] !== null ? ($b['selisih'] > 0 ? '+' : '').number_format($b['selisih'], 0, ',', '.') : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ $tgl($b['tanggal_pr']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ $tgl($b['tanggal_dibutuhkan']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ $tgl($b['tanggal_disetujui']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        {{ $tgl($b['tanggal_po']) }}
                                        @if ($b['po_id'])
                                            <div><a href="{{ route('pembelian.po.show', $b['po_id']) }}" wire:navigate class="font-mono text-orange-700 hover:underline dark:text-orange-300">{{ $b['nomor_po'] }}</a></div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ $tgl($b['tanggal_lunas']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ $tgl($b['tanggal_lengkap']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        <span class="font-mono font-semibold tabular-nums">{{ $b['lead_time'] }} {{ __('hari') }}</span>
                                        @if ($b['berjalan'])
                                            <div class="text-[10px] text-zinc-500">{{ __('masih berjalan') }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <flux:badge size="sm" :color="$warnaTahap[$b['tahap']]">{{ \App\Services\LaporanPembelianService::LABEL_TAHAP[$b['tahap']] }}</flux:badge>
                                        @if ($b['sla'])
                                            <div class="mt-1"><flux:badge size="sm" :color="$warnaSla[$b['sla']]">{{ \App\Services\LaporanPembelianService::LABEL_SLA[$b['sla']] }}</flux:badge></div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="15" class="px-4 py-10 text-center text-zinc-500">{{ __('Belum ada barang PR pada periode dan saringan ini.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ═════════════ GRAFIK TAHUNAN ═════════════ --}}
        <flux:modal name="grafik-tahunan" class="w-full max-w-3xl" @close="tutupGrafik">
            @php
                $namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                $nilaiTertinggi = max(1, collect($grafik)->max('nilai') ?? 0);
            @endphp
            <div class="space-y-4">
                <div class="flex flex-wrap items-start gap-3" style="padding-right: 2rem">
                    <div class="mr-auto">
                        <flux:heading size="lg">{{ __('Nilai PO per bulan') }}</flux:heading>
                        <flux:subheading>{{ $namaProyek }}{{ $saring['departemen'] ? ' · '.$saring['departemen'] : '' }}</flux:subheading>
                    </div>
                    <flux:select wire:model.live="tahunGrafik" class="w-28">
                        @foreach ($pilihanTahun as $th)
                            <flux:select.option value="{{ $th }}">{{ $th }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($grafik)
                    <div class="flex h-48 items-end gap-2 border-b border-zinc-300 dark:border-zinc-600" data-grafik="pembelian-tahunan">
                        @foreach ($grafik as $bulan => $g)
                            <div class="flex h-full flex-1 items-end" title="{{ $namaBulan[$bulan - 1] }} {{ $tahunGrafik }}: {{ $rp($g['nilai']) }} · {{ $g['jumlah'] }} PO">
                                @if ($g['nilai'] > 0)
                                    <div class="w-full bg-orange-500" style="border-radius: 4px 4px 0 0; height: {{ max(2, round($g['nilai'] / $nilaiTertinggi * 100)) }}%"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="-mt-2 flex gap-2 text-center text-[10px] text-zinc-500">
                        @foreach ($namaBulan as $b)
                            <div class="flex-1">{{ $b }}</div>
                        @endforeach
                    </div>

                    <div class="max-h-[60vh] overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ __('Bulan') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Jumlah PO') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Nilai PO') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($grafik as $bulan => $g)
                                    <tr @class(['text-zinc-400' => $g['jumlah'] === 0])>
                                        <td class="px-3 py-1.5">{{ $namaBulan[$bulan - 1] }} {{ $tahunGrafik }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $g['jumlah'] }}</td>
                                        <td class="px-3 py-1.5 text-right font-mono tabular-nums">{{ $rp($g['nilai']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-zinc-50 font-semibold dark:bg-zinc-800">
                                <tr>
                                    <td class="px-3 py-2">{{ __('Total') }} {{ $tahunGrafik }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ collect($grafik)->sum('jumlah') }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $rp(collect($grafik)->sum('nilai')) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </flux:modal>
    </div>
</section>
