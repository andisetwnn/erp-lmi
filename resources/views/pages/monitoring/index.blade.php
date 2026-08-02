<?php

use Carbon\CarbonInterface;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new #[Title('Monitoring')] class extends Component
{
    use WithPagination;

    /** '' = semua kategori, 'penjualan' | 'keuangan' */
    #[Url(as: 'c')]
    public string $category = '';

    #[Url(as: 'range')]
    public string $range = '7d';

    #[Url(as: 'event')]
    public string $eventFilter = '';

    /** ID activity terbaru saat terakhir user lihat (untuk deteksi new events). */
    public int $lastSeenId = 0;

    /** Jumlah aktivitas baru sejak lastSeenId (di-update tiap poll). */
    public int $newCount = 0;

    public const CATEGORIES = [
        '' => ['Semua', 'squares-2x2', 'zinc'],
        'penjualan' => ['Penjualan', 'chart-bar', 'emerald'],
        'keuangan' => ['Keuangan', 'banknotes', 'purple'],
        'unit' => ['Unit', 'home-modern', 'blue'],
    ];

    public const RANGES = [
        'today' => 'Hari Ini',
        '7d' => '7 Hari',
        '30d' => '30 Hari',
        'all' => 'Semua',
    ];

    public const EVENT_LABELS = [
        // Penjualan
        'booking.created'   => ['Booking Baru', 'home-modern', 'orange', 'penjualan'],
        'spr.submitted'     => ['SPR Diajukan', 'document-plus', 'emerald', 'penjualan'],
        'spr.approved'      => ['SPR Disetujui', 'check-circle', 'emerald', 'penjualan'],
        'spr.rejected'      => ['SPR Ditolak', 'x-circle', 'rose', 'penjualan'],
        'spr.cancelled'     => ['SPR Dibatalkan', 'x-mark', 'rose', 'penjualan'],
        'spr.switched'      => ['SPR Pindah Unit', 'arrows-right-left', 'blue', 'penjualan'],
        'spr.swapped'       => ['SPR Tukar Unit', 'arrow-path-rounded-square', 'indigo', 'penjualan'],
        'spr.akad'          => ['Akad Kredit', 'trophy', 'violet', 'penjualan'],
        'konsumen.signed'   => ['Konsumen Tanda Tangan', 'pencil-square', 'teal', 'penjualan'],
        // Keuangan
        'utj.verified'      => ['UTJ Diverifikasi', 'banknotes', 'purple', 'keuangan'],
        'realisasi.created' => ['Realisasi Pembayaran', 'currency-dollar', 'purple', 'keuangan'],
        'realisasi.updated' => ['Realisasi Dikoreksi', 'pencil-square', 'amber', 'keuangan'],
        'refund.processed'  => ['Refund Diproses', 'arrow-uturn-left', 'amber', 'keuangan'],
        'materai.stamped'   => ['e-Materai Ditempel', 'document-check', 'purple', 'keuangan'],
        // Unit
        'unit.created' => ['Unit Baru', 'plus-circle', 'blue', 'unit'],
        'unit.updated' => ['Unit Diubah', 'pencil-square', 'blue', 'unit'],
        'unit.status_changed' => ['Status Unit Berubah', 'arrow-path', 'cyan', 'unit'],
    ];

    /** Deskripsi tooltip per event — dijelaskan supaya user paham. */
    public const EVENT_DESC = [
        'booking.created'   => 'Booking unit baru dibuat oleh sales. Customer siap masuk ke tahap SPR.',
        'spr.submitted'     => 'Sales mengajukan SPR baru. Menunggu Keuangan verifikasi bukti transfer UTJ.',
        'spr.approved'      => 'Project Manager menyetujui isi kontrak SPR. Selanjutnya menunggu TTD konsumen.',
        'spr.rejected'      => 'SPR ditolak Keuangan (bukti UTJ tidak sesuai) atau Project Manager.',
        'spr.cancelled'     => 'SPR dibatalkan (customer mengundurkan diri, tolak bank, dll).',
        'spr.switched'      => 'Customer pindah ke unit lain. SPR lama dibatalkan, SPR baru diterbitkan (nomor transaksi PK/YYYY/MM/XXXX).',
        'spr.swapped'       => 'Dua customer saling menukar unit. Kedua SPR lama dibatalkan, 2 SPR baru diterbitkan bersamaan.',
        'spr.akad'          => 'Akad kredit terlaksana di notaris. Transaksi jual-beli sah — customer resmi jadi pemilik.',
        'konsumen.signed'   => 'Konsumen tanda tangan digital SPR melalui link WhatsApp (verifikasi NIK berhasil).',
        'utj.verified'      => 'Keuangan verifikasi bukti transfer UTJ cocok dengan mutasi bank. Status SPR jadi Diproses.',
        'realisasi.created' => 'Pembayaran termin UM dari customer diterima & dicatat Keuangan.',
        'realisasi.updated' => 'Data realisasi pembayaran dikoreksi (tanggal / jumlah / metode / keterangan). Nomor kwitansi tetap.',
        'refund.processed'  => 'Refund dana ke customer diproses (setelah SPR dibatalkan).',
        'materai.stamped'   => 'e-Materai ditempel di dokumen SPR final (langkah terakhir). SPR sekarang sah bermaterai.',
        'unit.created'         => 'Unit rumah baru ditambahkan ke master data.',
        'unit.updated'         => 'Data unit rumah diubah (blok, tipe, harga, dll).',
        'unit.status_changed'  => 'Status unit berubah antara Tersedia / Booking / Terjual.',
    ];

    public function setCategory(string $c): void
    {
        if (! array_key_exists($c, self::CATEGORIES)) return;
        $this->category = $c;
        $this->eventFilter = '';
        $this->resetPage();
    }

    public function setRange(string $r): void
    {
        if (! array_key_exists($r, self::RANGES)) return;
        $this->range = $r;
        $this->resetPage();
    }

    public function updatingEventFilter(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        // Set baseline: ID activity paling baru saat page loaded
        $this->lastSeenId = (int) Activity::max('id');
    }

    /**
     * Dipanggil tiap 30 detik oleh wire:poll.
     * Cek berapa aktivitas baru sejak lastSeenId (yang match filter aktif).
     * Kalau ada, dispatch event ke browser untuk toast + update banner.
     */
    public function checkNewActivities(): void
    {
        $query = Activity::query()
            ->when($this->category !== '',
                fn ($q) => $q->where('log_name', $this->category),
                fn ($q) => $q->whereIn('log_name', ['penjualan', 'keuangan', 'unit']))
            ->when($this->eventFilter, fn ($q) => $q->where('event', $this->eventFilter))
            ->where('id', '>', $this->lastSeenId);

        $before = $this->newCount;
        $this->newCount = (int) $query->count();

        // Ada event baru yang belum ada sebelumnya → trigger notif browser
        if ($this->newCount > $before && $this->newCount > 0) {
            $latest = $query->orderByDesc('id')->limit(3)->get(['description', 'event', 'log_name']);
            $this->dispatch('new-monitoring-activities',
                count: $this->newCount - $before,
                total: $this->newCount,
                latest: $latest->pluck('description')->toArray(),
            );
        }
    }

    /**
     * User klik banner "X aktivitas baru" → refresh feed & reset baseline.
     */
    public function refreshFeed(): void
    {
        $this->lastSeenId = (int) Activity::max('id');
        $this->newCount = 0;
        $this->resetPage();
    }

    private function rangeStart(): ?CarbonInterface
    {
        return match ($this->range) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };
    }

    public function with(): array
    {
        $query = Activity::query()->with(['causer', 'subject']);

        if ($this->category !== '') {
            $query->where('log_name', $this->category);
        } else {
            $query->whereIn('log_name', ['penjualan', 'keuangan', 'unit']);
        }

        if ($start = $this->rangeStart()) {
            $query->where('created_at', '>=', $start);
        }

        if ($this->eventFilter) {
            $query->where('event', $this->eventFilter);
        }

        $activities = $query->orderByDesc('created_at')->paginate(25);

        // Ringkasan count per event untuk periode aktif (independen dari eventFilter)
        $summaryQuery = Activity::query()
            ->when($this->category !== '',
                fn ($q) => $q->where('log_name', $this->category),
                fn ($q) => $q->whereIn('log_name', ['penjualan', 'keuangan', 'unit']))
            ->when($start = $this->rangeStart(), fn ($q) => $q->where('created_at', '>=', $start));

        $summary = $summaryQuery
            ->selectRaw('event, COUNT(*) as cnt')
            ->groupBy('event')
            ->pluck('cnt', 'event');

        return compact('activities', 'summary');
    }
}; ?>

