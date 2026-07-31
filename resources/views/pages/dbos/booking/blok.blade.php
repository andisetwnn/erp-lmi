<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Booking — Pilih Unit'), Layout('layouts.dbos')] class extends Component {
    public Proyek $proyek;

    #[Url(as: 'view')]
    public string $viewMode = 'siteplan'; // 'siteplan' | 'list'

    public function mount(int $id): void
    {
        $this->proyek = Proyek::findOrFail($id);

        // Kalau proyek belum punya siteplan, fallback ke list mode
        if (! $this->proyek->siteplan && $this->viewMode === 'siteplan') {
            $this->viewMode = 'list';
        }
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['siteplan', 'list']) ? $mode : 'siteplan';
    }

    public function refresh(): void
    {
        // Trigger re-render
    }

    public function with(): array
    {
        // Semua unit dengan tipe + harga (untuk tooltip)
        $units = Rumah::where('proyek_id', $this->proyek->id)
            ->with('tipeRumah:id,tipe,nama_tipe,kategori,harga_jual')
            ->orderBy('blok')
            ->orderBy('nomor_unit')
            ->get();

        // Group by blok untuk list view
        $bloks = Rumah::where('proyek_id', $this->proyek->id)
            ->selectRaw('blok,
                COUNT(*) as total,
                SUM(CASE WHEN status = "available" THEN 1 ELSE 0 END) as available_count,
                SUM(CASE WHEN status = "booking" THEN 1 ELSE 0 END) as booking_count,
                SUM(CASE WHEN status = "terjual" THEN 1 ELSE 0 END) as terjual_count,
                SUM(CASE WHEN status = "draft" THEN 1 ELSE 0 END) as draft_count')
            ->groupBy('blok')
            ->orderBy('blok')
            ->get();

        // Filter unit yang sudah di-mapping ke siteplan (rect ATAU polygon)
        $rectUnits = $units->filter(fn ($u) => ($u->siteplan_x !== null && $u->siteplan_w !== null) && empty($u->siteplan_points));
        $polygonUnits = $units->filter(fn ($u) => is_array($u->siteplan_points) && count($u->siteplan_points) >= 3);
        $mappedUnits = $rectUnits->merge($polygonUnits);
        $unmappedCount = $units->count() - $mappedUnits->count();

        return [
            'units' => $units,
            'mappedUnits' => $mappedUnits,
            'rectUnits' => $rectUnits,
            'polygonUnits' => $polygonUnits,
            'unmappedCount' => $unmappedCount,
            'bloks' => $bloks,
        ];
    }
}; ?>

