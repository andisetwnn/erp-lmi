<?php

use App\Models\Master\Spr;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Review SPR')] class extends Component
{
    public Spr $spr;

    public string $pmCatatan = '';

    public function mount(int $id): void
    {
        $this->loadSpr($id);
        $this->pmCatatan = (string) ($this->spr->pm_catatan ?? '');
    }

    private function loadSpr(int $id): void
    {
        $this->spr = Spr::with([
            'prospectCustomer.tempatKerja',
            'rumah.tipeRumah',
            'rumah.proyek',
            'sales',
            'utjConfirmedBy',
            'approvedBy',
            'pmApprovedBy',
        ])->findOrFail($id);
    }

    public function setujui(): void
    {
        abort_unless(Auth::user()->can('spr.approve'), 403);

        if ($this->spr->status !== 'approved') {
            Flux::toast(variant: 'danger', text: 'Hanya SPR yang UTJ-nya sudah dikonfirmasi Keuangan yang bisa disetujui.');
            return;
        }

        if ($this->spr->pm_approved_at) {
            Flux::toast(variant: 'warning', text: 'SPR ini sudah disetujui sebelumnya.');
            return;
        }

        $this->spr->update([
            'pm_approved_at' => now(),
            'pm_approved_by_user_id' => Auth::id(),
            'pm_catatan' => $this->pmCatatan ?: null,
            // Snapshot TTD PM saat approve — supaya print dokumen stabil.
            'ttd_pm_path' => Auth::user()->tanda_tangan_path,
        ]);

        Flux::toast(variant: 'success', text: "SPR {$this->spr->nomor_display} berhasil disetujui.");
        $this->redirect(route('approval.spr.index'), navigate: true);
    }

    public function batalkanApproval(): void
    {
        abort_unless(Auth::user()->can('spr.approve'), 403);

        if (! $this->spr->pm_approved_at) {
            return;
        }

        $this->spr->update([
            'pm_approved_at' => null,
            'pm_approved_by_user_id' => null,
            'pm_catatan' => null,
            'ttd_pm_path' => null,
        ]);

        Flux::modal('konfirmasi-batal-approve')->close();
        Flux::toast(variant: 'success', text: "Approval PM untuk SPR {$this->spr->nomor_display} dibatalkan.");
        $this->loadSpr($this->spr->id);
    }
}; ?>

