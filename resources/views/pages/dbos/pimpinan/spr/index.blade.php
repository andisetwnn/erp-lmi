<?php

use App\Models\Master\Sales;
use App\Models\Master\Spr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('SPR Grup'), Layout('layouts.pimpinan')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'sales', except: '')]
    public string $filterSales = '';

    #[Url(as: 'jenis', except: '')]
    public string $filterJenis = '';

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterSales(): void { $this->resetPage(); }
    public function updatingFilterJenis(): void { $this->resetPage(); }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $salesList = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->orderBy('nama')
            ->get(['id', 'nama', 'kode']);

        $base = Spr::query()->whereIn('sales_id', $bawahanIds);

        $counts = (clone $base)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $countSelesai = (clone $base)->where('status', 'approved')->whereNotNull('spr_finalized_at')->count();
        // "Diproses" = submitted (nunggu verif UTJ) + approved (nunggu PM/TTD/Materai) yang belum final
        $countDiproses = (clone $base)->whereIn('status', ['submitted', 'approved'])->whereNull('spr_finalized_at')->count();

        $totalNilai = (float) (clone $base)->where('status', 'approved')->sum('total_harga');

        $query = (clone $base)
            ->with(['sales:id,nama,kode', 'prospectCustomer:id,nama_lengkap,hp', 'rumah:id,blok,nomor_unit'])
            ->when($this->filterStatus === 'selesai', fn ($q) => $q->where('status', 'approved')->whereNotNull('spr_finalized_at'))
            ->when($this->filterStatus === 'diproses', fn ($q) => $q->whereIn('status', ['submitted', 'approved'])->whereNull('spr_finalized_at'))
            ->when($this->filterStatus && ! in_array($this->filterStatus, ['selesai', 'diproses']), fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSales, fn ($q) => $q->where('sales_id', $this->filterSales))
            ->when($this->filterJenis, fn ($q) => $q->where('jenis_pembayaran', $this->filterJenis))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('nomor_spr', 'like', $term)
                        ->orWhereHas('prospectCustomer', fn ($qp) => $qp->where('nama_lengkap', 'like', $term)->orWhere('hp', 'like', $term)->orWhere('nik', 'like', $term));
                });
            });

        $rows = $query->orderByDesc('tanggal_spr')->orderByDesc('id')->paginate(20);

        return [
            'grup' => $grup,
            'rows' => $rows,
            'salesList' => $salesList,
            'countAll' => array_sum($counts),
            'countDiproses' => $countDiproses,
            'countSelesai' => $countSelesai,
            'countRejected' => $counts['rejected'] ?? 0,
            'countDraft' => $counts['draft'] ?? 0,
            'totalNilai' => $totalNilai,
        ];
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">{{ __('SPR Grup') }}</flux:heading>
    <flux:subheading class="mb-6">{{ $grup->nama }}</flux:subheading>

    {{-- TABS STATUS --}}
    @php
        $tabs = [
            ['key' => '',         'label' => __('Semua'),    'count' => $countAll,      'color' => 'zinc',   'hint' => __('Semua SPR di grup ini.')],
            ['key' => 'diproses', 'label' => __('Diproses'), 'count' => $countDiproses, 'color' => 'green',  'hint' => __('SPR yang masih berjalan (verifikasi UTJ → PM approve → TTD konsumen → e-Materai).')],
            ['key' => 'selesai',  'label' => __('Selesai'),  'count' => $countSelesai,  'color' => 'violet', 'hint' => __('SPR final — lengkap semua TTD dan ber-e-Materai.')],
            ['key' => 'rejected', 'label' => __('Ditolak'),  'count' => $countRejected, 'color' => 'red',    'hint' => __('SPR ditolak oleh Keuangan atau Project Manager.')],
            ['key' => 'draft',    'label' => __('Draft'),    'count' => $countDraft,    'color' => 'zinc',   'hint' => __('Belum di-submit oleh sales.')],
        ];
    @endphp
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $tab)
            @php
                $active = $filterStatus === $tab['key'];
                $activeCls = match ($tab['color']) {
                    'zinc'   => 'bg-zinc-700 text-white border-zinc-700',
                    'blue'   => 'bg-blue-600 text-white border-blue-600',
                    'green'  => 'bg-emerald-600 text-white border-emerald-600',
                    'violet' => 'bg-violet-600 text-white border-violet-600',
                    'red'    => 'bg-rose-600 text-white border-rose-600',
                };
            @endphp
            <flux:tooltip content="{{ $tab['hint'] }}">
                <button type="button" wire:click="$set('filterStatus', '{{ $tab['key'] }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition',
                            $activeCls => $active,
                            'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => ! $active,
                        ])>
                    {{ $tab['label'] }}
                    <span @class([
                        'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px]',
                        'bg-white/25 text-white' => $active,
                        'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' => ! $active,
                    ])>{{ $tab['count'] }}</span>
                </button>
            </flux:tooltip>
        @endforeach
    </div>

    {{-- FILTER --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex-1 min-w-60">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        :placeholder="__('Cari nomor SPR, nama, NIK, atau HP...')" />
        </div>
        <flux:select wire:model.live="filterSales" placeholder="{{ __('Sales') }}" class="w-48">
            <flux:select.option value="">{{ __('Semua sales') }}</flux:select.option>
            @foreach ($salesList as $s)
                <flux:select.option value="{{ $s->id }}">{{ $s->nama }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="filterJenis" placeholder="{{ __('Jenis') }}" class="w-36">
            <flux:select.option value="">{{ __('Semua jenis') }}</flux:select.option>
            <flux:select.option value="cash">{{ __('Cash') }}</flux:select.option>
            <flux:select.option value="cash_bertahap">{{ __('Cash bertahap') }}</flux:select.option>
            <flux:select.option value="kpr">{{ __('KPR') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- TABLE --}}
    @if ($rows->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.document-check class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Belum ada SPR') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wider text-zinc-500">
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Nomor SPR') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Customer') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Sales') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Unit') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Total Harga') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Jenis') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Tanggal') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($rows as $s)
                            @php
                                [$badge, $badgeCls] = $s->statusBadge();
                            @endphp
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                <td class="px-4 py-3 font-mono text-xs font-semibold text-zinc-900 dark:text-white">
                                    {{ $s->nomor_display }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $s->prospectCustomer?->nama_lengkap ?? '—' }}</div>
                                    <div class="font-mono text-[10px] text-zinc-500">{{ $s->prospectCustomer?->hp ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div class="font-semibold text-amber-700 dark:text-amber-300">{{ $s->sales?->nama ?? '—' }}</div>
                                    <div class="font-mono text-[10px] text-zinc-500">#{{ $s->sales?->kode ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ $s->rumah ? $s->rumah->blok.'-'.$s->rumah->nomor_unit : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right text-xs font-bold text-emerald-700 dark:text-emerald-400">
                                    {{ number_format((float) $s->total_harga, 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ \App\Models\Master\Spr::JENIS_PEMBAYARAN[$s->jenis_pembayaran] ?? $s->jenis_pembayaran }}
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-500">
                                    {{ $s->tanggal_spr?->translatedFormat('d M Y') }}
                                </td>
                                <td class="px-4 py-3">
                                    <span @class(['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', $badgeCls])>{{ $badge }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            <flux:pagination :paginator="$rows" />
        </div>
    @endif
</div>