<section class="px-4 pb-8 pt-5">

    {{-- HEADER --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('dbos.booking.create') }}" wire:navigate
           class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-lg font-bold text-zinc-900 dark:text-white">{{ $proyek->nama_proyek }}</h1>
            <p class="text-xs text-zinc-500">{{ __('Langkah 2 dari 3 · Pilih Unit') }}</p>
        </div>
    </div>

    {{-- TOGGLE VIEW MODE --}}
    @if ($proyek->siteplan)
        <div class="mb-3 flex items-center justify-between">
            <div class="inline-flex overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <button type="button" wire:click="setViewMode('siteplan')"
                        @class([
                            'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold transition',
                            'bg-orange-600 text-white' => $viewMode === 'siteplan',
                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300' => $viewMode !== 'siteplan',
                        ])>
                    <flux:icon.map class="size-4" />
                    {{ __('Siteplan') }}
                </button>
                <button type="button" wire:click="setViewMode('list')"
                        @class([
                            'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold transition',
                            'bg-orange-600 text-white' => $viewMode === 'list',
                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300' => $viewMode !== 'list',
                        ])>
                    <flux:icon.list-bullet class="size-4" />
                    {{ __('List') }}
                </button>
            </div>

            <button type="button" wire:click="refresh"
                    class="inline-flex h-9 items-center gap-1.5 rounded-full bg-white px-3 text-xs font-semibold text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
                <flux:icon.arrow-path class="size-4" wire:loading.class="animate-spin" wire:target="refresh" />
                {{ __('Refresh') }}
            </button>
        </div>
    @endif

    {{-- ============== SITEPLAN VIEW ============== --}}
    @if ($viewMode === 'siteplan' && $proyek->siteplan)

        {{-- LEGEND --}}
        <div class="mb-3 grid grid-cols-2 gap-2 rounded-xl bg-white p-3 text-xs dark:bg-zinc-900 sm:grid-cols-4">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-4 rounded-sm border border-yellow-500 bg-yellow-300"></span>
                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Available') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-4 rounded-sm border border-amber-700 bg-amber-500"></span>
                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Booking') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-4 rounded-sm border border-blue-700 bg-blue-500"></span>
                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Terjual') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-4 rounded-sm border" style="background: rgba(168, 162, 158, 0.55); border-color: rgb(120, 113, 108);"></span>
                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Draft') }}</span>
            </div>
        </div>

        @if ($unmappedCount > 0)
            <div class="mb-3 rounded-lg border-l-4 border-amber-500 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                {{ __(':n unit belum di-mapping ke siteplan. Pakai view "List" untuk akses semua unit, atau minta admin mapping dulu.', ['n' => $unmappedCount]) }}
            </div>
        @endif

        {{-- SITEPLAN INTERACTIVE dengan pan & zoom --}}
        <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
             x-data="siteplanViewer()"
             x-init="initPanzoom()"
             style="height: 70vh;">

            {{-- Zoom controls (overlay) --}}
            <div class="absolute right-3 top-3 z-30 flex flex-col gap-1">
                <button type="button" @click="zoomIn()"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-700 shadow-lg active:scale-95 dark:bg-zinc-800 dark:text-zinc-200">
                    <flux:icon.plus class="size-5" />
                </button>
                <button type="button" @click="zoomOut()"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-700 shadow-lg active:scale-95 dark:bg-zinc-800 dark:text-zinc-200">
                    <flux:icon.minus class="size-5" />
                </button>
                <button type="button" @click="resetView()"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-700 shadow-lg active:scale-95 dark:bg-zinc-800 dark:text-zinc-200"
                        title="{{ __('Reset zoom') }}">
                    <flux:icon.arrows-pointing-out class="size-5" />
                </button>
            </div>

            {{-- Hint mobile --}}
            <div class="pointer-events-none absolute left-3 top-3 z-30 rounded-lg bg-zinc-900/70 px-2.5 py-1 text-[10px] text-white backdrop-blur-sm">
                {{ __('Cubit untuk zoom · drag untuk geser') }}
            </div>

            {{-- Scene yang di-panzoom --}}
            <div x-ref="scene" class="absolute inset-0 origin-top-left" style="transform-origin: 0 0;">
                <div class="relative" style="width: 100%;">
                    <img src="{{ asset('storage/'.$proyek->siteplan) }}" alt="Siteplan"
                         class="block w-full select-none"
                         draggable="false"
                         @load="onImageLoad" />

                    {{-- Rectangle overlays (legacy / unit yang masih pakai rect) --}}
                    @foreach ($rectUnits as $u)
                        @php
                            $bg = match ($u->status) {
                                'available' => 'background: rgba(252, 211, 77, 0.85); border-color: rgb(202, 138, 4);',
                                'booking'   => 'background: rgba(245, 158, 11, 0.85); border-color: rgb(180, 83, 9);',
                                'terjual'   => 'background: rgba(59, 130, 246, 0.85); border-color: rgb(29, 78, 216);',
                                'draft'     => 'background: rgba(168, 162, 158, 0.55); border-color: rgb(120, 113, 108);',
                            };
                            $isClickable = $u->status === 'available';
                            $hargaStr = $u->tipeRumah?->harga_jual > 0
                                ? 'Rp '.number_format($u->tipeRumah->harga_jual, 0, ',', '.')
                                : '—';
                            $tipeStr = $u->tipeRumah ? $u->tipeRumah->tipe.' — '.$u->tipeRumah->nama_tipe : '—';
                            $tooltipText = sprintf('%s-%s · %s · %s · %s',
                                $u->blok, $u->nomor_unit, $tipeStr, $hargaStr, strtoupper($u->status),
                            );
                        @endphp

                        @if ($isClickable)
                            <a href="{{ route('dbos.booking.form', $u->id) }}" wire:navigate
                               data-unit-link
                               title="{{ $tooltipText }}"
                               class="absolute cursor-pointer border-2 transition hover:scale-110 hover:z-10 hover:shadow-lg"
                               style="left: {{ $u->siteplan_x }}%; top: {{ $u->siteplan_y }}%; width: {{ $u->siteplan_w }}%; height: {{ $u->siteplan_h }}%; {{ $bg }}"></a>
                        @else
                            <div title="{{ $tooltipText }}"
                                 class="absolute border-2 cursor-not-allowed"
                                 style="left: {{ $u->siteplan_x }}%; top: {{ $u->siteplan_y }}%; width: {{ $u->siteplan_w }}%; height: {{ $u->siteplan_h }}%; {{ $bg }}"></div>
                        @endif
                    @endforeach

                    {{-- Polygon overlays (unit dengan siteplan_points) --}}
                    @if ($polygonUnits->isNotEmpty())
                        <svg class="pointer-events-none absolute inset-0 h-full w-full"
                             viewBox="0 0 100 100" preserveAspectRatio="none">
                            @foreach ($polygonUnits as $u)
                                @php
                                    $polyFill = match ($u->status) {
                                        'available' => 'rgba(252, 211, 77, 0.85)',
                                        'booking'   => 'rgba(245, 158, 11, 0.85)',
                                        'terjual'   => 'rgba(59, 130, 246, 0.85)',
                                        'draft'     => 'rgba(168, 162, 158, 0.55)',
                                    };
                                    $polyStroke = match ($u->status) {
                                        'available' => 'rgb(202, 138, 4)',
                                        'booking'   => 'rgb(180, 83, 9)',
                                        'terjual'   => 'rgb(29, 78, 216)',
                                        'draft'     => 'rgb(120, 113, 108)',
                                    };
                                    $isClickable = $u->status === 'available';
                                    $hargaStr = $u->tipeRumah?->harga_jual > 0
                                        ? 'Rp '.number_format($u->tipeRumah->harga_jual, 0, ',', '.')
                                        : '—';
                                    $tipeStr = $u->tipeRumah ? $u->tipeRumah->tipe.' — '.$u->tipeRumah->nama_tipe : '—';
                                    $tooltipText = sprintf('%s-%s · %s · %s · %s',
                                        $u->blok, $u->nomor_unit, $tipeStr, $hargaStr, strtoupper($u->status),
                                    );
                                    $pointsStr = collect($u->siteplan_points)
                                        ->map(fn ($p) => $p[0].','.$p[1])
                                        ->implode(' ');
                                @endphp

                                @if ($isClickable)
                                    <a href="{{ route('dbos.booking.form', $u->id) }}" wire:navigate
                                       data-unit-link
                                       class="pointer-events-auto">
                                        <polygon points="{{ $pointsStr }}"
                                                 fill="{{ $polyFill }}"
                                                 stroke="{{ $polyStroke }}"
                                                 stroke-width="0.3"
                                                 vector-effect="non-scaling-stroke"
                                                 class="cursor-pointer transition-all hover:brightness-110">
                                            <title>{{ $tooltipText }}</title>
                                        </polygon>
                                    </a>
                                @else
                                    <polygon points="{{ $pointsStr }}"
                                             fill="{{ $polyFill }}"
                                             stroke="{{ $polyStroke }}"
                                             stroke-width="0.3"
                                             vector-effect="non-scaling-stroke"
                                             class="cursor-not-allowed">
                                        <title>{{ $tooltipText }}</title>
                                    </polygon>
                                @endif
                            @endforeach
                        </svg>
                    @endif
                </div>
            </div>
        </div>

        <p class="mt-3 text-center text-xs text-zinc-500">
            {{ __('Cubit/scroll untuk zoom · Klik kotak') }}
            <span class="inline-block h-2.5 w-3.5 rounded-sm border border-yellow-500 bg-yellow-300 align-middle"></span>
            <span class="font-semibold text-yellow-700 dark:text-yellow-300">{{ __('kuning (Available)') }}</span>
            {{ __('untuk lanjut booking.') }}
        </p>

        <script>
            function siteplanViewer() {
                return {
                    panzoom: null,
                    initPanzoom() {
                        // Load panzoom dari CDN sekali, lalu init
                        if (typeof window.panzoom === 'undefined') {
                            const s = document.createElement('script');
                            s.src = 'https://unpkg.com/panzoom@9.4.3/dist/panzoom.min.js';
                            s.onload = () => this.setup();
                            document.head.appendChild(s);
                        } else {
                            this.setup();
                        }
                    },
                    setup() {
                        if (! this.$refs.scene) return;
                        // Hancurkan instance lama (kalau ada) untuk hot reload Livewire
                        if (this.panzoom) {
                            try { this.panzoom.dispose(); } catch (e) {}
                        }
                        this.panzoom = window.panzoom(this.$refs.scene, {
                            maxZoom: 10,
                            minZoom: 0.5,
                            bounds: true,
                            boundsPadding: 0.2,
                            smoothScroll: false,
                            zoomDoubleClickSpeed: 1, // disable double-click zoom (suka konflik dgn tap)
                            beforeMouseDown(e) {
                                // Cancel panzoom kalau klik di kotak unit (biar bisa klik link)
                                return e.target.closest('[data-unit-link]') !== null;
                            },
                            beforeWheel(e) {
                                // Wheel zoom hanya kalau hold ctrl/cmd
                                return !e.ctrlKey && !e.metaKey;
                            },
                        });
                    },
                    zoomIn() {
                        if (! this.panzoom) return;
                        const r = this.$root.getBoundingClientRect();
                        this.panzoom.smoothZoom(r.width / 2, r.height / 2, 1.5);
                    },
                    zoomOut() {
                        if (! this.panzoom) return;
                        const r = this.$root.getBoundingClientRect();
                        this.panzoom.smoothZoom(r.width / 2, r.height / 2, 0.667);
                    },
                    resetView() {
                        if (! this.panzoom) return;
                        this.panzoom.moveTo(0, 0);
                        this.panzoom.zoomAbs(0, 0, 1);
                    },
                    onImageLoad() {
                        // Bisa adjust initial fit di sini kalau perlu
                    },
                };
            }
        </script>

    {{-- ============== LIST VIEW (fallback) ============== --}}
    @else
        @if ($bloks->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.squares-2x2 class="mx-auto size-10 text-zinc-400" />
                <p class="mt-3 text-sm text-zinc-500">{{ __('Belum ada unit di proyek ini.') }}</p>
            </div>
        @else
            <div class="mb-2 text-xs text-zinc-500">{{ __('Pilih blok dulu:') }}</div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($bloks as $b)
                    @php $hasAvailable = $b->available_count > 0; @endphp
                    <a href="{{ route('dbos.booking.unit', ['id' => $proyek->id, 'blok' => $b->blok]) }}"
                       @if ($hasAvailable) wire:navigate @endif
                       @class([
                           'flex flex-col items-center gap-2 rounded-2xl border p-4 shadow-sm transition active:scale-95',
                           'border-emerald-200 bg-linear-to-br from-emerald-50 to-teal-50 dark:border-emerald-900/50 dark:from-emerald-950/30 dark:to-teal-950/30' => $hasAvailable,
                           'border-zinc-200 bg-white opacity-60 pointer-events-none dark:border-zinc-700 dark:bg-zinc-900' => ! $hasAvailable,
                       ])>
                        <div @class([
                            'flex h-14 w-14 items-center justify-center rounded-2xl text-xl font-bold',
                            'bg-emerald-500 text-white' => $hasAvailable,
                            'bg-zinc-200 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => ! $hasAvailable,
                        ])>
                            {{ $b->blok }}
                        </div>
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">
                            {{ __('Blok :b', ['b' => $b->blok]) }}
                        </div>
                        <div class="text-center text-[11px]">
                            <div>
                                <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $b->available_count }}</span>
                                <span class="text-zinc-500">{{ __('available') }}</span>
                            </div>
                            <div class="text-zinc-400">{{ $b->total }} {{ __('unit total') }}</div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    @endif

</section>
