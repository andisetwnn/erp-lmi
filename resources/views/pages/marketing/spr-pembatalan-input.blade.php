<?php

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Proyek;
use App\Models\Master\Spr;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Input Pembatalan SPR')] class extends Component
{
    /** Dari session global 'active_proyek_id' (dipilih di sidebar). */
    public ?int $proyekId = null;

    #[Url(as: 'spr')]
    public ?int $sprId = null;

    public ?Spr $spr = null;

    public ?int $alasanId = null;

    public ?string $keterangan = null;

    public string $refundStatus = 'pending';

    public string $refundAmount = '0';

    public ?string $refundAt = null;

    public ?string $refundKeterangan = null;

    public function mount(): void
    {
        $this->proyekId = session('active_proyek_id');
        if ($this->sprId) {
            $this->loadSpr($this->sprId);
        }
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->proyekId = $proyekId;
        // Reset SPR pick karena proyek beda
        $this->sprId = null;
        $this->spr = null;
    }

    public function updatedSprId(): void
    {
        if ($this->sprId) {
            $this->loadSpr($this->sprId);
        } else {
            $this->spr = null;
        }
    }

    private function loadSpr(int $id): void
    {
        $this->spr = Spr::with([
            'prospectCustomer:id,nama_lengkap,hp,nik',
            'rumah:id,blok,nomor_unit,tipe_rumah_id,proyek_id',
            'rumah.tipeRumah:id,tipe,nama_tipe',
            'sales:id,kode,nama',
            'realisasiPembayaran',
        ])->find($id);

        if (! $this->spr) {
            Flux::toast(variant: 'warning', text: 'SPR tidak ditemukan.');
        } elseif ($this->spr->status === 'cancelled') {
            Flux::toast(variant: 'warning', text: 'SPR ini sudah pernah dibatalkan.');
        }
    }

    public function submit(): void
    {
        $validated = $this->validate([
            'sprId' => ['required', 'exists:spr,id'],
            'alasanId' => ['required', 'exists:alasan_pembatalan,id'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            'refundStatus' => ['required', 'in:pending,tidak_ada_refund,partial,full'],
            'refundAmount' => ['nullable', 'numeric', 'min:0'],
            'refundAt' => ['nullable', 'date'],
            'refundKeterangan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'sprId' => 'SPR',
            'alasanId' => 'alasan pembatalan',
        ]);

        $spr = Spr::findOrFail($validated['sprId']);

        if ($spr->status === 'cancelled') {
            Flux::toast(variant: 'danger', heading: 'Gagal', text: 'SPR ini sudah dibatalkan sebelumnya.');
            return;
        }

        if (! in_array($spr->status, ['approved', 'submitted'])) {
            Flux::toast(variant: 'danger', heading: 'Gagal', text: 'SPR dengan status '.$spr->status.' tidak dapat dibatalkan.');
            return;
        }

        DB::transaction(function () use ($spr, $validated) {
            $spr->update([
                'status' => 'cancelled',
                'alasan_pembatalan_id' => $validated['alasanId'],
                'cancel_keterangan' => $validated['keterangan'] ?: null,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => Auth::id(),
                'refund_status' => $validated['refundStatus'],
                'refund_amount' => (float) ($validated['refundAmount'] ?? 0),
                'refund_at' => $validated['refundAt'] ?: null,
                'refund_keterangan' => $validated['refundKeterangan'] ?: null,
            ]);
            // SprObserver akan otomatis set rumah.status = 'available'
        });

        $spr->load('prospectCustomer', 'alasanPembatalan');
        BusinessActivityLogger::sprCancelled($spr, $spr->alasanPembatalan?->nama);
        if ((float) ($validated['refundAmount'] ?? 0) > 0) {
            BusinessActivityLogger::refundProcessed($spr, (float) $validated['refundAmount']);
        }

        Flux::toast(variant: 'success', text: "SPR {$spr->nomor_display} berhasil dibatalkan.");
        $this->redirect(route('marketing.spr-batal.list'), navigate: true);
    }

    public function with(): array
    {
        $activeProyek = $this->proyekId ? Proyek::find($this->proyekId) : null;

        // SPR yang bisa dibatalkan: status approved/submitted di proyek terpilih
        $sprOptions = $this->proyekId
            ? Spr::query()
                ->whereIn('status', ['approved', 'submitted'])
                ->whereHas('rumah', fn ($q) => $q->where('proyek_id', $this->proyekId))
                ->with(['prospectCustomer:id,nama_lengkap', 'rumah:id,blok,nomor_unit,proyek_id'])
                ->orderByDesc('tanggal_spr')
                ->limit(500)
                ->get(['id', 'nomor_spr', 'prospect_customer_id', 'rumah_id'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'label' => sprintf('%s · [%s-%s] %s',
                        $s->nomor_display,
                        $s->rumah?->blok ?? '-',
                        $s->rumah?->nomor_unit ?? '-',
                        $s->prospectCustomer?->nama_lengkap ?? '-',
                    ),
                ])
            : collect();

        $alasanList = AlasanPembatalan::where('is_aktif', true)->orderBy('nama')->get(['id', 'nama', 'dapat_meneruskan_angsuran']);

        // Total uang masuk untuk SPR yang dipilih — sumber dari spr_realisasi_pembayaran
        // (bukan lagi termin, karena realisasi sudah di-refactor jadi tabel tersendiri).
        $uangMasuk = 0;
        $bfMasuk = 0;
        $umMasuk = 0;
        if ($this->spr) {
            $realisasi = $this->spr->realisasiPembayaran;
            $bfMasuk = (float) $realisasi->where('jenis', 'bf')->sum('jumlah');
            $umMasuk = (float) $realisasi->where('jenis', 'um')->sum('jumlah');
            $uangMasuk = $bfMasuk + $umMasuk;
        }

        return compact('activeProyek', 'sprOptions', 'alasanList', 'uangMasuk', 'bfMasuk', 'umMasuk');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex items-start gap-3">
            <a href="{{ route('marketing.spr-batal.list') }}" wire:navigate
               class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800">
                <flux:icon.arrow-left class="size-4" />
            </a>
            <div>
                <flux:heading size="xl">{{ __('Input Pembatalan SPR') }}</flux:heading>
            </div>
        </div>

        {{-- PICKER --}}
        <div class="mb-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            @if ($activeProyek)
                <div class="mb-3 flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800/50">
                    <flux:icon.home-modern class="size-4 text-zinc-500" />
                    <span class="text-zinc-500">{{ __('Proyek aktif:') }}</span>
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $activeProyek->nama_proyek }}</span>
                    @if ($activeProyek->nama_perumahan)
                        <span class="text-zinc-400">·</span>
                        <span class="text-zinc-600 dark:text-zinc-400">{{ $activeProyek->nama_perumahan }}</span>
                    @endif
                </div>

                <flux:field>
                    <flux:label>{{ __('Nomor SPR') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model.live="sprId" :placeholder="__('— Pilih SPR —')">
                        <flux:select.option value="">{{ __('— Pilih SPR —') }}</flux:select.option>
                        @foreach ($sprOptions as $opt)
                            <flux:select.option value="{{ $opt['id'] }}">{{ $opt['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description class="text-[10px]">{{ __('Hanya SPR berstatus Diajukan / Disetujui yang bisa dibatalkan.') }}</flux:description>
                </flux:field>

                @if ($sprOptions->isEmpty())
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                        <flux:icon.exclamation-triangle class="-mt-0.5 mr-1 inline size-3.5" />
                        {{ __('Tidak ada SPR aktif yang bisa dibatalkan di proyek ini.') }}
                    </p>
                @endif
            @else
                <div class="flex flex-col items-center gap-2 py-8 text-center text-zinc-500">
                    <flux:icon.building-office-2 class="size-10 text-zinc-300 dark:text-zinc-600" />
                    <p class="font-semibold">{{ __('Pilih proyek dulu') }}</p>
                    <p class="text-xs">{{ __('Pilih proyek aktif melalui tombol di sidebar (kiri atas).') }}</p>
                </div>
            @endif
        </div>

        {{-- DATA SPR + FORM PEMBATALAN --}}
        @if ($spr)
            @php
                $prospect = $spr->prospectCustomer;
                $rumah = $spr->rumah;
                $tipe = $rumah?->tipeRumah;
                $hargaNet = (float) $spr->total_harga;
            @endphp

            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">
                <flux:icon.check-circle class="-mt-0.5 mr-1 inline size-4" />
                {{ __('Untuk membatalkan SPR ini, silakan pilih alasan dan klik tombol Batalkan.') }}
            </div>

            {{-- Data SPR --}}
            <div class="mb-4 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('Data SPR') }}</h3>
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr>
                            <th class="w-48 bg-zinc-50 px-4 py-2.5 text-left font-bold dark:bg-zinc-800/50">{{ __('Customer') }}</th>
                            <td class="px-4 py-2.5 text-emerald-700 dark:text-emerald-400">{{ $prospect?->nama_lengkap }}</td>
                        </tr>
                        <tr>
                            <th class="bg-zinc-50 px-4 py-2.5 text-left font-bold dark:bg-zinc-800/50">{{ __('Blok & Type Rumah') }}</th>
                            <td class="px-4 py-2.5">Blok {{ $rumah?->kode_unit }} Type {{ $tipe?->nama_tipe ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th class="bg-zinc-50 px-4 py-2.5 text-left align-top font-bold dark:bg-zinc-800/50">{{ __('Harga Net') }}</th>
                            <td class="px-4 py-2.5 text-right font-mono text-emerald-700 dark:text-emerald-400">
                                {{ number_format($hargaNet, 0, ',', '.') }}
                                @if ($spr->alasanPembatalan?->dapat_meneruskan_angsuran === false)
                                    <div class="mt-0.5 text-[10px] text-emerald-600 dark:text-emerald-500">
                                        {{ __('Jika mengundurkan diri maksimal dipotong 10% (opsional) dr harga net yaitu sebesar') }}
                                        {{ number_format($hargaNet * 0.10, 0, ',', '.') }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="bg-zinc-50 px-4 py-2.5 text-left font-bold dark:bg-zinc-800/50">{{ __('Uang Masuk') }} (UTJ)</th>
                            <td class="px-4 py-2.5 text-right font-mono text-emerald-700 dark:text-emerald-400">
                                {{ number_format($bfMasuk, 0, ',', '.') }}
                            </td>
                        </tr>
                        <tr>
                            <th class="bg-zinc-50 px-4 py-2.5 text-left font-bold dark:bg-zinc-800/50">{{ __('Uang Masuk') }} (Cicilan)</th>
                            <td class="px-4 py-2.5 text-right font-mono text-emerald-700 dark:text-emerald-400">
                                {{ number_format($umMasuk, 0, ',', '.') }}
                            </td>
                        </tr>
                        <tr>
                            <th class="bg-emerald-50 px-4 py-2.5 text-left font-bold uppercase dark:bg-emerald-950/30">{{ __('Total Uang Masuk') }}</th>
                            <td class="bg-emerald-50 px-4 py-2.5 text-right font-mono font-extrabold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">
                                {{ number_format($uangMasuk, 0, ',', '.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Form alasan + refund --}}
            <form wire:submit="submit" class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:field>
                    <flux:label>{{ __('Alasan Pembatalan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                    <flux:select wire:model="alasanId" :placeholder="__('— Alasan Pembatalan —')">
                        <flux:select.option value="">{{ __('— Alasan Pembatalan —') }}</flux:select.option>
                        @foreach ($alasanList as $a)
                            <flux:select.option value="{{ $a->id }}">{{ $a->nama }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="alasanId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Keterangan Tambahan') }} <span class="text-xs font-normal text-zinc-500">— ({{ __('Boleh dikosongkan') }})</span></flux:label>
                    <flux:textarea wire:model="keterangan" rows="2" placeholder="Contoh: tidak lolos BI Checking." />
                    <flux:error name="keterangan" />
                </flux:field>

                {{-- PENGEMBALIAN UANG SECTION --}}
                <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
                    <div class="mb-3 text-xs font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">{{ __('Pengembalian Uang') }}</div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('Status Pengembalian') }}</flux:label>
                            <flux:select wire:model="refundStatus">
                                <flux:select.option value="pending">{{ __('Menunggu') }}</flux:select.option>
                                <flux:select.option value="tidak_ada_refund">{{ __('Tidak Dikembalikan') }}</flux:select.option>
                                <flux:select.option value="partial">{{ __('Sebagian Dikembalikan') }}</flux:select.option>
                                <flux:select.option value="full">{{ __('Dikembalikan Penuh') }}</flux:select.option>
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Jumlah Dikembalikan (Rp)') }}</flux:label>
                            <x-money-input wire="refundAmount" />
                            <flux:error name="refundAmount" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Tanggal Dikembalikan') }}</flux:label>
                            <flux:input type="date" wire:model="refundAt" />
                        </flux:field>
                    </div>

                    <flux:field class="mt-3">
                        <flux:label>{{ __('Catatan Pengembalian') }} <span class="text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                        <flux:textarea wire:model="refundKeterangan" rows="2" placeholder="Mis: dipotong biaya admin Rp 2.000.000" />
                    </flux:field>
                </div>

                <div class="flex justify-center border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="danger" type="submit" icon="x-circle" class="w-full sm:w-64">
                        {{ __('BATALKAN') }}
                    </flux:button>
                </div>
            </form>
        @endif

    </div>
</section>
