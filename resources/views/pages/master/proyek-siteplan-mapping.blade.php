<?php

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Mapping Siteplan')] class extends Component
{
    public Proyek $proyek;

    public function mount(int $id): void
    {
        $this->proyek = Proyek::findOrFail($id);

        if (! $this->proyek->siteplan) {
            session()->flash('toast', 'Proyek ini belum punya siteplan. Upload dulu di Edit Proyek.');
            $this->redirect(route('master.proyek.index'), navigate: true);
        }
    }

    public function with(): array
    {
        return [
            'units' => Rumah::where('proyek_id', $this->proyek->id)
                ->with('tipeRumah:id,tipe')
                ->orderBy('blok')
                ->orderBy('nomor_unit')
                ->get(['id', 'blok', 'nomor_unit', 'status', 'siteplan_x', 'siteplan_y', 'siteplan_w', 'siteplan_h', 'siteplan_points', 'tipe_rumah_id']),
        ];
    }

    public function savePosition(int $id, $x, $y, $w, $h): void
    {
        Rumah::where('proyek_id', $this->proyek->id)
            ->where('id', $id)
            ->update([
                'siteplan_x' => round((float) $x, 2),
                'siteplan_y' => round((float) $y, 2),
                'siteplan_w' => round((float) $w, 2),
                'siteplan_h' => round((float) $h, 2),
                'siteplan_points' => null,
                'updated_by_user_id' => Auth::id(),
            ]);
    }

    public function savePolygon(int $id, array $points): void
    {
        // Validasi: minimal 3 titik, koordinat 0-100
        if (count($points) < 3) {
            return;
        }
        $clean = [];
        foreach ($points as $p) {
            if (! is_array($p) || count($p) !== 2) {
                return;
            }
            $x = max(0, min(100, round((float) $p[0], 2)));
            $y = max(0, min(100, round((float) $p[1], 2)));
            $clean[] = [$x, $y];
        }

        Rumah::where('proyek_id', $this->proyek->id)
            ->where('id', $id)
            ->update([
                'siteplan_x' => null,
                'siteplan_y' => null,
                'siteplan_w' => null,
                'siteplan_h' => null,
                'siteplan_points' => $clean,
                'updated_by_user_id' => Auth::id(),
            ]);
    }

    public function clearPosition(int $id): void
    {
        Rumah::where('proyek_id', $this->proyek->id)
            ->where('id', $id)
            ->update([
                'siteplan_x' => null,
                'siteplan_y' => null,
                'siteplan_w' => null,
                'siteplan_h' => null,
                'siteplan_points' => null,
                'updated_by_user_id' => Auth::id(),
            ]);
    }

    public function clearAll(): void
    {
        Rumah::where('proyek_id', $this->proyek->id)
            ->update([
                'siteplan_x' => null,
                'siteplan_y' => null,
                'siteplan_w' => null,
                'siteplan_h' => null,
                'siteplan_points' => null,
                'updated_by_user_id' => Auth::id(),
            ]);
        Flux::toast(variant: 'success', text: 'Semua mapping dihapus.');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-450 px-4 py-6 sm:px-6 lg:px-8"
         x-data="siteplanMapper(@js($units->toArray()), @js(asset('storage/'.$proyek->siteplan)))">

        {{-- HEADER --}}
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('master.proyek.index') }}" wire:navigate
                   class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
                    <flux:icon.arrow-left class="size-5" />
                </a>
                <div>
                    <flux:heading size="xl">{{ __('Mapping Siteplan') }}</flux:heading>
                    <flux:subheading>{{ $proyek->nama_proyek }} · {{ $proyek->nama_perumahan }}</flux:subheading>
                </div>
            </div>

            {{-- Progress bar besar + info --}}
            <div class="min-w-64 rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1.5 flex items-baseline justify-between gap-2">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Progress Mapping') }}</div>
                    <div class="text-xs">
                        <span class="text-lg font-bold tabular-nums text-emerald-700" x-text="mappedCount()"></span>
                        <span class="text-zinc-400"> / </span>
                        <span class="tabular-nums text-zinc-600 dark:text-zinc-400" x-text="units.length"></span>
                        <span class="ml-1 text-[10px] text-zinc-500">(<span x-text="units.length > 0 ? Math.round((mappedCount() / units.length) * 100) : 0"></span>%)</span>
                    </div>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div class="h-full bg-linear-to-r from-emerald-500 to-teal-500 transition-all duration-500"
                         :style="`width: ${units.length > 0 ? Math.round((mappedCount() / units.length) * 100) : 0}%;`"></div>
                </div>
            </div>
        </div>

        {{-- MAIN LAYOUT --}}
        <div class="grid gap-4 lg:grid-cols-[1fr_320px]">

            {{-- SITEPLAN CANVAS --}}
            <div class="space-y-3">

                {{-- MODE TOGGLE + THRESHOLD SLIDER --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">{{ __('Mode:') }}</span>
                        <div class="inline-flex rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-700">
                            <button type="button" @click="setMode('rect')"
                                    :class="mode === 'rect' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition">
                                {{ __('Kotak') }}
                            </button>
                            <button type="button" @click="setMode('auto')"
                                    :class="mode === 'auto' ? 'bg-emerald-600 text-white' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition">
                                ✨ {{ __('Auto-detect') }}
                            </button>
                            <button type="button" @click="setMode('manual')"
                                    :class="mode === 'manual' ? 'bg-amber-500 text-white' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition">
                                {{ __('Manual Polygon') }}
                            </button>
                        </div>

                        {{-- Threshold slider (auto mode) --}}
                        <div x-show="mode === 'auto'" x-cloak class="ml-auto flex items-center gap-2">
                            <span class="text-xs text-zinc-500">{{ __('Threshold:') }}</span>
                            <input type="range" min="5" max="80" step="1" x-model.number="threshold"
                                   class="h-1.5 w-32 cursor-pointer appearance-none rounded-full bg-zinc-200 dark:bg-zinc-700" />
                            <span x-text="threshold" class="w-7 text-right text-xs font-bold tabular-nums text-emerald-600 dark:text-emerald-400"></span>
                            <span x-show="!imageReady" class="text-[10px] italic text-amber-600">{{ __('Loading image...') }}</span>
                        </div>

                        {{-- Manual mode controls --}}
                        <div x-show="mode === 'manual'" x-cloak class="ml-auto flex items-center gap-2">
                            <span class="text-xs text-zinc-500">
                                {{ __('Titik:') }} <span x-text="manualPoints.length" class="font-bold text-amber-600"></span>
                            </span>
                            <button type="button" @click="finishManualPolygon()" :disabled="manualPoints.length < 3"
                                    class="rounded-md bg-amber-500 px-2.5 py-1 text-xs font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                                {{ __('Selesai') }}
                            </button>
                            <button type="button" @click="cancelManual()"
                                    class="rounded-md border border-zinc-200 px-2.5 py-1 text-xs text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                                {{ __('Batal') }}
                            </button>
                        </div>
                    </div>

                    {{-- Toolbar: Undo/Redo --}}
                    <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-2 dark:border-zinc-800">
                        <button type="button" @click="undo()" :disabled="!canUndo()"
                                class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
                                title="{{ __('Undo (Ctrl+Z)') }}">
                            <flux:icon.arrow-uturn-left class="size-3.5" />
                            <span>Undo</span>
                            <span class="text-[9px] text-zinc-400">Ctrl+Z</span>
                        </button>
                        <button type="button" @click="redo()" :disabled="!canRedo()"
                                class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
                                title="{{ __('Redo (Ctrl+Y)') }}">
                            <flux:icon.arrow-uturn-right class="size-3.5" />
                            <span>Redo</span>
                            <span class="text-[9px] text-zinc-400">Ctrl+Y</span>
                        </button>
                    </div>

                    {{-- Mode-specific hint --}}
                    <div class="mt-2 text-[11px] leading-relaxed text-zinc-500">
                        <span x-show="mode === 'rect'">
                            {{ __('Pilih unit di kanan → klik di siteplan untuk place kotak → drag tengah untuk geser, pojok kanan-bawah untuk resize.') }}
                        </span>
                        <span x-show="mode === 'auto'" x-cloak>
                            ✨ {{ __('Pilih unit → klik di TENGAH unit di siteplan → sistem deteksi polygon otomatis menggunakan flood-fill. Naikkan threshold jika hasil kurang luas, turunkan jika melebar ke area lain.') }}
                        </span>
                        <span x-show="mode === 'manual'" x-cloak>
                            {{ __('Pilih unit → klik titik-titik vertex polygon di siteplan (min 3 titik) → klik "Selesai" untuk save.') }}
                        </span>
                    </div>
                </div>

                {{-- Canvas (zoomable + scrollable) --}}
                <div class="relative rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    {{-- Zoom controls --}}
                    <div class="absolute right-3 top-3 z-30 flex flex-col gap-1">
                        <button type="button" @click="zoomIn()"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white text-zinc-700 shadow-md transition active:scale-95 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-200">
                            <flux:icon.plus class="size-4" />
                        </button>
                        <button type="button" @click="zoomOut()"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white text-zinc-700 shadow-md transition active:scale-95 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-200">
                            <flux:icon.minus class="size-4" />
                        </button>
                        <button type="button" @click="zoomReset()"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white text-zinc-700 shadow-md transition active:scale-95 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-200"
                                title="{{ __('Reset zoom') }}">
                            <flux:icon.arrows-pointing-out class="size-4" />
                        </button>
                        <div class="rounded-full bg-zinc-900/70 px-1.5 py-0.5 text-center text-[10px] font-bold text-white backdrop-blur-sm">
                            <span x-text="Math.round(zoomLevel * 100)"></span>%
                        </div>
                    </div>

                    {{-- Scrollable viewport --}}
                    <div class="overflow-auto rounded-2xl"
                         style="max-height: 75vh;"
                         x-ref="viewport">
                        <div x-ref="canvas"
                             class="relative select-none"
                             :style="`width: ${100 * zoomLevel}%;`"
                             @pointerdown="canvasClick($event)"
                             @pointermove.window="onPointerMove($event)"
                             @pointerup.window="onPointerUp()"
                             @wheel="if ($event.ctrlKey || $event.metaKey) { $event.preventDefault(); $event.deltaY < 0 ? zoomIn() : zoomOut(); }">
                        <img x-ref="siteImg" src="{{ asset('storage/'.$proyek->siteplan) }}"
                             alt="Siteplan"
                             draggable="false"
                             crossorigin="anonymous"
                             class="pointer-events-none block w-full select-none" />

                        {{-- Rectangle boxes --}}
                        <template x-for="u in units.filter(u => u.siteplan_x !== null && !u.siteplan_points)" :key="'rect-'+u.id">
                            <div class="group absolute border-2 transition-shadow"
                                 :class="{
                                     'border-emerald-600 bg-emerald-400/50 shadow-lg z-20': u.id === currentId,
                                     'border-zinc-500 bg-zinc-400/30 z-10 hover:z-20 hover:border-amber-500 hover:bg-amber-400/40': u.id !== currentId,
                                 }"
                                 :style="`left: ${u.siteplan_x}%; top: ${u.siteplan_y}%; width: ${u.siteplan_w}%; height: ${u.siteplan_h}%; cursor: move;`"
                                 @pointerdown.stop="startDrag($event, u)">

                                <div class="pointer-events-none absolute -top-5 left-0 whitespace-nowrap rounded bg-zinc-900 px-1.5 py-0.5 text-[9px] font-bold text-white opacity-0 transition group-hover:opacity-100"
                                     :class="{ 'opacity-100!': u.id === currentId }"
                                     x-text="`${u.blok}-${u.nomor_unit}`"></div>

                                <div class="absolute -right-1.5 -bottom-1.5 h-3 w-3 rounded-full border border-white bg-emerald-600 opacity-0 transition group-hover:opacity-100"
                                     :class="{ 'opacity-100!': u.id === currentId }"
                                     style="cursor: nwse-resize;"
                                     @pointerdown.stop="startResize($event, u)"></div>

                                <button type="button"
                                        class="absolute -right-2 -top-2 inline-flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-white shadow opacity-0 transition group-hover:opacity-100"
                                        :class="{ 'opacity-100!': u.id === currentId }"
                                        @pointerdown.stop=""
                                        @click.stop="removeBox(u.id)">
                                    <flux:icon.x-mark class="size-2.5" />
                                </button>
                            </div>
                        </template>

                        {{-- Polygon overlay (SVG, server-rendered untuk avoid Alpine SVG namespace bug) --}}
                        <svg class="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                            @foreach ($units as $u)
                                @if (is_array($u->siteplan_points) && count($u->siteplan_points) >= 3)
                                    @php
                                        $polyPoints = collect($u->siteplan_points)
                                            ->map(fn ($p) => $p[0].','.$p[1])
                                            ->implode(' ');
                                        // Warna polygon mengikuti status unit
                                        $statusFill = match ($u->status) {
                                            'available' => 'rgba(252, 211, 77, 0.80)',   // yellow
                                            'booking'   => 'rgba(245, 158, 11, 0.80)',   // amber
                                            'terjual'   => 'rgba(59, 130, 246, 0.80)',   // blue
                                            'draft'     => 'rgba(168, 162, 158, 0.55)',  // warm gray, terlihat di bg putih
                                        };
                                        $statusStroke = match ($u->status) {
                                            'available' => 'rgb(202, 138, 4)',
                                            'booking'   => 'rgb(180, 83, 9)',
                                            'terjual'   => 'rgb(29, 78, 216)',
                                            'draft'     => 'rgb(120, 113, 108)',
                                        };
                                    @endphp
                                    <polygon
                                        points="{{ $polyPoints }}"
                                        stroke-width="0.3"
                                        vector-effect="non-scaling-stroke"
                                        :fill="currentId === {{ $u->id }} ? 'rgba(16, 185, 129, 0.85)' : '{{ $statusFill }}'"
                                        :stroke="currentId === {{ $u->id }} ? 'rgb(5, 150, 105)' : '{{ $statusStroke }}'"
                                        :stroke-width="currentId === {{ $u->id }} ? 0.6 : 0.3"
                                        class="pointer-events-auto cursor-pointer transition-all hover:brightness-110"
                                        @click.stop="selectUnit({{ $u->id }})" />
                                @endif
                            @endforeach
                        </svg>

                        {{-- Manual polygon WIP preview --}}
                        <svg x-show="mode === 'manual' && manualPoints.length > 0" x-cloak
                             class="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                            <polyline
                                :points="manualPoints.map(p => p[0]+','+p[1]).join(' ')"
                                fill="rgba(245, 158, 11, 0.25)"
                                stroke="rgb(245, 158, 11)"
                                stroke-width="0.4"
                                stroke-dasharray="1 0.5"
                                vector-effect="non-scaling-stroke" />
                            <template x-for="(p, i) in manualPoints" :key="'mp-'+i">
                                <circle :cx="p[0]" :cy="p[1]" r="0.5" fill="white" stroke="rgb(245, 158, 11)" stroke-width="0.3" vector-effect="non-scaling-stroke" />
                            </template>
                        </svg>

                        {{-- Marker (auto mode click feedback) --}}
                        <div x-show="lastClickMarker" x-cloak
                             :style="`left: ${lastClickMarker?.x}%; top: ${lastClickMarker?.y}%;`"
                             class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2">
                            <div class="size-3 animate-ping rounded-full bg-emerald-500"></div>
                        </div>
                        </div> {{-- /canvas --}}
                    </div> {{-- /viewport --}}
                </div> {{-- /outer relative --}}

                {{-- Status --}}
                <div class="rounded-xl bg-white p-3 text-xs dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-zinc-500">{{ __('Unit aktif:') }}</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400"
                                  x-text="currentLabel() || '(pilih unit dulu →)'"></span>
                            {{-- Remove mapping button — muncul kalau current unit sudah di-mapping --}}
                            <button type="button" x-show="currentIsMapped()" x-cloak
                                    @click="askRemove(currentId)"
                                    class="inline-flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-700 transition hover:bg-red-100 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                                <flux:icon.trash class="size-3" />
                                {{ __('Hapus mapping ini') }}
                            </button>
                        </div>
                        <div class="text-zinc-500">
                            <span x-text="mappedCount()"></span>
                            /
                            <span x-text="units.length"></span>
                            {{ __('unit di-map') }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- SIDEBAR --}}
            <div class="lg:sticky lg:top-4 lg:self-start">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 p-3 dark:border-zinc-800">
                        <input type="text" x-model="search"
                               placeholder="{{ __('Cari unit (mis. A-05)...') }}"
                               class="block h-9 w-full rounded-lg border border-zinc-200 bg-white px-3 text-sm placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />

                        <div class="mt-2 flex gap-1 text-xs">
                            <button type="button" @click="filter = 'all'"
                                    :class="filter === 'all' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                                    class="flex-1 rounded-md py-1.5 font-semibold transition">
                                {{ __('Semua') }} (<span x-text="units.length"></span>)
                            </button>
                            <button type="button" @click="filter = 'unmapped'"
                                    :class="filter === 'unmapped' ? 'bg-amber-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                                    class="flex-1 rounded-md py-1.5 font-semibold transition">
                                {{ __('Belum') }} (<span x-text="unmappedCount()"></span>)
                            </button>
                            <button type="button" @click="filter = 'mapped'"
                                    :class="filter === 'mapped' ? 'bg-emerald-600 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                                    class="flex-1 rounded-md py-1.5 font-semibold transition">
                                {{ __('Sudah') }} (<span x-text="mappedCount()"></span>)
                            </button>
                        </div>

                        {{-- Filter Blok --}}
                        <div class="mt-2">
                            <select x-model="filterBlok"
                                    class="block h-8 w-full rounded-lg border border-zinc-200 bg-white px-2 text-xs focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                                <option value="">{{ __('Semua Blok') }}</option>
                                <template x-for="blok in availableBloks()" :key="blok">
                                    <option :value="blok" x-text="`Blok ${blok}`"></option>
                                </template>
                            </select>
                        </div>
                    </div>

                    <div class="max-h-[60vh] overflow-y-auto p-2">
                        <template x-for="u in filteredUnits()" :key="u.id">
                            <div @click="selectUnit(u.id)"
                                 class="group mb-1 flex w-full cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-left transition"
                                 :class="{
                                     'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/30': u.id === currentId,
                                     'border-zinc-200 bg-white hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900': u.id !== currentId,
                                 }">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold"
                                      :class="isMapped(u)
                                          ? (u.siteplan_points ? 'bg-emerald-500 text-white' : 'bg-zinc-500 text-white')
                                          : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400'">
                                    <span x-show="u.siteplan_points">◆</span>
                                    <span x-show="!u.siteplan_points && u.siteplan_x !== null">▢</span>
                                    <span x-show="!isMapped(u)">○</span>
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="font-mono text-sm font-semibold text-zinc-900 dark:text-white"
                                         x-text="`${u.blok}-${u.nomor_unit}`"></div>
                                    <div class="text-[10px] uppercase tracking-wider"
                                         :class="{
                                             'text-emerald-600': u.status === 'available',
                                             'text-amber-600': u.status === 'booking',
                                             'text-blue-600': u.status === 'terjual',
                                             'text-zinc-500': u.status === 'draft',
                                         }"
                                         x-text="u.status"></div>
                                </div>

                                {{-- Remove mapping (cuma muncul kalau unit udah di-mapping) --}}
                                <button type="button"
                                        x-show="isMapped(u)" x-cloak
                                        @click.stop="askRemove(u.id)"
                                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-zinc-400 opacity-0 transition hover:bg-red-50 hover:text-red-600 group-hover:opacity-100 dark:hover:bg-red-950/50"
                                        :class="{ 'opacity-100': u.id === currentId }"
                                        title="{{ __('Hapus mapping unit ini') }}">
                                    <flux:icon.trash class="size-3.5" />
                                </button>

                                <flux:icon.cursor-arrow-rays class="size-4 shrink-0 text-emerald-600"
                                                             x-show="u.id === currentId" />
                            </div>
                        </template>
                    </div>

                    <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">
                        <button type="button"
                                @click="askRemoveAll()"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                            <flux:icon.trash class="size-3.5" />
                            {{ __('Hapus semua mapping') }}
                        </button>
                    </div>
                </div>

                {{-- Legend --}}
                <div class="mt-3 rounded-xl bg-white p-3 text-[11px] dark:bg-zinc-900">
                    <div class="mb-1.5 font-bold uppercase tracking-wider text-zinc-500">{{ __('Legend Mapping') }}</div>
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500 text-[8px] text-white">◆</span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Polygon (akurat)') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-zinc-500 text-[8px] text-white">▢</span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Kotak (legacy)') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-zinc-200 text-[8px] text-zinc-500 dark:bg-zinc-700">○</span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Belum di-map') }}</span>
                        </div>
                    </div>

                    <div class="my-2 border-t border-zinc-100 dark:border-zinc-800"></div>

                    <div class="mb-1.5 font-bold uppercase tracking-wider text-zinc-500">{{ __('Warna Status') }}</div>
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-4 rounded-sm border border-yellow-600" style="background: rgba(252, 211, 77, 0.8);"></span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Available') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-4 rounded-sm border border-amber-700" style="background: rgba(245, 158, 11, 0.8);"></span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Booking') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-4 rounded-sm border border-blue-700" style="background: rgba(59, 130, 246, 0.8);"></span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Terjual') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-4 rounded-sm border" style="background: rgba(168, 162, 158, 0.55); border-color: rgb(120, 113, 108);"></span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Draft') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-4 rounded-sm border border-emerald-600" style="background: rgba(16, 185, 129, 0.85);"></span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Sedang aktif') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- MODAL: Konfirmasi hapus mapping per unit --}}
            <flux:modal name="confirm-remove-mapping" class="md:w-md">
                <div class="space-y-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:icon.exclamation-triangle class="size-5 text-rose-600" />
                            <flux:heading size="lg">{{ __('Hapus Mapping?') }}</flux:heading>
                        </div>
                        <flux:subheading>
                            {{ __('Mapping unit') }}
                            <span class="font-mono font-bold text-zinc-900 dark:text-white" x-text="removeTargetLabel()"></span>
                            {{ __('akan dihapus dari siteplan. Data unit-nya tidak terpengaruh.') }}
                        </flux:subheading>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled" type="button"
                                         x-on:click="removeTargetId = null">
                                {{ __('Batal') }}
                            </flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="button" @click="confirmRemove()">
                            {{ __('Ya, Hapus') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>

            {{-- MODAL: Konfirmasi hapus semua mapping --}}
            <flux:modal name="confirm-remove-all" class="md:w-md">
                <div class="space-y-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:icon.exclamation-triangle class="size-5 text-rose-600" />
                            <flux:heading size="lg">{{ __('Hapus Semua Mapping?') }}</flux:heading>
                        </div>
                        <flux:subheading>
                            {{ __('Semua') }}
                            <span class="font-bold text-rose-600" x-text="mappedCount()"></span>
                            {{ __('mapping unit di proyek ini akan dihapus. Tindakan ini tidak dapat dibatalkan.') }}
                        </flux:subheading>
                    </div>

                    <div class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:bg-rose-950/30 dark:text-rose-200">
                        <flux:icon.exclamation-triangle class="-mt-0.5 mr-1 inline size-3.5" />
                        {{ __('Data unit (blok, nomor, harga, dll) tidak terpengaruh. Hanya posisi di siteplan yang dihapus.') }}
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="button" @click="confirmRemoveAll()">
                            {{ __('Ya, Hapus Semua') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    </div>

    {{-- ALPINE COMPONENT --}}
    <script>
        function siteplanMapper(initialUnits, siteplanUrl) {
            return {
                units: initialUnits.map(u => ({
                    id: u.id,
                    blok: u.blok,
                    nomor_unit: u.nomor_unit,
                    status: u.status,
                    siteplan_x: u.siteplan_x !== null ? parseFloat(u.siteplan_x) : null,
                    siteplan_y: u.siteplan_y !== null ? parseFloat(u.siteplan_y) : null,
                    siteplan_w: u.siteplan_w !== null ? parseFloat(u.siteplan_w) : null,
                    siteplan_h: u.siteplan_h !== null ? parseFloat(u.siteplan_h) : null,
                    siteplan_points: u.siteplan_points || null,
                })),
                currentId: null,
                search: '',
                filter: 'all',
                filterBlok: '',

                mode: 'auto', // 'rect' | 'auto' | 'manual'
                threshold: 25,
                manualPoints: [],
                lastClickMarker: null,
                zoomLevel: 1,
                removeTargetId: null,

                // Image data untuk flood-fill
                imageReady: false,
                imageData: null,
                imageW: 0,
                imageH: 0,

                dragState: null,

                // Undo/Redo history stack — simpan snapshot state per unit
                history: [],     // stack of {id, prev: {x,y,w,h,points}, next: {x,y,w,h,points}}
                historyIdx: -1,  // pointer ke posisi current di history

                init() {
                    const firstUnmapped = this.units.find(u => !this.isMapped(u));
                    if (firstUnmapped) this.currentId = firstUnmapped.id;
                    else if (this.units.length > 0) this.currentId = this.units[0].id;

                    this.loadImageData();

                    // Keyboard shortcuts: Ctrl+Z (undo), Ctrl+Y atau Ctrl+Shift+Z (redo)
                    window.addEventListener('keydown', (e) => {
                        if (!(e.ctrlKey || e.metaKey)) return;
                        if (e.key === 'z' || e.key === 'Z') {
                            if (e.shiftKey) { this.redo(); }
                            else { this.undo(); }
                            e.preventDefault();
                        } else if (e.key === 'y' || e.key === 'Y') {
                            this.redo();
                            e.preventDefault();
                        }
                    });
                },

                // ===== UNDO / REDO =====
                snapshotUnit(u) {
                    return {
                        siteplan_x: u.siteplan_x,
                        siteplan_y: u.siteplan_y,
                        siteplan_w: u.siteplan_w,
                        siteplan_h: u.siteplan_h,
                        siteplan_points: u.siteplan_points ? JSON.parse(JSON.stringify(u.siteplan_points)) : null,
                    };
                },
                pushHistory(id, prev, next) {
                    // Buang history di depan pointer (branching)
                    this.history = this.history.slice(0, this.historyIdx + 1);
                    this.history.push({ id, prev, next });
                    // Batasi 50 langkah biar gak makan memory
                    if (this.history.length > 50) this.history.shift();
                    this.historyIdx = this.history.length - 1;
                },
                canUndo() { return this.historyIdx >= 0; },
                canRedo() { return this.historyIdx < this.history.length - 1; },
                undo() {
                    if (!this.canUndo()) return;
                    const h = this.history[this.historyIdx];
                    const u = this.units.find(x => x.id === h.id);
                    if (u) {
                        Object.assign(u, h.prev);
                        this.persistUnit(u);
                        this.currentId = u.id;
                    }
                    this.historyIdx--;
                },
                redo() {
                    if (!this.canRedo()) return;
                    this.historyIdx++;
                    const h = this.history[this.historyIdx];
                    const u = this.units.find(x => x.id === h.id);
                    if (u) {
                        Object.assign(u, h.next);
                        this.persistUnit(u);
                        this.currentId = u.id;
                    }
                },
                persistUnit(u) {
                    // Save ke server via Livewire — pakai savePolygon kalau ada points, savePosition kalau rect, atau clearPosition kalau null
                    if (u.siteplan_points && u.siteplan_points.length >= 3) {
                        this.$wire.savePolygon(u.id, u.siteplan_points);
                    } else if (u.siteplan_x !== null) {
                        this.$wire.savePosition(u.id, u.siteplan_x, u.siteplan_y, u.siteplan_w, u.siteplan_h);
                    } else {
                        this.$wire.clearPosition(u.id);
                    }
                },

                // ===== SCROLL/ZOOM TO UNIT =====
                scrollToUnit(u) {
                    if (!u || !this.$refs.viewport || !this.$refs.canvas) return;
                    let cx, cy;
                    if (u.siteplan_points && u.siteplan_points.length >= 3) {
                        // Center of polygon (avg of points)
                        cx = u.siteplan_points.reduce((s, p) => s + p[0], 0) / u.siteplan_points.length;
                        cy = u.siteplan_points.reduce((s, p) => s + p[1], 0) / u.siteplan_points.length;
                    } else if (u.siteplan_x !== null) {
                        cx = u.siteplan_x + u.siteplan_w / 2;
                        cy = u.siteplan_y + u.siteplan_h / 2;
                    } else {
                        return; // not mapped, nothing to scroll to
                    }
                    // Convert % to px & scroll to center in viewport
                    const canvas = this.$refs.canvas;
                    const vp = this.$refs.viewport;
                    const targetX = (cx / 100) * canvas.offsetWidth - vp.clientWidth / 2;
                    const targetY = (cy / 100) * canvas.offsetHeight - vp.clientHeight / 2;
                    vp.scrollTo({ left: Math.max(0, targetX), top: Math.max(0, targetY), behavior: 'smooth' });
                },

                // ===== BLOK FILTER — list unique blok values =====
                availableBloks() {
                    const set = new Set(this.units.map(u => u.blok));
                    return Array.from(set).sort();
                },

                async loadImageData() {
                    try {
                        const img = new Image();
                        img.crossOrigin = 'anonymous';
                        await new Promise((res, rej) => {
                            img.onload = res;
                            img.onerror = rej;
                            img.src = siteplanUrl;
                        });
                        this.imageW = img.naturalWidth;
                        this.imageH = img.naturalHeight;
                        const cnv = document.createElement('canvas');
                        cnv.width = this.imageW;
                        cnv.height = this.imageH;
                        const ctx = cnv.getContext('2d', { willReadFrequently: true });
                        ctx.drawImage(img, 0, 0);
                        this.imageData = ctx.getImageData(0, 0, this.imageW, this.imageH);
                        this.imageReady = true;
                    } catch (e) {
                        console.warn('Gagal load image untuk flood-fill:', e);
                    }
                },

                isMapped(u) {
                    return u.siteplan_x !== null || (u.siteplan_points && u.siteplan_points.length >= 3);
                },

                mappedCount() {
                    return this.units.filter(u => this.isMapped(u)).length;
                },

                unmappedCount() {
                    return this.units.length - this.mappedCount();
                },

                currentLabel() {
                    const u = this.units.find(x => x.id === this.currentId);
                    return u ? `${u.blok}-${u.nomor_unit}` : null;
                },

                currentIsMapped() {
                    const u = this.units.find(x => x.id === this.currentId);
                    return u ? this.isMapped(u) : false;
                },

                removeTargetLabel() {
                    const u = this.units.find(x => x.id === this.removeTargetId);
                    return u ? `${u.blok}-${u.nomor_unit}` : '—';
                },

                askRemove(id) {
                    const u = this.units.find(x => x.id === id);
                    if (!u || !this.isMapped(u)) return;
                    this.removeTargetId = id;
                    if (typeof Flux !== 'undefined') {
                        Flux.modal('confirm-remove-mapping').show();
                    } else {
                        // Fallback Livewire roundtrip
                        this.$wire.dispatch('open-modal', { name: 'confirm-remove-mapping' });
                    }
                },

                confirmRemove() {
                    const id = this.removeTargetId;
                    if (!id) return;
                    const u = this.units.find(x => x.id === id);
                    if (!u) { this.removeTargetId = null; return; }
                    u.siteplan_x = null;
                    u.siteplan_y = null;
                    u.siteplan_w = null;
                    u.siteplan_h = null;
                    u.siteplan_points = null;
                    this.$wire.clearPosition(id);
                    this.removeTargetId = null;
                    if (typeof Flux !== 'undefined') {
                        Flux.modal('confirm-remove-mapping').close();
                    }
                },

                askRemoveAll() {
                    if (typeof Flux !== 'undefined') {
                        Flux.modal('confirm-remove-all').show();
                    } else {
                        this.$wire.dispatch('open-modal', { name: 'confirm-remove-all' });
                    }
                },

                confirmRemoveAll() {
                    // Clear local state for instant UI feedback
                    this.units.forEach(u => {
                        u.siteplan_x = null;
                        u.siteplan_y = null;
                        u.siteplan_w = null;
                        u.siteplan_h = null;
                        u.siteplan_points = null;
                    });
                    this.$wire.clearAll();
                    if (typeof Flux !== 'undefined') {
                        Flux.modal('confirm-remove-all').close();
                    }
                },

                filteredUnits() {
                    const q = this.search.trim().toLowerCase();
                    return this.units.filter(u => {
                        if (this.filter === 'mapped' && !this.isMapped(u)) return false;
                        if (this.filter === 'unmapped' && this.isMapped(u)) return false;
                        if (this.filterBlok && u.blok !== this.filterBlok) return false;
                        if (q) {
                            const code = `${u.blok}-${u.nomor_unit}`.toLowerCase();
                            return code.includes(q);
                        }
                        return true;
                    });
                },

                selectUnit(id) {
                    this.currentId = id;
                    this.manualPoints = [];
                    // Auto-scroll ke polygon kalau unit sudah di-mapping
                    const u = this.units.find(x => x.id === id);
                    if (u && this.isMapped(u)) {
                        this.$nextTick(() => this.scrollToUnit(u));
                    }
                },

                setMode(m) {
                    this.mode = m;
                    this.manualPoints = [];
                    this.dragState = null;
                },

                zoomIn() {
                    this.zoomLevel = Math.min(5, +(this.zoomLevel * 1.25).toFixed(2));
                },

                zoomOut() {
                    this.zoomLevel = Math.max(0.5, +(this.zoomLevel / 1.25).toFixed(2));
                },

                zoomReset() {
                    this.zoomLevel = 1;
                    if (this.$refs.viewport) {
                        this.$refs.viewport.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
                    }
                },

                canvasPos(ev) {
                    const r = this.$refs.canvas.getBoundingClientRect();
                    return {
                        x: ((ev.clientX - r.left) / r.width) * 100,
                        y: ((ev.clientY - r.top) / r.height) * 100,
                    };
                },

                canvasClick(ev) {
                    if (this.dragState) return;
                    if (!this.currentId) return;
                    const u = this.units.find(x => x.id === this.currentId);
                    if (!u) return;

                    const p = this.canvasPos(ev);

                    if (this.mode === 'rect') {
                        const prev = this.snapshotUnit(u);
                        const sz = 1.5;
                        u.siteplan_x = Math.max(0, Math.min(100 - sz, p.x - sz / 2));
                        u.siteplan_y = Math.max(0, Math.min(100 - sz, p.y - sz / 2));
                        u.siteplan_w = sz;
                        u.siteplan_h = sz;
                        u.siteplan_points = null;
                        this.$wire.savePosition(u.id, u.siteplan_x, u.siteplan_y, u.siteplan_w, u.siteplan_h);
                        this.pushHistory(u.id, prev, this.snapshotUnit(u));
                        this.autoNext();
                    } else if (this.mode === 'auto') {
                        this.runAutoDetect(u, p);
                    } else if (this.mode === 'manual') {
                        this.manualPoints.push([+p.x.toFixed(2), +p.y.toFixed(2)]);
                    }
                },

                runAutoDetect(u, p) {
                    if (!this.imageReady) {
                        alert('Gambar belum dimuat. Mohon tunggu sebentar.');
                        return;
                    }
                    this.lastClickMarker = { x: p.x, y: p.y };
                    setTimeout(() => { this.lastClickMarker = null; }, 800);

                    const px = Math.round((p.x / 100) * this.imageW);
                    const py = Math.round((p.y / 100) * this.imageH);

                    const result = floodFillAndTrace(this.imageData, px, py, this.threshold);

                    if (!result || result.region < 50) {
                        alert('Area terlalu kecil. Naikkan threshold atau klik di area unit yang jelas.');
                        return;
                    }
                    if (result.region > this.imageW * this.imageH * 0.4) {
                        alert('Area terlalu besar — kemungkinan flood-fill bablas. Turunkan threshold.');
                        return;
                    }
                    if (!result.boundary || result.boundary.length < 3) {
                        alert('Gagal trace boundary. Coba lagi atau pakai mode manual.');
                        return;
                    }

                    const simplified = douglasPeucker(result.boundary, Math.max(2, this.imageW * 0.005));
                    const pointsPercent = simplified.map(([x, y]) => [
                        +((x / this.imageW) * 100).toFixed(2),
                        +((y / this.imageH) * 100).toFixed(2),
                    ]);

                    if (pointsPercent.length < 3) {
                        alert('Polygon hasil terlalu sederhana. Coba threshold lain.');
                        return;
                    }

                    const prevState = this.snapshotUnit(u);
                    u.siteplan_points = pointsPercent;
                    u.siteplan_x = null;
                    u.siteplan_y = null;
                    u.siteplan_w = null;
                    u.siteplan_h = null;
                    this.$wire.savePolygon(u.id, pointsPercent);
                    this.pushHistory(u.id, prevState, this.snapshotUnit(u));
                    this.autoNext();
                },

                finishManualPolygon() {
                    if (this.manualPoints.length < 3) {
                        alert('Butuh minimal 3 titik untuk polygon.');
                        return;
                    }
                    const u = this.units.find(x => x.id === this.currentId);
                    if (!u) return;
                    const prevState = this.snapshotUnit(u);
                    const pts = this.manualPoints.slice();
                    u.siteplan_points = pts;
                    u.siteplan_x = null;
                    u.siteplan_y = null;
                    u.siteplan_w = null;
                    u.siteplan_h = null;
                    this.$wire.savePolygon(u.id, pts);
                    this.pushHistory(u.id, prevState, this.snapshotUnit(u));
                    this.manualPoints = [];
                    this.autoNext();
                },

                cancelManual() {
                    this.manualPoints = [];
                },

                // ===== Existing rect drag/resize =====
                startDrag(ev, u) {
                    if (this.mode !== 'rect') return;
                    this.selectUnit(u.id);
                    const p = this.canvasPos(ev);
                    this.dragState = {
                        mode: 'move',
                        unitId: u.id,
                        startCanvasX: p.x,
                        startCanvasY: p.y,
                        origX: u.siteplan_x,
                        origY: u.siteplan_y,
                        origW: u.siteplan_w,
                        origH: u.siteplan_h,
                    };
                },

                startResize(ev, u) {
                    if (this.mode !== 'rect') return;
                    this.selectUnit(u.id);
                    const p = this.canvasPos(ev);
                    this.dragState = {
                        mode: 'resize',
                        unitId: u.id,
                        startCanvasX: p.x,
                        startCanvasY: p.y,
                        origX: u.siteplan_x,
                        origY: u.siteplan_y,
                        origW: u.siteplan_w,
                        origH: u.siteplan_h,
                    };
                },

                onPointerMove(ev) {
                    if (!this.dragState) return;
                    const p = this.canvasPos(ev);
                    const u = this.units.find(x => x.id === this.dragState.unitId);
                    if (!u) return;
                    const dx = p.x - this.dragState.startCanvasX;
                    const dy = p.y - this.dragState.startCanvasY;

                    if (this.dragState.mode === 'move') {
                        u.siteplan_x = Math.max(0, Math.min(100 - u.siteplan_w, this.dragState.origX + dx));
                        u.siteplan_y = Math.max(0, Math.min(100 - u.siteplan_h, this.dragState.origY + dy));
                    } else if (this.dragState.mode === 'resize') {
                        u.siteplan_w = Math.max(0.5, Math.min(100 - u.siteplan_x, this.dragState.origW + dx));
                        u.siteplan_h = Math.max(0.5, Math.min(100 - u.siteplan_y, this.dragState.origH + dy));
                    }
                },

                onPointerUp() {
                    if (!this.dragState) return;
                    const u = this.units.find(x => x.id === this.dragState.unitId);
                    if (u && u.siteplan_x !== null) {
                        this.$wire.savePosition(u.id, u.siteplan_x, u.siteplan_y, u.siteplan_w, u.siteplan_h);
                    }
                    this.dragState = null;
                },

                removeBox(id) {
                    const u = this.units.find(x => x.id === id);
                    if (!u) return;
                    u.siteplan_x = null;
                    u.siteplan_y = null;
                    u.siteplan_w = null;
                    u.siteplan_h = null;
                    u.siteplan_points = null;
                    this.$wire.clearPosition(id);
                },

                autoNext() {
                    const idx = this.units.findIndex(u => u.id === this.currentId);
                    for (let i = idx + 1; i < this.units.length; i++) {
                        if (!this.isMapped(this.units[i])) { this.currentId = this.units[i].id; return; }
                    }
                    for (let i = 0; i < idx; i++) {
                        if (!this.isMapped(this.units[i])) { this.currentId = this.units[i].id; return; }
                    }
                },
            };
        }

        // ============ FLOOD-FILL + BOUNDARY TRACE ============
        function floodFillAndTrace(imageData, sx, sy, threshold) {
            const w = imageData.width;
            const h = imageData.height;
            const data = imageData.data;
            if (sx < 0 || sx >= w || sy < 0 || sy >= h) return null;

            const startIdx = (sy * w + sx) * 4;
            const refR = data[startIdx], refG = data[startIdx + 1], refB = data[startIdx + 2];
            const mask = new Uint8Array(w * h);
            const stack = [sy * w + sx];

            // BFS flood-fill (4-connectivity)
            let regionCount = 0;
            while (stack.length) {
                const idx = stack.pop();
                if (mask[idx]) continue;
                const x = idx % w;
                const y = (idx - x) / w;
                const pi = idx * 4;
                if (Math.abs(data[pi] - refR) > threshold ||
                    Math.abs(data[pi + 1] - refG) > threshold ||
                    Math.abs(data[pi + 2] - refB) > threshold) continue;
                mask[idx] = 1;
                regionCount++;
                if (x + 1 < w) stack.push(idx + 1);
                if (x > 0) stack.push(idx - 1);
                if (y + 1 < h) stack.push(idx + w);
                if (y > 0) stack.push(idx - w);
                // Safety cap
                if (regionCount > w * h * 0.5) return { region: regionCount, boundary: null };
            }

            // Trace boundary: Moore-neighbor tracing
            const boundary = mooreNeighborTrace(mask, w, h, sx, sy);
            return { region: regionCount, boundary };
        }

        function mooreNeighborTrace(mask, w, h, startSx, startSy) {
            // Find topmost-leftmost boundary pixel starting from approximate seed
            let startX = -1, startY = -1;
            // Scan top to bottom for the first filled pixel in same column or near
            for (let y = 0; y < h && startX === -1; y++) {
                for (let x = 0; x < w; x++) {
                    if (mask[y * w + x]) { startX = x; startY = y; break; }
                }
            }
            if (startX === -1) return [];

            // Directions clockwise starting from up
            // [dx, dy] for: N, NE, E, SE, S, SW, W, NW
            const D = [[0,-1],[1,-1],[1,0],[1,1],[0,1],[-1,1],[-1,0],[-1,-1]];
            const isFilled = (x, y) => x >= 0 && x < w && y >= 0 && y < h && mask[y * w + x];

            const boundary = [[startX, startY]];
            let cx = startX, cy = startY;
            // Came from direction (the direction we entered from). Start "from west" → checked direction = 6
            let backDir = 6;
            const maxIter = w * h;
            let iter = 0;

            while (iter++ < maxIter) {
                // Start checking from (backDir + 2) mod 8 (90° clockwise from backDir)
                let found = false;
                const startDir = (backDir + 2) % 8;
                for (let i = 0; i < 8; i++) {
                    const d = (startDir + i) % 8;
                    const nx = cx + D[d][0];
                    const ny = cy + D[d][1];
                    if (isFilled(nx, ny)) {
                        cx = nx; cy = ny;
                        // The backDir = opposite of the direction we just moved
                        backDir = (d + 4) % 8;
                        boundary.push([cx, cy]);
                        found = true;
                        break;
                    }
                }
                if (!found) break;
                if (cx === startX && cy === startY && boundary.length > 1) break;
                if (boundary.length > 50000) break;
            }
            return boundary;
        }

        // ============ DOUGLAS-PEUCKER SIMPLIFICATION ============
        function douglasPeucker(points, tolerance) {
            if (points.length < 3) return points.slice();
            const tol2 = tolerance * tolerance;
            const keep = new Uint8Array(points.length);
            keep[0] = 1;
            keep[points.length - 1] = 1;

            function distSqToSeg(p, a, b) {
                let dx = b[0] - a[0], dy = b[1] - a[1];
                if (dx === 0 && dy === 0) return (p[0]-a[0])**2 + (p[1]-a[1])**2;
                const t = ((p[0]-a[0])*dx + (p[1]-a[1])*dy) / (dx*dx + dy*dy);
                const tt = Math.max(0, Math.min(1, t));
                const px = a[0] + tt*dx, py = a[1] + tt*dy;
                return (p[0]-px)**2 + (p[1]-py)**2;
            }

            function recurse(first, last) {
                if (last <= first + 1) return;
                let maxD = -1, idx = -1;
                for (let i = first + 1; i < last; i++) {
                    const d = distSqToSeg(points[i], points[first], points[last]);
                    if (d > maxD) { maxD = d; idx = i; }
                }
                if (maxD > tol2 && idx !== -1) {
                    keep[idx] = 1;
                    recurse(first, idx);
                    recurse(idx, last);
                }
            }
            recurse(0, points.length - 1);

            const result = [];
            for (let i = 0; i < points.length; i++) if (keep[i]) result.push(points[i]);
            return result;
        }
    </script>
</section>
