<?php

use App\Models\Master\Coa;
use App\Models\Master\PembayaranPembelian;
use App\Models\Master\PenerimaanBarang;
use App\Models\Master\PengembalianPembelian;
use App\Models\Master\PesananPembelian;
use App\Services\PembelianService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail Purchase Order')] class extends Component
{
    use WithFileUploads;

    public int $pesananId;

    // ===== FORM PEMBAYARAN =====
    public string $bayarTanggal = '';

    public string|int $bayarJumlah = 0;

    public string $bayarRekeningId = '';

    public $bayarBukti;

    public ?string $bayarKeterangan = null;

    // ===== FORM PENGEMBALIAN DARI SUPPLIER =====
    public string $kembaliTanggal = '';

    public string|int $kembaliJumlah = 0;

    public string $kembaliRekeningId = '';

    public $kembaliBukti;

    public ?string $kembaliKeterangan = null;

    // ===== FORM BARANG DATANG =====
    public string $terimaTanggal = '';

    public ?string $terimaSuratJalan = null;

    public $terimaFoto;

    public ?string $terimaCatatan = null;

    /** @var array<int, string> id baris PO => jumlah datang */
    public array $terimaJumlah = [];

    // ===== BATAL & TUTUP =====
    public string $alasanBatal = '';

    public string $alasanTutup = '';

    public function mount(int $id): void
    {
        $this->pesananId = PesananPembelian::findOrFail($id)->id;
    }

    #[Computed]
    public function po(): PesananPembelian
    {
        return PesananPembelian::with([
            'item', 'supplier.bank:id,nama', 'permintaan:id,nomor,proyek_id,departemen', 'permintaan.proyek:id,nama_proyek',
            'pembayaran.rekening:id,kode,nama', 'pembayaran.inputBy:id,name', 'pengembalian.rekening:id,kode,nama', 'pengembalian.inputBy:id,name',
            'penerimaan.item.itemPesanan:id,nama_barang,satuan', 'penerimaan.inputBy:id,name', 'createdBy:id,name', 'ditutupBy:id,name',
        ])->findOrFail($this->pesananId);
    }

    // -------------------------------------------------------------
    // PEMBAYARAN
    // -------------------------------------------------------------

    public function openBayar(): void
    {
        abort_unless(Auth::user()->can('pembelian.bayar'), 403);

        $this->reset(['bayarRekeningId', 'bayarBukti', 'bayarKeterangan']);
        $this->bayarTanggal = now()->format('Y-m-d');
        $this->bayarJumlah = (int) round($this->po->sisaBayar());
        $this->resetErrorBag();
        Flux::modal('bayar-po')->show();
    }

    public function simpanBayar(PembelianService $layanan): void
    {
        abort_unless(Auth::user()->can('pembelian.bayar'), 403);

        $this->validate([
            'bayarTanggal' => ['required', 'date'],
            'bayarJumlah' => ['required', 'numeric', 'gt:0'],
            'bayarRekeningId' => ['nullable', 'exists:coa,id'],
            'bayarBukti' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'bayarKeterangan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'bayarTanggal' => 'tanggal',
            'bayarJumlah' => 'jumlah',
            'bayarRekeningId' => 'rekening',
            'bayarBukti' => 'bukti transfer',
        ]);

        try {
            $bayar = $layanan->catatPembayaran($this->po, [
                'tanggal' => $this->bayarTanggal,
                'jumlah' => (float) $this->bayarJumlah,
                'rekening_coa_id' => $this->bayarRekeningId !== '' ? (int) $this->bayarRekeningId : null,
                'keterangan' => $this->bayarKeterangan ?: null,
            ], Auth::id());
        } catch (ValidationException $e) {
            $this->addError('bayarJumlah', collect($e->errors())->flatten()->first());

            return;
        }

        if ($this->bayarBukti) {
            $bayar->update([
                'bukti_path' => $this->bayarBukti->store("pembelian/bayar/{$this->pesananId}", 'private'),
                'bukti_nama' => $this->bayarBukti->getClientOriginalName(),
            ]);
        }

        Flux::modal('bayar-po')->close();
        unset($this->po);
        Flux::toast(variant: 'success', text: 'Pembayaran tercatat. Jangan lupa jurnalnya — modul ini belum membuat jurnal otomatis.');
    }

    // -------------------------------------------------------------
    // PENGEMBALIAN DARI SUPPLIER
    // -------------------------------------------------------------

    public function openKembali(): void
    {
        abort_unless(Auth::user()->can('pembelian.bayar'), 403);

        $this->reset(['kembaliRekeningId', 'kembaliBukti', 'kembaliKeterangan']);
        $this->kembaliTanggal = now()->format('Y-m-d');
        $this->kembaliJumlah = (int) round($this->po->kelebihanBayar());
        $this->resetErrorBag();
        Flux::modal('kembali-po')->show();
    }

    public function simpanKembali(PembelianService $layanan): void
    {
        abort_unless(Auth::user()->can('pembelian.bayar'), 403);

        $this->validate([
            'kembaliTanggal' => ['required', 'date'],
            'kembaliJumlah' => ['required', 'numeric', 'gt:0'],
            'kembaliRekeningId' => ['nullable', 'exists:coa,id'],
            'kembaliBukti' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
            'kembaliKeterangan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'kembaliTanggal' => 'tanggal',
            'kembaliJumlah' => 'jumlah',
            'kembaliRekeningId' => 'rekening',
            'kembaliBukti' => 'bukti transfer',
        ]);

        try {
            $kembali = $layanan->catatPengembalian($this->po, [
                'tanggal' => $this->kembaliTanggal,
                'jumlah' => (float) $this->kembaliJumlah,
                'rekening_coa_id' => $this->kembaliRekeningId !== '' ? (int) $this->kembaliRekeningId : null,
                'keterangan' => $this->kembaliKeterangan ?: null,
            ], Auth::id());
        } catch (ValidationException $e) {
            $this->addError('kembaliJumlah', collect($e->errors())->flatten()->first());

            return;
        }

        if ($this->kembaliBukti) {
            $kembali->update([
                'bukti_path' => $this->kembaliBukti->store("pembelian/kembali/{$this->pesananId}", 'private'),
                'bukti_nama' => $this->kembaliBukti->getClientOriginalName(),
            ]);
        }

        Flux::modal('kembali-po')->close();
        unset($this->po);
        Flux::toast(variant: 'success', text: 'Pengembalian dari supplier tercatat. Jangan lupa jurnalnya — modul ini belum membuat jurnal otomatis.');
    }

    public function unduhBuktiKembali(int $id)
    {
        $kembali = PengembalianPembelian::where('pesanan_pembelian_id', $this->pesananId)->findOrFail($id);
        abort_unless($kembali->bukti_path && Storage::disk('private')->exists($kembali->bukti_path), 404);

        return Storage::disk('private')->download($kembali->bukti_path, $kembali->bukti_nama ?: basename($kembali->bukti_path));
    }

    // -------------------------------------------------------------
    // BARANG DATANG
    // -------------------------------------------------------------

    public function openTerima(): void
    {
        abort_unless(Auth::user()->can('pembelian.terima'), 403);

        if ($this->po->pembayaran->isEmpty()) {
            Flux::toast(variant: 'warning', text: 'Supplier dibayar dulu sebelum barang dikirim — pembayarannya belum tercatat.');

            return;
        }

        $this->reset(['terimaSuratJalan', 'terimaFoto', 'terimaCatatan']);
        $this->terimaTanggal = now()->format('Y-m-d');
        $this->terimaJumlah = $this->po->item->mapWithKeys(fn ($i) => [
            $i->id => rtrim(rtrim(number_format($i->sisaDiterima(), 2, '.', ''), '0'), '.'),
        ])->all();
        $this->resetErrorBag();
        Flux::modal('terima-po')->show();
    }

    public function simpanTerima(PembelianService $layanan): void
    {
        abort_unless(Auth::user()->can('pembelian.terima'), 403);

        $this->validate([
            'terimaTanggal' => ['required', 'date'],
            'terimaSuratJalan' => ['nullable', 'string', 'max:60'],
            'terimaFoto' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
            'terimaCatatan' => ['nullable', 'string', 'max:500'],
            'terimaJumlah.*' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'terimaTanggal' => 'tanggal',
            'terimaSuratJalan' => 'nomor surat jalan',
            'terimaFoto' => 'foto',
            'terimaJumlah.*' => 'jumlah',
        ]);

        try {
            $terima = $layanan->catatPenerimaan($this->po, [
                'tanggal' => $this->terimaTanggal,
                'nomor_surat_jalan' => $this->terimaSuratJalan ?: null,
                'catatan' => $this->terimaCatatan ?: null,
            ], $this->terimaJumlah, Auth::id());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $kunci => $pesan) {
                $this->addError(str_starts_with($kunci, 'jumlah.') ? 'terimaJumlah.'.substr($kunci, 7) : 'terimaJumlah', $pesan[0]);
            }

            return;
        }

        if ($this->terimaFoto) {
            $terima->update([
                'foto_path' => $this->terimaFoto->store("pembelian/terima/{$this->pesananId}", 'private'),
                'foto_nama' => $this->terimaFoto->getClientOriginalName(),
            ]);
        }

        Flux::modal('terima-po')->close();
        unset($this->po);
        Flux::toast(variant: 'success', text: $this->po->status === 'selesai'
            ? 'Semua barang sudah diterima — PO selesai.'
            : 'Kedatangan barang tercatat.');
    }

    // -------------------------------------------------------------
    // BATAL & BERKAS
    // -------------------------------------------------------------

    public function openBatal(): void
    {
        abort_unless(Auth::user()->can('pembelian.po'), 403);
        $this->alasanBatal = '';
        $this->resetErrorBag();
        Flux::modal('batal-po')->show();
    }

    public function batalkan(PembelianService $layanan): void
    {
        abort_unless(Auth::user()->can('pembelian.po'), 403);
        $this->validate(['alasanBatal' => ['required', 'string', 'max:500']], [], ['alasanBatal' => 'alasan']);

        try {
            $layanan->batalkanPesanan($this->po, $this->alasanBatal, Auth::id());
        } catch (ValidationException $e) {
            $this->addError('alasanBatal', collect($e->errors())->flatten()->first());

            return;
        }

        Flux::modal('batal-po')->close();
        unset($this->po);
        Flux::toast(variant: 'success', text: 'PO dibatalkan.');
    }

    public function openTutup(): void
    {
        abort_unless(Auth::user()->can('pembelian.po'), 403);
        $this->alasanTutup = '';
        $this->resetErrorBag();
        Flux::modal('tutup-po')->show();
    }

    public function tutup(PembelianService $layanan): void
    {
        abort_unless(Auth::user()->can('pembelian.po'), 403);
        $this->validate(['alasanTutup' => ['required', 'string', 'max:500']], [], ['alasanTutup' => 'alasan']);

        try {
            $layanan->tutupPesanan($this->po, $this->alasanTutup, Auth::id());
        } catch (ValidationException $e) {
            $this->addError('alasanTutup', collect($e->errors())->flatten()->first());

            return;
        }

        Flux::modal('tutup-po')->close();
        unset($this->po);
        Flux::toast(variant: 'success', text: 'PO ditutup. Sisa barangnya bisa dipesan lagi dari PR.');
    }

    public function unduhBukti(int $id)
    {
        $bayar = PembayaranPembelian::where('pesanan_pembelian_id', $this->pesananId)->findOrFail($id);
        abort_unless($bayar->bukti_path && Storage::disk('private')->exists($bayar->bukti_path), 404);

        return Storage::disk('private')->download($bayar->bukti_path, $bayar->bukti_nama ?: basename($bayar->bukti_path));
    }

    public function unduhFoto(int $id)
    {
        $terima = PenerimaanBarang::where('pesanan_pembelian_id', $this->pesananId)->findOrFail($id);
        abort_unless($terima->foto_path && Storage::disk('private')->exists($terima->foto_path), 404);

        return Storage::disk('private')->download($terima->foto_path, $terima->foto_nama ?: basename($terima->foto_path));
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'bolehBayar' => $user->can('pembelian.bayar'),
            'bolehTerima' => $user->can('pembelian.terima'),
            'bolehPo' => $user->can('pembelian.po'),
            'rekeningOptions' => Coa::where('is_header', false)
                ->where(fn ($q) => $q->where('kode', 'like', '1001.%')->orWhere('kode', 'like', '1002.%'))
                ->orderBy('kode')->get(['id', 'kode', 'nama']),
        ];
    }
}; ?>

