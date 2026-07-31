<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Sales;
use App\Models\Master\SalesTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Bandingkan Anggota'), Layout('layouts.pimpinan')] class extends Component {
    #[Url(as: 'ids')]
    public string $idsParam = '';

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $rawIds = collect(explode(',', $this->idsParam))
            ->map(fn ($s) => (int) trim($s))
            ->filter()
            ->unique()
            ->take(4)
            ->values();

        $anggota = Sales::with(['jenisSales', 'bank'])
            ->where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->whereIn('id', $rawIds)
            ->get();

        // Maintain order dari URL parameter
        $anggota = $rawIds->map(fn ($id) => $anggota->firstWhere('id', $id))->filter()->values();

        $anggotaIds = $anggota->pluck('id');
        $monthStart = now()->startOfMonth();
        $today = Carbon::today();
        $periodeBulan = SalesTarget::currentPeriode();

        // Prospect stats (all-time, by status)
        $prospectStats = ProspectCustomer::whereIn('sales_id', $anggotaIds)
            ->selectRaw('sales_id, status, COUNT(*) as cnt')
            ->groupBy('sales_id', 'status')
            ->get()
            ->groupBy('sales_id')
            ->map(fn ($rows) => $rows->pluck('cnt', 'status')->toArray());

        // Booking stats (all-time, by status)
        $bookingStats = Booking::whereIn('sales_id', $anggotaIds)
            ->selectRaw('sales_id, status, COUNT(*) as cnt')
            ->groupBy('sales_id', 'status')
            ->get()
            ->groupBy('sales_id')
            ->map(fn ($rows) => $rows->pluck('cnt', 'status')->toArray());

        // Bulan ini
        $prospectBulan = ProspectCustomer::whereIn('sales_id', $anggotaIds)
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('sales_id, COUNT(*) as cnt')->groupBy('sales_id')->pluck('cnt', 'sales_id')->toArray();
        $bookingBulan = Booking::whereIn('sales_id', $anggotaIds)
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('sales_id, COUNT(*) as cnt')->groupBy('sales_id')->pluck('cnt', 'sales_id')->toArray();

        // Last activity
        $lastActivity = ProspectCustomerStatusLog::whereIn('changed_by_sales_id', $anggotaIds)
            ->selectRaw('changed_by_sales_id, MAX(created_at) as last_at')
            ->groupBy('changed_by_sales_id')
            ->pluck('last_at', 'changed_by_sales_id')
            ->toArray();

        // Targets
        $targets = SalesTarget::whereIn('sales_id', $anggotaIds)
            ->where('periode', $periodeBulan)
            ->get()->keyBy('sales_id');

        // Time-to-conversion per anggota
        $ttc = [];
        foreach ($anggotaIds as $aid) {
            $rows = Booking::where('sales_id', $aid)
                ->with('prospectCustomer:id,created_at')
                ->get(['id', 'created_at', 'prospect_customer_id'])
                ->map(function ($b) {
                    if (! $b->prospectCustomer || ! $b->prospectCustomer->created_at) return null;
                    return $b->prospectCustomer->created_at->diffInDays($b->created_at);
                })->filter();
            $ttc[$aid] = $rows->isNotEmpty() ? round($rows->avg(), 1) : null;
        }

        // Enrich anggota with stats
        $anggota = $anggota->map(function ($a) use ($prospectStats, $bookingStats, $prospectBulan, $bookingBulan, $lastActivity, $targets, $ttc) {
            $p = $prospectStats->get($a->id, []);
            $b = $bookingStats->get($a->id, []);
            $a->ps_cold = $p['cold'] ?? 0;
            $a->ps_warm = $p['warm'] ?? 0;
            $a->ps_hot = $p['hot'] ?? 0;
            $a->ps_finish = $p['finish'] ?? 0;
            $a->ps_archive = $p['archive'] ?? 0;
            $a->ps_aktif = $a->ps_cold + $a->ps_warm + $a->ps_hot + $a->ps_finish;
            $a->bs_aktif = $b['aktif'] ?? 0;
            $a->bs_sukses = $b['sukses'] ?? 0;
            $a->bs_akad = $b['akad'] ?? 0;
            $a->bs_batal = $b['batal'] ?? 0;
            $a->bulan_prospect = $prospectBulan[$a->id] ?? 0;
            $a->bulan_booking = $bookingBulan[$a->id] ?? 0;
            $a->last_at = isset($lastActivity[$a->id]) ? Carbon::parse($lastActivity[$a->id]) : null;
            $a->target = $targets->get($a->id);
            $a->ttc = $ttc[$a->id] ?? null;
            return $a;
        });

        return compact('anggota');
    }
}; ?>

