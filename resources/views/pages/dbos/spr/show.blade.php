<?php

use App\Models\Master\ProspectCustomerKontakDarurat;
use App\Models\Master\Spr;
use App\Support\FileOptimizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detail SPR'), Layout('layouts.dbos')] class extends Component
{
    use WithFileUploads;

    public Spr $spr;

    /** File baru bukti transfer UTJ (upload ulang saat salah upload). */
    public $utjBuktiNew = null;

    public function mount(int $id): void
    {
        $salesId = Auth::guard('sales')->id();
        $this->spr = Spr::with([
            'booking',
            'rumah.tipeRumah',
            'rumah.proyek',
            'prospectCustomer.tempatKerja',
            'prospectCustomer.bank',
            'prospectCustomer.kontakDarurat',
            'bankKpr',
            'sales',
            'utjConfirmedBy',
        ])
            ->where('sales_id', $salesId)
            ->findOrFail($id);
    }

    public function printPdf()
    {
        // Reload dengan relasi untuk template
        $spr = Spr::with([
            'rumah.tipeRumah',
            'rumah.proyek',
            'rumah.virtualAccount.bank',
            'prospectCustomer.tempatKerja',
            'sales',
            'utjConfirmedBy',
            'terminPembayaran',
        ])->findOrFail($this->spr->id);

        $pdf = Pdf::loadView('exports.spr-print', ['spr' => $spr])
            ->setPaper('a4', 'portrait');

        $filename = str_replace('/', '-', $spr->nomor_spr).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
        );
    }

    /**
     * Sales ganti bukti transfer UTJ (kalau salah upload).
     * Hanya bisa selama SPR belum di-verifikasi Keuangan (status = submitted).
     */
    public function updateBuktiUtj(): void
    {
        // Guard: hanya sales pemilik SPR
        $salesId = Auth::guard('sales')->id();
        if ($this->spr->sales_id !== $salesId) {
            Flux::toast(variant: 'danger', text: 'Tidak berhak mengubah bukti UTJ SPR ini.');

            return;
        }

        // Guard: hanya boleh diubah selama belum verif Keuangan
        if ($this->spr->utj_confirmed_at || $this->spr->status !== 'submitted') {
            Flux::toast(variant: 'warning', text: 'Bukti UTJ tidak bisa diubah setelah Keuangan melakukan verifikasi.');

            return;
        }

        $this->validate([
            'utjBuktiNew' => ['required', 'image', 'max:5120'],
        ], [], ['utjBuktiNew' => 'bukti transfer']);

        // Hapus file lama
        if ($this->spr->utj_bukti_path && Storage::disk('public')->exists($this->spr->utj_bukti_path)) {
            Storage::disk('public')->delete($this->spr->utj_bukti_path);
        }

        // Upload file baru + update DB
        $newPath = FileOptimizer::storeOptimized($this->utjBuktiNew, 'utj-bukti');
        $this->spr->update(['utj_bukti_path' => $newPath]);
        $this->spr->refresh();
        $this->utjBuktiNew = null;

        Flux::modal('ganti-bukti-utj')->close();
        Flux::toast(variant: 'success', text: 'Bukti transfer UTJ berhasil diganti.');
    }

    // ============ FITUR #6: Generate link TTD konsumen ============

    public function generateSigningLink(): void
    {
        if (! $this->spr->isReadyForKonsumenSigning()) {
            Flux::toast(
                variant: 'danger',
                heading: 'Belum siap',
                text: 'SPR belum siap dikirim ke konsumen. Menunggu approval Project Manager.',
            );
            return;
        }

        $hash = Spr::generateSigningLinkHash();
        $expiresAt = now()->addDay();

        $this->spr->update([
            'konsumen_signing_link_hash' => $hash,
            'konsumen_signing_link_expires_at' => $expiresAt,
        ]);

        $this->spr->refresh();

        Flux::toast(
            variant: 'success',
            text: 'Link TTD konsumen berhasil dibuat. Berlaku 1 hari.',
        );
    }

    public function regenerateSigningLink(): void
    {
        // Reset dulu, lalu generate baru — supaya sales bisa perbarui link yang expired.
        $this->spr->update([
            'konsumen_signing_link_hash' => null,
            'konsumen_signing_link_expires_at' => null,
        ]);
        $this->generateSigningLink();
    }

    public function getSigningUrlProperty(): ?string
    {
        return $this->spr->konsumen_signing_link_hash
            ? url('/spr/sign/'.$this->spr->konsumen_signing_link_hash)
            : null;
    }

    // ============ FITUR #6 lanjutan: Generate link DOWNLOAD PDF final untuk konsumen ============

    public function generateDownloadLink(): void
    {
        if (! $this->spr->isFinal()) {
            Flux::toast(
                variant: 'danger',
                heading: 'SPR belum final',
                text: 'Link download baru bisa dibuat setelah e-Materai ditempel Keuangan.',
            );
            return;
        }

        $hash = Spr::generateDownloadLinkHash();
        $this->spr->update([
            'konsumen_download_link_hash' => $hash,
            'konsumen_download_link_expires_at' => now()->addDays(7),
        ]);
        $this->spr->refresh();

        Flux::toast(
            variant: 'success',
            text: 'Link download berhasil dibuat. Berlaku 7 hari.',
        );
    }

    public function regenerateDownloadLink(): void
    {
        $this->spr->update([
            'konsumen_download_link_hash' => null,
            'konsumen_download_link_expires_at' => null,
        ]);
        $this->generateDownloadLink();
    }

    public function getDownloadUrlProperty(): ?string
    {
        return $this->spr->konsumen_download_link_hash
            ? url('/spr/download/'.$this->spr->konsumen_download_link_hash)
            : null;
    }
}; ?>

