<?php

use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\SprTerminPembayaran;
use App\Support\BusinessActivityLogger;
use App\Support\FileOptimizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail SPR')] class extends Component
{
    use WithFileUploads;

    public Spr $spr;

    #[Url(as: 'tab', except: 'spr')]
    public string $activeTab = 'spr';

    // Modal Tambah Transaksi (realisasi UM — jumlah bebas, auto FIFO alokasi)
    public ?string $trxTanggal = null;

    public string $trxJumlah = '0';

    public string $trxMetode = 'transfer';

    public ?string $trxKeterangan = null;

    // Modal Edit Realisasi UM (koreksi kwitansi yg sudah tercatat)
    public ?int $editRealisasiId = null;

    public ?string $editRealisasiTanggal = null;

    public string $editRealisasiJumlah = '0';

    public string $editRealisasiMetode = 'transfer';

    public ?string $editRealisasiKeterangan = null;

    // Upload dokumen SPR ttd + meterai dari customer
    public $dokumenSignedFile = null;

    public function mount(int $id): void
    {
        $this->loadSpr($id);
    }

    private function loadSpr(int $id): void
    {
        $this->spr = Spr::with([
            'prospectCustomer.tempatKerja',
            'prospectCustomer.bank',
            'rumah.tipeRumah',
            'rumah.proyek',
            'rumah.virtualAccount.bank',
            'sales',
            'bankKpr',
            'utjConfirmedBy',
            'approvedBy',
            'pmApprovedBy',
            'dokumenSignedBy',
            'terminPembayaran',
            'realisasiPembayaran.inputBy',
            'switchedFromSpr.rumah',
            'switchedToSpr.rumah',
        ])->findOrFail($id);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['spr', 'rincian'], true) ? $tab : 'spr';
    }

    public function printPdf()
    {
        if ($this->spr->status !== 'approved') {
            Flux::toast(variant: 'warning', text: 'Hanya SPR berstatus Disetujui yang bisa dicetak.');
            return null;
        }

        if (! $this->spr->pm_approved_at) {
            Flux::toast(variant: 'warning', text: 'SPR belum disetujui Project Manager. Cetak dinonaktifkan sampai persetujuan selesai.');
            return null;
        }

        $pdf = Pdf::loadView('exports.spr-print', ['spr' => $this->spr])
            ->setPaper('a4', 'portrait');

        $filename = str_replace('/', '-', $this->spr->nomor_spr).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
        );
    }

    public function openTambahTransaksi(): void
    {
        $this->reset(['trxTanggal', 'trxJumlah', 'trxMetode', 'trxKeterangan']);
        $this->trxTanggal = now()->format('Y-m-d');
        $this->trxMetode = 'transfer';
        $this->resetErrorBag();
        Flux::modal('tambah-transaksi')->show();
    }

    /** Upload dokumen SPR yang sudah TTD + tempel meterai oleh customer. */
    public function uploadDokumenSigned(): void
    {
        $this->validate([
            'dokumenSignedFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        // Hapus file lama kalau ada
        if ($this->spr->dokumen_signed_path && Storage::disk('public')->exists($this->spr->dokumen_signed_path)) {
            Storage::disk('public')->delete($this->spr->dokumen_signed_path);
        }

        $path = FileOptimizer::storeOptimized($this->dokumenSignedFile, 'spr-signed');

        $this->spr->update([
            'dokumen_signed_path' => $path,
            'dokumen_signed_at' => now(),
            'dokumen_signed_by_user_id' => Auth::id(),
        ]);

        $this->dokumenSignedFile = null;
        $this->loadSpr($this->spr->id);
        Flux::toast(text: __('Dokumen SPR ber-TTD & meterai berhasil di-upload.'), variant: 'success');
    }

    /** Hapus dokumen SPR TTD (kalau salah upload). */
    public function hapusDokumenSigned(): void
    {
        if ($this->spr->dokumen_signed_path && Storage::disk('public')->exists($this->spr->dokumen_signed_path)) {
            Storage::disk('public')->delete($this->spr->dokumen_signed_path);
        }
        $this->spr->update([
            'dokumen_signed_path' => null,
            'dokumen_signed_at' => null,
            'dokumen_signed_by_user_id' => null,
        ]);
        $this->loadSpr($this->spr->id);
        Flux::toast(text: __('Dokumen dihapus.'), variant: 'success');
    }

    /**
     * Prefill jumlah = sisa UM (customer lunas semua sisanya).
     */
    public function prefillLunas(): void
    {
        $totalUmDibayar = (float) $this->spr->realisasiPembayaran->where('jenis', 'um')->sum('jumlah');
        $sisaUm = max(0, (float) $this->spr->um_net - $totalUmDibayar);
        $this->trxJumlah = (string) $sisaUm;
    }

    public function saveTransaksi(): void
    {
        // Komersial: UM langsung ke bank, bukan ke developer. Block realisasi UM.
        if ($this->spr->kategori === 'komersial') {
            Flux::toast(variant: 'danger', text: 'SPR komersial tidak menerima realisasi UM ke developer.');
            return;
        }

        $validated = $this->validate([
            'trxTanggal' => ['required', 'date'],
            'trxJumlah' => ['required', 'numeric', 'min:1'],
            'trxMetode' => ['required', 'in:transfer,tunai'],
            'trxKeterangan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'trxTanggal' => 'tanggal transaksi',
            'trxJumlah' => 'jumlah transaksi',
            'trxMetode' => 'metode pembayaran',
        ]);

        // Cap jumlah supaya tidak lebih dari sisa UM.
        $totalUmDibayar = (float) $this->spr->realisasiPembayaran->where('jenis', 'um')->sum('jumlah');
        $sisaUm = max(0, (float) $this->spr->um_net - $totalUmDibayar);

        if ($sisaUm <= 0) {
            Flux::toast(variant: 'warning', text: 'UM sudah lunas. Tidak bisa tambah transaksi UM lagi.');
            return;
        }

        $jumlah = min((float) $validated['trxJumlah'], $sisaUm);

        // Wrap DB::transaction supaya lockForUpdate di generateNextNomor efektif
        // (mencegah race condition pada nomor kwitansi saat concurrent insert).
        $realisasi = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $jumlah) {
            return SprRealisasiPembayaran::create([
                'spr_id' => $this->spr->id,
                'jenis' => 'um',
                'tanggal_bayar' => $validated['trxTanggal'],
                'jumlah' => $jumlah,
                'nomor_kwitansi' => SprRealisasiPembayaran::generateNextNomor(),
                'metode' => $validated['trxMetode'],
                'keterangan' => $validated['trxKeterangan'] ?: null,
                'input_by_user_id' => Auth::id(),
            ]);
        });

        $realisasi->load('spr.prospectCustomer');
        BusinessActivityLogger::realisasiCreated($realisasi);

        Flux::modal('tambah-transaksi')->close();
        $this->loadSpr($this->spr->id);

        Flux::toast(variant: 'success', text: 'Realisasi UM Rp '.number_format($jumlah, 0, ',', '.').' tercatat.');
    }

    public function openEditRealisasi(int $id): void
    {
        abort_unless(Auth::user()?->can('pembayaran.kelola'), 403);

        $r = SprRealisasiPembayaran::where('spr_id', $this->spr->id)
            ->where('jenis', 'um')
            ->findOrFail($id);

        $this->editRealisasiId = $r->id;
        $this->editRealisasiTanggal = $r->tanggal_bayar?->format('Y-m-d');
        $this->editRealisasiJumlah = (string) (int) (float) $r->jumlah;
        $this->editRealisasiMetode = $r->metode ?: 'transfer';
        $this->editRealisasiKeterangan = $r->keterangan;
        $this->resetErrorBag();
        Flux::modal('edit-realisasi')->show();
    }

    public function saveEditRealisasi(): void
    {
        abort_unless(Auth::user()?->can('pembayaran.kelola'), 403);

        $validated = $this->validate([
            'editRealisasiTanggal' => ['required', 'date'],
            'editRealisasiJumlah' => ['required', 'numeric', 'min:1'],
            'editRealisasiMetode' => ['required', 'in:transfer,tunai'],
            'editRealisasiKeterangan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'editRealisasiTanggal' => 'tanggal',
            'editRealisasiJumlah' => 'jumlah',
            'editRealisasiMetode' => 'metode pembayaran',
        ]);

        $r = SprRealisasiPembayaran::where('spr_id', $this->spr->id)
            ->where('jenis', 'um')
            ->findOrFail($this->editRealisasiId);

        $newJumlah = (float) $validated['editRealisasiJumlah'];

        // Cap: jumlah + total realisasi UM lain (exclude row ini) tidak boleh > um_net.
        $totalUmLain = (float) SprRealisasiPembayaran::where('spr_id', $this->spr->id)
            ->where('jenis', 'um')
            ->where('id', '!=', $r->id)
            ->sum('jumlah');
        $maxAllowed = max(0, (float) $this->spr->um_net - $totalUmLain);
        if ($newJumlah > $maxAllowed) {
            $this->addError('editRealisasiJumlah', 'Jumlah melebihi sisa UM (maks Rp '.number_format($maxAllowed, 0, ',', '.').').');

            return;
        }

        $old = [
            'tanggal_bayar' => $r->tanggal_bayar?->format('Y-m-d'),
            'jumlah' => (float) $r->jumlah,
            'metode' => $r->metode,
            'keterangan' => $r->keterangan,
        ];

        $r->update([
            'tanggal_bayar' => $validated['editRealisasiTanggal'],
            'jumlah' => $newJumlah,
            'metode' => $validated['editRealisasiMetode'],
            'keterangan' => $validated['editRealisasiKeterangan'] ?: null,
        ]);

        BusinessActivityLogger::realisasiUpdated($r, $old);

        Flux::modal('edit-realisasi')->close();
        $this->loadSpr($this->spr->id);
        $this->reset(['editRealisasiId', 'editRealisasiTanggal', 'editRealisasiJumlah', 'editRealisasiMetode', 'editRealisasiKeterangan']);

        Flux::toast(variant: 'success', text: 'Realisasi kwitansi '.$r->nomor_kwitansi.' diperbarui.');
    }
}; ?>

