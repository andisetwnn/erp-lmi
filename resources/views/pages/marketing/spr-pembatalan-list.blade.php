<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Spr;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pembatalan SPR')] class extends Component
{
    use Sortable, WithPagination;

    protected function defaultSortBy(): ?string
    {
        return 'cancelled_at';
    }

    protected function defaultSortDir(): string
    {
        return 'desc';
    }

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Dari session global 'active_proyek_id' (dipilih di sidebar). */
    public ?int $filterProyek = null;

    #[Url(as: 'tgl_from')]
    public ?string $filterTanggalFrom = null;

    #[Url(as: 'tgl_to')]
    public ?string $filterTanggalTo = null;

    public ?int $historyId = null;

    // ============ Edit Refund ============
    public ?int $editId = null;

    public string $editRefundStatus = 'pending';

    public string $editRefundAmount = '0';

    public ?string $editRefundAt = null;

    public ?string $editRefundKeterangan = null;

    public function mount(): void
    {
        $this->filterProyek = session('active_proyek_id');
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->filterProyek = $proyekId;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTanggalFrom(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTanggalTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'filterTanggalFrom', 'filterTanggalTo']);
        $this->resetPage();
    }

    public function openHistory(int $sprId): void
    {
        $this->historyId = $sprId;
        Flux::modal('riwayat-pembayaran')->show();
    }

    public function openEdit(int $sprId): void
    {
        abort_unless(Auth::user()?->can('pembayaran.kelola'), 403);
        $spr = Spr::findOrFail($sprId);
        if ($spr->status !== 'cancelled') {
            Flux::toast(variant: 'warning', text: 'Hanya SPR yang sudah dibatalkan yang bisa diedit pengembaliannya.');

            return;
        }
        $this->editId = $spr->id;
        $this->editRefundStatus = $spr->refund_status ?: 'pending';
        $this->editRefundAmount = (string) (int) (float) $spr->refund_amount;
        $this->editRefundAt = $spr->refund_at?->format('Y-m-d');
        $this->editRefundKeterangan = $spr->refund_keterangan;
        $this->resetErrorBag();
        Flux::modal('edit-pengembalian')->show();
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()?->can('pembayaran.kelola'), 403);

        $validated = $this->validate([
            'editRefundStatus' => ['required', 'in:pending,tidak_ada_refund,partial,full'],
            'editRefundAmount' => ['nullable', 'numeric', 'min:0'],
            'editRefundAt' => ['nullable', 'date'],
            'editRefundKeterangan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'editRefundStatus' => 'status pengembalian',
            'editRefundAmount' => 'jumlah pengembalian',
            'editRefundAt' => 'tanggal pengembalian',
            'editRefundKeterangan' => 'catatan pengembalian',
        ]);

        $spr = Spr::findOrFail($this->editId);
        $oldAmount = (float) $spr->refund_amount;
        $newAmount = (float) ($validated['editRefundAmount'] ?? 0);

        $spr->update([
            'refund_status' => $validated['editRefundStatus'],
            'refund_amount' => $newAmount,
            'refund_at' => $validated['editRefundAt'] ?: null,
            'refund_keterangan' => $validated['editRefundKeterangan'] ?: null,
        ]);

        if ($oldAmount !== $newAmount && $newAmount > 0) {
            BusinessActivityLogger::refundProcessed($spr, $newAmount);
        }

        Flux::modal('edit-pengembalian')->close();
        Flux::toast(variant: 'success', text: "Pengembalian SPR {$spr->nomor_display} diperbarui.");
        $this->reset(['editId', 'editRefundStatus', 'editRefundAmount', 'editRefundAt', 'editRefundKeterangan']);
    }

    public function with(): array
    {
        $proyekSelected = (bool) $this->filterProyek;

        if (! $proyekSelected) {
            $query = Spr::query()->whereRaw('1=0');
        } else {
            $query = Spr::query()
                ->where('status', 'cancelled')
                ->with([
                    'prospectCustomer:id,nama_lengkap,hp,nik',
                    'rumah:id,blok,nomor_unit,tipe_rumah_id,proyek_id',
                    'rumah.tipeRumah:id,tipe,nama_tipe',
                    'sales:id,kode,nama',
                    'alasanPembatalan:id,nama',
                    'realisasiPembayaran:id,spr_id,jenis,jumlah',
                ])
                ->whereHas('rumah', fn ($q) => $q->where('proyek_id', $this->filterProyek))
                ->when($this->filterTanggalFrom, fn ($q) => $q->whereDate('cancelled_at', '>=', $this->filterTanggalFrom))
                ->when($this->filterTanggalTo, fn ($q) => $q->whereDate('cancelled_at', '<=', $this->filterTanggalTo))
                ->when($this->search !== '', function ($q) {
                    $s = $this->search;
                    $q->where(function ($qq) use ($s) {
                        $qq->where('nomor_spr', 'like', "%{$s}%")
                            ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%{$s}%"))
                            ->orWhereHas('rumah', fn ($r) => $r->whereRaw("CONCAT(blok,'-',nomor_unit) like ?", ["%{$s}%"]));
                    });
                });
        }

        $this->applySort($query, ['nomor_spr', 'cancelled_at', 'refund_amount']);

        $sprs = $query->paginate(15);

        // Detail history — pakai realisasiPembayaran (bukan lagi termin).
        $history = $this->historyId
            ? Spr::with([
                'prospectCustomer:id,nama_lengkap,hp',
                'rumah:id,blok,nomor_unit',
                'alasanPembatalan',
                'cancelledBy',
                'realisasiPembayaran',
            ])->find($this->historyId)
            : null;

        return compact('sprs', 'history');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <a href="{{ route('marketing.spr.index') }}" wire:navigate
                   class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <flux:icon.arrow-left class="size-4" />
                </a>
                <div>
                    <flux:heading size="xl">{{ __('Pembatalan SPR') }}</flux:heading>
                </div>
            </div>

            <div class="flex gap-2 self-start sm:self-auto">
                @can('master.kelola')
                    <flux:button variant="ghost" icon="cog-6-tooth" :href="route('master.alasan-pembatalan.index')" wire:navigate>
                        {{ __('Kelola Alasan') }}
                    </flux:button>
                @endcan
                <flux:button variant="primary" icon="plus" :href="route('marketing.spr-batal.input')" wire:navigate>
                    {{ __('Input Pembatalan') }}
                </flux:button>
            </div>
        </div>

        {{-- FILTERS --}}
        <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-12">
            <div class="md:col-span-6">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                            :placeholder="__('Cari SPR / nama / blok-unit...')"
                            :disabled="! $filterProyek" />
            </div>
            <div class="md:col-span-3">
                <flux:input type="date" wire:model.live="filterTanggalFrom" :disabled="! $filterProyek" />
            </div>
            <div class="md:col-span-3">
                <flux:input type="date" wire:model.live="filterTanggalTo" :disabled="! $filterProyek" />
            </div>
        </div>

        @if ($search || $filterTanggalFrom || $filterTanggalTo)
            <div class="mb-3">
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                    {{ __('Reset filter') }}
                </flux:button>
            </div>
        @endif

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <flux:table class="px-4">
                    <flux:table.columns class="bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:table.column class="w-12">{{ __('#') }}</flux:table.column>
                        <x-sortable-column field="nomor_spr" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('SPR') }}</x-sortable-column>
                        <flux:table.column>{{ __('Unit') }}</flux:table.column>
                        <flux:table.column>{{ __('Sales') }}</flux:table.column>
                        <flux:table.column>{{ __('Customer') }}</flux:table.column>
                        <x-sortable-column field="cancelled_at" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Tgl Pembatalan') }}</x-sortable-column>
                        <flux:table.column>{{ __('Alasan') }}</flux:table.column>
                        <flux:table.column>{{ __('Status Refund') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Uang Masuk') }}</flux:table.column>
                        <x-sortable-column field="refund_amount" align="end" :sort-by="$sortBy" :sort-dir="$sortDir">{{ __('Dikembalikan') }}</x-sortable-column>
                        <flux:table.column>{{ __('Tanggal Dikembalikan') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Sisa') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($sprs as $row)
                            @php
                                $uangMasuk = (float) $row->realisasiPembayaran->whereIn('jenis', ['bf', 'um'])->sum('jumlah');
                                $refundAmount = (float) $row->refund_amount;
                                $sisa = max(0, $uangMasuk - $refundAmount);
                                $refundLabel = Spr::REFUND_STATUS[$row->refund_status] ?? '—';
                            @endphp
                            <flux:table.row :key="'row-'.$row->id">
                                <flux:table.cell class="text-zinc-500">{{ $loop->index + ($sprs->firstItem() ?? 1) }}</flux:table.cell>
                                <flux:table.cell variant="strong" class="whitespace-nowrap font-mono text-xs">
                                    <a href="{{ route('marketing.spr.show', $row->id) }}" wire:navigate
                                       class="text-emerald-700 underline-offset-2 hover:underline dark:text-emerald-400">
                                        {{ $row->nomor_display }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <div class="font-mono font-semibold">{{ $row->rumah?->blok }}-{{ $row->rumah?->nomor_unit }}</div>
                                    <div class="text-[10px] text-zinc-500">{{ $row->rumah?->tipeRumah?->tipe ?? '—' }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">{{ $row->sales?->nama ?? '—' }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ $row->prospectCustomer?->nama_lengkap }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">{{ $row->cancelled_at?->format('d-m-Y') ?? '—' }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <div class="text-xs">{{ $row->alasanPembatalan?->nama ?? '—' }}</div>
                                    @if ($row->cancel_keterangan)
                                        <div class="text-[10px] italic text-zinc-500">{{ \Illuminate\Support\Str::limit($row->cancel_keterangan, 40) }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @switch($row->refund_status)
                                        @case('full')
                                            <flux:badge color="green" size="sm">{{ $refundLabel }}</flux:badge>
                                            @break
                                        @case('partial')
                                            <flux:badge color="amber" size="sm">{{ $refundLabel }}</flux:badge>
                                            @break
                                        @case('tidak_ada_refund')
                                            <flux:badge color="rose" size="sm">{{ $refundLabel }}</flux:badge>
                                            @break
                                        @case('pending')
                                            <flux:badge color="zinc" size="sm">{{ $refundLabel }}</flux:badge>
                                            @break
                                        @default
                                            <span class="text-zinc-400">—</span>
                                    @endswitch
                                </flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap font-mono text-xs">
                                    Rp {{ number_format($uangMasuk, 0, ',', '.') }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap font-mono text-xs">
                                    @if ($refundAmount > 0)
                                        Rp {{ number_format($refundAmount, 0, ',', '.') }}
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-xs">{{ $row->refund_at?->format('d-m-Y') ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap font-mono text-xs">
                                    Rp {{ number_format($sisa, 0, ',', '.') }}
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex justify-end gap-1">
                                        <flux:button size="sm" variant="ghost" icon="clock"
                                                     wire:click="openHistory({{ $row->id }})"
                                                     :title="__('Riwayat Pembayaran')" />
                                        @can('pembayaran.kelola')
                                            <flux:button size="sm" variant="ghost" icon="pencil-square"
                                                         wire:click="openEdit({{ $row->id }})"
                                                         :title="__('Edit Pengembalian')" />
                                        @endcan
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="13" class="py-16 text-center">
                                    @if (! $filterProyek)
                                        <div class="flex flex-col items-center gap-2 text-zinc-500">
                                            <flux:icon.building-office-2 class="size-10 text-zinc-300 dark:text-zinc-600" />
                                            <p class="font-semibold">{{ __('Pilih proyek dulu') }}</p>
                                            <p class="text-xs">{{ __('Pilih proyek aktif melalui tombol di sidebar (kiri atas).') }}</p>
                                        </div>
                                    @elseif ($search || $filterTanggalFrom || $filterTanggalTo)
                                        <span class="text-zinc-500">{{ __('Tidak ada pembatalan yang cocok dengan filter.') }}</span>
                                    @else
                                        <span class="text-zinc-500">{{ __('Belum ada SPR yang dibatalkan di proyek ini.') }}</span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        <div class="mt-3">
            {{ $sprs->links() }}
        </div>

        {{-- RIWAYAT PEMBAYARAN MODAL --}}
        <flux:modal name="riwayat-pembayaran" class="md:w-3xl" focusable>
            @if ($history)
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ __('Riwayat Pembayaran Customer') }}</flux:heading>
                        <flux:subheading>
                            <span class="font-mono">{{ $history->nomor_display }}</span> ·
                            {{ $history->prospectCustomer?->nama_lengkap }} ·
                            {{ $history->rumah?->blok }}-{{ $history->rumah?->nomor_unit }}
                        </flux:subheading>
                    </div>

                    {{-- Detail pembatalan --}}
                    <div class="rounded-lg border border-rose-200 bg-rose-50/40 p-3 dark:border-rose-900/50 dark:bg-rose-950/20">
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-rose-700 dark:text-rose-400">{{ __('Pembatalan') }}</div>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                            <div><dt class="text-zinc-500">Tgl Pembatalan</dt><dd class="font-semibold">{{ $history->cancelled_at?->translatedFormat('d M Y · H:i') ?? '—' }}</dd></div>
                            <div><dt class="text-zinc-500">Dibatalkan oleh</dt><dd>{{ $history->cancelledBy?->name ?? '—' }}</dd></div>
                            <div class="col-span-2"><dt class="text-zinc-500">Alasan</dt><dd class="font-semibold">{{ $history->alasanPembatalan?->nama ?? '—' }}</dd></div>
                            @if ($history->cancel_keterangan)
                                <div class="col-span-2"><dt class="text-zinc-500">Keterangan</dt><dd>{{ $history->cancel_keterangan }}</dd></div>
                            @endif
                        </dl>
                    </div>

                    {{-- Riwayat pembayaran --}}
                    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-[10px] font-bold uppercase tracking-wider text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                            {{ __('Pembayaran yang sudah dilakukan customer') }}
                        </div>
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/30">
                                <tr>
                                    <th class="px-3 py-1.5 text-left font-semibold">Jenis</th>
                                    <th class="px-3 py-1.5 text-left font-semibold">Kuitansi</th>
                                    <th class="px-3 py-1.5 text-left font-semibold">Tanggal</th>
                                    <th class="px-3 py-1.5 text-right font-semibold">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @php
                                    $realisasiCair = $history->realisasiPembayaran->whereIn('jenis', ['bf', 'um']);
                                    $totalCair = 0;
                                @endphp
                                @forelse ($realisasiCair as $r)
                                    @php
                                        $totalCair += (float) $r->jumlah;
                                        $jenisLabel = match($r->jenis) {
                                            'bf' => 'UTJ',
                                            'um' => 'Cicilan UM',
                                            default => strtoupper($r->jenis),
                                        };
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-1.5 font-bold">{{ $jenisLabel }}</td>
                                        <td class="px-3 py-1.5 font-mono">{{ $r->nomor_kwitansi ?? '—' }}</td>
                                        <td class="px-3 py-1.5">{{ $r->tanggal_bayar?->format('d-m-Y') }}</td>
                                        <td class="px-3 py-1.5 text-right font-mono">Rp {{ number_format((float) $r->jumlah, 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-6 text-center text-zinc-400 italic">{{ __('Belum ada pembayaran tercatat.') }}</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-emerald-50 dark:bg-emerald-950/30">
                                <tr>
                                    <td colspan="3" class="px-3 py-2 font-bold text-emerald-900 dark:text-emerald-300">Total Uang Masuk</td>
                                    <td class="px-3 py-2 text-right font-mono font-extrabold text-emerald-700 dark:text-emerald-300">Rp {{ number_format($totalCair, 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Pengembalian summary --}}
                    @if ($history->refund_status)
                        <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-3 dark:border-amber-900/50 dark:bg-amber-950/20">
                            <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">{{ __('Pengembalian Uang') }}</div>
                            <dl class="grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
                                <div><dt class="text-zinc-500">Status</dt><dd class="font-semibold">{{ Spr::REFUND_STATUS[$history->refund_status] ?? $history->refund_status }}</dd></div>
                                <div><dt class="text-zinc-500">Jumlah Dikembalikan</dt><dd class="font-mono font-bold">Rp {{ number_format((float) $history->refund_amount, 0, ',', '.') }}</dd></div>
                                <div><dt class="text-zinc-500">Tanggal Dikembalikan</dt><dd>{{ $history->refund_at?->format('d-m-Y') ?? '—' }}</dd></div>
                                @if ($history->refund_keterangan)
                                    <div class="col-span-3"><dt class="text-zinc-500">Keterangan</dt><dd class="italic">{{ $history->refund_keterangan }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    @endif

                    <div class="flex justify-end">
                        <flux:modal.close>
                            <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                        </flux:modal.close>
                    </div>
                </div>
            @endif
        </flux:modal>

        {{-- ============ MODAL: EDIT PENGEMBALIAN ============ --}}
        <flux:modal name="edit-pengembalian" class="md:w-lg" focusable>
            <form wire:submit="saveEdit" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Edit Pengembalian Uang') }}</flux:heading>
                    <flux:subheading>{{ __('Perbarui status, jumlah, tanggal, dan catatan pengembalian uang customer.') }}</flux:subheading>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('Status Pengembalian') }} <span class="text-red-500">*</span></flux:label>
                        <flux:select wire:model="editRefundStatus">
                            <flux:select.option value="pending">{{ __('Menunggu') }}</flux:select.option>
                            <flux:select.option value="tidak_ada_refund">{{ __('Tidak Dikembalikan') }}</flux:select.option>
                            <flux:select.option value="partial">{{ __('Sebagian Dikembalikan') }}</flux:select.option>
                            <flux:select.option value="full">{{ __('Dikembalikan Penuh') }}</flux:select.option>
                        </flux:select>
                        <flux:error name="editRefundStatus" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Jumlah Dikembalikan (Rp)') }}</flux:label>
                        <x-money-input wire="editRefundAmount" />
                        <flux:error name="editRefundAmount" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Tanggal Dikembalikan') }}</flux:label>
                        <flux:input type="date" wire:model="editRefundAt" />
                        <flux:error name="editRefundAt" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Catatan Pengembalian') }} <span class="text-xs font-normal text-zinc-500">— opsional</span></flux:label>
                    <flux:textarea wire:model="editRefundKeterangan" rows="2" placeholder="Mis: dipotong biaya admin Rp 2.000.000" />
                    <flux:error name="editRefundKeterangan" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" icon="check">
                        {{ __('Simpan Perubahan') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

    </div>
</section>
