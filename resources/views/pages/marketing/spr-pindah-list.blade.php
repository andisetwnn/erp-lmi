<?php

use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\SprSwitching;
use App\Services\SprSwitchingService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pindah Kavling')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $filterProyek = null;

    // ============ MODAL: PINDAH UNIT ============
    public ?int $pindahSprId = null;

    public ?int $pindahRumahBaruId = null;

    public string $pindahAlasan = '';

    // ============ MODAL: SWAP ============
    public ?int $swapSprAId = null;

    public ?int $swapSprBId = null;

    public string $swapAlasan = '';

    public function mount(): void
    {
        $this->filterProyek = session('active_proyek_id');
    }

    #[On('active-proyek-changed')]
    public function syncProyek(int $proyekId): void
    {
        $this->filterProyek = $proyekId;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // Reset selection saat SPR A ganti — biar filter unit / SPR B refresh
    public function updatedPindahSprId(): void
    {
        $this->pindahRumahBaruId = null;
    }

    public function updatedSwapSprAId(): void
    {
        $this->swapSprBId = null;
    }

    // ============ PINDAH UNIT ============

    public function openPindahUnit(): void
    {
        abort_unless(Auth::user()?->can('spr.pindah-unit'), 403);
        $this->reset(['pindahSprId', 'pindahRumahBaruId', 'pindahAlasan']);
        $this->resetErrorBag();
        Flux::modal('pindah-unit')->show();
    }

    public function savePindahUnit(SprSwitchingService $service): void
    {
        abort_unless(Auth::user()?->can('spr.pindah-unit'), 403);

        $validated = $this->validate([
            'pindahSprId' => ['required', 'exists:spr,id'],
            'pindahRumahBaruId' => ['required', 'exists:rumah,id'],
            'pindahAlasan' => ['required', 'string', 'min:5', 'max:500'],
        ], [], [
            'pindahSprId' => 'SPR',
            'pindahRumahBaruId' => 'unit tujuan',
            'pindahAlasan' => 'alasan pindah',
        ]);

        $spr = Spr::with('rumah.tipeRumah')->findOrFail($validated['pindahSprId']);

        try {
            $sprBaru = $service->pindahUnit(
                $spr,
                $validated['pindahRumahBaruId'],
                $validated['pindahAlasan'],
                Auth::id(),
            );

            Flux::modal('pindah-unit')->close();
            Flux::toast(
                variant: 'success',
                heading: 'Berhasil pindah',
                text: "SPR {$spr->nomor_spr} → SPR baru {$sprBaru->nomor_spr} (unit {$sprBaru->rumah?->blok}-{$sprBaru->rumah?->nomor_unit}).",
            );

            $this->reset(['pindahSprId', 'pindahRumahBaruId', 'pindahAlasan']);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $msgs) {
                foreach ($msgs as $msg) {
                    $this->addError('pindahRumahBaruId', $msg);
                }
            }
        }
    }

    // ============ SWAP ============

    public function openSwap(): void
    {
        abort_unless(Auth::user()?->can('spr.pindah-unit'), 403);
        $this->reset(['swapSprAId', 'swapSprBId', 'swapAlasan']);
        $this->resetErrorBag();
        Flux::modal('swap-spr')->show();
    }

    public function saveSwap(SprSwitchingService $service): void
    {
        abort_unless(Auth::user()?->can('spr.pindah-unit'), 403);

        $validated = $this->validate([
            'swapSprAId' => ['required', 'exists:spr,id'],
            'swapSprBId' => ['required', 'exists:spr,id', 'different:swapSprAId'],
            'swapAlasan' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'swapSprBId.different' => 'SPR B harus berbeda dari SPR A.',
        ], [
            'swapSprAId' => 'SPR A',
            'swapSprBId' => 'SPR B',
            'swapAlasan' => 'alasan swap',
        ]);

        $sprA = Spr::with('rumah.tipeRumah')->findOrFail($validated['swapSprAId']);
        $sprB = Spr::with('rumah.tipeRumah')->findOrFail($validated['swapSprBId']);

        try {
            [$sprBaruA, $sprBaruB] = $service->swapSpr(
                $sprA,
                $sprB,
                $validated['swapAlasan'],
                Auth::id(),
            );

            Flux::modal('swap-spr')->close();
            Flux::toast(
                variant: 'success',
                heading: 'Tukar berhasil',
                text: "SPR {$sprA->nomor_spr} ↔ {$sprB->nomor_spr} berhasil ditukar.",
            );

            $this->reset(['swapSprAId', 'swapSprBId', 'swapAlasan']);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $msgs) {
                foreach ($msgs as $msg) {
                    $this->addError('swapSprBId', $msg);
                }
            }
        }
    }

    public function with(): array
    {
        // ============ HISTORY: SprSwitching events (1 baris per event, nomor PK) ============
        $historyQuery = SprSwitching::query()
            ->with([
                'sprLamaA.rumah', 'sprLamaA.prospectCustomer:id,nama_lengkap,hp',
                'sprBaruA.rumah',
                'sprLamaB.rumah', 'sprLamaB.prospectCustomer:id,nama_lengkap,hp',
                'sprBaruB.rumah',
                'processedBy:id,name',
            ])
            ->when($this->filterProyek, function ($q) {
                $q->whereHas('sprLamaA.rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek));
            })
            ->when($this->search, function ($q) {
                $s = "%{$this->search}%";
                $q->where(function ($qq) use ($s) {
                    $qq->where('nomor_switching', 'like', $s)
                        ->orWhere('alasan', 'like', $s)
                        ->orWhereHas('sprLamaA', fn ($sp) => $sp->where('nomor_spr', 'like', $s))
                        ->orWhereHas('sprBaruA', fn ($sp) => $sp->where('nomor_spr', 'like', $s))
                        ->orWhereHas('sprLamaA.prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', $s))
                        ->orWhereHas('sprLamaB.prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', $s));
                });
            })
            ->orderByDesc('processed_at');

        $history = $historyQuery->paginate(15);

        // ============ OPTIONS untuk modal: SPR SELESAI (approved + bermeterai) yang boleh dipindah ============
        $sprAktifOptions = collect();
        if (Auth::user()?->can('spr.pindah-unit')) {
            $sprAktifOptions = Spr::query()
                ->with(['rumah.tipeRumah', 'prospectCustomer:id,nama_lengkap'])
                ->where('status', 'approved')
                ->whereNotNull('spr_finalized_at')
                ->when($this->filterProyek, fn ($q) => $q->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)))
                ->orderByDesc('tanggal_spr')
                ->limit(200)
                ->get();
        }

        // Unit available untuk modal Pindah — filter by kategori & proyek SPR yg dipilih
        $rumahAvailable = collect();
        if ($this->pindahSprId) {
            $spr = $sprAktifOptions->firstWhere('id', $this->pindahSprId);
            if ($spr && $spr->rumah) {
                $rumahAvailable = Rumah::with('tipeRumah')
                    ->where('proyek_id', $spr->rumah->proyek_id)
                    ->where('status', 'available')
                    ->whereHas('tipeRumah', fn ($t) => $t->where('kategori', $spr->rumah->tipeRumah?->kategori))
                    ->orderBy('blok')->orderBy('nomor_unit')
                    ->get();
            }
        }

        // SPR B options untuk modal Swap — filter kategori + proyek sama dgn SPR A, exclude SPR A
        $sprBOptions = collect();
        if ($this->swapSprAId) {
            $sprA = $sprAktifOptions->firstWhere('id', $this->swapSprAId);
            if ($sprA && $sprA->rumah) {
                $sprBOptions = $sprAktifOptions->filter(function ($s) use ($sprA) {
                    return $s->id !== $sprA->id
                        && $s->rumah?->proyek_id === $sprA->rumah?->proyek_id
                        && $s->rumah?->tipeRumah?->kategori === $sprA->rumah?->tipeRumah?->kategori;
                })->values();
            }
        }

        return compact('history', 'sprAktifOptions', 'rumahAvailable', 'sprBOptions');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex items-start gap-3">
            <a href="{{ route('marketing.spr.index') }}" wire:navigate
               class="mt-1 inline-flex h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
               title="{{ __('Kembali ke SPR') }}">
                <flux:icon.arrow-left class="size-4" />
            </a>
            <div class="flex-1">
                <div class="flex items-center gap-2">
                    <flux:heading size="xl">{{ __('Pindah Kavling') }}</flux:heading>
                    <button type="button" x-on:click="$flux.modal('info-pindah-kavling').show()"
                            class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-blue-300 bg-blue-50 text-blue-700 transition hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/70"
                            title="{{ __('Info & ketentuan') }}">
                        <flux:icon.information-circle class="size-4" />
                    </button>
                </div>
                <flux:subheading>
                    {{ __('Kelola perpindahan customer antar kavling.') }}
                </flux:subheading>
            </div>
        </div>

        {{-- ACTION BUTTONS --}}
        @can('spr.pindah-unit')
            <div class="mb-5 flex flex-wrap gap-3">
                <button type="button" wire:click="openPindahUnit"
                        class="group inline-flex items-center gap-2 rounded-xl border-2 border-blue-500 bg-blue-50 px-5 py-3 text-sm font-bold text-blue-700 shadow-sm transition hover:bg-blue-100 dark:border-blue-500 dark:bg-blue-950/50 dark:text-blue-300 dark:hover:bg-blue-950">
                    <flux:icon.arrows-right-left class="size-5" />
                    {{ __('Pindah Unit') }}
                </button>
                <button type="button" wire:click="openSwap"
                        class="group inline-flex items-center gap-2 rounded-xl border-2 border-indigo-500 bg-indigo-50 px-5 py-3 text-sm font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-100 dark:border-indigo-500 dark:bg-indigo-950/50 dark:text-indigo-300 dark:hover:bg-indigo-950">
                    <flux:icon.arrow-path-rounded-square class="size-5" />
                    {{ __('Tukar Unit') }}
                </button>
            </div>
        @endcan

        {{-- SEARCH history --}}
        <div class="mb-3">
            <div class="relative max-w-md">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('Cari riwayat: nomor SPR / nama customer / blok-unit...') }}"
                       class="block h-9 w-full rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-xs shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </div>
        </div>

        {{-- HISTORY TABLE --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                {{ __('Riwayat Pindah Kavling') }}
                <span class="ml-1 text-zinc-400">({{ number_format($history->total()) }})</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-left font-bold uppercase text-[10px] text-zinc-500">
                            <th class="px-3 py-2">Nomor Transaksi</th>
                            <th class="px-3 py-2">Tanggal</th>
                            <th class="px-3 py-2">Tipe</th>
                            <th class="px-3 py-2">Customer & Perpindahan</th>
                            <th class="px-3 py-2">Alasan</th>
                            <th class="px-3 py-2">Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($history as $h)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="whitespace-nowrap px-3 py-2">
                                    <a href="{{ route('marketing.spr-pindah.show', $h->id) }}" wire:navigate
                                       class="font-mono font-bold text-emerald-700 underline-offset-2 hover:underline dark:text-emerald-400">
                                        {{ $h->nomor_switching }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-500">
                                    {{ $h->processed_at?->translatedFormat('d M Y') ?? '—' }}
                                    <div class="text-[10px] text-zinc-400">{{ $h->processed_at?->format('H:i') }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    @if ($h->tipe === 'swap')
                                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold uppercase text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">Tukar</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">Pindah</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    {{-- Sisi A --}}
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold">{{ $h->sprLamaA?->prospectCustomer?->nama_lengkap }}</span>
                                        <span class="font-mono text-zinc-500">{{ $h->sprLamaA?->rumah?->blok }}-{{ $h->sprLamaA?->rumah?->nomor_unit }}</span>
                                        <flux:icon.arrow-right class="size-3 text-zinc-400" />
                                        <a href="{{ route('marketing.spr.show', $h->sprBaruA->id) }}" wire:navigate
                                           class="font-mono font-semibold text-emerald-700 hover:underline dark:text-emerald-400">
                                            {{ $h->sprBaruA?->rumah?->blok }}-{{ $h->sprBaruA?->rumah?->nomor_unit }}
                                        </a>
                                    </div>
                                    @if ($h->tipe === 'swap' && $h->sprLamaB)
                                        <div class="mt-1 flex items-center gap-2">
                                            <span class="font-semibold">{{ $h->sprLamaB?->prospectCustomer?->nama_lengkap }}</span>
                                            <span class="font-mono text-zinc-500">{{ $h->sprLamaB?->rumah?->blok }}-{{ $h->sprLamaB?->rumah?->nomor_unit }}</span>
                                            <flux:icon.arrow-right class="size-3 text-zinc-400" />
                                            <a href="{{ route('marketing.spr.show', $h->sprBaruB->id) }}" wire:navigate
                                               class="font-mono font-semibold text-emerald-700 hover:underline dark:text-emerald-400">
                                                {{ $h->sprBaruB?->rumah?->blok }}-{{ $h->sprBaruB?->rumah?->nomor_unit }}
                                            </a>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $h->alasan }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $h->processedBy?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-12 text-center">
                                    <flux:icon.arrows-right-left class="mx-auto mb-2 size-8 text-zinc-300" />
                                    <div class="text-sm font-semibold text-zinc-500">{{ __('Belum ada riwayat pindah kavling') }}</div>
                                    <div class="mt-1 text-xs text-zinc-400">{{ __('Klik tombol "Pindah Unit" atau "Tukar Unit" di atas untuk memulai.') }}</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $history->links() }}</div>
        </div>
    </div>

    {{-- ============ MODAL: PINDAH UNIT ============ --}}
    <flux:modal name="pindah-unit" class="md:w-lg" focusable>
        <form wire:submit="savePindahUnit" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Pindah Unit') }}</flux:heading>
                <flux:subheading>{{ __('SPR lama dibatalkan, SPR baru diterbitkan untuk unit tujuan.') }}</flux:subheading>
            </div>

            {{-- Pilih SPR --}}
            <flux:field>
                <flux:label>{{ __('SPR yang akan dipindah') }} <span class="text-red-500">*</span></flux:label>
                <flux:select wire:model.live="pindahSprId">
                    <flux:select.option value="">— {{ __('Pilih SPR') }} —</flux:select.option>
                    @foreach ($sprAktifOptions as $s)
                        <flux:select.option value="{{ $s->id }}">
                            {{ $s->nomor_display }} · {{ $s->prospectCustomer?->nama_lengkap }} · {{ $s->rumah?->blok }}-{{ $s->rumah?->nomor_unit }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description class="text-[10px]">{{ __('Hanya SPR berstatus SELESAI (sudah disetujui dan bermeterai) yang bisa dipindah.') }}</flux:description>
                <flux:error name="pindahSprId" />
            </flux:field>

            @if ($pindahSprId)
                @php $sprPilih = $sprAktifOptions->firstWhere('id', $pindahSprId); @endphp
                @if ($sprPilih)
                    <div class="rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/50">
                        <div class="font-semibold">{{ $sprPilih->prospectCustomer?->nama_lengkap }}</div>
                        <div class="text-zinc-500">
                            SPR {{ $sprPilih->nomor_display }} · Unit {{ $sprPilih->rumah?->blok }}-{{ $sprPilih->rumah?->nomor_unit }} · Rp {{ number_format((float) $sprPilih->total_harga, 0, ',', '.') }}
                        </div>
                    </div>

                    {{-- Pilih unit tujuan --}}
                    <flux:field>
                        <flux:label>{{ __('Pindah ke unit') }} <span class="text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="pindahRumahBaruId">
                            <flux:select.option value="">— {{ __('Pilih unit tujuan') }} —</flux:select.option>
                            @foreach ($rumahAvailable as $r)
                                <flux:select.option value="{{ $r->id }}">
                                    {{ $r->blok }}-{{ $r->nomor_unit }} · {{ $r->tipeRumah?->nama_tipe }} · Rp {{ number_format((float) ($r->tipeRumah?->harga_jual ?? 0), 0, ',', '.') }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description class="text-[10px]">
                            {{ __('Hanya unit tersedia di proyek dan kategori yang sama') }}
                            @if ($rumahAvailable->isEmpty())
                                — <span class="text-rose-600">{{ __('tidak ada unit tersedia.') }}</span>
                            @endif
                        </flux:description>
                        <flux:error name="pindahRumahBaruId" />
                    </flux:field>

                    @if ($pindahRumahBaruId)
                        @php
                            $rumahTujuan = $rumahAvailable->firstWhere('id', (int) $pindahRumahBaruId);
                            $hargaBaru = (float) ($rumahTujuan?->tipeRumah?->harga_jual ?? 0);
                            $hargaLama = (float) ($sprPilih->total_harga ?? 0);
                            $selisih = $hargaBaru - $hargaLama;
                        @endphp
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs dark:border-blue-900 dark:bg-blue-950/30">
                            <div class="flex justify-between"><span>Harga lama</span><span class="font-mono">Rp {{ number_format($hargaLama, 0, ',', '.') }}</span></div>
                            <div class="flex justify-between"><span>Harga baru</span><span class="font-mono">Rp {{ number_format($hargaBaru, 0, ',', '.') }}</span></div>
                            <div class="mt-1 flex justify-between border-t border-blue-200 pt-1 font-bold dark:border-blue-800">
                                <span>Selisih</span>
                                <span class="font-mono {{ $selisih >= 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                                    {{ $selisih >= 0 ? '+' : '' }}Rp {{ number_format($selisih, 0, ',', '.') }}
                                    <span class="text-[10px] font-normal">({{ $selisih >= 0 ? 'tambah UM' : 'refund' }})</span>
                                </span>
                            </div>
                        </div>
                    @endif
                @endif
            @endif

            <flux:field>
                <flux:label>{{ __('Alasan Pindah') }} <span class="text-red-500">*</span></flux:label>
                <flux:textarea wire:model="pindahAlasan" rows="2" placeholder="mis. Customer minta view taman, unit sekarang tidak sesuai brief" />
                <flux:error name="pindahAlasan" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" icon="arrows-right-left">
                    {{ __('Konfirmasi Pindah') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ============ MODAL: SWAP 2 SPR ============ --}}
    <flux:modal name="swap-spr" class="md:w-lg" focusable>
        <form wire:submit="saveSwap" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Tukar Unit') }}</flux:heading>
                <flux:subheading>{{ __('Tukar unit antar 2 customer aktif. Kedua SPR lama dibatalkan, 2 SPR baru diterbitkan bersamaan.') }}</flux:subheading>
            </div>

            {{-- SPR A --}}
            <flux:field>
                <flux:label>{{ __('SPR A') }} <span class="text-red-500">*</span></flux:label>
                <flux:select wire:model.live="swapSprAId">
                    <flux:select.option value="">— {{ __('Pilih SPR A') }} —</flux:select.option>
                    @foreach ($sprAktifOptions as $s)
                        <flux:select.option value="{{ $s->id }}">
                            {{ $s->nomor_display }} · {{ $s->prospectCustomer?->nama_lengkap }} · {{ $s->rumah?->blok }}-{{ $s->rumah?->nomor_unit }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="swapSprAId" />
            </flux:field>

            @if ($swapSprAId)
                @php $sprAPilih = $sprAktifOptions->firstWhere('id', $swapSprAId); @endphp
                @if ($sprAPilih)
                    <div class="rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/50">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">SPR A</div>
                        <div class="mt-1 font-semibold">{{ $sprAPilih->prospectCustomer?->nama_lengkap }}</div>
                        <div class="text-zinc-500">SPR {{ $sprAPilih->nomor_display }} · Unit {{ $sprAPilih->rumah?->blok }}-{{ $sprAPilih->rumah?->nomor_unit }} · Rp {{ number_format((float) $sprAPilih->total_harga, 0, ',', '.') }}</div>
                    </div>

                    {{-- SPR B --}}
                    <flux:field>
                        <flux:label>{{ __('SPR B (yang akan ditukar)') }} <span class="text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="swapSprBId">
                            <flux:select.option value="">— {{ __('Pilih SPR B') }} —</flux:select.option>
                            @foreach ($sprBOptions as $sB)
                                <flux:select.option value="{{ $sB->id }}">
                                    {{ $sB->nomor_display }} · {{ $sB->prospectCustomer?->nama_lengkap }} · {{ $sB->rumah?->blok }}-{{ $sB->rumah?->nomor_unit }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description class="text-[10px]">
                            {{ __('Hanya SPR SELESAI di proyek dan kategori yang sama dengan SPR A.') }}
                            @if ($sprBOptions->isEmpty())
                                — <span class="text-rose-600">{{ __('tidak ada SPR yang bisa ditukar.') }}</span>
                            @endif
                        </flux:description>
                        <flux:error name="swapSprBId" />
                    </flux:field>

                    @if ($swapSprBId)
                        @php $sprBPilih = $sprBOptions->firstWhere('id', $swapSprBId); @endphp
                        @if ($sprBPilih)
                            <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-xs dark:border-indigo-900 dark:bg-indigo-950/30">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-indigo-600">SPR B</div>
                                <div class="mt-1 font-semibold">{{ $sprBPilih->prospectCustomer?->nama_lengkap }}</div>
                                <div class="text-zinc-600 dark:text-zinc-400">Unit {{ $sprBPilih->rumah?->blok }}-{{ $sprBPilih->rumah?->nomor_unit }} · Rp {{ number_format((float) $sprBPilih->total_harga, 0, ',', '.') }}</div>
                                <div class="mt-2 border-t border-indigo-200 pt-2 dark:border-indigo-800">
                                    <div class="text-[10px] font-semibold">{{ __('Setelah dipindahkan:') }}</div>
                                    <div class="mt-1 text-zinc-700 dark:text-zinc-300">
                                        {{ $sprAPilih->prospectCustomer?->nama_lengkap }} → pindah ke <b>{{ $sprBPilih->rumah?->blok }}-{{ $sprBPilih->rumah?->nomor_unit }}</b><br>
                                        {{ $sprBPilih->prospectCustomer?->nama_lengkap }} → pindah ke <b>{{ $sprAPilih->rumah?->blok }}-{{ $sprAPilih->rumah?->nomor_unit }}</b>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif
                @endif
            @endif

            <flux:field>
                <flux:label>{{ __('Alasan Tukar') }} <span class="text-red-500">*</span></flux:label>
                <flux:textarea wire:model="swapAlasan" rows="2" placeholder="mis. Kesepakatan kedua customer" />
                <flux:error name="swapAlasan" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" icon="arrow-path-rounded-square">
                    {{ __('Konfirmasi Tukar') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ============ MODAL: INFO & KETENTUAN ============ --}}
    <flux:modal name="info-pindah-kavling" class="md:w-xl" focusable>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Info & Ketentuan Pindah Kavling') }}</flux:heading>
                <flux:subheading>{{ __('Panduan singkat untuk sales admin sebelum memproses perpindahan.') }}</flux:subheading>
            </div>

            {{-- Cara kerja --}}
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-200">
                    <flux:icon.arrows-right-left class="size-3.5" />
                    {{ __('Dua Jenis Perpindahan') }}
                </div>
                <div class="space-y-2 text-xs text-zinc-700 dark:text-zinc-300">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">Pindah</span>
                        <p><b>Pindah Unit</b> — 1 customer pindah dari unit lama ke unit lain yang <b>tersedia</b>. SPR lama dibatalkan, SPR baru diterbitkan otomatis.</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold uppercase text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">Tukar</span>
                        <p><b>Tukar Unit</b> — 2 customer saling menukar unit. Kedua SPR lama dibatalkan, 2 SPR baru diterbitkan bersamaan.</p>
                    </div>
                </div>
            </div>

            {{-- Ketentuan --}}
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/20">
                <div class="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-200">
                    <flux:icon.exclamation-triangle class="size-3.5" />
                    {{ __('Ketentuan yang wajib dipenuhi') }}
                </div>
                <ul class="list-disc space-y-1 pl-5 text-xs text-amber-900 dark:text-amber-200">
                    <li>SPR harus berstatus <b>SELESAI</b> — sudah disetujui Project Manager dan sudah bermeterai. SPR yang masih DIPROSES tidak bisa dipindah (batalkan dan buat SPR baru manual).</li>
                    <li>SPR yang sudah <b>akad kredit</b> tidak bisa dipindah.</li>
                    <li>Kategori tujuan harus <b>sama</b> — subsidi hanya bisa pindah ke subsidi, komersial ke komersial.</li>
                    <li>Unit tujuan harus berstatus <b>tersedia</b> dan berada di <b>proyek yang sama</b>.</li>
                    <li>Alasan perpindahan wajib diisi (minimal 5 karakter) sebagai catatan audit.</li>
                </ul>
            </div>

            {{-- Otomatis diproses --}}
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                <div class="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-200">
                    <flux:icon.sparkles class="size-3.5" />
                    {{ __('Yang otomatis diproses sistem') }}
                </div>
                <ul class="list-disc space-y-1 pl-5 text-xs text-emerald-900 dark:text-emerald-200">
                    <li>Realisasi UTJ dan UM yang sudah cair otomatis <b>berpindah</b> ke SPR baru (kwitansi tetap tercatat).</li>
                    <li>Kalau unit baru <b>lebih murah</b> dan UM sudah melebihi kebutuhan → tercatat <b>refund kelebihan</b> otomatis (status pending, menunggu proses keuangan).</li>
                    <li>Kalau unit baru <b>lebih mahal</b> → sisa UM otomatis dibagi ke termin baru.</li>
                    <li>Setiap perpindahan mendapat <b>nomor transaksi</b> (format PK/YYYY/MM/XXXX) sebagai referensi audit.</li>
                    <li>Status unit lama dikembalikan ke <b>tersedia</b>, unit baru berubah ke <b>booking</b> atau <b>terjual</b>.</li>
                </ul>
            </div>

            {{-- Tips --}}
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-900/50 dark:bg-blue-950/20">
                <div class="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-blue-800 dark:text-blue-200">
                    <flux:icon.light-bulb class="size-3.5" />
                    {{ __('Tips') }}
                </div>
                <ul class="list-disc space-y-1 pl-5 text-xs text-blue-900 dark:text-blue-200">
                    <li>Klik <b>Nomor Transaksi</b> di tabel riwayat untuk melihat detail perpindahan (sisi lama, sisi baru, realisasi terpengaruh).</li>
                    <li>SPR lama yang dibatalkan masih dapat dibuka — banner akan menunjukkan link ke SPR baru dan nomor transaksi.</li>
                </ul>
            </div>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Mengerti') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