@php
    $spr = $this->spr;

    // Kalau SPR ini sudah dipindah ke SPR baru (Pindah Kavling) → override realisasi
    // dengan kwitansi historis (yg dulu di SPR ini, sekarang link via switching_id).
    // Dilakukan di render (bukan di mount) supaya selalu fresh & lolos Livewire dehydrate.
    if ($spr->switched_to_spr_id) {
        $spr->setRelation('realisasiPembayaran', $spr->realisasiHistoris()->load('inputBy'));
    }

    $prospect = $spr->prospectCustomer;
    $rumah = $spr->rumah;
    $tipe = $rumah?->tipeRumah;
    $proyek = $rumah?->proyek;
    $sales = $spr->sales;
    $termins = $spr->terminPembayaran->sortBy([['jenis', 'asc'], ['urutan', 'asc']])->values();
    [$badgeLabel, $badgeCls] = $spr->statusBadge();

    $alamatProspect = collect([
        $prospect?->alamat,
        $prospect?->kelurahan_nama ? 'Kelurahan '.$prospect->kelurahan_nama : null,
        $prospect?->kecamatan_nama ? 'Kec. '.$prospect->kecamatan_nama : null,
        $prospect?->kota_nama ? 'Dati II. '.$prospect->kota_nama : null,
        $prospect?->provinsi_nama ? 'Provinsi. '.$prospect->provinsi_nama : null,
    ])->filter()->implode(', ');

    $alamatKantor = $prospect?->tempatKerja?->alamat;

    // Sumber pembayaran = spr_realisasi_pembayaran (bukan lagi termin).
    // um_net sudah termasuk UTJ (booking fee = bagian dari UM). Sisa UM = um_net - total yang sudah masuk (UTJ + UM cicilan).
    $totalUmDibayar = (float) $spr->realisasiPembayaran->where('jenis', 'um')->sum('jumlah');
    $utjDibayar = (float) $spr->realisasiPembayaran->where('jenis', 'bf')->sum('jumlah');
    $totalDibayar = $totalUmDibayar + $utjDibayar;
    $kurangUm = max(0, (float) $spr->um_net - $totalDibayar);

    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <a href="{{ route('marketing.spr.list') }}" wire:navigate
                   class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                   title="{{ __('Kembali ke Data SPR') }}">
                    <flux:icon.arrow-left class="size-4" />
                </a>
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-emerald-500 to-emerald-700 text-white shadow-sm">
                        <flux:icon.document-text class="size-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Detail SPR') }}</flux:heading>
                            <span @class([
                                'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                $badgeCls,
                            ])>{{ $badgeLabel }}</span>
                        </div>
                        <flux:subheading class="font-mono">{{ $spr->nomor_display }}</flux:subheading>
                    </div>
                </div>
            </div>

            <a href="{{ route('marketing.spr.list') }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800">
                <flux:icon.magnifying-glass class="size-3.5" />
                {{ __('Cari SPR Lain') }}
            </a>
        </div>

        {{-- BANNER SWITCHING (Pindah Kavling) --}}
        @php $switching = $spr->switching(); @endphp
        @if ($switching)
            @if ($switching->spr_lama_a_id === $spr->id || $switching->spr_lama_b_id === $spr->id)
                @php
                    $sprBaru = $switching->spr_lama_a_id === $spr->id ? $switching->sprBaruA : $switching->sprBaruB;
                @endphp
                <div class="mb-4 rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-900 dark:bg-orange-950/30">
                    <div class="flex items-start gap-2">
                        <flux:icon.arrows-right-left class="mt-0.5 size-4 shrink-0 text-orange-600" />
                        <div class="flex-1 text-xs text-orange-800 dark:text-orange-200">
                            <div class="font-bold">{{ __('SPR ini sudah dipindah (Pindah Kavling)') }}</div>
                            <div class="mt-0.5">
                                Nomor referensi: <span class="font-mono font-bold">{{ $switching->nomor_switching }}</span>
                                · Diproses {{ $switching->processed_at?->translatedFormat('d M Y H:i') }}
                                · Oleh {{ $switching->processedBy?->name ?? '—' }}
                            </div>
                            <div class="mt-1">
                                {{ __('SPR baru:') }}
                                <a href="{{ route('marketing.spr.show', $sprBaru->id) }}" wire:navigate class="font-mono font-bold underline">
                                    {{ $sprBaru?->nomor_display }} (unit {{ $sprBaru?->rumah?->kode_unit }})
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @elseif ($switching->spr_baru_a_id === $spr->id || $switching->spr_baru_b_id === $spr->id)
                @php
                    $sprLama = $switching->spr_baru_a_id === $spr->id ? $switching->sprLamaA : $switching->sprLamaB;
                @endphp
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/30">
                    <div class="flex items-start gap-2">
                        <flux:icon.sparkles class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                        <div class="flex-1 text-xs text-emerald-800 dark:text-emerald-200">
                            <div class="font-bold">{{ __('SPR ini hasil Pindah Kavling') }}</div>
                            <div class="mt-0.5">
                                Nomor referensi: <span class="font-mono font-bold">{{ $switching->nomor_switching }}</span>
                                · Diproses {{ $switching->processed_at?->translatedFormat('d M Y H:i') }}
                            </div>
                            <div class="mt-1">
                                {{ __('Berasal dari SPR lama:') }}
                                <a href="{{ route('marketing.spr.show', $sprLama->id) }}" wire:navigate class="font-mono font-bold underline">
                                    {{ $sprLama?->nomor_display }} (unit {{ $sprLama?->rumah?->kode_unit }})
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        {{-- TABS --}}
        <div class="mb-4 flex items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
            <button type="button" wire:click="setTab('spr')"
                    @class([
                        'flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-bold transition -mb-px',
                        'border-emerald-600 text-emerald-700 dark:border-emerald-400 dark:text-emerald-400' => $activeTab === 'spr',
                        'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200' => $activeTab !== 'spr',
                    ])>
                <flux:icon.document class="size-4" />
                {{ __('SPR') }}
            </button>
            <button type="button" wire:click="setTab('rincian')"
                    @class([
                        'flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-bold transition -mb-px',
                        'border-emerald-600 text-emerald-700 dark:border-emerald-400 dark:text-emerald-400' => $activeTab === 'rincian',
                        'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200' => $activeTab !== 'rincian',
                    ])>
                <flux:icon.calculator class="size-4" />
                {{ __('Rincian Harga') }}
            </button>
        </div>

        {{-- ============ TAB: SPR PREVIEW ============ --}}
        @if ($activeTab === 'spr')
            @include('pages::marketing._spr-document')

            {{-- ============ LEGACY PREVIEW (dinonaktifkan, dipindah ke partial) ============ --}}
            @if (false)
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">

                {{-- Document title --}}
                <div class="border-b border-zinc-200 px-6 py-5 text-center dark:border-zinc-700">
                    <h2 class="text-base font-extrabold uppercase tracking-wide text-zinc-900 underline decoration-2 underline-offset-4 dark:text-white">
                        {{ __('Surat Pemesanan dan Konfirmasi Pembelian Rumah') }}
                    </h2>
                </div>

                {{-- Date + No SPR --}}
                <div class="grid grid-cols-1 gap-3 border-b border-zinc-200 bg-zinc-50/50 px-6 py-3 text-xs dark:border-zinc-700 dark:bg-zinc-800/30 md:grid-cols-2">
                    <div>
                        <span class="text-zinc-500">{{ __('Tanggal') }} :</span>
                        <span class="ms-2 font-bold text-zinc-900 dark:text-white">{{ $spr->tanggal_spr?->format('d/m/Y') }}</span>
                    </div>
                    <div class="md:text-right">
                        <span class="text-zinc-500">{{ __('Nomor SPR') }} :</span>
                        <span class="ms-2 font-mono font-bold text-zinc-900 dark:text-white">{{ $spr->nomor_display }}</span>
                    </div>
                </div>

                {{-- 2-column: Customer info (left) + Syarat (right) --}}
                <div class="grid grid-cols-1 gap-x-8 gap-y-5 px-6 py-5 md:grid-cols-2">

                    {{-- LEFT: Identitas pemesan --}}
                    <div>
                        <h3 class="mb-3 text-sm font-bold text-zinc-900 dark:text-white">{{ __('Yang bertanda tangan dibawah ini') }}</h3>
                        <table class="w-full text-xs">
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr>
                                    <td class="w-6 py-2 align-top text-zinc-500">1.</td>
                                    <td class="w-28 py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('Nama') }}</td>
                                    <td class="w-3 py-2 align-top text-zinc-400">:</td>
                                    <td class="py-2 font-semibold text-zinc-900 dark:text-white">{{ $prospect?->nama_lengkap }}</td>
                                </tr>
                                <tr>
                                    <td class="py-2 align-top text-zinc-500">2.</td>
                                    <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('No KTP') }}</td>
                                    <td class="py-2 align-top text-zinc-400">:</td>
                                    <td class="py-2 font-mono font-semibold text-zinc-900 dark:text-white">{{ $prospect?->nik ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td class="py-2 align-top text-zinc-500">3.</td>
                                    <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('Alamat') }}</td>
                                    <td class="py-2 align-top text-zinc-400">:</td>
                                    <td class="py-2 text-zinc-900 dark:text-white">{{ $alamatProspect ?: '—' }}</td>
                                </tr>
                                <tr>
                                    <td class="py-2 align-top text-zinc-500">4.</td>
                                    <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('Nama Kantor') }}</td>
                                    <td class="py-2 align-top text-zinc-400">:</td>
                                    <td class="py-2 text-zinc-900 dark:text-white">
                                        @if ($prospect?->tempatKerja?->nama)
                                            <span class="font-semibold">{{ $prospect->tempatKerja->nama }}</span>
                                            @if ($alamatKantor)
                                                <div class="mt-0.5 text-zinc-600 dark:text-zinc-400">{{ $alamatKantor }}</div>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-2 align-top text-zinc-500">5.</td>
                                    <td class="py-2 align-top text-zinc-600 dark:text-zinc-400">{{ __('No Telp') }}</td>
                                    <td class="py-2 align-top text-zinc-400">:</td>
                                    <td class="py-2 text-zinc-900 dark:text-white">
                                        <div class="grid grid-cols-[80px_1fr] gap-y-1">
                                            <span class="text-zinc-500">{{ __('HP') }}</span>
                                            <span class="font-mono font-semibold">{{ $prospect?->hp ?? '—' }}</span>
                                            <span class="text-zinc-500">{{ __('Kantor') }}</span>
                                            <span class="font-mono">{{ $prospect?->hp_2 ?? '—' }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="mt-3 text-[11px] italic text-zinc-600 dark:text-zinc-400">
                            {{ __('Dengan ini memesan untuk membeli sebidang tanah diatasnya di perumahan') }}
                            <strong class="not-italic text-zinc-900 dark:text-white">{{ $proyek?->nama_proyek ?? '—' }}</strong>
                            {{ __('dengan syarat dan ketentuan:') }}
                        </div>

                        {{-- Info unit (I. Detail Unit) --}}
                        <div class="mt-4">
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                                    {{ __('I. Unit & Harga') }}
                                </h3>
                                {{-- Unit info --}}
                                <div class="grid grid-cols-3 gap-2 border-b border-zinc-200 px-3 py-2 text-xs dark:border-zinc-700">
                                    <div><span class="text-zinc-500">Blok/No</span> <span class="ms-1 font-mono font-bold">{{ $rumah?->blok }}/{{ $rumah?->nomor_unit }}</span></div>
                                    <div><span class="text-zinc-500">Type</span> <span class="ms-1 font-bold">{{ $tipe?->nama_tipe ?? '—' }}</span></div>
                                    <div><span class="text-zinc-500">LT/LB</span> <span class="ms-1 font-mono">{{ (int) ($rumah?->luas_tanah ?? 0) }}/{{ (int) ($rumah?->luas_bangunan ?? 0) }}</span></div>
                                </div>

                                {{-- Harga breakdown --}}
                                @php
                                    $kategoriLabel = $spr->kategori === 'subsidi' ? 'Subsidi' : 'Komersil';
                                @endphp
                                <table class="w-full text-xs">
                                    <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        <tr>
                                            <th class="px-3 py-1.5 text-left font-semibold"></th>
                                            <th class="px-3 py-1.5 text-right font-semibold">{{ $kategoriLabel }}</th>
                                            <th class="w-10 px-2 py-1.5 text-center font-semibold"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        <tr>
                                            <td class="px-3 py-1.5 text-zinc-600">Harga JUAL/AJB</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums font-semibold">Rp {{ $fmt($spr->harga_jual) }}</td>
                                            <td class="px-2 py-1.5 text-center text-zinc-400"></td>
                                        </tr>
                                        <tr>
                                            <td class="px-3 py-1.5 text-zinc-600">PPN</td>
                                            @if ((float) $spr->ppn > 0)
                                                <td class="px-3 py-1.5 text-right font-mono tabular-nums font-semibold text-amber-600">Rp {{ $fmt($spr->ppn) }}</td>
                                            @else
                                                <td class="px-3 py-1.5 text-right font-mono tabular-nums text-zinc-400">Rp 0</td>
                                            @endif
                                            <td class="px-2 py-1.5 text-center text-zinc-500">+</td>
                                        </tr>
                                        @if ((float) $spr->biaya_tambahan > 0)
                                            <tr>
                                                <td class="px-3 py-1.5 text-zinc-600">Biaya Administrasi</td>
                                                <td class="px-3 py-1.5 text-right font-mono tabular-nums font-semibold">Rp {{ $fmt($spr->biaya_tambahan) }}</td>
                                                <td class="px-2 py-1.5 text-center text-zinc-500">+/+</td>
                                            </tr>
                                        @endif
                                        @if ((float) $spr->diskon > 0)
                                            <tr>
                                                <td class="px-3 py-1.5 text-zinc-600">Diskon</td>
                                                <td class="px-3 py-1.5 text-right font-mono tabular-nums text-rose-600">(Rp {{ $fmt($spr->diskon) }})</td>
                                                <td class="px-2 py-1.5 text-center text-zinc-500">−</td>
                                            </tr>
                                        @endif
                                        <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                            <td class="px-3 py-1.5 font-bold">Harga Jual All In</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums font-bold">Rp {{ $fmt($spr->total_harga) }}</td>
                                            <td class="px-2 py-1.5 text-center text-zinc-500">+</td>
                                        </tr>
                                        <tr>
                                            <td class="px-3 py-1.5 text-zinc-600">KPR</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums">Rp {{ $fmt($spr->nilai_kpr) }}</td>
                                            <td class="px-2 py-1.5 text-center text-zinc-500">−/−</td>
                                        </tr>
                                        <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                                            <td class="px-3 py-1.5 font-bold">Total UM</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums font-bold">Rp {{ $fmt($spr->dp_nominal) }}</td>
                                            <td class="px-2 py-1.5"></td>
                                        </tr>
                                        @if ((float) $spr->sbum > 0)
                                            <tr>
                                                <td class="px-3 py-1.5 text-zinc-600">SBUM</td>
                                                <td class="px-3 py-1.5 text-right font-mono tabular-nums">Rp {{ $fmt($spr->sbum) }}</td>
                                                <td class="px-2 py-1.5"></td>
                                            </tr>
                                        @endif
                                        <tr class="bg-orange-50 dark:bg-orange-950/30">
                                            <td class="px-3 py-1.5 font-bold text-orange-900 dark:text-orange-300">Diskon UM (UM Sendiri)</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums font-extrabold text-orange-700 dark:text-orange-300">Rp {{ $fmt($spr->um_net) }}</td>
                                            <td class="px-2 py-1.5"></td>
                                        </tr>
                                        <tr class="bg-emerald-50 dark:bg-emerald-950/30">
                                            <td class="px-3 py-1.5 font-bold text-emerald-900 dark:text-emerald-300">UTJ</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums font-bold text-emerald-700 dark:text-emerald-300">Rp {{ $fmt($spr->utj_nominal) }}</td>
                                            <td class="px-2 py-1.5 text-center text-[10px] text-emerald-700 dark:text-emerald-400">
                                                Tgl<br><span class="font-bold">{{ $spr->utj_tanggal_transaksi?->format('d/m/y') ?? '—' }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            {{-- Jadwal/Termin Pembayaran --}}
                            <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                                    {{ __('II. Jadwal / Termin Pembayaran') }}
                                </h3>
                                <table class="w-full text-xs">
                                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-800/30 dark:text-zinc-400">
                                        <tr>
                                            <th class="w-12 px-2 py-1.5 text-center font-semibold">Ke</th>
                                            <th class="px-2 py-1.5 text-left font-semibold">Uang Muka</th>
                                            <th class="px-2 py-1.5 text-left font-semibold">Tanggal</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @php
                                            $umTermins = $termins->where('jenis', 'um')->sortBy('urutan')->values();
                                        @endphp
                                        @for ($i = 1; $i <= 5; $i++)
                                            @php $t = $umTermins->firstWhere('urutan', $i); @endphp
                                            <tr>
                                                <td class="px-2 py-1.5 text-center font-mono font-bold">ke {{ $i }}</td>
                                                <td class="px-2 py-1.5 font-mono">
                                                    @if ($t)
                                                        Rp {{ $fmt($t->jumlah_jadwal) }}
                                                    @else
                                                        <span class="text-zinc-300">Rp.</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-1.5">
                                                    @if ($t?->tanggal_jadwal)
                                                        {{ $t->tanggal_jadwal->format('d-M-y') }}
                                                    @else
                                                        <span class="text-zinc-300">Tgl :</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endfor
                                    </tbody>
                                </table>
                            </div>

                            {{-- III. Harga Jual All In Sudah Termasuk Biaya Administrasi --}}
                            <div class="mt-4 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                                    {{ __('III. Harga Jual All In Sudah Termasuk Biaya Administrasi') }} :
                                </h3>
                                <div class="space-y-1.5 px-3 py-2 text-[11px]">
                                    <div class="flex gap-2">
                                        <span class="w-16 shrink-0 text-zinc-500">- Notaris</span>
                                        <span class="text-zinc-700 dark:text-zinc-300">: Biaya AJB, Validasi BPHTB, BN</span>
                                    </div>
                                    <div class="flex gap-2">
                                        <span class="w-16 shrink-0 text-zinc-500">- Umum</span>
                                        <span class="text-zinc-700 dark:text-zinc-300">: Biaya Splitzing Sertifikat, Biaya Utilities, Biaya KPR</span>
                                    </div>
                                    <div class="flex gap-2">
                                        <span class="w-16 shrink-0 text-zinc-500">- Pajak</span>
                                        <span class="text-zinc-700 dark:text-zinc-300">: BPHTB, PBB, IMB, PPN dan Biaya-biaya resmi sesuai ketentuan pemerintah</span>
                                    </div>
                                </div>
                            </div>

                            {{-- IV. Harga Belum Termasuk Biaya --}}
                            <div class="mt-3 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                                    {{ __('IV. Harga Belum Termasuk Biaya') }} :
                                </h3>
                                <div class="space-y-1.5 px-3 py-2 text-[11px]">
                                    <div class="flex items-baseline gap-2">
                                        <span class="whitespace-nowrap text-zinc-500">- Materai</span>
                                        <span class="flex-1 border-b border-dotted border-zinc-300 dark:border-zinc-600"></span>
                                        <span class="whitespace-nowrap font-mono text-zinc-700 dark:text-zinc-300">: Rp.</span>
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <span class="whitespace-nowrap text-zinc-500">- Saldo mengendap ( persyaratan Bank )</span>
                                        <span class="flex-1 border-b border-dotted border-zinc-300 dark:border-zinc-600"></span>
                                        <span class="whitespace-nowrap font-mono text-zinc-700 dark:text-zinc-300">: Rp.</span>
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <span class="whitespace-nowrap text-zinc-500">- Harga Kelebihan Tanah (jika ada)</span>
                                        <span class="flex-1 border-b border-dotted border-zinc-300 dark:border-zinc-600"></span>
                                        <span class="whitespace-nowrap font-mono text-zinc-700 dark:text-zinc-300">: Rp.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- RIGHT: Syarat & Kondisi --}}
                    <div>
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <h3 class="border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-white">
                                {{ __('V. Syarat dan Kondisi') }}
                            </h3>
                            <div class="px-4 py-3">
                                @php $vaListSyarat = $spr->rumah?->virtualAccount ?? collect(); @endphp
                                <ol class="space-y-2 text-[11px] leading-relaxed text-zinc-700 dark:text-zinc-300" style="list-style-type: decimal; padding-left: 18px;">
                                    <li>{{ __('Pihak pembeli wajib membayar uang tanda jadi (UTJ) dan uang muka sesuai ketentuan pada poin I SPKP (disepakati) selambat-lambatnya dalam waktu 15 (lima belas) hari kalender sejak SPKP ini di tandatangani pembeli. Apabila pembayaran uang muka ke-1 pada SPKP tidak dilakukan dalam waktu 15 (lima belas) hari kalender, maka pembeli dianggap membatalkan secara sepihak dan uang tanda jadi yang telah di bayarkan tidak dapat dikembalikan (hangus).') }}</li>
                                    <li>{{ __('Pembeli wajib menyiapkan dan menyerahkan kelengkapan berkas pembelian dalam waktu 15 (lima belas) hari kalender sejak pembayaran uang tanda jadi, apabila dalam kurun waktu tersebut pembeli belum menyerahkan berkas kepada Developer maka pembeli dianggap membatalkan pembelian secara sepihak.') }}</li>
                                    <li>{{ __('Apabila pihak pembeli dengan alasan apapun juga telah lalai atau tidak dapat melunasi/memenuhi pembayaran uang muka sesuai waktu/tanggal yang telah di tetapkan dan disepakati sesuai JADWAL TERMIN PEMBAYARAN (Poin II SPKP) serta tidak menyerahkan data-data/berkas KPR sesuai jadwal waktu pada ayat (2) diatas sehingga mengakibatkan pembatalan pembelian unit rumah, maka uang tanda jadi yang telah dibayarkan tidak dapat dikembalikan (hangus) dan seluruh uang muka yang telah dibayarkan dapat dikembalikan setelah dipotong biaya administrasi sebesar Rp. 2.000.000,- (dua juta rupiah).') }}</li>
                                    <li>{{ __('Apabila terjadi keterlambatan pembayaran uang muka dari jadwal yang telah ditentukan, akan dikenakan denda/bunga sebesar 3% (tiga persen) per bulan (1% per hari), dan jika keterlambatan tersebut berlangsung selama 2 (dua) bulan berturut-turut sejak pembayaran uang muka ke-2, maka Pihak Pembeli dianggap membatalkan secara sepihak (sesuai ayat 3).') }}</li>
                                    <li>{{ __('Pindah kaviling atau ganti nama setelah uang tanda jadi masuk dikenakan biaya tambahan sebesar Rp. 1.000.000,-/unit.') }}</li>
                                    <li>{!! __('Apabila terjadi pembatalan KPR oleh Bank (unit <strong>Subsidi dan Komersil</strong>), maka uang muka dikembalikan 100% dan untuk uang tanda jadi yang telah dibayarkan tidak dapat dikembalikan (hangus) sesuai ayat 1.') !!}</li>
                                    <li>{{ __('Dalam hal terdapat perbedaan luas tanah, antara luas tanah yang tertulis di SPKP dengan luas hasil pengukuran instansi berwenang (BPN) atau luas yang tercantum dalam buku sertifikat sampai batas toleransi 3% (tiga persen), maka kedua belah pihak setuju dan sepakat tidak akan melakukan penuntutan apapun juga serta menerima luas berdasarkan buku Sertifikat tanah yang terbit.') }}</li>
                                    <li>
                                        {{ __('Pembayaran :') }}
                                        <div class="mt-1 space-y-0.5 pl-3">
                                            <div>
                                                a. {!! __('Semua Pembayaran harus dilakukan di kasir atau transfer ke Virtual Account A/n <strong>PT. LANGIT MEMBANGUN INDONESIA</strong> :') !!}
                                                @if ($vaListSyarat->isNotEmpty())
                                                    <div class="mt-0.5 space-y-0.5 pl-3">
                                                        @foreach ($vaListSyarat as $va)
                                                            <div>{{ $va->bank?->nama ?? 'Bank' }} VA : <strong class="font-mono">{{ $va->nomor_va }}</strong></div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                            <div>b. {{ __('Bukti Transfer/bukti setor Bank harap diberikan kepada kasir Developer.') }}</div>
                                            <div>c. {{ __('Kwitansi resmi dapat diperoleh di kasir Perusahaan dengan membawa bukti transfer Bank/bukti setor Bank untuk ditukarkan.') }}</div>
                                            <div>d. {{ __('Pembayaran dianggap sah apabila sudah dilakukan validasi oleh kasir Developer.') }}</div>
                                        </div>
                                    </li>
                                    <li>{{ __('Developer tidak bertanggung jawab atas semua pembayaran yang di lakukan pembeli diluar ketentuan pada poin 8 diatas.') }}</li>
                                </ol>

                                <div class="mt-4 text-[11px] italic text-zinc-600 dark:text-zinc-400">
                                    {{ __('Demikian Surat Pesanan dan Konfirmasi Pembayaran ini dibuat, dan telah disetujui oleh pemesan untuk dapat digunakan sebagaimana mestinya.') }}
                                </div>

                                {{-- Pemesan (customer) — materai + nama, di bawah paragraf "Demikian", right-aligned --}}
                                <div class="mt-4 flex justify-end">
                                    <div class="flex flex-col items-center gap-2">
                                        <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-200">
                                            {{ __('Pemesan') }}
                                        </div>
                                        <div class="flex h-16 w-14 items-center justify-center rounded border-2 border-dashed border-zinc-300 text-center text-[9px] italic leading-tight text-zinc-400 dark:border-zinc-600">
                                            Materai<br>Tempel
                                        </div>
                                        <div class="mt-4 border-t border-zinc-400 px-8 pt-1 text-xs font-bold text-zinc-900 dark:border-zinc-500 dark:text-white">
                                            ( {{ $prospect?->nama_lengkap ?? '—' }} )
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ============ TANDA TANGAN OTORISASI (Sales / Keuangan / PM) ============ --}}
                @php
                    $ttdBoxes = [
                        [
                            'role'      => 'Sales',
                            'name'      => $spr->sales?->nama,
                            'path'      => $spr->ttd_sales_path,
                            'color'     => 'orange',
                            'stampAt'   => $spr->created_at,
                        ],
                        [
                            'role'      => 'Keuangan',
                            'name'      => $spr->utjConfirmedBy?->name,
                            'path'      => $spr->ttd_finance_path,
                            'color'     => 'emerald',
                            'stampAt'   => $spr->utj_confirmed_at,
                        ],
                        [
                            'role'      => 'Project Manager',
                            'name'      => $spr->pmApprovedBy?->name,
                            'path'      => $spr->ttd_pm_path,
                            'color'     => 'violet',
                            'stampAt'   => $spr->pm_approved_at,
                        ],
                    ];
                    $ttdColor = [
                        'orange'  => 'bg-orange-50 border-orange-200 text-orange-700 dark:bg-orange-950/20 dark:border-orange-900/50 dark:text-orange-300',
                        'emerald' => 'bg-emerald-50 border-emerald-200 text-emerald-700 dark:bg-emerald-950/20 dark:border-emerald-900/50 dark:text-emerald-300',
                        'violet'  => 'bg-violet-50 border-violet-200 text-violet-700 dark:bg-violet-950/20 dark:border-violet-900/50 dark:text-violet-300',
                    ];
                @endphp
                <div class="mx-6 mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @foreach ($ttdBoxes as $ttd)
                        <div class="overflow-hidden rounded-lg border {{ $ttdColor[$ttd['color']] }}">
                            <div class="border-b border-current/20 px-3 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider">
                                {{ __($ttd['role']) }}
                            </div>
                            <div class="flex h-24 items-center justify-center bg-white p-2 dark:bg-zinc-900">
                                @if ($ttd['path'])
                                    <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($ttd['path']) }}"
                                         alt="ttd-{{ $ttd['role'] }}"
                                         class="max-h-full max-w-full object-contain">
                                @else
                                    <div class="flex flex-col items-center gap-1 text-center">
                                        <flux:icon.pencil-square class="size-5 text-zinc-300 dark:text-zinc-700" />
                                        <span class="text-[9px] italic text-zinc-400">{{ __('belum ditandatangani') }}</span>
                                    </div>
                                @endif
                            </div>
                            <div class="border-t border-current/20 bg-white px-3 py-1.5 text-center dark:bg-zinc-900">
                                <div class="text-[11px] font-bold text-zinc-900 dark:text-white">
                                    ( {{ $ttd['name'] ?? '—' }} )
                                </div>
                                @if ($ttd['stampAt'])
                                    <div class="text-[9px] text-zinc-500">
                                        {{ $ttd['stampAt']->translatedFormat('d M Y · H:i') }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Bottom action bar --}}
            <div class="mt-4 flex flex-col gap-3 rounded-2xl border-2 border-emerald-200 bg-emerald-50/40 px-6 py-4 dark:border-emerald-900/50 dark:bg-emerald-950/20 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-xs">
                        @if ($spr->utj_confirmed_at)
                            <div class="flex items-center gap-1.5 font-semibold text-emerald-700 dark:text-emerald-400">
                                <flux:icon.check-circle class="size-4" />
                                {{ __('UTJ diterima') }} {{ $spr->utj_tanggal_transaksi?->format('d/m/Y') }}
                            </div>
                        @else
                            <div class="flex items-center gap-1.5 text-amber-700 dark:text-amber-400">
                                <flux:icon.clock class="size-4" />
                                {{ __('Menunggu verifikasi UTJ oleh Keuangan') }}
                            </div>
                        @endif
                    </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($spr->materai_stamped_at && $spr->materai_file_path)
                        <a href="{{ route('marketing.spr.materai-pdf', $spr->id) }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-purple-500 bg-white px-3 py-1.5 text-sm font-semibold text-purple-700 shadow-sm transition hover:bg-purple-50">
                            <flux:icon.document-check class="size-4" />
                            {{ __('Cetak SPR Final') }}
                        </a>
                    @endif

                    @if ($spr->status === 'approved' && $spr->pm_approved_at)
                        <a href="{{ route('marketing.spr.print', $spr->id) }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                            <flux:icon.printer class="size-4" />
                            {{ __('Cetak SPR Konsumen') }}
                        </a>
                    @elseif ($spr->status === 'approved' && ! $spr->pm_approved_at)
                        <flux:button variant="ghost" icon="printer" disabled>
                            {{ __('Menunggu Approval Project Manager') }}
                        </flux:button>
                    @else
                        <flux:button variant="ghost" icon="printer" disabled>
                            {{ __('Cetak (hanya untuk SPR Disetujui)') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            {{-- Card "Dokumen SPR TTD + Meterai" dihilangkan — flow baru pakai TTD digital konsumen + e-Materai Peruri lewat menu terpisah --}}
        @endif

        {{-- ============ TAB: RINCIAN HARGA ============ --}}
        @if ($activeTab === 'rincian')

            {{-- Top info card --}}
            <div class="mb-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                    <span class="font-mono text-sm font-bold text-zinc-900 dark:text-white">{{ __('SPR') }} : {{ $spr->nomor_display }}</span>
                    @if ($proyek?->nama_perumahan)
                        <span class="text-xs text-zinc-500">·</span>
                        <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $proyek->nama_perumahan }}</span>
                    @endif
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Blok</dt><dd class="font-mono font-semibold">{{ $rumah?->kode_unit }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Pelunasan</dt><dd class="font-semibold uppercase">{{ Spr::JENIS_PEMBAYARAN[$spr->jenis_pembayaran] ?? $spr->jenis_pembayaran }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Type</dt><dd class="font-semibold">{{ $tipe?->nama_tipe ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Sales</dt><dd class="font-semibold">{{ $sales?->nama ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">Nama</dt><dd class="font-semibold">{{ $prospect?->nama_lengkap }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-zinc-500">HP</dt><dd class="font-mono font-semibold">{{ $prospect?->hp }}</dd></div>
                </dl>

                {{-- Timeline Proses — tahap internal SPR (data aktual) + tahap eksternal KPR (placeholder modul mendatang) --}}
                @php
                    $timelineInternal = [
                        [
                            'label' => 'SPR Terbit',
                            'value' => $spr->tanggal_spr?->format('d/m/Y'),
                            'done' => (bool) $spr->tanggal_spr,
                        ],
                        [
                            'label' => 'UTJ Ditransaksi',
                            'value' => $spr->utj_tanggal_transaksi?->format('d/m/Y'),
                            'done' => (bool) $spr->utj_tanggal_transaksi,
                        ],
                        [
                            'label' => 'UTJ Diverifikasi',
                            'value' => $spr->utj_confirmed_at?->format('d/m/Y'),
                            'done' => (bool) $spr->utj_confirmed_at,
                        ],
                        [
                            'label' => 'Disetujui PM',
                            'value' => $spr->pm_approved_at?->format('d/m/Y'),
                            'done' => (bool) $spr->pm_approved_at,
                        ],
                        [
                            'label' => 'TTD Konsumen',
                            'value' => $spr->konsumen_signed_at?->format('d/m/Y'),
                            'done' => (bool) $spr->konsumen_signed_at,
                        ],
                        [
                            'label' => 'e-Materai',
                            'value' => $spr->materai_stamped_at?->format('d/m/Y'),
                            'done' => (bool) $spr->materai_stamped_at,
                        ],
                        [
                            'label' => 'SPR Final',
                            'value' => $spr->spr_finalized_at?->format('d/m/Y'),
                            'done' => (bool) $spr->spr_finalized_at,
                        ],
                    ];
                    $timelineEksternal = [
                        ['label' => 'Berkas KPR',   'value' => null],
                        ['label' => 'Wawancara',    'value' => null],
                        ['label' => 'SP3K',         'value' => null],
                        ['label' => 'Rencana Akad', 'value' => null],
                        ['label' => 'Akad AJB',     'value' => null],
                    ];

                    $renderItem = function ($t) {
                        $done = $t['done'] ?? false;
                        return [$done, $t['label'], $t['value'] ?? null];
                    };
                @endphp

                <div class="mt-4 space-y-3">
                    {{-- Tahap Internal SPR --}}
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50/60 p-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                        <div class="mb-2 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                            <flux:icon.clock class="size-3" />
                            {{ __('Tahapan Penjualan') }}
                        </div>
                        <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs sm:grid-cols-4 lg:grid-cols-7">
                            @foreach ($timelineInternal as $t)
                                <div class="flex flex-col">
                                    <dt class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                                        @if ($t['done'] ?? false)
                                            <flux:icon.check-circle class="size-3 text-emerald-600" />
                                        @else
                                            <flux:icon.minus-circle class="size-3 text-zinc-300" />
                                        @endif
                                        {{ $t['label'] }}
                                    </dt>
                                    <dd class="mt-0.5 font-semibold {{ ($t['done'] ?? false) ? 'text-zinc-900 dark:text-white' : 'text-zinc-400' }}">
                                        @if ($t['value'])
                                            {{ $t['value'] }}
                                        @else
                                            <span class="italic text-[10px]">Belum</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Tahap Eksternal KPR (belum aktif — placeholder modul mendatang) --}}
                    <div class="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/60 p-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                        <div class="mb-2 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                            <flux:icon.clock class="size-3" />
                            {{ __('Tahapan Pemberkasan') }}
                        </div>
                        <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs sm:grid-cols-3 lg:grid-cols-5">
                            @foreach ($timelineEksternal as $t)
                                <div class="flex flex-col">
                                    <dt class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                                        <flux:icon.minus-circle class="size-3 text-zinc-300" />
                                        {{ $t['label'] }}
                                    </dt>
                                    <dd class="mt-0.5 font-semibold text-zinc-400">
                                        <span class="italic text-[10px]">Belum</span>
                                    </dd>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- 3-column: Harga Konsumen | Rencana | Realisasi --}}
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

                {{-- ===== HARGA KONSUMEN ===== --}}
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Harga Konsumen') }}</h3>
                    </div>
                    <div class="space-y-1 px-4 py-3 text-xs">
                        <div class="flex justify-between"><dt class="text-zinc-500">Harga Pricelist</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->harga_jual) }}</dd></div>
                        @if ((float) $spr->diskon > 0)
                            <div class="flex justify-between"><dt class="text-zinc-500">Diskon</dt><dd class="font-mono tabular-nums text-rose-600">({{ $fmt($spr->diskon) }})</dd></div>
                        @endif
                        @if ((float) $spr->biaya_tambahan > 0)
                            <div class="flex justify-between"><dt class="text-zinc-500">Biaya Tambahan</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->biaya_tambahan) }}</dd></div>
                        @endif
                        <div class="flex justify-between border-t border-zinc-200 pt-1.5 dark:border-zinc-700">
                            <dt class="font-semibold">DPP (Harga Net)</dt>
                            <dd class="font-mono font-bold tabular-nums">{{ $fmt((float) $spr->harga_jual - (float) $spr->diskon + (float) $spr->biaya_tambahan) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">PPN</dt>
                            @if ((float) $spr->ppn > 0)
                                <dd class="font-mono tabular-nums text-amber-600">{{ $fmt($spr->ppn) }}</dd>
                            @else
                                <dd class="font-mono tabular-nums text-zinc-400">0</dd>
                            @endif
                        </div>
                        <div class="flex justify-between rounded-md bg-emerald-50 px-2 py-1.5 dark:bg-emerald-950/30">
                            <dt class="font-bold text-emerald-900 dark:text-emerald-300">Total Harga</dt>
                            <dd class="font-mono font-extrabold tabular-nums text-emerald-700 dark:text-emerald-300">{{ $fmt($spr->total_harga) }}</dd>
                        </div>

                        @if ((float) $spr->sbum > 0)
                            <div class="mt-3 border-t border-dashed border-zinc-300 pt-2 dark:border-zinc-700">
                                <div class="mb-1 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Potongan') }}</div>
                                <div class="flex justify-between"><dt class="text-zinc-500">SBUM</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->sbum) }}</dd></div>
                                <div class="flex justify-between border-t border-zinc-200 pt-1 dark:border-zinc-700">
                                    <dt class="font-semibold">Total Potongan</dt>
                                    <dd class="font-mono font-bold tabular-nums">{{ $fmt($spr->sbum) }}</dd>
                                </div>
                            </div>
                        @endif

                        <div class="mt-3 space-y-1 border-t border-dashed border-zinc-300 pt-2 dark:border-zinc-700">
                            <div class="flex justify-between"><dt class="text-zinc-500">Nilai KPR Bank</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->nilai_kpr) }}</dd></div>
                            {{-- Komersial: UM langsung ke bank, tidak dicatat di sistem developer --}}
                            @if ($spr->kategori !== 'komersial')
                                <div class="flex justify-between"><dt class="text-zinc-500">Uang Muka (UM)</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->dp_nominal) }}</dd></div>
                                <div class="flex justify-between rounded-md bg-orange-50 px-2 py-1.5 dark:bg-orange-950/30">
                                    <dt class="font-bold text-orange-900 dark:text-orange-300">UM yang harus dibayar</dt>
                                    <dd class="font-mono font-extrabold tabular-nums text-orange-700 dark:text-orange-300">{{ $fmt($spr->um_net) }}</dd>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- ===== RENCANA PEMBAYARAN ===== --}}
                @php
                    $bf = $termins->firstWhere('jenis', 'bf');
                    $ums = $termins->where('jenis', 'um')->sortBy('urutan');
                    // um_net = total UM kewajiban customer (sudah termasuk UTJ 500rb di dalamnya).
                    $utjNominal = (float) $spr->utj_nominal;
                    $totalUm = (float) $spr->um_net;
                    $rencanaUangMasuk = $totalUm + (float) $spr->nilai_kpr + (float) $spr->sbum;
                @endphp
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Rencana Pembayaran') }}</h3>
                    </div>
                    <div class="space-y-3 px-4 py-3 text-xs">

                        {{-- UTJ + Uang Muka --}}
                        <div>
                            <div class="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:text-orange-400">
                                <flux:icon.banknotes class="size-3" />
                                {{ $spr->kategori === 'komersial' ? __('UTJ') : __('Uang Muka') }}
                            </div>

                            {{-- UTJ row: cek berdasarkan realisasi jenis 'bf' --}}
                            @if ($bf)
                                @php
                                    $bfDibayar = (float) $spr->realisasiPembayaran->where('jenis', 'bf')->sum('jumlah');
                                    $bfCair = $bfDibayar >= (float) $bf->jumlah_jadwal;
                                @endphp
                                <div class="flex items-center justify-between gap-2 border-b border-zinc-100 py-1 dark:border-zinc-800">
                                    <span class="w-14 font-mono text-zinc-600">UTJ</span>
                                    <span class="flex-1 text-zinc-500">{{ $bf->tanggal_jadwal?->format('d/m/Y') ?? '—' }}</span>
                                    <span class="font-mono tabular-nums">{{ $fmt($bf->jumlah_jadwal) }}</span>
                                    @if ($bfCair)
                                        <flux:icon.check-circle class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" title="Sudah cair" />
                                    @else
                                        <flux:icon.clock class="size-3.5 shrink-0 text-amber-500" title="Belum cair" />
                                    @endif
                                </div>
                            @endif

                            {{-- UM cicilan — hanya subsidi (komersial: langsung ke bank) --}}
                            @if ($spr->kategori !== 'komersial')
                                @php
                                    $totalUmRealisasi = (float) $spr->realisasiPembayaran->where('jenis', 'um')->sum('jumlah');
                                    $sisaAlokasi = $totalUmRealisasi;
                                @endphp
                                @foreach ($ums as $t)
                                    @php
                                        $terminJumlah = (float) $t->jumlah_jadwal;
                                        if ($sisaAlokasi >= $terminJumlah) {
                                            $status = 'lunas';
                                            $terbayar = $terminJumlah;
                                            $sisaAlokasi -= $terminJumlah;
                                        } elseif ($sisaAlokasi > 0) {
                                            $status = 'partial';
                                            $terbayar = $sisaAlokasi;
                                            $sisaAlokasi = 0;
                                        } else {
                                            $status = 'belum';
                                            $terbayar = 0;
                                        }
                                    @endphp
                                    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 py-1 dark:border-zinc-800">
                                        <span class="w-14 font-mono text-zinc-600">UM-{{ $t->urutan }}</span>
                                        <span class="flex-1 text-zinc-500">{{ $t->tanggal_jadwal?->format('d/m/Y') ?? '—' }}</span>
                                        <div class="flex flex-col items-end">
                                            <span class="font-mono tabular-nums">{{ $fmt($terminJumlah) }}</span>
                                            @if ($status === 'partial')
                                                <span class="font-mono text-[9px] text-amber-600">Terbayar {{ $fmt($terbayar) }}</span>
                                            @endif
                                        </div>
                                        @if ($status === 'lunas')
                                            <flux:icon.check-circle class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" title="Lunas" />
                                        @elseif ($status === 'partial')
                                            <flux:icon.clock class="size-3.5 shrink-0 text-amber-500" title="Partial: Rp {{ number_format($terbayar, 0, ',', '.') }}" />
                                        @else
                                            <flux:icon.minus-circle class="size-3.5 shrink-0 text-zinc-300 dark:text-zinc-600" title="Belum" />
                                        @endif
                                    </div>
                                @endforeach
                                <div class="flex justify-between rounded-md bg-emerald-50 px-2 py-1.5 dark:bg-emerald-950/30">
                                    <dt class="font-bold text-emerald-900 dark:text-emerald-200">Total UM</dt>
                                    <dd class="font-mono font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ $fmt($totalUm) }}</dd>
                                </div>
                                @if ($kurangUm > 0)
                                    <div class="mt-1 flex justify-between rounded-md bg-amber-50 px-2 py-1 text-[11px] dark:bg-amber-950/30">
                                        <dt class="font-semibold text-amber-800 dark:text-amber-300">Sisa Kurang</dt>
                                        <dd class="font-mono font-bold tabular-nums text-amber-700 dark:text-amber-300">{{ $fmt($kurangUm) }}</dd>
                                    </div>
                                @endif
                            @endif
                        </div>

                        {{-- SBUM (Subsidi Bantuan Uang Muka) — dari bank, terpisah dari plafon KPR --}}
                        @if ((float) $spr->sbum > 0)
                            <div class="border-t border-dashed border-zinc-300 pt-3 dark:border-zinc-700">
                                <div class="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-400">
                                    <flux:icon.gift class="size-3" />
                                    {{ __('SBUM (Subsidi Bantuan Uang Muka)') }}
                                </div>
                                <div class="flex items-center justify-between gap-2 rounded-md bg-purple-50/50 px-2 py-1.5 dark:bg-purple-950/20">
                                    <span class="flex-1 text-zinc-600">Nominal</span>
                                    <span class="font-mono font-bold tabular-nums text-purple-700 dark:text-purple-300">{{ $fmt($spr->sbum) }}</span>
                                    <flux:icon.clock class="size-3.5 shrink-0 text-amber-500" title="Menunggu cair" />
                                </div>
                            </div>
                        @endif

                        {{-- KPR --}}
                        @if ($spr->jenis_pembayaran === 'kpr')
                            <div class="border-t border-dashed border-zinc-300 pt-3 dark:border-zinc-700">
                                <div class="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-400">
                                    <flux:icon.building-library class="size-3" />
                                    {{ __('KPR') }}
                                </div>
                                <div class="flex items-center justify-between gap-2 border-b border-zinc-100 py-1 dark:border-zinc-800">
                                    <span class="flex-1 text-zinc-600">Plafon</span>
                                    <span class="font-mono tabular-nums">{{ $fmt($spr->nilai_kpr) }}</span>
                                    <flux:icon.clock class="size-3.5 shrink-0 text-amber-500" title="Belum cair (modul SP3K belum ada)" />
                                </div>
                                <div class="flex justify-between rounded-md bg-zinc-100 px-2 py-1.5 dark:bg-zinc-800/50">
                                    <dt class="font-bold">Total KPR</dt>
                                    <dd class="font-mono font-bold tabular-nums">{{ $fmt((float) $spr->nilai_kpr) }}</dd>
                                </div>
                                <div class="mt-1 flex justify-between rounded-md bg-amber-50 px-2 py-1 text-[11px] dark:bg-amber-950/30">
                                    <dt class="font-semibold text-amber-800 dark:text-amber-300">Kurang</dt>
                                    <dd class="font-mono font-bold tabular-nums text-amber-700 dark:text-amber-300">{{ $fmt((float) $spr->nilai_kpr) }}</dd>
                                </div>
                            </div>
                        @endif

                        {{-- Rencana uang masuk --}}
                        <div class="rounded-md bg-emerald-50 px-2 py-1.5 dark:bg-emerald-950/30">
                            <div class="flex justify-between">
                                <dt class="font-bold text-emerald-900 dark:text-emerald-300">Rencana Uang Masuk</dt>
                                <dd class="font-mono font-extrabold tabular-nums text-emerald-700 dark:text-emerald-300">
                                    {{ $fmt($rencanaUangMasuk) }}
                                </dd>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ===== REALISASI PEMBAYARAN ===== --}}
                @php
                    $canAddTrx = in_array($spr->status, ['approved', 'submitted']);
                    // Komersial: UM cicilan langsung ke bank (bukan ke developer) → tidak boleh input realisasi UM
                    $canAddUmTrx = $canAddTrx && $spr->kategori !== 'komersial';
                @endphp
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-2.5 dark:border-zinc-700">
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Realisasi Pembayaran') }}</h3>
                            @if ($spr->switched_to_spr_id)
                                <span class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-orange-700 dark:bg-orange-950/50 dark:text-orange-300">
                                    <flux:icon.archive-box class="size-2.5" />
                                    {{ __('Riwayat · sudah dipindah') }}
                                </span>
                            @endif
                        </div>
                        @if ($canAddUmTrx)
                            <flux:button size="sm" variant="primary" icon="plus" wire:click="openTambahTransaksi">
                                {{ __('Tambah Transaksi') }}
                            </flux:button>
                        @endif
                    </div>
                    <div class="space-y-4 px-4 py-3 text-xs">

                        {{-- ============ UTJ (Booking Fee) ============ --}}
                        @php
                            $realisasiUtj = $spr->realisasiPembayaran->where('jenis', 'bf');
                        @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-1.5">
                                <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-400">
                                    <flux:icon.ticket class="size-3" />
                                    {{ __('UTJ (Booking Fee)') }}
                                </div>
                                @if ($realisasiUtj->isNotEmpty())
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                        <flux:icon.check class="size-2.5" />
                                        {{ __('Lunas') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                        <flux:icon.clock class="size-2.5" />
                                        {{ __('Menunggu') }}
                                    </span>
                                @endif
                            </div>
                            @if ($realisasiUtj->isNotEmpty())
                                @foreach ($realisasiUtj as $r)
                                    <div class="flex items-center justify-between gap-2 rounded-md bg-purple-50/50 px-2 py-1.5 text-[11px] dark:bg-purple-950/20">
                                        <div class="flex items-center gap-2">
                                            @if ($r->nomor_kwitansi)
                                                <a href="{{ route('marketing.spr.kuitansi', ['id' => $spr->id, 'realisasiId' => $r->id]) }}"
                                                   target="_blank" rel="noopener"
                                                   class="font-mono text-purple-700 underline-offset-2 hover:underline dark:text-purple-300"
                                                   title="Buka kuitansi">
                                                    {{ $r->nomor_kwitansi }}
                                                </a>
                                            @else
                                                <span class="font-mono text-zinc-400">—</span>
                                            @endif
                                            <span class="text-zinc-500">·</span>
                                            <span class="text-zinc-500">{{ $r->tanggal_bayar?->format('d/m/Y') }}</span>
                                        </div>
                                        <span class="font-mono font-bold tabular-nums text-purple-700 dark:text-purple-300">{{ $fmt($r->jumlah) }}</span>
                                    </div>
                                @endforeach
                            @else
                                <div class="rounded-md bg-zinc-50 px-2 py-3 text-center italic text-[11px] text-zinc-400 dark:bg-zinc-800/30">
                                    {{ __('UTJ belum diverifikasi Keuangan.') }}
                                </div>
                            @endif
                        </div>

                        {{-- ============ UANG MUKA (cicilan) — hanya subsidi ============ --}}
                        @if ($spr->kategori !== 'komersial')
                        <div class="border-t border-dashed border-zinc-200 pt-3 dark:border-zinc-700">
                            <div class="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:text-orange-400">
                                <flux:icon.banknotes class="size-3" />
                                {{ __('Uang Muka (Cicilan)') }}
                            </div>
                            <table class="w-full text-[11px]">
                                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700">
                                    <tr>
                                        <th class="py-1 text-left font-semibold">Kuitansi</th>
                                        <th class="py-1 text-left font-semibold">Tanggal</th>
                                        <th class="py-1 text-right font-semibold">Masuk</th>
                                        @can('pembayaran.kelola')
                                            @if (! $spr->switched_to_spr_id && $spr->status !== 'cancelled')
                                                <th class="py-1 text-right font-semibold w-8"></th>
                                            @endif
                                        @endcan
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $realisasiUm = $spr->realisasiPembayaran->where('jenis', 'um');
                                        $canEditRealisasi = auth()->user()?->can('pembayaran.kelola')
                                            && ! $spr->switched_to_spr_id
                                            && $spr->status !== 'cancelled';
                                    @endphp
                                    @forelse ($realisasiUm as $r)
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                            <td class="py-1 font-mono">
                                                @if ($r->nomor_kwitansi)
                                                    <a href="{{ route('marketing.spr.kuitansi', ['id' => $spr->id, 'realisasiId' => $r->id]) }}"
                                                       target="_blank" rel="noopener"
                                                       class="text-emerald-700 underline-offset-2 hover:underline dark:text-emerald-400"
                                                       title="Buka kuitansi di tab baru">
                                                        {{ $r->nomor_kwitansi }}
                                                    </a>
                                                @else
                                                    <span class="text-zinc-400">—</span>
                                                @endif
                                            </td>
                                            <td class="py-1">{{ $r->tanggal_bayar?->format('d/m/Y') }}</td>
                                            <td class="py-1 text-right font-mono tabular-nums">{{ $fmt($r->jumlah) }}</td>
                                            @if ($canEditRealisasi)
                                                <td class="py-1 text-right">
                                                    <button type="button" wire:click="openEditRealisasi({{ $r->id }})"
                                                            class="rounded p-1 text-zinc-400 transition hover:bg-amber-50 hover:text-amber-700 dark:hover:bg-amber-950/30"
                                                            title="{{ __('Edit realisasi (koreksi nominal / tanggal)') }}">
                                                        <flux:icon.pencil-square class="size-3.5" />
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $canEditRealisasi ? 4 : 3 }}" class="py-3 text-center italic text-zinc-400">{{ __('Belum ada UM cair.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            <div class="mt-1 flex items-center justify-between rounded-md bg-zinc-100 px-2 py-1.5 dark:bg-zinc-800/50">
                                <dt class="font-bold">Total UM Cair</dt>
                                <dd class="font-mono font-bold tabular-nums">{{ $fmt($totalUmDibayar) }}</dd>
                            </div>
                            @if ($kurangUm > 0)
                                <div class="mt-1 flex items-center justify-between rounded-md bg-rose-50 px-2 py-1.5 dark:bg-rose-950/30">
                                    <dt class="font-bold text-rose-700 dark:text-rose-400">Sisa UM</dt>
                                    <dd class="font-mono font-bold tabular-nums text-rose-700 dark:text-rose-400">{{ $fmt($kurangUm) }}</dd>
                                </div>
                            @else
                                <div class="mt-1 flex items-center justify-between rounded-md bg-emerald-50 px-2 py-1.5 dark:bg-emerald-950/30">
                                    <dt class="font-bold text-emerald-700 dark:text-emerald-400">{{ __('UM Lunas') }}</dt>
                                    <dd>
                                        <flux:icon.check-circle class="size-4 text-emerald-700 dark:text-emerald-400" />
                                    </dd>
                                </div>
                            @endif
                        </div>
                        @endif {{-- end kategori !== komersial (UM cicilan section) --}}

                        {{-- KPR realisasi - placeholder --}}
                        @if ($spr->jenis_pembayaran === 'kpr')
                            <div class="border-t border-dashed border-zinc-300 pt-3 dark:border-zinc-700">
                                <div class="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-400">
                                    <flux:icon.building-library class="size-3" />
                                    {{ __('KPR') }}
                                </div>
                                <div class="rounded-md bg-zinc-50 px-2 py-3 text-center italic text-[11px] text-zinc-400 dark:bg-zinc-800/30">
                                    {{ __('Pencairan KPR akan tercatat setelah modul Akad/SP3K tersedia.') }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ MODAL: TAMBAH TRANSAKSI ============ --}}
        <flux:modal name="tambah-transaksi" class="md:w-lg" focusable>
            <form wire:submit="saveTransaksi" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Tambah Transaksi UM') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Catat pembayaran UM dari customer. Jumlah bebas — bisa cicil sebagian, lunas sekaligus, atau bayar berapa saja.') }}
                    </flux:subheading>
                </div>

                @if ($kurangUm <= 0)
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-4 text-center text-xs text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">
                        <flux:icon.check-circle class="-mt-0.5 mr-1 inline size-4" />
                        {{ __('UM sudah lunas. Tidak ada sisa untuk dicatat.') }}
                    </div>
                @else
                    {{-- Info sisa UM --}}
                    <div class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs dark:border-amber-900/50 dark:bg-amber-950/30">
                        <span class="font-semibold text-amber-800 dark:text-amber-200">{{ __('Sisa UM belum dibayar') }}</span>
                        <span class="font-mono font-bold text-amber-900 dark:text-amber-100">Rp {{ number_format($kurangUm, 0, ',', '.') }}</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <flux:field>
                            <flux:label>{{ __('Tgl Transaksi') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <flux:input type="date" wire:model="trxTanggal" required />
                            <flux:error name="trxTanggal" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Jumlah') }} <span class="ms-1 text-red-500">*</span></flux:label>
                            <x-money-input wire="trxJumlah" required />
                            <flux:description class="text-[10px]">Max: Rp {{ number_format($kurangUm, 0, ',', '.') }}</flux:description>
                            <flux:error name="trxJumlah" />
                        </flux:field>
                    </div>

                    {{-- Preset: Lunas sisa --}}
                    <button type="button" wire:click="prefillLunas"
                            class="w-full rounded-lg border-2 border-dashed border-emerald-300 bg-emerald-50/50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-300">
                        <flux:icon.check-badge class="-mt-0.5 mr-1 inline size-3.5" />
                        {{ __('Preset: Lunas Sisa') }} (Rp {{ number_format($kurangUm, 0, ',', '.') }})
                    </button>

                    <div>
                        <flux:label class="mb-2 block">{{ __('Metode Pembayaran') }}</flux:label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (['transfer' => 'Transfer', 'tunai' => 'Tunai'] as $key => $label)
                                <label @class([
                                    'flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-semibold transition',
                                    'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' => $trxMetode === $key,
                                    'border-zinc-200 text-zinc-700 dark:border-zinc-700 dark:text-zinc-300' => $trxMetode !== $key,
                                ])>
                                    <input type="radio" wire:model="trxMetode" value="{{ $key }}" class="accent-emerald-600" />
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Keterangan') }} <span class="text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                        <flux:textarea wire:model="trxKeterangan" rows="2" placeholder="Contoh: transfer BCA / cash di kasir" />
                        <flux:error name="trxKeterangan" />
                    </flux:field>

                    <div class="rounded-lg bg-zinc-50 px-3 py-2 text-[10px] italic text-zinc-500 dark:bg-zinc-800/50">
                        <flux:icon.information-circle class="-mt-0.5 mr-1 inline size-3" />
                        {{ __('Nomor kuitansi otomatis di-generate 5-digit sequential.') }}
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    @if ($kurangUm > 0)
                        <flux:button variant="primary" type="submit" icon="check">
                            {{ __('Simpan') }}
                        </flux:button>
                    @endif
                </div>
            </form>
        </flux:modal>

        {{-- ============ MODAL: EDIT REALISASI UM ============ --}}
        <flux:modal name="edit-realisasi" class="md:w-lg" focusable>
            <form wire:submit="saveEditRealisasi" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Edit Realisasi Pembayaran') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Koreksi tanggal, jumlah, metode, atau keterangan realisasi UM. Nomor kwitansi tetap. Perubahan tercatat di log audit.') }}
                    </flux:subheading>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('Tanggal Bayar') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="editRealisasiTanggal" required />
                        <flux:error name="editRealisasiTanggal" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Jumlah (Rp)') }} <span class="ms-1 text-red-500">*</span></flux:label>
                        <x-money-input wire="editRealisasiJumlah" required />
                        <flux:error name="editRealisasiJumlah" />
                    </flux:field>
                </div>

                <div>
                    <flux:label class="mb-2 block">{{ __('Metode Pembayaran') }}</flux:label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['transfer' => 'Transfer', 'tunai' => 'Tunai'] as $key => $label)
                            <label @class([
                                'flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-semibold transition',
                                'border-amber-500 bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300' => $editRealisasiMetode === $key,
                                'border-zinc-200 text-zinc-700 dark:border-zinc-700 dark:text-zinc-300' => $editRealisasiMetode !== $key,
                            ])>
                                <input type="radio" wire:model="editRealisasiMetode" value="{{ $key }}" class="accent-amber-600" />
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <flux:field>
                    <flux:label>{{ __('Keterangan') }} <span class="text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                    <flux:textarea wire:model="editRealisasiKeterangan" rows="2" placeholder="Contoh: transfer BCA / cash di kasir" />
                    <flux:error name="editRealisasiKeterangan" />
                </flux:field>

                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[10px] italic text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200">
                    <flux:icon.exclamation-triangle class="-mt-0.5 mr-1 inline size-3" />
                    {{ __('Perubahan tercatat di log audit dengan nilai lama & baru. Nomor kwitansi tidak berubah.') }}
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" icon="check">
                        {{ __('Simpan Perubahan') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

    </div>
</section>
