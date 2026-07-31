<?php

use App\Models\Master\Spr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('SPR'), Layout('layouts.dbos')] class extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: 'terbaru')]
    public string $sort = 'terbaru';

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
        $this->tab = in_array($tab, ['all', 'draft', 'diproses', 'selesai', 'rejected'], true) ? $tab : 'all';
        $this->resetPage();
    }

    public function with(): array
    {
        $salesId = Auth::guard('sales')->id();
        $base = fn () => Spr::where('sales_id', $salesId);

        $counts = [
            'all' => $base()->count(),
            'draft' => $base()->where('status', 'draft')->count(),
            // "Diproses" = submitted (nunggu verif UTJ) + approved (nunggu PM/TTD/Materai), belum final
            'diproses' => $base()->whereIn('status', ['submitted', 'approved'])->whereNull('spr_finalized_at')->count(),
            'selesai' => $base()->where('status', 'approved')->whereNotNull('spr_finalized_at')->count(),
            'rejected' => $base()->where('status', 'rejected')->count(),
        ];

        $query = $base()->with([
            'prospectCustomer:id,nama_lengkap,hp',
            'rumah:id,blok,nomor_unit,tipe_rumah_id',
            'rumah.tipeRumah:id,tipe,nama_tipe',
            'bankKpr:id,nama',
        ])->select([
            'id', 'nomor_spr', 'status', 'sales_id', 'prospect_customer_id', 'rumah_id',
            'total_harga', 'bank_kpr_id', 'created_at', 'jenis_pembayaran', 'tanggal_spr',
            'utj_confirmed_at', 'approved_at', 'pm_approved_at',
            // Fitur #6 columns
            'materai_stamped_at', 'konsumen_signed_at', 'spr_finalized_at',
        ]);

        if ($this->tab === 'selesai') {
            $query->where('status', 'approved')->whereNotNull('spr_finalized_at');
        } elseif ($this->tab === 'diproses') {
            $query->whereIn('status', ['submitted', 'approved'])->whereNull('spr_finalized_at');
        } elseif ($this->tab !== 'all') {
            $query->where('status', $this->tab);
        }

        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('nomor_spr', 'like', "%{$s}%")
                    ->orWhereHas('prospectCustomer', fn ($qq) => $qq->where('nama_lengkap', 'like', "%{$s}%")->orWhere('hp', 'like', "%{$s}%"));
            });
        }

        $query = match ($this->sort) {
            'terlama'    => $query->orderBy('created_at', 'asc'),
            'nomor_asc'  => $query->orderBy('nomor_spr', 'asc'),
            'nomor_desc' => $query->orderBy('nomor_spr', 'desc'),
            'nilai_desc' => $query->orderByDesc('total_harga'),
            'nilai_asc'  => $query->orderBy('total_harga', 'asc'),
            default      => $query->orderByDesc('created_at'),
        };

        $sprs = $query->paginate(10);

        return compact('sprs', 'counts');
    }
}; ?>

