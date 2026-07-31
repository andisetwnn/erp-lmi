<?php

use App\Models\Master\Booking;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Booking — Pilih Unit'), Layout('layouts.dbos')] class extends Component
{
    public Proyek $proyek;

    public string $blok;

    public function mount(int $id, string $blok): void
    {
        $this->proyek = Proyek::findOrFail($id);
        $this->blok = $blok;
    }

    public function refresh(): void
    {
        // Trigger re-render
    }

    public function with(): array
    {
        $units = Rumah::where('proyek_id', $this->proyek->id)
            ->where('blok', $this->blok)
            ->with('tipeRumah:id,tipe,nama_tipe,kategori,harga_jual')
            ->orderBy('nomor_unit')
            ->get();

        // Cooldown re-booking: kalau sales saat ini pernah batal/expired
        // unit yg sama < 2 hari kerja yg lalu, blokir di list.
        $today = Carbon::today();
        $salesId = Auth::guard('sales')->id();

        $cooldowns = Booking::where('sales_id', $salesId)
            ->whereIn('rumah_id', $units->pluck('id'))
            ->whereNotNull('unit_dilepas_at')
            ->whereIn('status', ['batal', 'aktif']) // aktif yg sudah expired via auto-expire
            ->selectRaw('rumah_id, MAX(unit_dilepas_at) as latest_dilepas')
            ->groupBy('rumah_id')
            ->get()
            ->mapWithKeys(function ($row) use ($today) {
                $dilepas = Carbon::parse($row->latest_dilepas);
                $allowedAt = $dilepas->copy()->addDays(2);
                return $today->lt($allowedAt) ? [$row->rumah_id => $allowedAt] : [];
            });

        return compact('units', 'cooldowns');
    }
}; ?>

<section class="px-4 pb-8 pt-5">

    {{-- HEADER --}}
    <div class="mb-5 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('dbos.booking.blok', $proyek->id) }}" wire:navigate
               class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
                <flux:icon.arrow-left class="size-5" />
            </a>
            <div>
                <h1 class="text-lg font-bold text-zinc-900 dark:text-white">
                    {{ $proyek->nama_proyek }} — {{ __('Blok :b', ['b' => $blok]) }}
                </h1>
                <p class="text-xs text-zinc-500">{{ __('Langkah 3 dari 4 · Pilih Unit') }}</p>
            </div>
        </div>

        <button type="button" wire:click="refresh"
                class="inline-flex h-10 items-center gap-1.5 rounded-full bg-white px-3 text-xs font-semibold text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-path class="size-4" wire:loading.class="animate-spin" wire:target="refresh" />
            {{ __('Refresh') }}
        </button>
    </div>

    @if ($units->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.key class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm text-zinc-500">{{ __('Belum ada unit di blok ini.') }}</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($units as $u)
                @php
                    $statusInfo = match ($u->status) {
                        'available' => ['bg' => 'bg-emerald-500', 'border' => 'border-emerald-200 dark:border-emerald-900/50', 'text' => 'text-emerald-700 dark:text-emerald-300', 'badge' => 'TERSEDIA', 'tap' => true],
                        'booking'   => ['bg' => 'bg-amber-500',   'border' => 'border-amber-200 dark:border-amber-900/50',   'text' => 'text-amber-700 dark:text-amber-300',   'badge' => 'BOOKING',  'tap' => false],
                        'terjual'   => ['bg' => 'bg-blue-500',    'border' => 'border-blue-200 dark:border-blue-900/50',    'text' => 'text-blue-700 dark:text-blue-300',    'badge' => 'TERJUAL',  'tap' => false],
                        'draft'     => ['bg' => 'bg-zinc-400',    'border' => 'border-zinc-200 dark:border-zinc-700',       'text' => 'text-zinc-600 dark:text-zinc-400',     'badge' => 'DRAFT',    'tap' => false],
                    };
                    $cooldownUntil = $cooldowns[$u->id] ?? null;
                    if ($cooldownUntil) {
                        $statusInfo = ['bg' => 'bg-zinc-500', 'border' => 'border-zinc-300 dark:border-zinc-700', 'text' => 'text-zinc-600 dark:text-zinc-400', 'badge' => 'COOLDOWN', 'tap' => false];
                    }
                    $clickable = $statusInfo['tap'];
                @endphp

                @if ($clickable)
                    <a href="{{ route('dbos.booking.form', $u->id) }}" wire:navigate
                       class="flex overflow-hidden rounded-2xl border bg-white shadow-sm transition active:scale-[0.98] dark:bg-zinc-900 {{ $statusInfo['border'] }}">
                        @include('pages.dbos.booking._unit-card', ['u' => $u, 'statusInfo' => $statusInfo])
                    </a>
                @else
                    <div class="flex overflow-hidden rounded-2xl border bg-white opacity-70 shadow-sm dark:bg-zinc-900 {{ $statusInfo['border'] }}">
                        @include('pages.dbos.booking._unit-card', ['u' => $u, 'statusInfo' => $statusInfo])
                    </div>
                    @if ($cooldownUntil)
                        <div class="-mt-2 mb-1 ml-3 inline-flex items-center gap-1 rounded-b-lg bg-zinc-100 px-2 py-1 text-[10px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            <flux:icon.clock class="size-3" />
                            {{ __('Bisa booking ulang:') }} {{ $cooldownUntil->translatedFormat('D, d M Y') }}
                        </div>
                    @endif
                @endif
            @endforeach
        </div>
    @endif

</section>