<section class="w-full">
    @php
        $po = $this->po;
        $angka = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        [$labelBayar, $warnaBayar] = match ($po->statusBayar()) {
            'belum' => ['Belum dibayar', 'rose'],
            'sebagian' => ['Dibayar sebagian', 'amber'],
            default => ['Lunas', 'emerald'],
        };
        [$labelBarang, $warnaBarang] = match ($po->statusBarang()) {
            'belum' => ['Barang belum datang', 'zinc'],
            'sebagian' => ['Barang datang sebagian', 'amber'],
            default => ['Barang lengkap', 'emerald'],
        };
    @endphp
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">

        @php
            $bolehBatal = $po->status === 'terbit' && $bolehPo && $po->pembayaran->isEmpty() && $po->penerimaan->isEmpty();
            // Supplier tidak sanggup mengirim sisanya: PO yang sudah dibayar atau sudah ada barang datang ditutup, bukan dibatalkan.
            $bolehTutup = $po->status === 'terbit' && $bolehPo && ($po->pembayaran->isNotEmpty() || $po->penerimaan->isNotEmpty());
            // PO yang sudah selesai (barang lengkap atau ditutup) masih bisa dilunasi sisanya.
            $tampilBayar = $po->status !== 'batal' && $bolehBayar && $po->sisaBayar() > 0;
            $tampilKembali = $bolehBayar && $po->kelebihanBayar() > 0;
            // Barang baru dikirim setelah dibayar, jadi tombolnya muncul setelah ada pembayaran.
            $tampilTerima = $po->status === 'terbit' && $bolehTerima && $po->pembayaran->isNotEmpty();
        @endphp
        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('pembelian.po.index')" wire:navigate />
                <div class="min-w-0">
                    <flux:heading size="xl" class="font-mono">{{ $po->nomor }}</flux:heading>
                    <flux:subheading>
                        {{ $po->supplier?->nama }} · {{ __('dari') }}
                        <a href="{{ route('pembelian.permintaan.show', $po->permintaan_pembelian_id) }}" wire:navigate class="font-mono underline">{{ $po->permintaan?->nomor }}</a>
                        ({{ $po->permintaan?->namaLokasi() }})
                    </flux:subheading>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @if ($po->status === 'batal')
                            <flux:badge color="zinc" size="sm">{{ __('Batal') }}</flux:badge>
                        @else
                            <flux:badge :color="$warnaBayar" size="sm">{{ $labelBayar }}</flux:badge>
                            @if ($po->ditutup())
                                <flux:badge color="zinc" size="sm">{{ __('Ditutup') }}</flux:badge>
                            @else
                                <flux:badge :color="$warnaBarang" size="sm">{{ $labelBarang }}</flux:badge>
                            @endif
                            @if ($po->kelebihanBayar() > 0)
                                <flux:badge color="rose" size="sm">{{ __('Kelebihan bayar') }}</flux:badge>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
                @if ($bolehBatal)
                    <flux:button variant="ghost" icon="x-circle" class="text-rose-600" wire:click="openBatal">{{ __('Batalkan') }}</flux:button>
                @endif
                @if ($bolehTutup)
                    <flux:button variant="ghost" icon="lock-closed" wire:click="openTutup">{{ __('Tutup PO') }}</flux:button>
                @endif
                @if ($po->status !== 'batal')
                    <flux:button icon="printer" :href="route('pembelian.po.cetak', $po->id)" target="_blank">{{ __('Cetak PO') }}</flux:button>
                @endif
                @if ($tampilTerima)
                    <flux:button :variant="$tampilBayar ? 'outline' : 'primary'" icon="truck" wire:click="openTerima">{{ __('Catat barang datang') }}</flux:button>
                @endif
                @if ($tampilBayar)
                    <flux:button variant="primary" icon="banknotes" wire:click="openBayar">{{ __('Catat pembayaran') }}</flux:button>
                @endif
                @if ($tampilKembali)
                    <flux:button variant="primary" icon="receipt-refund" wire:click="openKembali">{{ __('Catat pengembalian') }}</flux:button>
                @endif
            </div>
        </div>

        @if ($po->status === 'batal')
            <div class="mb-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="font-semibold">{{ __('PO dibatalkan') }}</div>
                <div class="mt-1 text-zinc-600 dark:text-zinc-300" style="white-space: pre-line">{{ $po->alasan_batal }}</div>
            </div>
        @elseif ($po->ditutup())
            <div class="mb-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-800/50" data-status="po-ditutup">
                <div class="font-semibold">
                    {{ __('PO ditutup') }} {{ $po->ditutup_at->translatedFormat('d M Y') }}{{ $po->ditutupBy ? ' · '.$po->ditutupBy->name : '' }}
                </div>
                <div class="mt-1 text-zinc-600 dark:text-zinc-300" style="white-space: pre-line">{{ $po->alasan_tutup }}</div>
                <div class="mt-2 text-zinc-600 dark:text-zinc-300">
                    {{ __('Nilai PO disesuaikan dengan barang yang datang: Rp :awal menjadi Rp :akhir. Sisa barangnya bisa dipesan lagi dari PR.', [
                        'awal' => number_format((float) $po->total_awal, 0, ',', '.'),
                        'akhir' => number_format((float) $po->total, 0, ',', '.'),
                    ]) }}
                </div>
                @if ($po->totalDikembalikan() > 0)
                    <div class="mt-2 text-zinc-600 dark:text-zinc-300">
                        {{ __('Sudah dikembalikan supplier: Rp :n.', ['n' => number_format($po->totalDikembalikan(), 0, ',', '.')]) }}
                    </div>
                @endif
                @if ($po->kelebihanBayar() > 0)
                    <div class="mt-2 font-medium text-rose-600">
                        {{ __('Kelebihan bayar Rp :n — tagih pengembalian ke supplier.', ['n' => number_format($po->kelebihanBayar(), 0, ',', '.')]) }}
                    </div>
                @elseif ($po->sisaBayar() > 0)
                    <div class="mt-2 font-medium text-amber-700 dark:text-amber-300">
                        {{ __('Masih kurang bayar Rp :n untuk barang yang sudah datang.', ['n' => number_format($po->sisaBayar(), 0, ',', '.')]) }}
                    </div>
                @endif
            </div>
        @elseif ($po->status === 'terbit')
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                @if ($po->pembayaran->isEmpty())
                    {{ __('Langkah berikutnya: Keuangan membayar supplier. Barang dikirim setelah dibayar.') }}
                @elseif ($po->sisaBayar() > 0)
                    {{ __('Langkah berikutnya: catat barang saat datang dan lunasi sisa pembayaran. PO selesai otomatis begitu semua barang diterima.') }}
                @else
                    {{ __('Langkah berikutnya: catat barang saat datang. PO selesai otomatis begitu semua barang diterima.') }}
                @endif
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                <th class="px-4 py-3">{{ __('Barang') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Dipesan') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Datang') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Harga') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Subtotal') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($po->item as $i)
                                <tr wire:key="item-{{ $i->id }}">
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ $i->nama_barang }}</div>
                                        @if ($i->spesifikasi)<div class="text-xs text-zinc-500">{{ $i->spesifikasi }}</div>@endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                        {{ $angka($i->jumlah) }} {{ $i->satuan }}
                                        @if ($i->jumlah_awal !== null)
                                            <div class="text-xs text-zinc-500">{{ __('dari') }} {{ $angka($i->jumlah_awal) }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                                        <span @class(['font-semibold text-emerald-700 dark:text-emerald-300' => $i->sisaDiterima() <= 0])>{{ $angka($i->jumlah_diterima) }}</span>
                                        @if ($i->sisaDiterima() > 0 && (float) $i->jumlah_diterima > 0)
                                            <div class="text-xs text-amber-700 dark:text-amber-300">{{ __('kurang') }} {{ $angka($i->sisaDiterima()) }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums">{{ number_format((float) $i->harga, 0, ',', '.') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono tabular-nums">{{ number_format($i->subtotal(), 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-zinc-50 text-sm dark:bg-zinc-800/50">
                            <tr><td colspan="4" class="px-4 pt-3 text-right text-zinc-500">{{ __('Subtotal') }}</td><td class="px-4 pt-3 text-right font-mono tabular-nums">{{ number_format((float) $po->subtotal, 0, ',', '.') }}</td></tr>
                            @if ($po->ppn_terpisah)
                                <tr><td colspan="4" class="px-4 pt-1 text-right text-zinc-500">PPN {{ $angka($po->ppn_persen) }}%</td><td class="px-4 pt-1 text-right font-mono tabular-nums">{{ number_format((float) $po->ppn, 0, ',', '.') }}</td></tr>
                            @endif
                            <tr><td colspan="4" class="px-4 pt-1 text-right font-semibold">{{ __('Total PO') }}</td><td class="px-4 pt-1 text-right font-mono font-semibold tabular-nums">Rp {{ number_format((float) $po->total, 0, ',', '.') }}</td></tr>
                            <tr><td colspan="4" class="px-4 pt-1 text-right text-zinc-500">{{ __('Sudah dibayar') }}</td><td class="px-4 pt-1 text-right font-mono tabular-nums">{{ number_format($po->totalDibayar(), 0, ',', '.') }}</td></tr>
                            @if ($po->totalDikembalikan() > 0)
                                <tr><td colspan="4" class="px-4 pt-1 text-right text-zinc-500">{{ __('Dikembalikan supplier') }}</td><td class="px-4 pt-1 text-right font-mono tabular-nums">−{{ number_format($po->totalDikembalikan(), 0, ',', '.') }}</td></tr>
                            @endif
                            @if ($po->kelebihanBayar() > 0)
                                <tr><td colspan="4" class="px-4 pt-1 text-right font-semibold text-rose-600">{{ __('Kelebihan bayar, belum kembali') }}</td><td class="px-4 pt-1 text-right font-mono font-semibold tabular-nums text-rose-600">Rp {{ number_format($po->kelebihanBayar(), 0, ',', '.') }}</td></tr>
                            @endif
                            <tr><td colspan="4" class="px-4 pb-3 pt-1 text-right font-semibold">{{ __('Sisa bayar') }}</td><td class="px-4 pb-3 pt-1 text-right font-mono font-semibold tabular-nums">Rp {{ number_format($po->sisaBayar(), 0, ',', '.') }}</td></tr>
                        </tfoot>
                    </table>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700"><flux:heading size="lg">{{ __('Pembayaran') }}</flux:heading></div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($po->pembayaran as $b)
                            <div wire:key="bayar-{{ $b->id }}" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                <div>
                                    <div class="font-medium">{{ $b->tanggal->translatedFormat('d M Y') }}{{ $b->rekening ? ' · '.$b->rekening->nama : '' }}</div>
                                    <div class="text-xs text-zinc-500">{{ $b->inputBy?->name }}{{ $b->keterangan ? ' · '.$b->keterangan : '' }}</div>
                                </div>
                                <div class="flex items-center gap-3">
                                    @if ($b->bukti_path)
                                        <flux:button size="xs" variant="ghost" icon="paper-clip" wire:click="unduhBukti({{ $b->id }})">{{ __('Bukti') }}</flux:button>
                                    @endif
                                    <span class="font-mono tabular-nums">Rp {{ number_format((float) $b->jumlah, 0, ',', '.') }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-center text-sm text-zinc-500">{{ __('Belum ada pembayaran.') }}</div>
                        @endforelse
                    </div>
                </div>

                @if ($po->pengembalian->isNotEmpty() || $po->kelebihanBayar() > 0)
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" data-riwayat="pengembalian">
                        <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700"><flux:heading size="lg">{{ __('Pengembalian dari supplier') }}</flux:heading></div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($po->pengembalian as $k)
                                <div wire:key="kembali-{{ $k->id }}" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                    <div>
                                        <div class="font-medium">{{ $k->tanggal->translatedFormat('d M Y') }}{{ $k->rekening ? ' · '.$k->rekening->nama : '' }}</div>
                                        <div class="text-xs text-zinc-500">{{ $k->inputBy?->name }}{{ $k->keterangan ? ' · '.$k->keterangan : '' }}</div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        @if ($k->bukti_path)
                                            <flux:button size="xs" variant="ghost" icon="paper-clip" wire:click="unduhBuktiKembali({{ $k->id }})">{{ __('Bukti') }}</flux:button>
                                        @endif
                                        <span class="font-mono tabular-nums">Rp {{ number_format((float) $k->jumlah, 0, ',', '.') }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="px-5 py-6 text-center text-sm text-zinc-500">{{ __('Supplier belum mengembalikan kelebihan bayar.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endif

                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700"><flux:heading size="lg">{{ __('Barang datang') }}</flux:heading></div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($po->penerimaan as $t)
                            <div wire:key="terima-{{ $t->id }}" class="px-5 py-3 text-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <div class="font-medium">{{ $t->tanggal->translatedFormat('d M Y') }}{{ $t->nomor_surat_jalan ? ' · SJ '.$t->nomor_surat_jalan : '' }}</div>
                                        <div class="text-xs text-zinc-500">{{ $t->inputBy?->name }}{{ $t->catatan ? ' · '.$t->catatan : '' }}</div>
                                    </div>
                                    @if ($t->foto_path)
                                        <flux:button size="xs" variant="ghost" icon="photo" wire:click="unduhFoto({{ $t->id }})">{{ __('Foto') }}</flux:button>
                                    @endif
                                </div>
                                <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                                    {{ $t->item->map(fn ($x) => ($x->itemPesanan?->nama_barang).' '.$angka($x->jumlah).' '.($x->itemPesanan?->satuan))->implode(', ') }}
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-center text-sm text-zinc-500">{{ __('Belum ada barang datang.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-lg border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg" class="mb-3">{{ __('Supplier') }}</flux:heading>
                    <div class="font-medium">{{ $po->supplier?->nama }}</div>
                    @if ($po->supplier?->kontak_nama || $po->supplier?->hp)
                        <div class="text-zinc-500">{{ $po->supplier->kontak_nama }} {{ $po->supplier->hp }}</div>
                    @endif
                    @if ($po->supplier?->nomor_rekening)
                        <div class="mt-3 rounded-md bg-zinc-50 p-3 dark:bg-zinc-800/50">
                            <div class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Rekening') }}</div>
                            <div class="mt-1">{{ $po->supplier->bank?->nama }}</div>
                            <div class="font-mono">{{ $po->supplier->nomor_rekening }}</div>
                            <div class="text-xs text-zinc-500">{{ $po->supplier->rekening_atas_nama }}</div>
                        </div>
                    @endif
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <dl class="space-y-3">
                        <div><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Tanggal PO') }}</dt><dd>{{ $po->tanggal->translatedFormat('d F Y') }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Tanggal kirim') }}</dt><dd>{{ $po->tanggal_kirim?->translatedFormat('d F Y') ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Syarat bayar') }}</dt><dd>{{ $po->syarat_bayar ?: '—' }}</dd></div>
                        @if ($po->catatan)
                            <div><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Catatan') }}</dt><dd class="" style="white-space: pre-line">{{ $po->catatan }}</dd></div>
                        @endif
                        <div><dt class="text-xs uppercase tracking-wider text-zinc-500">{{ __('Diterbitkan') }}</dt><dd>{{ $po->createdBy?->name }} · {{ $po->created_at->translatedFormat('d M Y H:i') }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>

        {{-- MODAL PEMBAYARAN --}}
        <flux:modal name="bayar-po" class="md:w-lg">
            <form wire:submit="simpanBayar" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Catat pembayaran ke supplier') }}</flux:heading>
                    <flux:subheading>{{ __('Sisa yang harus dibayar: Rp :n', ['n' => number_format($po->sisaBayar(), 0, ',', '.')]) }}</flux:subheading>
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Tanggal') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="bayarTanggal" required />
                        <flux:error name="bayarTanggal" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Jumlah') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <x-money-input wire="bayarJumlah" required />
                        <flux:error name="bayarJumlah" />
                    </flux:field>
                </div>
                <flux:field>
                    <flux:label>{{ __('Dibayar dari') }}</flux:label>
                    <flux:select wire:model="bayarRekeningId">
                        <flux:select.option value="">{{ __('-- Pilih kas / bank --') }}</flux:select.option>
                        @foreach ($rekeningOptions as $r)
                            <flux:select.option value="{{ $r->id }}">{{ $r->kode }} — {{ $r->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="bayarRekeningId" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Bukti transfer') }}</flux:label>
                    <input type="file" wire:model="bayarBukti" accept=".pdf,.jpg,.jpeg,.png,.webp" class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />
                    <flux:error name="bayarBukti" />
                </flux:field>
                <flux:textarea wire:model="bayarKeterangan" :label="__('Keterangan')" rows="2" />
                <p class="text-xs text-zinc-500">{{ __('Modul ini belum membuat jurnal. Jurnal pembayarannya tetap dibuat Keuangan seperti biasa.') }}</p>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan pembayaran') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- MODAL PENGEMBALIAN --}}
        <flux:modal name="kembali-po" class="md:w-lg">
            <form wire:submit="simpanKembali" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Catat pengembalian dari supplier') }}</flux:heading>
                    <flux:subheading>{{ __('Kelebihan bayar yang belum kembali: Rp :n', ['n' => number_format($po->kelebihanBayar(), 0, ',', '.')]) }}</flux:subheading>
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Tanggal') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="kembaliTanggal" required />
                        <flux:error name="kembaliTanggal" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Jumlah') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <x-money-input wire="kembaliJumlah" required />
                        <flux:error name="kembaliJumlah" />
                    </flux:field>
                </div>
                <flux:field>
                    <flux:label>{{ __('Diterima di') }}</flux:label>
                    <flux:select wire:model="kembaliRekeningId">
                        <flux:select.option value="">{{ __('-- Pilih kas / bank --') }}</flux:select.option>
                        @foreach ($rekeningOptions as $r)
                            <flux:select.option value="{{ $r->id }}">{{ $r->kode }} — {{ $r->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="kembaliRekeningId" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Bukti transfer') }}</flux:label>
                    <input type="file" wire:model="kembaliBukti" accept=".pdf,.jpg,.jpeg,.png,.webp" class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />
                    <flux:error name="kembaliBukti" />
                </flux:field>
                <flux:textarea wire:model="kembaliKeterangan" :label="__('Keterangan')" rows="2" />
                <p class="text-xs text-zinc-500">{{ __('Modul ini belum membuat jurnal. Jurnal pengembaliannya tetap dibuat Keuangan seperti biasa.') }}</p>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan pengembalian') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- MODAL BARANG DATANG --}}
        <flux:modal name="terima-po" class="md:w-2xl">
            <form wire:submit="simpanTerima" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Catat barang datang') }}</flux:heading>
                    <flux:subheading>{{ __('Isi jumlah yang benar-benar datang hari ini. Barang yang belum datang biarkan 0.') }}</flux:subheading>
                </div>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Tanggal datang') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="terimaTanggal" required />
                        <flux:error name="terimaTanggal" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Nomor surat jalan') }}</flux:label>
                        <flux:input wire:model="terimaSuratJalan" />
                        <flux:error name="terimaSuratJalan" />
                    </flux:field>
                </div>
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                <th class="px-3 py-2">{{ __('Barang') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Belum datang') }}</th>
                                <th class="px-3 py-2">{{ __('Datang sekarang') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($po->item as $i)
                                <tr wire:key="terima-item-{{ $i->id }}">
                                    <td class="px-3 py-2">{{ $i->nama_barang }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-zinc-500">{{ $angka($i->sisaDiterima()) }} {{ $i->satuan }}</td>
                                    <td class="w-36 px-3 py-2">
                                        <flux:input type="number" step="0.01" min="0" wire:model="terimaJumlah.{{ $i->id }}" :disabled="$i->sisaDiterima() <= 0" size="sm" />
                                        <flux:error name="terimaJumlah.{{ $i->id }}" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <flux:error name="terimaJumlah" />
                <flux:field>
                    <flux:label>{{ __('Foto barang / surat jalan') }}</flux:label>
                    <input type="file" wire:model="terimaFoto" accept="image/*,.pdf" class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600" />
                    <flux:error name="terimaFoto" />
                </flux:field>
                <flux:textarea wire:model="terimaCatatan" :label="__('Catatan')" rows="2" :placeholder="__('Contoh: 2 sak semen basah, diganti minggu depan')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- MODAL TUTUP --}}
        <flux:modal name="tutup-po" class="md:w-lg">
            <form wire:submit="tutup" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Tutup PO ini?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Pakai ini kalau supplier tidak sanggup mengirim sisa barang. Jumlah pesanan dipangkas menjadi yang sudah datang:') }}</flux:text>
                </div>
                <ul class="space-y-1 text-sm">
                    @foreach ($po->item->filter(fn ($i) => $i->sisaDiterima() > 0) as $i)
                        <li wire:key="tutup-{{ $i->id }}" class="flex justify-between gap-3">
                            <span>{{ $i->nama_barang }}</span>
                            <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $angka($i->jumlah_diterima) }} {{ __('dari') }} {{ $angka($i->jumlah) }} {{ $i->satuan }} · {{ $angka($i->sisaDiterima()) }} {{ __('tidak dikirim') }}</span>
                        </li>
                    @endforeach
                </ul>
                @php
                    $nilaiBaru = $po->nilaiBarangDatang();
                    $selisihBayar = round($po->totalDibayar() - $nilaiBaru, 2);
                @endphp
                <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800/50">
                    <div class="flex justify-between"><span>{{ __('Nilai PO menjadi') }}</span><span class="font-mono tabular-nums">Rp {{ number_format($nilaiBaru, 0, ',', '.') }}</span></div>
                    <div class="flex justify-between text-zinc-500"><span>{{ __('semula') }}</span><span class="font-mono tabular-nums">Rp {{ number_format((float) $po->total, 0, ',', '.') }}</span></div>
                    <div class="flex justify-between"><span>{{ __('Sudah dibayar') }}</span><span class="font-mono tabular-nums">Rp {{ number_format($po->totalDibayar(), 0, ',', '.') }}</span></div>
                    @if ($selisihBayar > 0)
                        <div class="mt-1 font-medium text-rose-600">{{ __('Kelebihan bayar Rp :n akan tercatat untuk ditagih ke supplier.', ['n' => number_format($selisihBayar, 0, ',', '.')]) }}</div>
                    @elseif ($selisihBayar < 0)
                        <div class="mt-1 font-medium text-amber-700 dark:text-amber-300">{{ __('Masih kurang bayar Rp :n untuk barang yang sudah datang.', ['n' => number_format(-$selisihBayar, 0, ',', '.')]) }}</div>
                    @endif
                </div>
                <flux:text>{{ __('Sisa barangnya kembali bisa dipesan dari PR :pr, misalnya ke supplier lain.', ['pr' => $po->permintaan?->nomor]) }}</flux:text>
                <flux:field>
                    <flux:label>{{ __('Alasan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:textarea wire:model="alasanTutup" rows="3" :placeholder="__('mis. Stok supplier habis, sisa daun pintu tidak bisa dikirim')" />
                    <flux:error name="alasanTutup" />
                </flux:field>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" type="button">{{ __('Tidak') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Ya, tutup PO') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- MODAL BATAL --}}
        <flux:modal name="batal-po" class="md:w-md">
            <form wire:submit="batalkan" class="space-y-5">
                <flux:heading size="lg">{{ __('Batalkan PO ini?') }}</flux:heading>
                <flux:field>
                    <flux:label>{{ __('Alasan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:textarea wire:model="alasanBatal" rows="3" />
                    <flux:error name="alasanBatal" />
                </flux:field>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" type="button">{{ __('Tidak') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" type="submit">{{ __('Ya, batalkan PO') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
</section>
