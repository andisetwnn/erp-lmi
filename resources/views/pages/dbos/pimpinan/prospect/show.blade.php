<?php

use App\Models\Master\Booking;
use App\Models\Master\PimpinanActivityLog;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\ProspectReassignmentLog;
use App\Models\Master\Sales;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detail Prospect'), Layout('layouts.pimpinan')] class extends Component {
    public int $id;

    // Re-assign form state
    public ?int $reassignTargetId = null;

    public string $reassignAlasan = '';

    public function mount(int $id): void
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $prospect = ProspectCustomer::whereIn('sales_id', $bawahanIds)->find($id);
        abort_unless($prospect, 404);

        $this->id = $prospect->id;
    }

    public function openReassignModal(): void
    {
        $this->reassignTargetId = null;
        $this->reassignAlasan = '';
        $this->resetErrorBag();
        Flux::modal('prospect-reassign')->show();
    }

    public function confirmReassign(): void
    {
        $this->validate([
            'reassignTargetId' => ['required', 'integer'],
            'reassignAlasan' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reassignTargetId.required' => 'Pilih sales tujuan.',
            'reassignAlasan.required' => 'Alasan re-assign wajib diisi.',
            'reassignAlasan.min' => 'Alasan minimal 5 karakter.',
        ]);

        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        // Validasi sales tujuan harus di grup yang sama
        if (! $bawahanIds->contains($this->reassignTargetId)) {
            Flux::toast(variant: 'danger', text: 'Sales tujuan tidak valid (bukan anggota grup).');
            return;
        }

        $prospect = ProspectCustomer::whereIn('sales_id', $bawahanIds)->find($this->id);
        if (! $prospect) {
            Flux::toast(variant: 'danger', text: 'Prospect tidak ditemukan.');
            return;
        }

        if ($prospect->sales_id === $this->reassignTargetId) {
            Flux::toast(variant: 'warning', text: 'Prospect sudah dimiliki sales tersebut.');
            return;
        }

        DB::transaction(function () use ($prospect, $pimpinan) {
            $fromSales = Sales::find($prospect->sales_id);
            $toSales = Sales::find($this->reassignTargetId);

            ProspectReassignmentLog::create([
                'prospect_customer_id' => $prospect->id,
                'from_sales_id' => $prospect->sales_id,
                'to_sales_id' => $this->reassignTargetId,
                'alasan' => trim($this->reassignAlasan),
                'reassigned_by_sales_id' => $pimpinan->id,
            ]);

            $prospect->update(['sales_id' => $this->reassignTargetId]);

            PimpinanActivityLog::log(
                $pimpinan->id,
                'reassign_prospect',
                $prospect->nama_lengkap,
                [
                    'prospect_id' => $prospect->id,
                    'from' => $fromSales?->nama,
                    'to' => $toSales?->nama,
                    'alasan' => trim($this->reassignAlasan),
                ],
            );
        });

        Flux::modal('prospect-reassign')->close();
        $this->reassignTargetId = null;
        $this->reassignAlasan = '';
        Flux::toast(variant: 'success', text: 'Prospect berhasil dipindahkan ke sales lain.');
    }

    public function with(): array
    {
        /** @var Sales $pimpinan */
        $pimpinan = Auth::guard('sales')->user();
        $grup = $pimpinan->grupYangDipimpin();

        $bawahanIds = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->pluck('id');

        $prospect = ProspectCustomer::with([
            'proyek:id,nama_proyek',
            'sales:id,nama,kode',
            'tempatKerja:id,nama',
            'bank:id,nama',
            'kontakDarurat:id,prospect_customer_id,nama,hubungan,nomor_telepon',
        ])->whereIn('sales_id', $bawahanIds)->findOrFail($this->id);

        $logs = ProspectCustomerStatusLog::with('changedBy:id,nama,kode')
            ->where('prospect_customer_id', $prospect->id)
            ->orderByDesc('created_at')
            ->get();

        $bookings = Booking::with(['rumah:id,blok,nomor_unit', 'proyek:id,nama_proyek'])
            ->where('prospect_customer_id', $prospect->id)
            ->orderByDesc('created_at')
            ->get();

        $reassignLogs = ProspectReassignmentLog::with([
            'fromSales:id,nama,kode',
            'toSales:id,nama,kode',
            'reassignedBy:id,nama,kode',
        ])
            ->where('prospect_customer_id', $prospect->id)
            ->orderByDesc('created_at')
            ->get();

        // List sales anggota grup (exclude sales pemilik saat ini)
        $salesOptions = Sales::where('sales_grup_id', $grup->id)
            ->where('id', '!=', $pimpinan->id)
            ->where('id', '!=', $prospect->sales_id)
            ->where('is_aktif', true)
            ->orderBy('nama')
            ->get(['id', 'nama', 'kode']);

        // ============= UNIFIED TIMELINE: status + reassign + booking =============
        $timeline = collect();
        foreach ($logs as $log) {
            $timeline->push([
                'time' => $log->created_at,
                'kind' => 'status',
                'data' => $log,
            ]);
        }
        foreach ($reassignLogs as $rl) {
            $timeline->push([
                'time' => $rl->created_at,
                'kind' => 'reassign',
                'data' => $rl,
            ]);
        }
        foreach ($bookings as $bk) {
            $timeline->push([
                'time' => $bk->created_at,
                'kind' => 'booking',
                'data' => $bk,
            ]);
        }
        $timeline = $timeline->sortByDesc(fn ($e) => $e['time']->timestamp)->values();

        return compact('prospect', 'logs', 'reassignLogs', 'bookings', 'salesOptions', 'timeline');
    }
}; ?>