@php
    [$badgeLabel, $badgeColor] = $spr->statusBadge();
    $prospect = $spr->prospectCustomer;
    $rumah = $spr->rumah;
    $tipe = $rumah?->tipeRumah;
    $jenisLabel = Spr::JENIS_PEMBAYARAN[$spr->jenis_pembayaran] ?? $spr->jenis_pembayaran;
    $metodeLabel = Spr::METODE_UTJ[$spr->utj_metode] ?? $spr->utj_metode;
@endphp

<section class="px-4 pb-24 pt-4">

    {{-- HEADER --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('dbos.spr.index') }}" wire:navigate
           class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Detail SPR') }}</h1>
            <p class="font-mono text-xs text-zinc-500">{{ $spr->nomor_display }}</p>
        </div>
        <span @class([
            'shrink-0 rounded-full px-3 py-1 text-[10px] font-bold uppercase tracking-wider',
            $badgeColor,
        ])>{{ $badgeLabel }}</span>
    </div>

    {{-- Cetak SPR dilakukan di web ERP (Marketing → SPR), bukan di DBOS. --}}

    @if ($spr->status === 'rejected' && $spr->alasan_reject)
        <div class="mb-3 rounded-2xl border-2 border-rose-200 bg-rose-50 p-3 dark:border-rose-900/50 dark:bg-rose-950/30">
            <div class="text-[10px] font-bold uppercase tracking-wider text-rose-700 dark:text-rose-300">{{ __('Alasan Penolakan') }}</div>
            <p class="mt-1 text-xs text-rose-900 dark:text-rose-200">{{ $spr->alasan_reject }}</p>
        </div>
    @endif

    <div class="space-y-3">
        {{-- Customer & Unit --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-blue-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-blue-950/20">
                <span class="text-[10px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">{{ __('Customer & Unit') }}</span>
            </div>
            <div class="space-y-2 px-4 py-3 text-xs">
                <div class="flex justify-between gap-2"><dt class="text-zinc-500">Nama</dt><dd class="text-right font-bold text-zinc-900 dark:text-white">{{ $prospect->nama_lengkap }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-zinc-500">NIK</dt><dd class="text-right font-mono">{{ $prospect->nik ?? '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-zinc-500">HP</dt><dd class="text-right font-mono text-green-700 dark:text-green-400">{{ $prospect->hp }}</dd></div>
                <div class="border-t border-zinc-100 pt-2 dark:border-zinc-800"></div>
                <div class="flex justify-between gap-2"><dt class="text-zinc-500">Proyek</dt><dd class="text-right font-semibold">{{ $rumah?->proyek?->nama_proyek }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-zinc-500">Unit</dt><dd class="text-right font-mono font-bold">{{ $rumah?->kode_unit }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-zinc-500">Tipe</dt><dd class="text-right">{{ $tipe?->tipe }} {{ $tipe?->nama_tipe }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-zinc-500">Tanggal SPR</dt><dd class="text-right font-semibold">{{ $spr->tanggal_spr?->translatedFormat('d M Y') }}</dd></div>
            </div>
        </div>

        {{-- Harga --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-emerald-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-emerald-950/20">
                <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">{{ __('Detail Harga') }}</span>
            </div>
            <div class="space-y-1.5 px-4 py-3 text-xs">
                <div class="flex justify-between"><dt class="text-zinc-500">Harga Jual</dt><dd class="text-right tabular-nums">Rp {{ number_format((float) $spr->harga_jual, 0, ',', '.') }}</dd></div>
                @if ((float) $spr->biaya_tambahan > 0)
                    <div class="flex justify-between"><dt class="text-zinc-500">Biaya Tambahan</dt><dd class="text-right tabular-nums">+ Rp {{ number_format((float) $spr->biaya_tambahan, 0, ',', '.') }}</dd></div>
                @endif
                @if ((float) $spr->diskon > 0)
                    <div class="flex justify-between"><dt class="text-zinc-500">Diskon</dt><dd class="text-right tabular-nums text-rose-600">− Rp {{ number_format((float) $spr->diskon, 0, ',', '.') }}</dd></div>
                @endif
                @if ((float) $spr->kelebihan_tanah_m2 > 0)
                    <div class="flex justify-between"><dt class="text-zinc-500">Kelebihan Tanah</dt><dd class="text-right">{{ number_format((float) $spr->kelebihan_tanah_m2, 2) }} m² × Rp {{ number_format((float) $spr->harga_per_m2, 0, ',', '.') }}</dd></div>
                @endif
                <div class="mt-2 flex justify-between border-t border-zinc-200 pt-2 dark:border-zinc-700">
                    <dt class="font-bold text-zinc-900 dark:text-white">Harga Jual All in</dt>
                    <dd class="text-right text-base font-extrabold tabular-nums text-emerald-700 dark:text-emerald-300">
                        Rp {{ number_format((float) $spr->total_harga, 0, ',', '.') }}
                    </dd>
                </div>
            </div>
        </div>

        {{-- Angsuran --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-orange-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-orange-950/20">
                <span class="text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:text-orange-300">{{ __('Skema Pembayaran') }}</span>
            </div>
            <div class="space-y-1.5 px-4 py-3 text-xs">
                <div class="flex justify-between"><dt class="text-zinc-500">Jenis</dt><dd class="text-right font-semibold">{{ $jenisLabel }}</dd></div>
                @if ($spr->jenis_pembayaran === 'kpr' && $spr->bankKpr)
                    <div class="flex justify-between"><dt class="text-zinc-500">Bank KPR</dt><dd class="text-right font-semibold">{{ $spr->bankKpr->nama }}</dd></div>
                @endif
                @if ($spr->jenis_pembayaran === 'kpr')
                    <div class="flex justify-between"><dt class="text-zinc-500">Nilai KPR</dt><dd class="text-right tabular-nums">Rp {{ number_format((float) $spr->nilai_kpr, 0, ',', '.') }}</dd></div>
                @endif
                {{-- Komersial: UM langsung ke bank, tidak tercatat di sistem developer --}}
                @if ($spr->kategori !== 'komersial')
                    <div class="flex justify-between"><dt class="text-zinc-500">Total UM</dt><dd class="text-right font-semibold tabular-nums">Rp {{ number_format((float) $spr->dp_nominal, 0, ',', '.') }}</dd></div>
                    <div class="mt-2 flex justify-between border-t border-zinc-200 pt-2 dark:border-zinc-700">
                        <dt class="font-bold text-zinc-900 dark:text-white">UM Sendiri (Customer)</dt>
                        <dd class="text-right text-base font-extrabold tabular-nums text-orange-700 dark:text-orange-300">
                            Rp {{ number_format((float) $spr->um_net, 0, ',', '.') }}
                        </dd>
                    </div>
                @endif
                @if ($spr->catatan_angsuran)
                    <div class="mt-2 rounded-md bg-zinc-50 px-2 py-1.5 text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">{{ $spr->catatan_angsuran }}</div>
                @endif
            </div>
        </div>

        {{-- Jadwal Termin Pembayaran (SBUM di-exclude — itu urusan pusat/bank, bukan customer) --}}
        @php
            $termins = $spr->terminPembayaran()
                ->whereIn('jenis', ['bf', 'um'])
                ->orderByRaw(\App\Models\Master\Spr::urutanJenisSql(['bf', 'um']).', urutan')
                ->get();

            // Kumpulkan realisasi per jenis, urut tanggal (FIFO).
            $realisasiBfList = $spr->realisasiPembayaran
                ->where('jenis', 'bf')
                ->sortBy('tanggal_bayar')
                ->values();
            $realisasiUmList = $spr->realisasiPembayaran
                ->where('jenis', 'um')
                ->sortBy('tanggal_bayar')
                ->values();

            /**
             * Pointer FIFO: track sisa nominal dari realisasi yang belum ter-alokasikan.
             * $bfPtr / $umPtr = index realisasi aktif; $bfSisa / $umSisa = sisa nominal realisasi itu.
             */
            $bfPtr = 0; $bfSisa = (float) ($realisasiBfList[0]?->jumlah ?? 0);
            $umPtr = 0; $umSisa = (float) ($realisasiUmList[0]?->jumlah ?? 0);
        @endphp
        @if ($termins->isNotEmpty())
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-purple-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-purple-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-300">{{ __('Jadwal / Termin Pembayaran') }}</span>
                </div>
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400">
                        <tr>
                            <th class="px-3 py-1.5 text-left">{{ __('Termin') }}</th>
                            <th class="px-3 py-1.5 text-left">{{ __('Tanggal Jadwal') }}</th>
                            <th class="px-3 py-1.5 text-left">{{ __('Tgl Realisasi') }}</th>
                            <th class="px-3 py-1.5 text-right">{{ __('Jumlah') }}</th>
                            <th class="px-3 py-1.5 text-center">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($termins as $t)
                            @php
                                $jml = (float) $t->jumlah_jadwal;
                                $sisaTermin = $jml;
                                $tglRealisasi = null; // tgl realisasi terakhir yang bantu cover termin ini

                                // Pilih pointer sesuai jenis termin
                                $isBf = $t->jenis === 'bf';
                                $list = $isBf ? $realisasiBfList : $realisasiUmList;
                                $ptr  = $isBf ? $bfPtr : $umPtr;
                                $sisa = $isBf ? $bfSisa : $umSisa;

                                // Alokasi FIFO
                                while ($sisaTermin > 0 && $ptr < $list->count()) {
                                    $ambil = min($sisaTermin, $sisa);
                                    $sisaTermin -= $ambil;
                                    $sisa -= $ambil;
                                    $tglRealisasi = $list[$ptr]->tanggal_bayar; // update ke realisasi terakhir yg dipakai
                                    if ($sisa <= 0.0001) {
                                        $ptr++;
                                        $sisa = (float) ($list[$ptr]?->jumlah ?? 0);
                                    }
                                }

                                // Simpan pointer balik
                                if ($isBf) { $bfPtr = $ptr; $bfSisa = $sisa; }
                                else       { $umPtr = $ptr; $umSisa = $sisa; }

                                if ($sisaTermin <= 0.0001)      $status = 'lunas';
                                elseif ($sisaTermin < $jml)     $status = 'partial';
                                else                            $status = 'belum';
                            @endphp
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="px-3 py-1.5 font-mono font-bold">{{ $t->label() }}</td>
                                <td class="px-3 py-1.5">{{ $t->tanggal_jadwal?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-3 py-1.5">
                                    @if ($tglRealisasi)
                                        <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $tglRealisasi->translatedFormat('d M Y') }}</span>
                                    @else
                                        <span class="text-zinc-300">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 text-right font-mono">Rp {{ number_format($jml, 0, ',', '.') }}</td>
                                <td class="px-3 py-1.5 text-center">
                                    @if ($status === 'lunas')
                                        <flux:badge color="green" size="sm">{{ __('Lunas') }}</flux:badge>
                                    @elseif ($status === 'partial')
                                        <flux:badge color="amber" size="sm">{{ __('Partial') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">{{ __('Menunggu') }}</flux:badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- UTJ --}}
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-purple-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-purple-950/20">
                <span class="text-[10px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-300">{{ __('Uang Tanda Jadi') }}</span>
            </div>
            <div class="space-y-1.5 px-4 py-3 text-xs">
                <div class="flex justify-between"><dt class="text-zinc-500">Nominal</dt><dd class="text-right text-base font-bold tabular-nums text-purple-700 dark:text-purple-300">Rp {{ number_format((float) $spr->utj_nominal, 0, ',', '.') }}</dd></div>
                @if ($spr->utj_confirmed_at)
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Tgl Diterima</dt>
                        <dd class="text-right font-semibold">{{ $spr->utj_tanggal_transaksi?->translatedFormat('d M Y') ?? '—' }}</dd>
                    </div>
                @else
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Status Pembayaran</dt>
                        <dd class="text-right text-[10px] font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">Menunggu verifikasi Keuangan</dd>
                    </div>
                @endif
                @if ($spr->utj_keterangan)
                    <div class="mt-2 rounded-md bg-zinc-50 px-2 py-1.5 text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">{{ $spr->utj_keterangan }}</div>
                @endif
            </div>

            {{-- Bukti Transfer UTJ --}}
            @if ($spr->utj_bukti_path)
                @php
                    // Bukti masih boleh diganti kalau Keuangan belum verifikasi.
                    $canReupload = ! $spr->utj_confirmed_at && $spr->status === 'submitted';
                @endphp
                <div class="border-t border-zinc-100 bg-amber-50/40 px-4 py-3 dark:border-zinc-800 dark:bg-amber-950/20">
                    <div class="mb-2 flex items-center justify-between gap-1.5">
                        <div class="flex items-center gap-1.5">
                            <flux:icon.document-arrow-up class="size-3.5 text-amber-700 dark:text-amber-400" />
                            <span class="text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">
                                {{ __('Bukti Transfer UTJ') }}
                            </span>
                        </div>
                        @if ($canReupload)
                            <flux:modal.trigger name="ganti-bukti-utj">
                                <button type="button"
                                        class="inline-flex items-center gap-1 rounded-md bg-amber-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-amber-700"
                                        title="Ganti bukti kalau salah upload">
                                    <flux:icon.arrow-path class="size-3" />
                                    {{ __('Ganti Bukti') }}
                                </button>
                            </flux:modal.trigger>
                        @endif
                    </div>
                    @php $ext = strtolower(pathinfo($spr->utj_bukti_path, PATHINFO_EXTENSION)); @endphp
                    @if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']))
                        <a href="{{ asset('storage/'.$spr->utj_bukti_path) }}" target="_blank" class="block">
                            <img src="{{ asset('storage/'.$spr->utj_bukti_path) }}" alt="Bukti UTJ"
                                 class="h-40 w-full rounded-lg border border-zinc-200 object-cover dark:border-zinc-700" />
                        </a>
                        <p class="mt-1.5 text-center text-[10px] text-zinc-500">{{ __('Ketuk untuk lihat ukuran penuh') }}</p>
                    @else
                        <a href="{{ asset('storage/'.$spr->utj_bukti_path) }}" target="_blank"
                           class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-amber-700 shadow-sm dark:bg-zinc-900 dark:text-amber-400">
                            <flux:icon.document class="size-4" />
                            {{ __('Lihat file bukti') }} (.{{ $ext }})
                        </a>
                    @endif
                </div>

                {{-- MODAL: Ganti bukti UTJ --}}
                @if ($canReupload)
                    <flux:modal name="ganti-bukti-utj" class="md:w-md" focusable>
                        <form wire:submit="updateBuktiUtj" class="space-y-4">
                            <div>
                                <flux:heading size="lg">{{ __('Ganti Bukti Transfer UTJ') }}</flux:heading>
                                <flux:subheading>
                                    {{ __('Upload file baru — file lama akan digantikan.') }}
                                </flux:subheading>
                            </div>

                            <flux:field>
                                <flux:label>{{ __('File Bukti Baru') }} <span class="text-red-500">*</span></flux:label>
                                <input type="file" wire:model="utjBuktiNew" accept="image/*"
                                       class="block w-full text-sm text-zinc-700 file:mr-3 file:rounded-md file:border-0 file:bg-amber-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-amber-700 hover:file:bg-amber-200 dark:text-zinc-300 dark:file:bg-amber-950 dark:file:text-amber-300" />
                                <flux:description class="text-[10px]">{{ __('PNG/JPG maks 5MB. File lama otomatis dihapus.') }}</flux:description>
                                <flux:error name="utjBuktiNew" />
                                <div wire:loading wire:target="utjBuktiNew" class="mt-1 text-xs text-zinc-500">
                                    {{ __('Mengunggah...') }}
                                </div>
                            </flux:field>

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                                </flux:modal.close>
                                <flux:button variant="primary" type="submit">{{ __('Simpan Bukti Baru') }}</flux:button>
                            </div>
                        </form>
                    </flux:modal>
                @endif
            @endif
        </div>

        @if ($spr->catatan)
            <div class="rounded-2xl bg-white px-4 py-3 shadow-sm dark:bg-zinc-900">
                <div class="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Catatan') }}</div>
                <p class="text-xs text-zinc-700 dark:text-zinc-300">{{ $spr->catatan }}</p>
            </div>
        @endif

        {{-- ============ FITUR #6: TTD Digital Konsumen (Card) ============ --}}
        @if ($spr->konsumen_signed_at)
            {{-- Sudah TTD --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-emerald-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-emerald-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                        {{ __('SPR Sudah Ditandatangani Konsumen') }}
                    </span>
                </div>
                <div class="space-y-1.5 px-4 py-3 text-xs">
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Tgl Tanda Tangan</dt>
                        <dd class="text-right font-semibold">{{ $spr->konsumen_signed_at?->translatedFormat('d M Y · H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Status Materai</dt>
                        <dd class="text-right font-semibold">
                            @if ($spr->materai_stamped_at)
                                <span class="text-emerald-700 dark:text-emerald-400">Sudah ber-e-Materai</span>
                            @else
                                <span class="text-[10px] uppercase tracking-wider text-amber-700 dark:text-amber-400">Menunggu Keuangan</span>
                            @endif
                        </dd>
                    </div>
                </div>
            </div>
        @elseif ($spr->isReadyForKonsumenSigning() || $spr->hasActiveSigningLink())
            {{-- Siap generate link atau link aktif --}}
            <div class="overflow-hidden rounded-2xl border-2 border-blue-300 bg-white shadow-sm dark:border-blue-800 dark:bg-zinc-900">
                <div class="flex items-center gap-2 border-b border-blue-100 bg-blue-500/10 px-4 py-2.5 dark:border-blue-900/50">
                    <flux:icon.pencil-square class="size-5 text-blue-600" />
                    <span class="text-sm font-bold uppercase tracking-wider text-blue-800 dark:text-blue-200">
                        {{ __('TTD Konsumen') }}
                    </span>
                </div>
                <div class="p-4">
                    @if ($spr->hasActiveSigningLink())
                        {{-- Link aktif — tampilkan link + tombol copy/share/regenerate --}}
                        <div class="mb-3 rounded-xl bg-emerald-50 p-3 dark:bg-emerald-950/30">
                            <div class="flex items-center gap-1.5 text-[11px] font-bold text-emerald-700 dark:text-emerald-400">
                                <flux:icon.clock class="size-3.5" />
                                {{ __('Link aktif — berlaku sampai') }}
                                <span class="font-mono">{{ $spr->konsumen_signing_link_expires_at?->translatedFormat('H:i') }}</span>
                                <span>({{ $spr->konsumen_signing_link_expires_at?->diffForHumans() }})</span>
                            </div>
                        </div>

                        <div x-data="{ copied: false }" class="space-y-2">
                            <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-700 dark:bg-zinc-800">
                                <input type="text" readonly value="{{ $this->signingUrl }}"
                                       x-ref="linkInput"
                                       class="w-full truncate bg-transparent px-2 py-1 font-mono text-[11px] text-zinc-700 focus:outline-none dark:text-zinc-300" />
                                <button type="button"
                                        @click="navigator.clipboard.writeText($refs.linkInput.value); copied = true; setTimeout(() => copied = false, 2000)"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md bg-blue-600 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-blue-700">
                                    <flux:icon.clipboard class="size-3.5" x-show="!copied" />
                                    <flux:icon.check class="size-3.5" x-show="copied" x-cloak />
                                    <span x-text="copied ? '{{ __('Tersalin') }}' : '{{ __('Salin') }}'"></span>
                                </button>
                            </div>

                            @php
                                $waNumber = preg_replace('/\D/', '', $spr->prospectCustomer?->hp ?? '');
                                if (str_starts_with($waNumber, '0')) {
                                    $waNumber = '62'.substr($waNumber, 1);
                                }
                                $waText = "Halo {$spr->prospectCustomer?->nama_lengkap},\n\nSilakan tanda tangan SPR #{$spr->nomor_display} melalui link berikut (berlaku 1 hari):\n\n{$this->signingUrl}\n\nTerima kasih.";
                                $waUrl = $waNumber ? 'https://wa.me/'.$waNumber.'?text='.urlencode($waText) : null;
                            @endphp
                            @if ($waUrl)
                                <a href="{{ $waUrl }}" target="_blank"
                                   class="flex items-center justify-center gap-2 rounded-lg bg-green-600 py-2.5 text-xs font-bold text-white shadow active:scale-95 hover:bg-green-700">
                                    <flux:icon.paper-airplane class="size-4" />
                                    {{ __('Kirim via WhatsApp') }}
                                </a>
                            @endif

                            <button type="button" wire:click="regenerateSigningLink"
                                    wire:loading.attr="disabled" wire:target="regenerateSigningLink"
                                    class="flex w-full items-center justify-center gap-2 rounded-lg border border-blue-300 bg-white py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50 dark:border-blue-800 dark:bg-zinc-900 dark:text-blue-300">
                                <flux:icon.arrow-path class="size-3.5" />
                                {{ __('Perpanjang / Buat Link Baru') }}
                            </button>

                            <p class="text-center text-[10px] italic text-zinc-500">
                                {{ __('Konsumen wajib input NIK untuk validasi identitas sebelum bisa tanda tangan.') }}
                            </p>
                        </div>
                    @else
                        {{-- Belum ada link — tampilkan tombol generate --}}
                        <p class="mb-3 text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('Buat link tanda tangan untuk konsumen. Berlaku 1 hari.') }}
                        </p>
                        <button type="button" wire:click="generateSigningLink"
                                wire:loading.attr="disabled" wire:target="generateSigningLink"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 py-3 text-sm font-bold text-white shadow active:scale-95 hover:bg-blue-700 disabled:opacity-50">
                            <flux:icon.link class="size-4" wire:loading.remove wire:target="generateSigningLink" />
                            <flux:icon.arrow-path class="size-4 animate-spin" wire:loading wire:target="generateSigningLink" />
                            <span wire:loading.remove wire:target="generateSigningLink">{{ __('Buat Link TTD Konsumen') }}</span>
                            <span wire:loading wire:target="generateSigningLink">{{ __('Membuat link...') }}</span>
                        </button>
                    @endif
                </div>
            </div>
        @endif

        {{-- Link Download PDF final untuk konsumen --}}
        @if ($spr->isFinal())
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-purple-50/50 px-4 py-2.5 dark:border-zinc-800 dark:bg-purple-950/20">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-300">
                        {{ __('Link Download untuk Konsumen') }}
                    </span>
                </div>
                <div class="px-4 py-3">
                    @if ($spr->hasActiveDownloadLink())
                        <div x-data="{ copied: false }" class="space-y-2">
                            <div class="text-[10px] text-zinc-500">
                                {{ __('Link aktif — berlaku') }} {{ $spr->konsumen_download_link_expires_at?->diffForHumans() }}
                            </div>
                            <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-1.5 dark:border-zinc-700 dark:bg-zinc-800">
                                <input type="text" readonly value="{{ $this->downloadUrl }}"
                                       x-ref="dlLinkInput"
                                       class="w-full truncate bg-transparent px-2 py-1 font-mono text-[10px] text-zinc-700 focus:outline-none dark:text-zinc-300" />
                                <button type="button"
                                        @click="navigator.clipboard.writeText($refs.dlLinkInput.value); copied = true; setTimeout(() => copied = false, 2000)"
                                        class="inline-flex shrink-0 items-center gap-1 rounded bg-purple-600 px-2 py-1 text-[10px] font-bold text-white hover:bg-purple-700">
                                    <flux:icon.clipboard class="size-3" x-show="!copied" />
                                    <flux:icon.check class="size-3" x-show="copied" x-cloak />
                                    <span x-text="copied ? '{{ __('OK') }}' : '{{ __('Salin') }}'"></span>
                                </button>
                            </div>

                            @php
                                $waNumberDl = preg_replace('/\D/', '', $spr->prospectCustomer?->hp ?? '');
                                if (str_starts_with($waNumberDl, '0')) {
                                    $waNumberDl = '62'.substr($waNumberDl, 1);
                                }
                                $waTextDl = "Halo {$spr->prospectCustomer?->nama_lengkap},\n\nBerikut link untuk mengunduh salinan Surat Pemesanan Rumah #{$spr->nomor_display} yang sudah lengkap ditandatangani dan bermaterai:\n\n{$this->downloadUrl}\n\nTerima kasih.";
                                $waUrlDl = $waNumberDl ? 'https://wa.me/'.$waNumberDl.'?text='.urlencode($waTextDl) : null;
                            @endphp
                            <div class="flex gap-2">
                                @if ($waUrlDl)
                                    <a href="{{ $waUrlDl }}" target="_blank"
                                       class="flex flex-1 items-center justify-center gap-1.5 rounded-md bg-green-600 py-1.5 text-[11px] font-semibold text-white active:scale-95 hover:bg-green-700">
                                        <flux:icon.paper-airplane class="size-3.5" />
                                        {{ __('Kirim WA') }}
                                    </a>
                                @endif
                                <button type="button" wire:click="regenerateDownloadLink"
                                        wire:loading.attr="disabled" wire:target="regenerateDownloadLink"
                                        class="flex items-center justify-center gap-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-[11px] font-semibold text-zinc-600 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
                                        title="{{ __('Buat link baru') }}">
                                    <flux:icon.arrow-path class="size-3.5" />
                                </button>
                            </div>
                        </div>
                    @else
                        <button type="button" wire:click="generateDownloadLink"
                                wire:loading.attr="disabled" wire:target="generateDownloadLink"
                                class="flex w-full items-center justify-center gap-1.5 rounded-md bg-purple-600 py-2 text-xs font-semibold text-white active:scale-95 hover:bg-purple-700 disabled:opacity-50">
                            <flux:icon.link class="size-3.5" wire:loading.remove wire:target="generateDownloadLink" />
                            <flux:icon.arrow-path class="size-3.5 animate-spin" wire:loading wire:target="generateDownloadLink" />
                            <span wire:loading.remove wire:target="generateDownloadLink">{{ __('Buat Link Download') }}</span>
                            <span wire:loading wire:target="generateDownloadLink">{{ __('Membuat...') }}</span>
                        </button>
                    @endif
                </div>
            </div>
        @endif

        {{-- Meta --}}
        <div class="rounded-2xl bg-zinc-100 px-4 py-2.5 text-[11px] text-zinc-500 dark:bg-zinc-800/50">
            <div class="flex justify-between">
                <span>{{ __('Dibuat') }}</span>
                <span>{{ $spr->created_at?->translatedFormat('d M Y · H:i') }}</span>
            </div>
            @if ($spr->approved_at)
                <div class="mt-0.5 flex justify-between">
                    <span>{{ __('Disetujui') }}</span>
                    <span class="font-semibold text-emerald-600">{{ $spr->approved_at?->translatedFormat('d M Y · H:i') }}</span>
                </div>
            @endif
        </div>
    </div>

</section>
