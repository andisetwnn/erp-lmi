<?php

use App\Livewire\Concerns\Sortable;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\SprTerminPembayaran;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Penerimaan Konsumen')] class extends Component
{
    use Sortable, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'pp', except: 15)]
    public int $perPage = 15;

    // State modal konfirmasi UTJ
    public ?int $confirmSprId = null;

    public string $confirmTanggalTransaksi = '';

    public string $confirmNominalAktual = '';

    public ?string $confirmCatatan = null;

    protected function defaultSortBy(): ?string
    {
        return 'created_at';
    }

    protected function defaultSortDir(): string
    {
        return 'desc';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function effectivePerPage(): int
    {
        // 0 = "Semua" — pakai angka besar biar semua row masuk 1 page
        return $this->perPage > 0 ? $this->perPage : 10000;
    }

    public function openKonfirmasi(int $sprId): void
    {
        $spr = Spr::findOrFail($sprId);
        $this->confirmSprId = $spr->id;
        $this->confirmTanggalTransaksi = now()->format('Y-m-d');
        $this->confirmNominalAktual = (string) $spr->utj_nominal;
        $this->confirmCatatan = null;
        $this->resetErrorBag();
        Flux::modal('konfirmasi-utj')->show();
    }

    public function submitKonfirmasi(): void
    {
        $validated = $this->validate([
            'confirmTanggalTransaksi' => ['required', 'date'],
            'confirmNominalAktual' => ['required', 'numeric', 'min:0'],
            'confirmCatatan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'confirmTanggalTransaksi' => 'tanggal transaksi',
            'confirmNominalAktual' => 'nominal aktual',
            'confirmCatatan' => 'catatan',
        ]);

        $spr = Spr::findOrFail($this->confirmSprId);

        DB::transaction(function () use ($spr, $validated) {
            $spr->update([
                'utj_tanggal_transaksi' => $validated['confirmTanggalTransaksi'],
                'utj_nominal_aktual' => (float) $validated['confirmNominalAktual'],
                'utj_confirmed_by_user_id' => Auth::id(),
                'utj_confirmed_at' => now(),
                'status' => 'approved',
                'approved_by_user_id' => Auth::id(),
                'approved_at' => now(),
                // tanggal_spr disamakan dengan tanggal transfer UTJ aktual dari Finance.
                // Semua jadwal termin UM diregenerasi dari anchor ini.
                'tanggal_spr' => $validated['confirmTanggalTransaksi'],
                // Snapshot TTD Finance (user yang konfirmasi UTJ) — supaya print SPR stabil.
                'ttd_finance_path' => Auth::user()->tanda_tangan_path,
            ]);

            // Auto-create realisasi UTJ (bf) — kalau belum ada realisasi bf untuk SPR ini.
            $sudahAdaRealisasiBf = $spr->realisasiPembayaran()->where('jenis', 'bf')->exists();
            if (! $sudahAdaRealisasiBf) {
                SprRealisasiPembayaran::create([
                    'spr_id' => $spr->id,
                    'jenis' => 'bf',
                    'tanggal_bayar' => $validated['confirmTanggalTransaksi'],
                    'jumlah' => (float) $validated['confirmNominalAktual'],
                    'nomor_kwitansi' => SprRealisasiPembayaran::generateNextNomor(),
                    'metode' => 'transfer',
                    'keterangan' => trim('UTJ dikonfirmasi Finance'.($validated['confirmCatatan'] ? ' · '.$validated['confirmCatatan'] : '')),
                    'input_by_user_id' => Auth::id(),
                ]);
            }

            // Regenerate jadwal termin UM berdasarkan tanggal transfer UTJ.
            // Termin ke-1 = UTJ + 15 hari, sisanya +1 bulan dari termin sebelumnya.
            // Aman diregenerasi: pada tahap konfirmasi UTJ, realisasi UM belum ada (UM dibayar
            // setelah TTD konsumen + akad).
            $terminUm = $spr->terminPembayaran()->where('jenis', 'um')->orderBy('urutan')->get();
            if ($terminUm->isNotEmpty()) {
                $anchor = \App\Support\SprJadwalTermin::toAnchor($validated['confirmTanggalTransaksi']);
                foreach ($terminUm as $t) {
                    $t->update([
                        'tanggal_jadwal' => \App\Support\SprJadwalTermin::tanggalTermin($anchor, (int) $t->urutan),
                    ]);
                }
            }
        });

        $spr->load('prospectCustomer');
        BusinessActivityLogger::utjVerified($spr);
        BusinessActivityLogger::sprApproved($spr);

        Flux::modal('konfirmasi-utj')->close();
        Flux::toast(
            variant: 'success',
            heading: "UTJ SPR {$spr->nomor_display} dikonfirmasi",
            text: 'Langkah selanjutnya: Project Manager approve SPR → Sales kirim link TTD ke konsumen → setelah konsumen TTD, bubuhkan e-Materai di menu Marketing → e-Materai.',
            duration: 8000,
        );

        $this->reset(['confirmSprId', 'confirmTanggalTransaksi', 'confirmNominalAktual', 'confirmCatatan']);
    }

    public function with(): array
    {
        $base = fn () => Spr::with([
            'prospectCustomer:id,nama_lengkap,hp,nik,bank_id,nomor_rekening',
            'prospectCustomer.bank:id,nama',
            'rumah:id,blok,nomor_unit,tipe_rumah_id,proyek_id',
            'rumah.proyek:id,nama_proyek',
            'rumah.tipeRumah:id,tipe,nama_tipe',
            'sales:id,kode,nama',
            'bankKpr:id,nama',
            'utjConfirmedBy:id,name',
        ]);

        // SPR yang menunggu konfirmasi UTJ
        $query = $base()->where('status', 'submitted');

        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('nomor_spr', 'like', "%{$s}%")
                    ->orWhereHas('prospectCustomer', fn ($qq) => $qq->where('nama_lengkap', 'like', "%{$s}%")->orWhere('hp', 'like', "%{$s}%"));
            });
        }

        // Sortable columns whitelist (kolom relasi pakai closure)
        $this->applySort($query, [
            'nomor_spr',
            'tanggal_spr',
            'created_at',
            'nama_lengkap' => fn ($q, $dir) => $q
                ->leftJoin('prospect_customer as pc_sort', 'pc_sort.id', '=', 'spr.prospect_customer_id')
                ->orderBy('pc_sort.nama_lengkap', $dir)
                ->select('spr.*'),
        ]);

        $sprs = $query->paginate($this->effectivePerPage());

        // Detail SPR untuk modal konfirmasi
        $confirmSpr = $this->confirmSprId
            ? Spr::with([
                'prospectCustomer:id,nama_lengkap,hp,nik',
                'rumah:id,blok,nomor_unit',
            ])->find($this->confirmSprId)
            : null;

        return compact('sprs', 'confirmSpr');
    }
}; ?>