@php
    $spr = $this->spr;
    $prospect = $spr->prospectCustomer;
    $rumah = $spr->rumah;
    $tipe = $rumah?->tipeRumah;
    $proyek = $rumah?->proyek;
    $sales = $spr->sales;
    [$badgeLabel, $badgeCls] = $spr->statusBadge();
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex items-start gap-3">
            <a href="{{ route('approval.spr.index') }}" wire:navigate
               class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800">
                <flux:icon.arrow-left class="size-4" />
            </a>
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-purple-700 text-white shadow-sm">
                    <flux:icon.clipboard-document-check class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="lg">{{ __('Review SPR') }}</flux:heading>
                        <span @class([
                            'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                            $badgeCls,
                        ])>{{ $badgeLabel }}</span>
                        @if ($spr->pm_approved_at)
                            <span class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-violet-700 dark:bg-violet-950/50 dark:text-violet-300">
                                Approved PM
                            </span>
                        @endif
                    </div>
                    <flux:subheading class="font-mono">{{ $spr->nomor_display }}</flux:subheading>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {{-- LEFT: Ringkasan (2 col) --}}
            <div class="space-y-4 lg:col-span-2">
                {{-- Info Customer & Unit --}}
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                        <flux:icon.user class="size-4 text-violet-600" />
                        {{ __('Customer & Unit') }}
                    </h3>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-xs sm:grid-cols-2">
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">Nama</dt><dd class="font-semibold">{{ $prospect?->nama_lengkap }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">HP</dt><dd class="font-mono font-semibold">{{ $prospect?->hp }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">NIK</dt><dd class="font-mono font-semibold">{{ $prospect?->nik ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">Sales</dt><dd class="font-semibold">{{ $sales?->kode }} · {{ $sales?->nama }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">Proyek</dt><dd class="font-semibold">{{ $proyek?->nama_proyek }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">Unit</dt><dd class="font-mono font-semibold">{{ $rumah?->kode_unit }} · {{ $tipe?->tipe }}</dd></div>
                    </dl>
                </div>

                {{-- Harga --}}
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                        <flux:icon.currency-dollar class="size-4 text-violet-600" />
                        {{ __('Harga & Pembayaran') }}
                    </h3>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-1.5 text-xs sm:grid-cols-2">
                        <div class="flex justify-between"><dt class="text-zinc-500">Harga Jual</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->harga_jual) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-zinc-500">Total Harga</dt><dd class="font-mono font-bold tabular-nums">{{ $fmt($spr->total_harga) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-zinc-500">Jenis Pembayaran</dt><dd class="font-semibold uppercase">{{ Spr::JENIS_PEMBAYARAN[$spr->jenis_pembayaran] ?? $spr->jenis_pembayaran }}</dd></div>
                        @if ($spr->jenis_pembayaran === 'kpr')
                            <div class="flex justify-between"><dt class="text-zinc-500">Nilai KPR</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->nilai_kpr) }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-zinc-500">UM Net</dt><dd class="font-mono tabular-nums">{{ $fmt($spr->um_net) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-zinc-500">UTJ</dt><dd class="font-mono tabular-nums text-emerald-700">{{ $fmt($spr->utj_nominal_aktual ?? $spr->utj_nominal) }}</dd></div>
                    </dl>
                </div>

                {{-- Jejak Approval --}}
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                        <flux:icon.clock class="size-4 text-violet-600" />
                        {{ __('Jejak Approval') }}
                    </h3>
                    <ul class="space-y-2 text-xs">
                        <li class="flex items-start gap-2">
                            <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                            <div>
                                <div class="font-semibold">Sales submit SPR</div>
                                <div class="text-zinc-500">
                                    {{ $spr->created_at?->translatedFormat('d M Y · H:i') }} oleh {{ $sales?->nama ?? '—' }}
                                </div>
                            </div>
                        </li>
                        <li class="flex items-start gap-2">
                            <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                            <div>
                                <div class="font-semibold">Keuangan konfirmasi UTJ</div>
                                <div class="text-zinc-500">
                                    {{ $spr->approved_at?->translatedFormat('d M Y · H:i') }} oleh {{ $spr->utjConfirmedBy?->name ?? '—' }}
                                </div>
                            </div>
                        </li>
                        <li class="flex items-start gap-2">
                            @if ($spr->pm_approved_at)
                                <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-violet-600" />
                                <div>
                                    <div class="font-semibold">Project Manager setujui</div>
                                    <div class="text-zinc-500">
                                        {{ $spr->pm_approved_at?->translatedFormat('d M Y · H:i') }} oleh {{ $spr->pmApprovedBy?->name }}
                                    </div>
                                    @if ($spr->pm_catatan)
                                        <div class="mt-1 rounded-md bg-zinc-50 px-2 py-1 text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                                            {{ $spr->pm_catatan }}
                                        </div>
                                    @endif
                                </div>
                            @else
                                <flux:icon.clock class="mt-0.5 size-4 shrink-0 animate-pulse text-violet-600" />
                                <div class="text-violet-700 dark:text-violet-300 italic">Menunggu approval Project Manager (langkah saat ini)</div>
                            @endif
                        </li>
                        <li class="flex items-start gap-2">
                            @if ($spr->konsumen_signed_at)
                                <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-teal-600" />
                                <div>
                                    <div class="font-semibold">Konsumen tanda tangan digital</div>
                                    <div class="text-zinc-500">
                                        {{ $spr->konsumen_signed_at?->translatedFormat('d M Y · H:i') }}
                                    </div>
                                </div>
                            @else
                                <flux:icon.minus-circle class="mt-0.5 size-4 shrink-0 text-zinc-300" />
                                <div class="text-zinc-500 italic">Menunggu tanda tangan konsumen</div>
                            @endif
                        </li>
                        <li class="flex items-start gap-2">
                            @if ($spr->materai_stamped_at)
                                <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-purple-600" />
                                <div>
                                    <div class="font-semibold">Keuangan bubuhkan e-Materai</div>
                                    <div class="text-zinc-500">
                                        {{ $spr->materai_stamped_at?->translatedFormat('d M Y · H:i') }} oleh {{ $spr->stampedBy?->name ?? '—' }}
                                    </div>
                                </div>
                            @else
                                <flux:icon.minus-circle class="mt-0.5 size-4 shrink-0 text-zinc-300" />
                                <div class="text-zinc-500 italic">Menunggu e-Materai (dilakukan Keuangan setelah konsumen TTD)</div>
                            @endif
                        </li>
                    </ul>
                </div>
            </div>

            {{-- RIGHT: Action --}}
            <div class="space-y-4">
                {{-- Action Card --}}
                <div class="rounded-2xl border-2 border-violet-200 bg-violet-50/50 p-5 shadow-sm dark:border-violet-900/50 dark:bg-violet-950/20">
                    @if ($spr->pm_approved_at)
                        {{-- Sudah approved --}}
                        <div class="mb-3 flex items-center gap-2">
                            <flux:icon.check-badge class="size-6 text-violet-600" />
                            <h3 class="text-sm font-bold text-violet-900 dark:text-violet-200">{{ __('Sudah Disetujui') }}</h3>
                        </div>
                        <p class="mb-3 text-xs text-violet-800 dark:text-violet-300">
                            {{ __('SPR ini sudah disetujui dan dapat dicetak. Jika perlu peninjauan ulang, Anda dapat membatalkan persetujuannya.') }}
                        </p>
                        <div class="space-y-2">
                            <a href="{{ route('marketing.spr.show', $spr->id) }}" wire:navigate
                               class="flex w-full items-center justify-center gap-2 rounded-lg bg-white px-3 py-2 text-xs font-bold text-violet-700 shadow-sm hover:bg-violet-50 dark:bg-zinc-900 dark:text-violet-300">
                                <flux:icon.arrow-top-right-on-square class="size-4" />
                                {{ __('Buka Detail SPR') }}
                            </a>
                            <flux:modal.trigger name="konfirmasi-batal-approve">
                                <flux:button variant="ghost" class="w-full text-xs">
                                    <flux:icon.arrow-uturn-left class="size-3.5" />
                                    {{ __('Batalkan Approval') }}
                                </flux:button>
                            </flux:modal.trigger>
                        </div>
                    @else
                        {{-- Form approval --}}
                        <div class="mb-3 flex items-center gap-2">
                            <flux:icon.clipboard-document-check class="size-6 text-violet-600" />
                            <h3 class="text-sm font-bold text-violet-900 dark:text-violet-200">{{ __('Approval Project Manager') }}</h3>
                        </div>
                        <p class="mb-3 text-xs text-violet-800 dark:text-violet-300">
                            {{ __('Setelah disetujui, tanda tangan Anda akan otomatis melekat pada SPR yang dicetak.') }}
                        </p>

                        <flux:field class="mb-3">
                            <flux:label class="text-xs">{{ __('Catatan (opsional)') }}</flux:label>
                            <flux:textarea wire:model="pmCatatan" rows="3"
                                           placeholder="Contoh: Sudah cross-check dengan data booking. OK." />
                        </flux:field>

                        <flux:modal.trigger name="konfirmasi-approve-spr">
                            <flux:button variant="primary" class="w-full bg-violet-600! hover:bg-violet-700!">
                                <flux:icon.check class="size-4" />
                                {{ __('Setujui SPR') }}
                            </flux:button>
                        </flux:modal.trigger>

                        @if (! auth()->user()->tanda_tangan_path)
                            <p class="mt-2 text-[10px] italic text-amber-700 dark:text-amber-400">
                                ⚠️ {{ __('Anda belum mendaftarkan TTD. Persetujuan akan tetap tercatat tetapi tanpa tanda tangan.') }}
                            </p>
                        @endif
                    @endif
                </div>

            </div>
        </div>

    </div>

    {{-- ============ MODAL: KONFIRMASI APPROVE ============ --}}
    <flux:modal name="konfirmasi-approve-spr" class="md:w-md" focusable>
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <flux:icon.clipboard-document-check class="size-6 text-violet-600" />
                <flux:heading size="lg">{{ __('Setujui SPR?') }}</flux:heading>
            </div>
            <flux:text>
                {{ __('Tanda tangan digital Anda akan otomatis dilekatkan ke dokumen SPR ini. Setelah disetujui, SPR dapat dicetak & didistribusikan.') }}
            </flux:text>

            <div class="rounded-lg bg-violet-50 px-3 py-2 text-xs dark:bg-violet-950/30">
                <div class="flex justify-between text-violet-900 dark:text-violet-200">
                    <span class="font-semibold">SPR</span>
                    <span class="font-mono font-bold">{{ $spr->nomor_display }}</span>
                </div>
                <div class="flex justify-between text-violet-900 dark:text-violet-200">
                    <span class="font-semibold">Customer</span>
                    <span>{{ $prospect?->nama_lengkap ?? '—' }}</span>
                </div>
            </div>

            @if (! auth()->user()->tanda_tangan_path)
                <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                    <flux:icon.exclamation-triangle class="-mt-0.5 mr-1 inline size-3.5" />
                    {{ __('Anda belum mendaftarkan TTD. Persetujuan akan tercatat tetapi tanpa gambar tanda tangan.') }}
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="filled">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button type="button" variant="primary" wire:click="setujui" icon="check"
                             class="bg-violet-600! hover:bg-violet-700!">
                    {{ __('Ya, Setujui') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ============ MODAL: KONFIRMASI BATAL APPROVE ============ --}}
    <flux:modal name="konfirmasi-batal-approve" class="md:w-md" focusable>
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <flux:icon.arrow-uturn-left class="size-6 text-amber-600" />
                <flux:heading size="lg">{{ __('Batalkan Approval PM?') }}</flux:heading>
            </div>
            <flux:text>
                {{ __('Approval Project Manager untuk SPR ini akan dicabut. Tanda tangan PM di dokumen juga akan dihilangkan. SPR tidak bisa dicetak sampai di-approve ulang.') }}
            </flux:text>

            <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs dark:bg-amber-950/30">
                <div class="flex justify-between text-amber-900 dark:text-amber-200">
                    <span class="font-semibold">SPR</span>
                    <span class="font-mono font-bold">{{ $spr->nomor_display }}</span>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="filled">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button type="button" variant="danger" wire:click="batalkanApproval" icon="arrow-uturn-left">
                    {{ __('Ya, Batalkan') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