<section class="pb-24">

    {{-- HEADER --}}
    <div class="sticky top-0 z-10 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h1 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Daftar SPR') }}</h1>
                <flux:modal.trigger name="spr-status-info">
                    <button type="button"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-zinc-400 transition hover:bg-zinc-100 hover:text-orange-600 active:scale-95 dark:hover:bg-zinc-800"
                            title="{{ __('Keterangan status SPR') }}">
                        <flux:icon.information-circle class="size-5" />
                    </button>
                </flux:modal.trigger>
            </div>
        </div>

        {{-- TABS --}}
        <div class="mt-3 flex gap-2 overflow-x-auto">
            @php
                $tabs = [
                    'all'      => ['Semua',    'orange',  'Semua SPR yang pernah Anda buat.'],
                    'diproses' => ['Diproses', 'emerald', 'SPR yang masih berjalan (verifikasi UTJ → PM approve → TTD konsumen → e-Materai).'],
                    'selesai'  => ['Selesai',  'violet',  'SPR final — sudah lengkap semua TTD dan ber-e-Materai. Siap kirim link download ke konsumen.'],
                    'rejected' => ['Ditolak',  'rose',    'SPR ditolak oleh Keuangan atau Project Manager.'],
                    'draft'    => ['Draft',    'zinc',    'SPR belum di-submit. Masih bisa diedit.'],
                ];
            @endphp
            @foreach ($tabs as $key => [$label, $color, $hint])
                @php
                    $active = $tab === $key;
                    $activeClasses = match ($color) {
                        'orange'  => 'bg-orange-600 text-white',
                        'blue'    => 'bg-blue-600 text-white',
                        'emerald' => 'bg-emerald-600 text-white',
                        'violet'  => 'bg-violet-600 text-white',
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

    {{-- MODAL: Keterangan status SPR --}}
    <flux:modal name="spr-status-info" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.information-circle class="size-5 text-orange-600" />
                    <flux:heading size="lg">{{ __('Keterangan Status SPR') }}</flux:heading>
                </div>
                <flux:subheading>{{ __('Arti setiap tab dan tahapan SPR di sistem.') }}</flux:subheading>
            </div>

            <ul class="space-y-2.5">
                @foreach ($tabs as $key => [$label, $color, $hint])
                    @php
                        $badgeCls = match ($color) {
                            'orange'  => 'bg-orange-100 text-orange-800 dark:bg-orange-950/40 dark:text-orange-300',
                            'blue'    => 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300',
                            'emerald' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                            'violet'  => 'bg-violet-100 text-violet-800 dark:bg-violet-950/40 dark:text-violet-300',
                            'rose'    => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                            'zinc'    => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                        };
                    @endphp
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex shrink-0 rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $badgeCls }}">{{ $label }}</span>
                        <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $hint }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="rounded-lg border border-orange-200 bg-orange-50 p-3 text-xs text-orange-900 dark:border-orange-900/50 dark:bg-orange-950/30 dark:text-orange-200">
                <flux:icon.light-bulb class="-mt-0.5 mr-1 inline size-3.5" />
                {{ __('Untuk melihat tahapan detail SPR (Verifikasi Keuangan / Approval PM / TTD Konsumen / e-Materai), cek indikator Tahapan di kartu masing-masing SPR.') }}
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

    {{-- SEARCH --}}
    <div class="px-4 pt-4">
        <div class="relative">
            <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
            <input type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('Cari nomor SPR, nama, atau HP...') }}"
                   class="block h-11 w-full rounded-xl border border-zinc-200 bg-white pl-10 pr-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
        </div>
    </div>

    {{-- SORT --}}
    <div class="flex items-center justify-between gap-2 px-4 pt-3">
        <label for="spr-sort" class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
            {{ __('Urutkan') }}
        </label>
        <select id="spr-sort" wire:model.live="sort"
                class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
            <option value="terbaru">{{ __('Terbaru') }}</option>
            <option value="terlama">{{ __('Terlama') }}</option>
            <option value="nomor_asc">{{ __('Nomor SPR A-Z') }}</option>
            <option value="nomor_desc">{{ __('Nomor SPR Z-A') }}</option>
            <option value="nilai_desc">{{ __('Nilai terbesar') }}</option>
            <option value="nilai_asc">{{ __('Nilai terkecil') }}</option>
        </select>
    </div>

    {{-- LIST --}}
    <div class="space-y-3 px-4 pt-4">
        @forelse ($sprs as $s)
            @php
                [$badgeLabel, $badgeColor] = $s->statusBadge();
                $prospect = $s->prospectCustomer;
                $rumah = $s->rumah;
                $tipe = $rumah?->tipeRumah;
                $kodeUnit = $rumah ? $rumah->kode_unit : '—';
                $tipeLabel = $tipe ? trim(($tipe->tipe ?? '').' '.($tipe->nama_tipe ?? '')) : '—';

                $isFinal = $s->status === 'approved' && $s->spr_finalized_at !== null;
                $accent = match (true) {
                    $isFinal                                          => 'bg-violet-500',
                    in_array($s->status, ['submitted', 'approved'])   => 'bg-emerald-500',
                    $s->status === 'draft'                             => 'bg-zinc-500',
                    $s->status === 'akad'                              => 'bg-violet-500',
                    $s->status === 'rejected'                          => 'bg-rose-500',
                    $s->status === 'cancelled'                         => 'bg-orange-500',
                    default                                            => 'bg-zinc-400',
                };
            @endphp

            <a href="{{ route('dbos.spr.show', $s->id) }}" wire:navigate
               class="block overflow-hidden rounded-2xl bg-white shadow-md transition active:scale-[0.99] dark:bg-zinc-900">
                {{-- Top strip --}}
                <div @class(['flex items-center justify-between gap-2 px-4 py-2 text-white', $accent])>
                    <span class="inline-flex items-center gap-1.5 truncate font-mono text-xs font-bold">
                        <flux:icon.document-check class="size-3.5" />
                        {{ $s->nomor_display }}
                    </span>
                    <span class="text-[11px] font-bold uppercase tracking-wider">{{ $badgeLabel }}</span>
                </div>

                {{-- Body --}}
                <div class="flex gap-3 px-4 py-3">
                    <div @class([
                        'flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-xl text-white shadow-sm',
                        $accent,
                    ])>
                        <span class="text-[9px] font-bold uppercase tracking-widest opacity-70">Unit</span>
                        <span class="text-sm font-extrabold leading-none">{{ $kodeUnit }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-bold text-zinc-900 dark:text-white">
                            {{ $prospect?->nama_lengkap ?? '—' }}
                        </div>
                        <div class="mt-0.5 text-[11px] text-zinc-500">
                            {{ $tipeLabel }} · {{ ucwords(str_replace('_', ' ', $s->jenis_pembayaran)) }}
                        </div>
                        <div class="mt-1 flex items-center gap-1 text-[11px] text-zinc-500">
                            <flux:icon.calendar-days class="size-3" />
                            {{ $s->tanggal_spr?->translatedFormat('d M Y') ?? '—' }}
                            <span class="text-zinc-300">·</span>
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                                Rp {{ number_format((float) $s->total_harga, 0, ',', '.') }}
                            </span>
                        </div>
                    </div>
                    <flux:icon.chevron-right class="mt-1 size-5 shrink-0 text-zinc-400" />
                </div>

                @if ($s->status === 'rejected' && $s->alasan_reject)
                    <div class="border-t border-zinc-100 px-4 py-2 dark:border-zinc-800">
                        <p class="text-[11px] text-rose-700 dark:text-rose-300">
                            <span class="font-semibold">{{ __('Alasan penolakan:') }}</span>
                            {{ $s->alasan_reject }}
                        </p>
                    </div>
                @endif

                {{-- Progress 5 tahap (fitur #6: Verif Keuangan → PM Approve → TTD Konsumen → e-Materai → Selesai) --}}
                @if (in_array($s->status, ['submitted', 'approved']))
                    @php
                        $steps = $s->approvalSteps();
                        $stepIcons = ['banknotes', 'shield-check', 'pencil-square', 'document-text', 'trophy'];
                        $currentActiveIdx = null;
                        foreach ($steps as $idx => $step) {
                            if ($step['active']) { $currentActiveIdx = $idx; break; }
                        }
                    @endphp
                    <div class="border-t border-zinc-100 px-3 py-3 dark:border-zinc-800">
                        <div class="mb-2 text-[9px] font-bold uppercase tracking-wider text-zinc-500">
                            {{ __('Tahapan') }}
                        </div>
                        <div class="flex items-center gap-1">
                            @foreach ($steps as $idx => $step)
                                @php
                                    $icon = $stepIcons[$idx] ?? 'check';
                                    $prevDone = $idx === 0 || ($steps[$idx - 1]['done'] ?? false);
                                @endphp
                                <div class="flex flex-1 flex-col items-center">
                                    <div @class([
                                        'flex h-6 w-6 items-center justify-center rounded-full text-white shadow-sm',
                                        'bg-emerald-500' => $step['done'],
                                        'bg-amber-500 animate-pulse' => $step['active'],
                                        'bg-zinc-300 dark:bg-zinc-600' => ! $step['done'] && ! $step['active'],
                                    ])>
                                        @if ($step['done'])
                                            <flux:icon.check class="size-3" />
                                        @elseif ($step['active'])
                                            <flux:icon.clock class="size-3" />
                                        @else
                                            <flux:icon.minus class="size-3" />
                                        @endif
                                    </div>
                                    <div class="mt-1 text-center text-[8px] leading-tight text-zinc-600 dark:text-zinc-400">
                                        {{ $step['label'] }}
                                    </div>
                                </div>
                                @if (! $loop->last)
                                    <div @class([
                                        'mb-4 h-0.5 flex-1',
                                        'bg-emerald-500' => $step['done'],
                                        'bg-zinc-200 dark:bg-zinc-700' => ! $step['done'],
                                    ])></div>
                                @endif
                            @endforeach
                        </div>

                        {{-- Status text kontekstual --}}
                        <div class="mt-2 text-center text-[10px] font-semibold">
                            @if ($currentActiveIdx === 0)
                                <span class="text-amber-700 dark:text-amber-400">{{ __('Menunggu verifikasi Keuangan') }}</span>
                            @elseif ($currentActiveIdx === 1)
                                <span class="text-amber-700 dark:text-amber-400">{{ __('Menunggu approval Project Manager') }}</span>
                            @elseif ($currentActiveIdx === 2)
                                <span class="text-blue-700 dark:text-blue-400">{{ __('Siap kirim link TTD ke konsumen') }}</span>
                            @elseif ($currentActiveIdx === 3)
                                <span class="text-amber-700 dark:text-amber-400">{{ __('Menunggu e-Materai dari Keuangan') }}</span>
                            @elseif ($currentActiveIdx === 4)
                                <span class="text-amber-700 dark:text-amber-400">{{ __('Menunggu finalisasi sistem') }}</span>
                            @else
                                <span class="text-emerald-700 dark:text-emerald-400">{{ __('SPR final — siap kirim ke konsumen') }}</span>
                            @endif
                        </div>
                    </div>
                @endif
            </a>
        @empty
            <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-6 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon.document-check class="mx-auto size-10 text-zinc-400" />
                <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    @if ($search !== '')
                        {{ __('Tidak ada SPR yang cocok dengan pencarian.') }}
                    @else
                        {{ __('Belum ada SPR.') }}
                    @endif
                </p>
                <p class="mt-1 text-[11px] text-zinc-500">
                    {{ __('Buat SPR dari booking aktif di menu Booking.') }}
                </p>
                <a href="{{ route('dbos.booking.index') }}" wire:navigate
                   class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-orange-600 px-4 py-2 text-xs font-semibold text-white shadow-sm active:scale-95">
                    <flux:icon.clipboard-document-list class="size-4" />
                    {{ __('Lihat Daftar Booking') }}
                </a>
            </div>
        @endforelse

        @if ($sprs->hasPages())
            <div class="pt-2">
                {{ $sprs->links() }}
            </div>
        @endif
    </div>

</section>