<section class="w-full"
         wire:poll.30s="checkNewActivities"
         x-data="{
            requestNotifPermission() {
                if ('Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission();
                }
            },
            showBrowserNotif(title, body) {
                if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
                    new Notification(title, { body, icon: '/favicon.ico', tag: 'monitoring' });
                }
            }
         }"
         x-init="requestNotifPermission()"
         @new-monitoring-activities.window="
            const count = $event.detail.count ?? $event.detail[0]?.count;
            const total = $event.detail.total ?? $event.detail[0]?.total;
            const latest = $event.detail.latest ?? $event.detail[0]?.latest ?? [];
            const preview = latest[0] ?? '';
            // Toast Flux
            if (window.Flux) {
                Flux.toast({
                    variant: 'info',
                    heading: count + ' aktivitas baru',
                    text: preview,
                    duration: 4000,
                });
            }
            // Browser desktop notif (kalau tab tidak aktif)
            showBrowserNotif('ERP-LMI — ' + count + ' aktivitas baru', preview);
            // Update title dengan badge count
            if (document.hidden) {
                document.title = '(' + total + ') Monitoring · ERP-LMI';
            }
         "
         @visibilitychange.window="if (!document.hidden) document.title = 'Monitoring · ERP-LMI'">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex items-start justify-between gap-3">
            <div>
                <flux:heading size="xl">{{ __('Monitoring') }}</flux:heading>
                <flux:subheading>
                    {{ __('Aktivitas transaksi bisnis realtime — penjualan & keuangan') }}
                </flux:subheading>
            </div>
            <div class="flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">
                <span class="relative flex size-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                </span>
                {{ __('LIVE · auto-refresh 30s') }}
            </div>
        </div>

        {{-- BANNER new activities --}}
        @if ($newCount > 0)
            <button type="button" wire:click="refreshFeed"
                    class="mb-4 flex w-full items-center justify-center gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800 shadow-sm transition hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                <flux:icon.bell-alert class="size-4 animate-pulse" />
                {{ $newCount }} {{ __('aktivitas baru — klik untuk muat ulang') }}
            </button>
        @endif

        {{-- CATEGORY PILLS --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            @foreach ($this::CATEGORIES as $catKey => [$catLbl, $catIco, $catColor])
                @php $catActive = $category === $catKey; @endphp
                <button type="button" wire:click="setCategory('{{ $catKey }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold transition',
                            "bg-{$catColor}-600 text-white shadow" => $catActive,
                            'bg-white text-zinc-600 border border-zinc-200 hover:bg-zinc-50 dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-700 dark:hover:bg-zinc-800' => ! $catActive,
                        ])>
                    <flux:icon :name="$catIco" class="size-3.5" />
                    {{ $catLbl }}
                </button>
            @endforeach
        </div>

        {{-- FILTER BAR --}}
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="inline-flex items-center rounded-lg border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                @foreach ($this::RANGES as $k => $lbl)
                    @php $active = $range === $k; @endphp
                    <button type="button" wire:click="setRange('{{ $k }}')"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition',
                                'bg-emerald-600 text-white shadow' => $active,
                                'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! $active,
                            ])>{{ $lbl }}</button>
                @endforeach
            </div>

            <select wire:model.live="eventFilter" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                <option value="">— Semua Event —</option>
                @foreach ($this::EVENT_LABELS as $ev => [$lbl, $ico, $col, $eventCat])
                    @if ($category === '' || $eventCat === $category)
                        <option value="{{ $ev }}">{{ $lbl }}</option>
                    @endif
                @endforeach
            </select>

            <button type="button" wire:click="$refresh"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <flux:icon.arrow-path class="size-3.5" wire:loading.class="animate-spin" />
                {{ __('Refresh') }}
            </button>
        </div>

        {{-- SUMMARY per event (compact & rapi + tooltip info) --}}
        @if ($summary->isNotEmpty())
            <div class="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                @foreach ($summary as $event => $cnt)
                    @php
                        [$lbl, $ico, $col] = $this::EVENT_LABELS[$event] ?? [$event, 'sparkles', 'zinc', null];
                        $desc = $this::EVENT_DESC[$event] ?? '';
                    @endphp
                    <flux:tooltip content="{{ $desc }}">
                        <div class="flex cursor-help items-center gap-2.5 rounded-lg border border-{{ $col }}-200 bg-{{ $col }}-50 px-3 py-2.5 transition hover:border-{{ $col }}-400 dark:border-{{ $col }}-800 dark:bg-{{ $col }}-950/30 dark:hover:border-{{ $col }}-600">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-{{ $col }}-100 text-{{ $col }}-700 dark:bg-{{ $col }}-950/70 dark:text-{{ $col }}-400">
                                <flux:icon :name="$ico" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-lg font-bold leading-none tabular-nums text-{{ $col }}-800 dark:text-{{ $col }}-300">{{ number_format($cnt) }}</div>
                                <div class="mt-1 text-[10px] font-semibold uppercase leading-tight tracking-wide text-{{ $col }}-700 dark:text-{{ $col }}-400">{{ $lbl }}</div>
                            </div>
                        </div>
                    </flux:tooltip>
                @endforeach
            </div>
        @endif

        {{-- TIMELINE --}}
        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            @if ($activities->isEmpty())
                <div class="p-12 text-center">
                    <flux:icon.inbox class="mx-auto mb-3 size-12 text-zinc-400" />
                    <div class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Belum ada aktivitas') }}</div>
                    <div class="mt-1 text-xs text-zinc-500">{{ __('Aktivitas akan muncul di sini setelah ada transaksi.') }}</div>
                </div>
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($activities as $act)
                        @php
                            [$lbl, $ico, $col] = $this::EVENT_LABELS[$act->event] ?? [$act->event ?? 'Activity', 'sparkles', 'zinc', null];
                            $props = $act->properties ?? collect();
                            $causerName = $act->causer?->name ?? $act->causer?->nama ?? '—';
                        @endphp
                        <li class="flex gap-3 px-4 py-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-{{ $col }}-100 text-{{ $col }}-700 dark:bg-{{ $col }}-950/50 dark:text-{{ $col }}-400">
                                <flux:icon :name="$ico" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ \App\Support\BusinessActivityLogger::shortenDesc($act->description) }}</div>
                                    <div class="text-[10px] text-zinc-500" title="{{ $act->created_at?->format('d M Y H:i:s') }}">
                                        {{ $act->created_at?->diffForHumans() }}
                                    </div>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-zinc-500">
                                    <span class="inline-flex items-center gap-1 rounded-md bg-{{ $col }}-50 px-1.5 py-0.5 text-[10px] font-semibold text-{{ $col }}-700 dark:bg-{{ $col }}-950/30 dark:text-{{ $col }}-400">
                                        {{ $lbl }}
                                    </span>
                                    @if ($act->log_name)
                                        <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                            {{ ucfirst($act->log_name) }}
                                        </span>
                                    @endif
                                    <span>·</span>
                                    <span>oleh <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $causerName }}</span></span>
                                    @if (isset($props['sales']) && $props['sales'])
                                        <span>·</span>
                                        <span>Sales: <span class="font-semibold">{{ $props['sales'] }}</span></span>
                                    @endif
                                    @if (isset($props['nilai']) && $props['nilai'] > 0)
                                        <span>·</span>
                                        <span class="font-mono font-bold text-emerald-700 dark:text-emerald-400">Rp {{ number_format((float) $props['nilai'], 0, ',', '.') }}</span>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="border-t border-zinc-100 p-3 dark:border-zinc-800">{{ $activities->links() }}</div>
            @endif
        </div>

    </div>
</section>