<div>
    {{-- BREADCRUMB --}}
    <div class="mb-4 flex items-center gap-2 text-sm">
        <a href="{{ route('dbos.pimpinan.prospect.index') }}" wire:navigate
           class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
            {{ __('Prospect Grup') }}
        </a>
        <flux:icon.chevron-right class="size-3.5 text-zinc-400" />
        <span class="font-semibold text-zinc-900 dark:text-white">{{ $prospect->nama_lengkap }}</span>
    </div>

    @php
        $statusBadge = match ($prospect->status) {
            'cold' => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300', 'COLD'],
            'warm' => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
            'hot' => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300', 'HOT'],
            'finish' => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
            'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', 'ARCHIVE'],
        };
        $waLink = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $prospect->hp ?? '');
        $alamatPenuh = collect([
            $prospect->alamat,
            $prospect->kelurahan_nama,
            $prospect->kecamatan_nama,
            $prospect->kota_nama,
            $prospect->provinsi_nama,
        ])->filter()->implode(', ');

    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $prospect->nama_lengkap }}</flux:heading>
                <span @class(['rounded-full px-2.5 py-1 text-xs font-bold uppercase tracking-wider', $statusBadge[0]])>{{ $statusBadge[1] }}</span>
            </div>
        </div>
        @if ($salesOptions->isNotEmpty())
            <button type="button" wire:click="openReassignModal"
                    class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-700 shadow-sm transition hover:bg-amber-100 active:scale-95 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                <flux:icon.arrow-path-rounded-square class="size-4" />
                {{ __('Pindahkan ke sales lain') }}
            </button>
        @endif
    </div>


    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- INFO COLUMN --}}
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Informasi') }}</h3>
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Sales pemilik') }}</dt>
                        <dd class="mt-0.5 font-semibold text-amber-700 dark:text-amber-300">
                            {{ $prospect->sales?->nama ?? '—' }}
                            @if ($prospect->sales)
                                <span class="font-mono text-[10px] text-zinc-400">#{{ $prospect->sales->kode }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Proyek') }}</dt>
                        <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $prospect->proyek?->nama_proyek ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('HP') }}</dt>
                        <dd class="mt-0.5">
                            @if ($prospect->hp)
                                <a href="{{ $waLink }}" target="_blank"
                                   class="inline-flex items-center gap-1 font-mono font-semibold text-green-600 hover:underline dark:text-green-400">
                                    <flux:icon.phone class="size-3.5" />
                                    {{ $prospect->hp }}
                                </a>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Sumber') }}</dt>
                        <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $prospect->sumber }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Tanggal input') }}</dt>
                        <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $prospect->created_at?->translatedFormat('d M Y · H:i') }}</dd>
                    </div>
                    @if ($prospect->nik)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('NIK') }}</dt>
                            <dd class="mt-0.5 font-mono font-semibold text-zinc-900 dark:text-white">{{ $prospect->nik }}</dd>
                        </div>
                    @endif
                    @if ($prospect->npwp)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('NPWP') }}</dt>
                            <dd class="mt-0.5 font-mono font-semibold text-zinc-900 dark:text-white">{{ $prospect->npwp }}</dd>
                        </div>
                    @endif
                    @if ($alamatPenuh)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-zinc-500">{{ __('Alamat KTP') }}</dt>
                            <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ $alamatPenuh }}</dd>
                        </div>
                    @endif
                    @if ($prospect->tempatKerja)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Tempat kerja') }}</dt>
                            <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">{{ $prospect->tempatKerja->nama }}</dd>
                        </div>
                    @endif
                    @if ($prospect->bank)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('Rekening') }}</dt>
                            <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">
                                {{ $prospect->bank->nama }}
                                <span class="font-mono text-xs text-zinc-500">· {{ $prospect->nomor_rekening ?? '—' }}</span>
                            </dd>
                        </div>
                    @endif
                    @if ($prospect->bi_kol || $prospect->bi_dbr !== null)
                        <div>
                            <dt class="text-xs text-zinc-500">{{ __('BI Checking') }}</dt>
                            <dd class="mt-0.5 font-semibold text-zinc-900 dark:text-white">
                                KOL {{ $prospect->bi_kol ?? '—' }} · DBR {{ $prospect->bi_dbr !== null ? $prospect->bi_dbr.'%' : '—' }}
                            </dd>
                        </div>
                    @endif
                    @php
                        // Sembunyikan catatan yang isinya marker teknis
                        $catatanUser = $prospect->catatan && ! str_starts_with($prospect->catatan, 'Imported dari MASTER DATA xlsx')
                            ? $prospect->catatan
                            : null;
                    @endphp
                    @if ($catatanUser)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-zinc-500">{{ __('Catatan') }}</dt>
                            <dd class="mt-0.5 rounded-lg bg-zinc-50 px-3 py-2 text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">{{ $catatanUser }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @php
                // Sembunyikan kontak yang belum diisi (nama generik) dari tampilan
                $kontakReal = $prospect->kontakDarurat->reject(fn ($k) => str_contains((string) $k->nama, '(placeholder)'));
            @endphp
            @if ($kontakReal->isNotEmpty())
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Kontak Darurat') }}</h3>
                    <ul class="space-y-2 text-sm">
                        @foreach ($kontakReal as $k)
                            <li class="flex items-center gap-3 rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                <flux:icon.user class="size-4 text-zinc-400" />
                                <div class="min-w-0 flex-1">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $k->nama }}</div>
                                    <div class="text-xs text-zinc-500">{{ $k->hubungan }}</div>
                                </div>
                                <span class="font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $k->nomor_telepon }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- CUSTOMER JOURNEY TIMELINE (unified) --}}
        <div class="lg:col-span-1">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.map class="size-4 text-zinc-500" />
                    <span class="text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ __('Customer Journey') }}</span>
                </div>

                @if ($timeline->isEmpty())
                    <p class="py-6 text-center text-xs text-zinc-500">{{ __('Belum ada riwayat.') }}</p>
                @else
                    @php
                        $statusBadgeColors = [
                            'cold' => ['bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300', 'COLD'],
                            'warm' => ['bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300', 'WARM'],
                            'hot' => ['bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300', 'HOT'],
                            'finish' => ['bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300', 'FINISH'],
                            'archive' => ['bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', 'ARCHIVE'],
                        ];
                    @endphp
                    <ol class="relative ms-1 space-y-4 border-s-2 border-zinc-200 ps-4 dark:border-zinc-700">
                        @foreach ($timeline as $i => $event)
                            @php
                                $dotColor = match ($event['kind']) {
                                    'status' => 'bg-amber-500',
                                    'reassign' => 'bg-purple-500',
                                    'booking' => 'bg-blue-500',
                                };
                            @endphp
                            <li class="relative">
                                <span @class(['absolute -inset-s-5.75 mt-1.5 flex h-3 w-3 items-center justify-center rounded-full ring-4 ring-white dark:ring-zinc-900', $dotColor])></span>
                                <div class="text-[11px] text-zinc-500">
                                    {{ $event['time']?->translatedFormat('d M Y · H:i') ?? '—' }}
                                </div>

                                @switch($event['kind'])
                                    @case('status')
                                        @php
                                            $log = $event['data'];
                                            $isInitial = $loop->last && $log->status_dari === null;
                                            $isStatusChange = $log->status_dari !== null && $log->status_dari !== $log->status_ke;
                                        @endphp
                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                            @if ($isInitial)
                                                <flux:icon.plus-circle class="size-3.5 text-orange-600" />
                                                <span class="text-zinc-700 dark:text-zinc-300">{{ __('Prospect dibuat') }}</span>
                                                <span @class(['rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase', $statusBadgeColors[$log->status_ke][0] ?? 'bg-zinc-100'])>
                                                    {{ $statusBadgeColors[$log->status_ke][1] ?? strtoupper($log->status_ke) }}
                                                </span>
                                            @elseif ($isStatusChange)
                                                <span @class(['rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase', $statusBadgeColors[$log->status_dari][0] ?? 'bg-zinc-100'])>
                                                    {{ $statusBadgeColors[$log->status_dari][1] ?? strtoupper($log->status_dari) }}
                                                </span>
                                                <flux:icon.arrow-right class="size-3 text-zinc-400" />
                                                <span @class(['rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase', $statusBadgeColors[$log->status_ke][0] ?? 'bg-zinc-100'])>
                                                    {{ $statusBadgeColors[$log->status_ke][1] ?? strtoupper($log->status_ke) }}
                                                </span>
                                            @else
                                                <flux:icon.pencil-square class="size-3.5 text-zinc-500" />
                                                <span class="text-zinc-700 dark:text-zinc-300">{{ __('Catatan') }}</span>
                                            @endif
                                            @if ($log->changedBy)
                                                <span class="text-zinc-400">·</span>
                                                <span class="text-[11px] font-semibold text-zinc-500">{{ $log->changedBy->nama }}</span>
                                            @endif
                                        </div>
                                        @if ($log->catatan)
                                            <p class="mt-1 rounded-lg bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                                                {{ $log->catatan }}
                                            </p>
                                        @endif
                                        @break

                                    @case('reassign')
                                        @php $rl = $event['data']; @endphp
                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                            <flux:icon.arrow-path-rounded-square class="size-3.5 text-purple-600" />
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ __('Re-assign:') }}</span>
                                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $rl->fromSales?->nama ?? '—' }}</span>
                                            <flux:icon.arrow-right class="size-3 text-zinc-400" />
                                            <span class="font-semibold text-amber-700 dark:text-amber-300">{{ $rl->toSales?->nama ?? '—' }}</span>
                                            @if ($rl->reassignedBy)
                                                <span class="text-zinc-400">·</span>
                                                <span class="text-[11px] font-semibold text-zinc-500">{{ $rl->reassignedBy->nama }}</span>
                                            @endif
                                        </div>
                                        @if ($rl->alasan)
                                            <p class="mt-1 rounded-lg bg-purple-50 px-2 py-1.5 text-[11px] text-purple-800 dark:bg-purple-950/30 dark:text-purple-300">
                                                {{ $rl->alasan }}
                                            </p>
                                        @endif
                                        @break

                                    @case('booking')
                                        @php $bk = $event['data']; @endphp
                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                            <flux:icon.clipboard-document-list class="size-3.5 text-blue-600" />
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ __('Booking dibuat') }}</span>
                                            <span class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">
                                                {{ strtoupper($bk->status) }}
                                            </span>
                                        </div>
                                        <a href="{{ route('dbos.pimpinan.booking.show', $bk->id) }}" wire:navigate
                                           class="mt-1 inline-flex items-center gap-1 rounded-lg bg-blue-50 px-2 py-1.5 text-[11px] text-blue-800 hover:bg-blue-100 dark:bg-blue-950/30 dark:text-blue-300">
                                            {{ $bk->proyek?->nama_proyek ?? '—' }}
                                            @if ($bk->rumah) · {{ $bk->rumah->kode_unit }} @endif
                                            <flux:icon.arrow-right class="size-3" />
                                        </a>
                                        @break
                                @endswitch
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>

    </div>

    {{-- MODAL RE-ASSIGN --}}
    <flux:modal name="prospect-reassign" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon.arrow-path-rounded-square class="size-5 text-amber-600" />
                    <flux:heading size="lg">{{ __('Pindahkan ke sales lain') }}</flux:heading>
                </div>
                <flux:subheading>
                    {{ __('Prospect:') }} <span class="font-semibold">{{ $prospect->nama_lengkap }}</span>
                </flux:subheading>
            </div>

            <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                <flux:icon.information-circle class="inline size-3.5" />
                {{ __('Saat ini dimiliki:') }} <span class="font-semibold">{{ $prospect->sales?->nama ?? '—' }}</span>.
                {{ __('Re-assign akan dicatat di riwayat (audit log).') }}
            </div>

            <flux:field>
                <flux:label>{{ __('Sales tujuan') }}</flux:label>
                <flux:select wire:model="reassignTargetId" placeholder="{{ __('Pilih sales...') }}">
                    @foreach ($salesOptions as $s)
                        <flux:select.option value="{{ $s->id }}">{{ $s->nama }} (#{{ $s->kode }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="reassignTargetId" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Alasan re-assign') }}</flux:label>
                <flux:textarea wire:model="reassignAlasan" rows="3"
                               :placeholder="__('Contoh: Sales A resign, dipindahkan ke Sales B untuk follow-up lanjutan.')" />
                <flux:description>{{ __('Wajib diisi, minimal 5 karakter. Akan disimpan sebagai audit log.') }}</flux:description>
                <flux:error name="reassignAlasan" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="button" wire:click="confirmReassign"
                             class="bg-amber-600! hover:bg-amber-700!">
                    {{ __('Pindahkan') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
