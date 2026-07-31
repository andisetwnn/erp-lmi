<?php

use App\Models\Master\Booking;
use App\Models\Master\Sales;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detail Booking'), Layout('layouts.pimpinan')] class extends Component {
    public int $id;

    public function mount(int $id): void
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $booking = Booking::whereIn('sales_id', $bawahanIds)->find($id);
        abort_unless($booking, 404);

        $this->id = $booking->id;
    }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $booking = Booking::with([
            'rumah.tipeRumah',
            'proyek:id,nama_proyek',
            'prospectCustomer.proyek:id,nama_proyek',
            'sales:id,nama,kode,telepon',
            'spr',
        ])->whereIn('sales_id', $bawahanIds)->findOrFail($this->id);

        return compact('booking');
    }
}; ?>

<div>
    {{-- BREADCRUMB --}}
    <div class="mb-4 flex items-center gap-2 text-sm">
        <a href="{{ route('dbos.pimpinan.booking.index') }}" wire:navigate
           class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
            {{ __('Booking Grup') }}
        </a>
        <flux:icon.chevron-right class="size-3.5 text-zinc-400" />
        <span class="font-semibold text-zinc-900 dark:text-white">Booking #{{ $booking->id }}</span>
    </div>

    @php
        $stBadge = match ($booking->status) {
            'aktif' => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300', 'AKTIF'],
            'sukses' => ['bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300', 'SUKSES'],
            'akad' => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', 'AKAD'],
            'batal' => ['bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300', 'BATAL'],
        };
        $isExpired = $booking->status === 'aktif'
            && $booking->tanggal_expired
            && $booking->tanggal_expired->lte(now());
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">Booking #{{ $booking->id }}</flux:heading>
                <span @class(['rounded-full px-2.5 py-1 text-xs font-bold uppercase tracking-wider', $stBadge[0]])>{{ $stBadge[1] }}</span>
                @if ($isExpired)
                    <span class="rounded-full bg-zinc-200 px-2 py-0.5 text-[10px] font-bold uppercase text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">
                        EXPIRED
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- LEFT: Booking detail --}}
        <div class="lg:col-span-2 space-y-4">
            {{-- STATUS & TANGGAL --}}
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Informasi Booking') }}</h3>
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Tanggal booking') }}</dt>
                        <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $booking->tanggal_booking?->translatedFormat('d M Y') }}</dd>
                    </div>
                    @if ($booking->tanggal_expired)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Tanggal expired') }}</dt>
                            <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">
                                {{ $booking->tanggal_expired->translatedFormat('d M Y') }}
                                <span class="text-xs font-normal text-zinc-500">· {{ $booking->tanggal_expired->diffForHumans() }}</span>
                            </dd>
                        </div>
                    @endif
                    @if ($booking->unit_dilepas_at)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Unit dilepas') }}</dt>
                            <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $booking->unit_dilepas_at->translatedFormat('d M Y') }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($booking->keterangan_batal)
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 dark:border-rose-900/40 dark:bg-rose-950/30">
                        <div class="text-xs font-semibold text-rose-800 dark:text-rose-300">{{ __('Alasan pembatalan') }}</div>
                        <p class="mt-1 text-sm text-rose-700 dark:text-rose-400">{{ $booking->keterangan_batal }}</p>
                    </div>
                @endif
            </div>

            {{-- UNIT INFO --}}
            @if ($booking->rumah)
                @php
                    $rumah = $booking->rumah;
                    $tipe = $rumah->tipeRumah;
                @endphp
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Unit & Proyek') }}</h3>
                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Proyek') }}</dt>
                            <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $booking->proyek?->nama_proyek ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Blok / Unit') }}</dt>
                            <dd class="mt-0.5 font-mono font-semibold text-zinc-900 dark:text-white">{{ $rumah->kode_unit }}</dd>
                        </div>
                        @if ($tipe)
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('Tipe') }}</dt>
                                @php
                                    $tipeKode = trim((string) ($tipe->tipe ?? ''));
                                    $tipeNama = trim((string) ($tipe->nama_tipe ?? ''));
                                    $tipeLabel = $tipeNama !== '' && $tipeKode !== '' && ! str_contains(mb_strtolower($tipeKode), mb_strtolower($tipeNama))
                                        ? $tipeKode.' '.$tipeNama
                                        : ($tipeKode !== '' ? $tipeKode : $tipeNama);
                                @endphp
                                <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $tipeLabel ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('LB / LT') }}</dt>
                                <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">
                                    {{ $rumah->luas_bangunan }} / {{ $rumah->luas_tanah }} m²
                                </dd>
                            </div>
                        @endif
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-zinc-500">{{ __('Harga jual') }}</dt>
                            <dd class="mt-0.5 text-xl font-bold text-emerald-700 dark:text-emerald-400">
                                Rp {{ number_format((float) $rumah->harga_jual, 0, ',', '.') }}
                            </dd>
                        </div>
                    </dl>
                </div>
            @endif
        </div>

        {{-- RIGHT: Customer & Sales --}}
        <div class="space-y-4 lg:col-span-1">
            {{-- CUSTOMER --}}
            @if ($booking->prospectCustomer)
                @php $p = $booking->prospectCustomer; @endphp
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Customer') }}</h3>
                    <div class="font-bold text-zinc-900 dark:text-white">{{ $p->nama_lengkap }}</div>
                    @if ($p->hp)
                        <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $p->hp) }}" target="_blank"
                           class="mt-1 inline-flex items-center gap-1 font-mono text-sm text-green-600 hover:underline dark:text-green-400">
                            <flux:icon.phone class="size-3.5" />
                            {{ $p->hp }}
                        </a>
                    @endif
                    @if ($p->nik)
                        <div class="mt-2 text-xs text-zinc-500">
                            <span class="text-zinc-400">NIK:</span>
                            <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ $p->nik }}</span>
                        </div>
                    @endif
                    <div class="mt-3">
                        <a href="{{ route('dbos.pimpinan.prospect.show', $p->id) }}" wire:navigate
                           class="inline-flex h-8 items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            {{ __('Lihat detail prospect') }}
                            <flux:icon.arrow-right class="size-3" />
                        </a>
                    </div>
                </div>
            @endif

            {{-- SALES --}}
            @php
                $sales = $booking->sales;
                $salesInitials = $sales ? collect(explode(' ', $sales->nama))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') : '?';
            @endphp
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Sales Pemilik') }}</h3>
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-amber-100 text-base font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                        {{ $salesInitials }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-bold text-zinc-900 dark:text-white">{{ $sales?->nama ?? '—' }}</div>
                        <div class="font-mono text-xs text-zinc-500">#{{ $sales?->kode ?? '—' }}</div>
                        @if ($sales?->telepon)
                            <div class="mt-0.5 text-xs text-zinc-500">{{ $sales->telepon }}</div>
                        @endif
                    </div>
                </div>
                @if ($sales)
                    <a href="{{ route('dbos.pimpinan.anggota.show', $sales->id) }}" wire:navigate
                       class="mt-3 inline-flex h-8 w-full items-center justify-center gap-1 rounded-lg border border-zinc-200 bg-white px-2 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                        {{ __('Lihat detail anggota') }}
                        <flux:icon.arrow-right class="size-3" />
                    </a>
                @endif
            </div>
        </div>

    </div>
</div>