<div>
    {{-- BREADCRUMB --}}
    <div class="mb-4 flex items-center gap-2 text-sm">
        <a href="{{ route('dbos.pimpinan.anggota.index') }}" wire:navigate
           class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
            {{ __('Anggota Grup') }}
        </a>
        <flux:icon.chevron-right class="size-3.5 text-zinc-400" />
        <span class="font-semibold text-zinc-900 dark:text-white">{{ __('Bandingkan') }}</span>
    </div>

    <flux:heading size="xl" level="1">{{ __('Bandingkan Anggota') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Side-by-side comparison KPI & target progress') }}</flux:subheading>

    @if ($anggota->isEmpty())
        <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-white px-8 py-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon.users class="mx-auto size-10 text-zinc-400" />
            <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Tidak ada anggota dipilih') }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ __('Kembali ke daftar anggota & pilih minimal 2 anggota untuk dibandingkan.') }}</p>
            <a href="{{ route('dbos.pimpinan.anggota.index') }}" wire:navigate
               class="mt-4 inline-flex h-9 items-center gap-1 rounded-lg bg-amber-600 px-4 text-xs font-semibold text-white">
                <flux:icon.arrow-left class="size-3.5" />
                {{ __('Pilih anggota') }}
            </a>
        </div>
    @else
        @php
            $cols = $anggota->count();
            $rows = [
                ['label' => 'Kode', 'kind' => 'text', 'fn' => fn ($a) => '#'.$a->kode],
                ['label' => 'Jenis', 'kind' => 'text', 'fn' => fn ($a) => $a->jenisSales?->nama ?? '—'],
                ['label' => 'Status', 'kind' => 'badge', 'fn' => fn ($a) => $a->is_aktif ? ['Aktif', 'emerald'] : ['Nonaktif', 'zinc']],
                ['label' => '— PROSPECT (all-time) —', 'kind' => 'sep'],
                ['label' => 'Cold', 'kind' => 'num', 'fn' => fn ($a) => $a->ps_cold, 'color' => 'text-blue-600 dark:text-blue-400'],
                ['label' => 'Warm', 'kind' => 'num', 'fn' => fn ($a) => $a->ps_warm, 'color' => 'text-amber-600 dark:text-amber-400'],
                ['label' => 'Hot', 'kind' => 'num', 'fn' => fn ($a) => $a->ps_hot, 'color' => 'text-red-600 dark:text-red-400'],
                ['label' => 'Finish', 'kind' => 'num', 'fn' => fn ($a) => $a->ps_finish, 'color' => 'text-green-600 dark:text-green-400'],
                ['label' => 'Total Aktif', 'kind' => 'num', 'fn' => fn ($a) => $a->ps_aktif, 'color' => 'font-bold text-orange-700 dark:text-orange-400', 'highlight' => true],
                ['label' => '— BOOKING (all-time) —', 'kind' => 'sep'],
                ['label' => 'Aktif', 'kind' => 'num', 'fn' => fn ($a) => $a->bs_aktif, 'color' => 'text-amber-600 dark:text-amber-400'],
                ['label' => 'Sukses (SPR)', 'kind' => 'num', 'fn' => fn ($a) => $a->bs_sukses, 'color' => 'text-purple-600 dark:text-purple-400'],
                ['label' => 'Akad', 'kind' => 'num', 'fn' => fn ($a) => $a->bs_akad, 'color' => 'font-bold text-emerald-700 dark:text-emerald-400', 'highlight' => true],
                ['label' => 'Batal', 'kind' => 'num', 'fn' => fn ($a) => $a->bs_batal, 'color' => 'text-rose-500 dark:text-rose-400'],
                ['label' => '— BULAN INI —', 'kind' => 'sep'],
                ['label' => 'Prospect baru', 'kind' => 'num', 'fn' => fn ($a) => $a->bulan_prospect, 'highlight' => true],
                ['label' => 'Booking baru', 'kind' => 'num', 'fn' => fn ($a) => $a->bulan_booking, 'highlight' => true],
                ['label' => 'Target P', 'kind' => 'progress', 'fn' => fn ($a) => [$a->bulan_prospect, $a->target?->target_prospect ?? 0]],
                ['label' => 'Target B', 'kind' => 'progress', 'fn' => fn ($a) => [$a->bulan_booking, $a->target?->target_booking ?? 0]],
                ['label' => '— LAINNYA —', 'kind' => 'sep'],
                ['label' => 'Time-to-conversion', 'kind' => 'text', 'fn' => fn ($a) => $a->ttc !== null ? $a->ttc.' hari' : '—'],
                ['label' => 'Aktivitas terakhir', 'kind' => 'text', 'fn' => fn ($a) => $a->last_at?->diffForHumans() ?? 'belum'],
            ];

            // Pre-compute max values per row for "best" highlight
            $maxByRow = [];
            foreach ($rows as $i => $row) {
                if (! empty($row['highlight']) && $row['kind'] === 'num') {
                    $vals = $anggota->map(fn ($a) => (int) $row['fn']($a))->all();
                    $maxByRow[$i] = max($vals);
                }
            }
        @endphp

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                {{ __('Metrik') }}
                            </th>
                            @foreach ($anggota as $a)
                                @php
                                    $initials = collect(explode(' ', $a->nama))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
                                @endphp
                                <th class="px-4 py-3 text-left">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                            {{ $initials }}
                                        </div>
                                        <div class="min-w-0">
                                            <a href="{{ route('dbos.pimpinan.anggota.show', $a->id) }}" wire:navigate
                                               class="block truncate text-sm font-bold text-zinc-900 hover:underline dark:text-white">{{ $a->nama }}</a>
                                            <div class="font-mono text-[10px] text-zinc-500">#{{ $a->kode }}</div>
                                        </div>
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($rows as $i => $row)
                            @if ($row['kind'] === 'sep')
                                <tr class="bg-zinc-50/70 dark:bg-zinc-800/40">
                                    <td colspan="{{ $cols + 1 }}" class="px-4 py-1.5 text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                                        {{ $row['label'] }}
                                    </td>
                                </tr>
                            @else
                                <tr class="hover:bg-zinc-50/40 dark:hover:bg-zinc-800/20">
                                    <td class="px-4 py-2 text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $row['label'] }}</td>
                                    @foreach ($anggota as $a)
                                        <td class="px-4 py-2">
                                            @php $val = $row['fn']($a); @endphp
                                            @if ($row['kind'] === 'num')
                                                @php
                                                    $isMax = isset($maxByRow[$i]) && (int) $val === $maxByRow[$i] && (int) $val > 0 && $cols > 1;
                                                    $colorCls = $row['color'] ?? 'text-zinc-700 dark:text-zinc-200';
                                                @endphp
                                                <span @class([$colorCls, 'inline-flex items-center gap-1'])>
                                                    {{ $val }}
                                                    @if ($isMax)
                                                        <flux:icon.trophy class="size-3 text-amber-500" title="{{ __('Tertinggi') }}" />
                                                    @endif
                                                </span>
                                            @elseif ($row['kind'] === 'text')
                                                <span class="text-zinc-700 dark:text-zinc-200">{{ $val }}</span>
                                            @elseif ($row['kind'] === 'badge')
                                                @php [$lbl, $color] = $val; @endphp
                                                <span @class([
                                                    'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase',
                                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' => $color === 'emerald',
                                                    'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300' => $color === 'zinc',
                                                ])>{{ $lbl }}</span>
                                            @elseif ($row['kind'] === 'progress')
                                                @php
                                                    [$actual, $target] = $val;
                                                    if ($target > 0) {
                                                        $pct = min(100, round(($actual / $target) * 100));
                                                        $clr = $pct >= 100 ? 'bg-emerald-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-rose-400');
                                                    }
                                                @endphp
                                                @if ($target > 0)
                                                    <div class="space-y-0.5">
                                                        <div class="flex items-center justify-between text-[10px]">
                                                            <span class="text-zinc-500">{{ $actual }}/{{ $target }}</span>
                                                            <span class="font-bold">{{ $pct }}%</span>
                                                        </div>
                                                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                            <div @class(['h-full', $clr]) style="width: {{ $pct }}%"></div>
                                                        </div>
                                                    </div>
                                                @else
                                                    <span class="text-[11px] text-zinc-400">{{ __('belum di-set') }}</span>
                                                @endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