@php
    $arrow = fn ($col, $active, $dir) => $active === $col ? ($dir === 'asc' ? '↑' : '↓') : '';
    $thBtn = function ($col, $label, $align = 'left') use ($sortBy, $sortDir, $arrow) {
        $active = $sortBy === $col;
        $alignClass = $align === 'right' ? 'justify-end text-right' : 'justify-start text-left';
        $colorClass = $active ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200';
        return '<button type="button" wire:click="sort(\''.$col.'\')" class="inline-flex items-center gap-1 w-full '.$alignClass.' '.$colorClass.' transition">'.
            e($label).' <span class="text-[9px]">'.$arrow($col, $sortBy, $sortDir).'</span></button>';
    };
@endphp

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5">
            <flux:heading size="xl">{{ __('Penerimaan Konsumen') }}</flux:heading>
            <flux:subheading>{{ __('Konfirmasi Uang Tanda Jadi (UTJ) — verifikasi bukti transfer dari customer.') }}</flux:subheading>
        </div>

        {{-- SEARCH + PAGE SIZE --}}
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full max-w-md">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                <input type="search" wire:model.live.debounce.400ms="search"
                       placeholder="{{ __('Cari nomor SPR, nama customer, atau HP...') }}"
                       class="block h-10 w-full rounded-xl border border-zinc-200 bg-white pl-10 pr-3 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </div>

            <div class="flex items-center gap-2">
                <label for="pk-perpage" class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
                    {{ __('Tampilkan') }}
                </label>
                <select id="pk-perpage" wire:model.live="perPage"
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                    @foreach ([10, 25, 50, 100, 0] as $pp)
                        <option value="{{ $pp }}">{{ $pp === 0 ? __('Semua') : $pp.' baris' }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr class="text-xs uppercase tracking-wider text-zinc-500">
                            <th class="px-3 py-3 text-left font-semibold">{!! $thBtn('nomor_spr', 'SPR') !!}</th>
                            <th class="px-3 py-3 text-left font-semibold">{!! $thBtn('tanggal_spr', 'Tgl SPR') !!}</th>
                            <th class="px-3 py-3 text-left font-semibold">Sales</th>
                            <th class="px-3 py-3 text-left font-semibold">{!! $thBtn('nama_lengkap', 'Customer') !!}</th>
                            <th class="px-3 py-3 text-left font-semibold">Rekening Customer</th>
                            <th class="px-3 py-3 text-left font-semibold">Proyek</th>
                            <th class="px-3 py-3 text-left font-semibold">Blok</th>
                            <th class="px-3 py-3 text-left font-semibold">Bukti</th>
                            <th class="px-3 py-3 text-left font-semibold">{!! $thBtn('created_at', 'Expired pada') !!}</th>
                            <th class="px-3 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($sprs as $s)
                            @php
                                // Expired = 3 hari sejak SPR dibuat oleh sales.
                                $expired = $s->created_at?->copy()->addDays(3);
                            @endphp
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-xs font-bold text-zinc-900 dark:text-white">{{ $s->nomor_display }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $s->tanggal_spr?->format('d/m/Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $s->sales?->nama ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3">{{ $s->prospectCustomer?->nama_lengkap }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">
                                    @if ($s->prospectCustomer?->bank || $s->prospectCustomer?->nomor_rekening)
                                        {{ $s->prospectCustomer?->bank?->nama }} {{ $s->prospectCustomer?->nomor_rekening }}
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $s->rumah?->proyek?->nama_proyek ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-xs">{{ $s->rumah?->kode_unit }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">
                                    @if ($s->utj_bukti_path)
                                        <span class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                            <flux:icon.check class="size-3" /> Ada
                                        </span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $expired?->format('d/m/Y H:i:s') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right">
                                    <button type="button" wire:click="openKonfirmasi({{ $s->id }})"
                                            class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-white shadow-sm hover:bg-emerald-700">
                                        <flux:icon.check class="size-3.5" />
                                        Konfirmasi UTJ
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-3 py-16 text-center">
                                    <flux:icon.inbox class="mx-auto size-10 text-zinc-300" />
                                    <p class="mt-3 text-sm font-semibold text-zinc-500">
                                        {{ __('Tidak ada UTJ menunggu konfirmasi') }}
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if (! $sprs->isEmpty())
            <div class="mt-4">
                <flux:pagination :paginator="$sprs" />
            </div>
        @endif

        {{-- ============ MODAL: Konfirmasi UTJ ============ --}}
        <flux:modal name="konfirmasi-utj" class="md:w-2xl">
            @if ($confirmSpr)
                <div class="space-y-5">
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:icon.check-circle class="size-5 text-emerald-600" />
                            <flux:heading size="lg">{{ __('Konfirmasi UTJ') }}</flux:heading>
                        </div>
                        <flux:subheading>
                            {{ __('Verifikasi nominal & tanggal sesuai bukti pembayaran / mutasi rekening.') }}
                        </flux:subheading>
                    </div>

                    {{-- Info SPR --}}
                    <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-800/50">
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <div class="text-zinc-500">Nomor SPR</div>
                                <div class="font-mono font-bold text-zinc-900 dark:text-white">{{ $confirmSpr->nomor_display }}</div>
                            </div>
                            <div>
                                <div class="text-zinc-500">Customer</div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $confirmSpr->prospectCustomer?->nama_lengkap }}</div>
                            </div>
                            <div>
                                <div class="text-zinc-500">Unit</div>
                                <div class="font-mono font-bold text-zinc-900 dark:text-white">{{ $confirmSpr->rumah?->kode_unit }}</div>
                            </div>
                            <div>
                                <div class="text-zinc-500">Metode</div>
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ ucfirst($confirmSpr->utj_metode) }}</div>
                            </div>
                        </div>
                    </div>

                    {{-- Bukti Transfer Upload Sales --}}
                    @if ($confirmSpr->utj_bukti_path)
                        <div>
                            <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-amber-700">{{ __('Bukti Transfer UTJ (upload sales)') }}</div>
                            @php
                                $ext = strtolower(pathinfo($confirmSpr->utj_bukti_path, PATHINFO_EXTENSION));
                                $url = \Illuminate\Support\Facades\Storage::disk('public')->url($confirmSpr->utj_bukti_path);
                            @endphp
                            <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                                @if (in_array($ext, ['jpg', 'jpeg', 'png']))
                                    <a href="{{ $url }}" target="_blank">
                                        <img src="{{ $url }}" alt="Bukti Transfer" class="max-h-72 rounded-lg border border-amber-300" />
                                    </a>
                                @else
                                    <a href="{{ $url }}" target="_blank" class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100 dark:bg-zinc-900 dark:text-amber-300">
                                        <flux:icon.document-arrow-down class="size-4" />
                                        {{ __('Buka Bukti PDF') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($confirmSpr->utj_keterangan)
                        <div class="rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                            <span class="font-semibold">Catatan sales:</span> {{ $confirmSpr->utj_keterangan }}
                        </div>
                    @endif

                    {{-- Nominal yang di-input sales (read-only) --}}
                    <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 dark:border-blue-900/50 dark:bg-blue-950/30">
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <div class="text-blue-700 dark:text-blue-300">Nominal UTJ (master)</div>
                                <div class="mt-0.5 text-base font-bold tabular-nums text-blue-900 dark:text-blue-100">
                                    Rp {{ number_format((float) $confirmSpr->utj_nominal, 0, ',', '.') }}
                                </div>
                            </div>
                            <div>
                                <div class="text-blue-700 dark:text-blue-300">SPR dibuat</div>
                                <div class="mt-0.5 text-base font-bold text-blue-900 dark:text-blue-100">
                                    {{ $confirmSpr->created_at?->format('d/m/Y') ?? '—' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Input Finance --}}
                    <div class="rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
                            {{ __('Verifikasi Aktual') }}
                        </div>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Tanggal Transaksi') }} <span class="ms-1 text-red-500">*</span></flux:label>
                                <div x-data="{
                                    init() {
                                        this.$nextTick(() => {
                                            if (typeof flatpickr === 'undefined') {
                                                console.warn('Flatpickr belum ter-load.');
                                                return;
                                            }
                                            flatpickr(this.$refs.dateInput, {
                                                locale: 'id',
                                                dateFormat: 'Y-m-d',
                                                altInput: true,
                                                altFormat: 'd/m/Y',
                                                allowInput: true,
                                                maxDate: 'today',
                                            });
                                        });
                                    }
                                }">
                                    <input type="text" x-ref="dateInput"
                                           wire:model="confirmTanggalTransaksi"
                                           class="block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm shadow-xs focus:border-emerald-500 focus:outline-hidden focus:ring-2 focus:ring-emerald-500/30 dark:border-zinc-700 dark:bg-zinc-800"
                                           placeholder="dd/mm/yyyy"
                                           required />
                                </div>
                                <flux:description>{{ __('Tanggal masuk ke rekening sesuai mutasi.') }}</flux:description>
                                <flux:error name="confirmTanggalTransaksi" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Nominal Aktual') }} <span class="ms-1 text-red-500">*</span></flux:label>
                                <x-money-input wire="confirmNominalAktual" required />
                                <flux:description>{{ __('Sesuai bukti bayar (jika berbeda dari input sales, isi yang aktual).') }}</flux:description>
                                <flux:error name="confirmNominalAktual" />
                            </flux:field>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:modal.close>
                            <flux:button variant="ghost" type="button" size="base">
                                {{ __('Batal') }}
                            </flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="button" wire:click="submitKonfirmasi" icon="check-circle" size="base"
                                     class="bg-emerald-600! hover:bg-emerald-700!">
                            {{ __('Konfirmasi & Setujui') }}
                        </flux:button>
                    </div>

                    @if (! auth()->user()->tanda_tangan_path)
                        <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                            <flux:icon.exclamation-triangle class="-mt-0.5 mr-1 inline size-3.5" />
                            {{ __('Anda belum mendaftarkan tanda tangan digital.') }}
                            <a href="{{ route('tanda-tangan.edit') }}" class="font-bold underline">{{ __('Daftar sekarang') }}</a>
                            {{ __('agar SPR yang Anda konfirmasi dapat dicetak dengan TTD.') }}
                        </div>
                    @endif
                </div>
            @endif
        </flux:modal>

    </div>
</section>
