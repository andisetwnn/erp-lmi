<?php

use App\Models\Master\Booking;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Daftar Booking'), Layout('layouts.dbos')] class extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'aktif')]
    public string $tab = 'aktif';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: 'terbaru')]
    public string $sort = 'terbaru';

    public ?int $cancelId = null;

    public string $cancelKeterangan = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['aktif', 'sukses', 'batal', 'expired'], true) ? $tab : 'aktif';
        $this->resetPage();
    }

    public function openCancel(int $id): void
    {
        $this->cancelId = $id;
        $this->cancelKeterangan = '';
        Flux::modal('booking-cancel')->show();
    }

    public function confirmCancel(): void
    {
        $this->validate([
            'cancelKeterangan' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'cancelKeterangan.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        $salesId = Auth::guard('sales')->id();

        DB::transaction(function () use ($salesId) {
            $booking = Booking::where('sales_id', $salesId)
                ->where('status', 'aktif')
                ->findOrFail($this->cancelId);

            $booking->update([
                'status' => 'batal',
                'keterangan_batal' => $this->cancelKeterangan,
                'unit_dilepas_at' => Carbon::today()->toDateString(),
            ]);

            // Lepas unit kembali jadi available
            $booking->rumah?->update(['status' => 'available']);
        });

        Flux::modal('booking-cancel')->close();
        Flux::toast(variant: 'success', text: 'Booking berhasil dibatalkan.');
        $this->cancelId = null;
        $this->cancelKeterangan = '';
    }

    public function with(): array
    {
        $salesId = Auth::guard('sales')->id();
        $now = Carbon::now();

        $base = fn () => Booking::where('sales_id', $salesId);

        $aktifFilter = fn ($q) => $q->where('status', 'aktif')
            ->where(fn ($x) => $x->whereNull('tanggal_expired')->orWhere('tanggal_expired', '>', $now));

        $expiredFilter = fn ($q) => $q->where('status', 'aktif')
            ->whereNotNull('tanggal_expired')
            ->where('tanggal_expired', '<=', $now);

        $counts = [
            'aktif' => $base()->tap($aktifFilter)->count(),
            'sukses' => $base()->whereIn('status', ['sukses', 'akad'])->count(),
            'batal' => $base()->where('status', 'batal')->count(),
            'expired' => $base()->tap($expiredFilter)->count(),
        ];

        $query = $base()->with(['rumah.tipeRumah', 'proyek', 'prospectCustomer']);

        match ($this->tab) {
            'aktif' => $aktifFilter($query),
            'sukses' => $query->whereIn('status', ['sukses', 'akad']),
            'batal' => $query->where('status', 'batal'),
            'expired' => $expiredFilter($query),
        };

        if ($this->search !== '') {
            $s = $this->search;
            $query->whereHas('prospectCustomer', function ($q) use ($s) {
                $q->where('nama_lengkap', 'like', "%{$s}%")
                    ->orWhere('nik', 'like', "%{$s}%")
                    ->orWhere('hp', 'like', "%{$s}%");
            });
        }

        $query = match ($this->sort) {
            'terlama'          => $query->orderBy('created_at', 'asc'),
            'deadline_terdekat'=> $query->orderByRaw('tanggal_expired IS NULL')->orderBy('tanggal_expired', 'asc'),
            'deadline_terjauh' => $query->orderByRaw('tanggal_expired IS NULL')->orderBy('tanggal_expired', 'desc'),
            default            => $query->orderByDesc('created_at'),
        };

        $bookings = $query->paginate(10);

        return compact('bookings', 'counts');
    }
}; ?>

<section class="pb-24">

    {{-- HEADER --}}
    <div class="sticky top-0 z-10 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Daftar Booking') }}</h1>
                <flux:modal.trigger name="booking-rules">
                    <button type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-zinc-400 transition hover:bg-zinc-100 hover:text-orange-600 active:scale-95 dark:hover:bg-zinc-800"
                            title="{{ __('Aturan masa berlaku booking') }}">
                        <flux:icon.information-circle class="size-5" />
                    </button>
                </flux:modal.trigger>
            </div>
            <a href="{{ route('dbos.booking.create') }}" wire:navigate
               class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm active:scale-95">
                <flux:icon.plus class="size-4" />
                {{ __('Booking Baru') }}
            </a>
        </div>

        {{-- TABS --}}
        <div class="mt-3 flex gap-2 overflow-x-auto">
            @php
                $tabs = [
                    'aktif'   => ['Aktif',   'blue'],
                    'sukses'  => ['Sukses',  'emerald'],
                    'batal'   => ['Batal',   'rose'],
                    'expired' => ['Expired', 'zinc'],
                ];
            @endphp
            @foreach ($tabs as $key => [$label, $color])
                @php
                    $active = $tab === $key;
                    $activeClasses = match ($color) {
                        'blue'    => 'bg-blue-600 text-white',
                        'emerald' => 'bg-emerald-600 text-white',
                        'rose'    => 'bg-rose-600 text-white',
                        'zinc'    => 'bg-zinc-700 text-white',
                    };
                @endphp
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @class([
                            'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition',
                            $activeClasses => $active,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300' => ! $active,
                        ])>
                    <span>{{ $label }}</span>
                    <span @class([
                        'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold',
                        'bg-white/25 text-white' => $active,
                        'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => ! $active,
                    ])>
                        {{ $counts[$key] }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- SEARCH --}}
    <div class="px-4 pt-4">
        <div class="relative">
            <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
            <input type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('Cari nama, NIK, atau HP...') }}"
                   class="block h-11 w-full rounded-xl border border-zinc-200 bg-white pl-10 pr-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
        </div>
    </div>

    {{-- SORT --}}
    <div class="flex items-center justify-between gap-2 px-4 pt-3">
        <label for="booking-sort" class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
            {{ __('Urutkan') }}
        </label>
        <select id="booking-sort" wire:model.live="sort"
                class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
            <option value="terbaru">{{ __('Terbaru') }}</option>
            <option value="terlama">{{ __('Terlama') }}</option>
            <option value="deadline_terdekat">{{ __('Deadline terdekat') }}</option>
            <option value="deadline_terjauh">{{ __('Deadline terjauh') }}</option>
        </select>
    </div>

    {{-- LIST --}}
    <div class="space-y-3 px-4 pt-4">
        @forelse ($bookings as $b)
            @php
                $rumah = $b->rumah;
                $tipe = $rumah?->tipeRumah;
                $prospect = $b->prospectCustomer;
                $kodeUnit = $rumah ? $rumah->kode_unit : '—';
                $tipeLabel = $tipe ? trim(($tipe->tipe ?? '').' '.($tipe->nama_tipe ?? '')) : '—';

                // Top strip: warna + label + ikon, beda per status & urgensi
                // Deadline exact = created_at + 24 jam (bukan end of day tanggal_expired)
                $deadlineTs = null;
                $strip = null;
                if ($tab === 'aktif' && $b->tanggal_expired) {
                    $deadline = $b->tanggal_expired;
                    $deadlineTs = $deadline->getTimestamp();
                    $secondsLeft = $deadlineTs - now()->getTimestamp();
                    $hoursLeft = max(0, (int) floor($secondsLeft / 3600));

                    // Label = berdasarkan tanggal deadline; warna/pulse = urgency dari jam tersisa
                    if ($secondsLeft <= 0) {
                        $strip = ['bg' => 'bg-rose-600', 'icon' => 'fire', 'label' => 'EXPIRED', 'pulse' => true];
                    } else {
                        // Tentukan label dari tanggal deadline
                        if ($deadline->isToday()) {
                            $label = 'Hari ini berakhir';
                        } elseif ($deadline->isTomorrow()) {
                            $label = 'Besok berakhir';
                        } else {
                            $daysLeft = (int) ceil($hoursLeft / 24);
                            $label = $daysLeft.' hari lagi';
                        }

                        // Warna/pulse dari urgency (jam tersisa)
                        if ($hoursLeft < 6) {
                            $strip = ['bg' => 'bg-rose-500', 'icon' => 'fire', 'label' => $label, 'pulse' => true];
                        } elseif ($hoursLeft < 24) {
                            $strip = ['bg' => 'bg-amber-500', 'icon' => 'clock', 'label' => $label, 'pulse' => false];
                        } else {
                            $strip = ['bg' => 'bg-emerald-500', 'icon' => 'clock', 'label' => $label, 'pulse' => false];
                        }
                    }
                } elseif ($tab === 'sukses') {
                    $isAkad = $b->status === 'akad';
                    $strip = ['bg' => 'bg-emerald-500', 'icon' => 'check-circle', 'label' => $isAkad ? 'Akad selesai' : 'SPR terbit', 'pulse' => false];
                } elseif ($tab === 'batal') {
                    $strip = ['bg' => 'bg-rose-500', 'icon' => 'x-circle', 'label' => 'Dibatalkan', 'pulse' => false];
                } elseif ($tab === 'expired') {
                    $strip = ['bg' => 'bg-zinc-500', 'icon' => 'clock', 'label' => 'Sudah expired', 'pulse' => false];
                } else {
                    $strip = ['bg' => 'bg-blue-500', 'icon' => 'clock', 'label' => 'Aktif', 'pulse' => false];
                }

                $avatarColor = match ($tab) {
                    'aktif'   => 'bg-blue-500',
                    'sukses'  => 'bg-emerald-500',
                    'batal'   => 'bg-rose-500',
                    'expired' => 'bg-zinc-500',
                };
            @endphp

            <div class="overflow-hidden rounded-2xl bg-white shadow-md dark:bg-zinc-900">

                {{-- TOP STRIP: status / urgency banner --}}
                <div @class(['flex items-center gap-2 px-4 py-2 text-white', $strip['bg'], 'animate-pulse' => $strip['pulse']])
                     @if ($tab === 'aktif' && $deadlineTs)
                        x-data="{
                            deadline: {{ $deadlineTs * 1000 }},
                            display: '',
                            expired: false,
                            tick() {
                                const diff = this.deadline - Date.now();
                                if (diff <= 0) { this.display = 'EXPIRED'; this.expired = true; return; }
                                const days = Math.floor(diff / 86400000);
                                const hours = Math.floor((diff % 86400000) / 3600000);
                                const mins = Math.floor((diff % 3600000) / 60000);
                                const secs = Math.floor((diff % 60000) / 1000);
                                this.display = (days > 0 ? days + 'h ' : '') + hours + 'j ' + mins + 'm ' + secs + 's';
                            }
                        }"
                        x-init="tick(); setInterval(() => tick(), 1000);"
                     @endif>
                    @switch($strip['icon'])
                        @case('fire')          <flux:icon.fire class="size-4 shrink-0" /> @break
                        @case('clock')         <flux:icon.clock class="size-4 shrink-0" /> @break
                        @case('check-circle')  <flux:icon.check-circle class="size-4 shrink-0" /> @break
                        @case('x-circle')      <flux:icon.x-circle class="size-4 shrink-0" /> @break
                    @endswitch
                    <span class="text-[11px] font-bold uppercase tracking-wider">{{ $strip['label'] }}</span>

                    @if ($tab === 'aktif' && $deadlineTs)
                        {{-- Live countdown --}}
                        <span class="ml-auto inline-flex items-center gap-1 rounded-md bg-white/20 px-2 py-0.5 text-[11px] font-bold tabular-nums backdrop-blur-sm"
                              x-text="display"></span>
                    @elseif ($b->tanggal_expired)
                        <span class="ml-auto text-[10px] font-semibold opacity-80">
                            {{ $b->tanggal_expired->translatedFormat('d M Y') }}
                        </span>
                    @endif
                </div>

                {{-- BODY: 2 kolom (avatar | content) dengan dashed divider --}}
                <div class="flex gap-4 px-4 py-4">
                    {{-- Avatar unit, vertical block --}}
                    <div @class(['relative flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-xl text-white shadow-sm', $avatarColor])>
                        <span class="text-[9px] font-bold uppercase tracking-widest opacity-70">{{ __('Unit') }}</span>
                        <span class="mt-0.5 text-lg font-extrabold leading-none">{{ $kodeUnit }}</span>
                    </div>

                    {{-- Right content with dashed left border (ticket stub feel) --}}
                    <div class="min-w-0 flex-1 border-l border-dashed border-zinc-300 pl-4 dark:border-zinc-700">
                        <div class="truncate text-base font-bold text-zinc-900 dark:text-white">
                            {{ $prospect?->nama_lengkap ?? '—' }}
                        </div>
                        @if ($prospect?->hp)
                            <a href="tel:+{{ $prospect->hp }}"
                               class="mt-0.5 inline-flex items-center gap-1.5 text-[11px] font-semibold text-zinc-500 hover:text-orange-600 dark:hover:text-orange-400">
                                <flux:icon.phone class="size-3" />
                                {{ $prospect->hp }}
                            </a>
                        @endif

                        <div class="mt-2.5 space-y-1.5">
                            <div class="flex items-center gap-1.5 text-[11px] text-zinc-600 dark:text-zinc-400">
                                <flux:icon.home class="size-3 shrink-0 text-zinc-400" />
                                <span class="truncate">{{ $tipeLabel }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-700 dark:text-zinc-300">
                                <flux:icon.building-office-2 class="size-3 shrink-0 text-zinc-400" />
                                <span class="truncate">{{ $b->proyek?->nama_proyek ?? '—' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- PERFORATION: ticket-style notches + dashed line --}}
                <div class="relative">
                    <div class="absolute left-0 top-1/2 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full bg-zinc-100 dark:bg-zinc-950"></div>
                    <div class="absolute right-0 top-1/2 size-4 -translate-y-1/2 translate-x-1/2 rounded-full bg-zinc-100 dark:bg-zinc-950"></div>
                    <div class="mx-4 border-t-2 border-dashed border-zinc-200 dark:border-zinc-700"></div>
                </div>

                {{-- FOOTER: actions / context per tab --}}
                @if ($tab === 'aktif')
                    <div class="flex gap-2 px-4 py-3">
                        <a href="{{ route('dbos.spr.create', $b->id) }}" wire:navigate
                           class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2.5 text-xs font-semibold text-white shadow-sm transition active:scale-95 hover:bg-emerald-700">
                            <flux:icon.document-plus class="size-3.5" />
                            {{ __('Buat SPR') }}
                        </a>
                        <button type="button" wire:click="openCancel({{ $b->id }})"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-xs font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            <flux:icon.x-mark class="size-3.5" />
                            {{ __('Batal') }}
                        </button>
                    </div>
                @elseif ($tab === 'batal' && $b->keterangan_batal)
                    <div class="px-4 py-3">
                        <div class="rounded-lg bg-rose-50 px-3 py-2 dark:bg-rose-950/30">
                            <p class="text-[11px] text-rose-800 dark:text-rose-200">
                                <span class="font-bold uppercase tracking-wide">{{ __('Alasan') }}:</span>
                                {{ $b->keterangan_batal }}
                            </p>
                        </div>
                    </div>
                @elseif ($tab === 'expired')
                    <div class="px-4 py-3 text-center">
                        <p class="text-[11px] text-zinc-500">
                            {{ __('Unit sudah kembali ke daftar tersedia.') }}
                        </p>
                    </div>
                @elseif ($tab === 'sukses')
                    <div class="px-4 py-3 text-center">
                        <p class="text-[11px] text-zinc-500">
                            {{ __('Dibooking pada') }}
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                                {{ $b->tanggal_booking?->translatedFormat('d M Y') }}
                            </span>
                        </p>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-6 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.clipboard-document-list class="mx-auto size-10 text-zinc-400" />
                <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    @if ($search !== '')
                        {{ __('Tidak ada booking yang cocok dengan pencarian.') }}
                    @else
                        @switch($tab)
                            @case('aktif')   {{ __('Belum ada booking aktif.') }} @break
                            @case('sukses')  {{ __('Belum ada booking sukses.') }} @break
                            @case('batal')   {{ __('Belum ada booking yang dibatalkan.') }} @break
                            @case('expired') {{ __('Belum ada booking expired.') }} @break
                        @endswitch
                    @endif
                </p>
                @if ($tab === 'aktif' && $search === '')
                    <a href="{{ route('dbos.booking.create') }}" wire:navigate
                       class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-orange-600 px-4 py-2 text-xs font-semibold text-white shadow-sm active:scale-95">
                        <flux:icon.plus class="size-4" />
                        {{ __('Buat Booking Baru') }}
                    </a>
                @endif
            </div>
        @endforelse

        @if ($bookings->hasPages())
            <div class="pt-2">
                {{ $bookings->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL: Aturan masa berlaku booking --}}
    <flux:modal name="booking-rules" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.information-circle class="size-5 text-orange-600" />
                    <flux:heading size="lg">{{ __('Masa Berlaku Booking') }}</flux:heading>
                </div>
                <flux:subheading>{{ __('Setiap booking di DBOS punya batas waktu. Pahami supaya tidak kehilangan unit.') }}</flux:subheading>
            </div>

            {{-- Stage timeline --}}
            <div class="space-y-3">
                {{-- Stage 1 --}}
                <div class="rounded-xl border border-blue-200 bg-blue-50/50 p-3 dark:border-blue-900/50 dark:bg-blue-950/20">
                    <div class="flex items-start gap-3">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">1</div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Setelah Booking') }}</p>
                                <span class="rounded-md bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                    {{ __('1 hari kerja') }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                {{ __('Sales wajib lanjut ke input SPR dalam 1 hari kerja. Melewati batas tersebut, unit otomatis dilepas kembali ke daftar tersedia.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Cooldown --}}
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/50">
                    <div class="flex items-start gap-3">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-600 text-white">
                            <flux:icon.lock-closed class="size-3.5" />
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Cooldown Re-booking') }}</p>
                                <span class="rounded-md bg-zinc-200 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">
                                    {{ __('2 hari kerja') }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                {{ __('Jika booking dibatalkan atau expired, sales yang sama harus menunggu 2 hari kerja sebelum dapat booking ulang unit yang sama. Sales lain dapat langsung booking unit tersebut tanpa menunggu.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary" type="button" class="bg-orange-600! hover:bg-orange-700!">
                        {{ __('Mengerti') }}
                    </flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: Cancel booking --}}
    <flux:modal name="booking-cancel" class="md:w-md">
        <div class="space-y-4">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.exclamation-triangle class="size-5 text-rose-600" />
                    <flux:heading size="lg">{{ __('Batalkan Booking') }}</flux:heading>
                </div>
                <flux:subheading>{{ __('Unit akan kembali tersedia setelah dibatalkan.') }}</flux:subheading>
            </div>

            <flux:field>
                <flux:label>{{ __('Alasan Pembatalan') }} <span class="ms-1 text-red-500">*</span></flux:label>
                <flux:textarea wire:model="cancelKeterangan" rows="3"
                               placeholder="Mis. customer mundur, batal KPR, dll." />
                <flux:error name="cancelKeterangan" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="button" wire:click="confirmCancel">
                    {{ __('Batalkan Booking') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

</section>
