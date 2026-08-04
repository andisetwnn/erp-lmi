<?php

use App\Models\Master\Booking;
use App\Models\Master\ProspectCustomer;
use App\Models\Master\ProspectCustomerStatusLog;
use App\Models\Master\Rumah;
use App\Support\BusinessActivityLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Form Booking'), Layout('layouts.dbos')] class extends Component {
    public Rumah $rumah;

    public ?int $selectedProspectId = null;

    // Picker state
    public bool $showPicker = false;

    public string $searchProspect = '';

    public function mount(int $id): void
    {
        $this->rumah = Rumah::with('proyek:id,nama_proyek', 'tipeRumah:id,tipe,nama_tipe,harga_jual')
            ->findOrFail($id);

        if ($this->rumah->status !== 'available') {
            $this->redirect(route('dbos.booking.unit', [
                'id' => $this->rumah->proyek_id,
                'blok' => $this->rumah->blok,
            ]), navigate: true);
        }
    }

    public function with(): array
    {
        // PROSPECT yg bisa di-booking: HANYA yg status = finish, milik sales itu, di proyek itu.
        // Plus belum punya booking aktif (skip yg sudah dipakai untuk unit lain)
        $prospects = $this->showPicker
            ? ProspectCustomer::query()
                ->where('sales_id', Auth::guard('sales')->id())
                ->where('proyek_id', $this->rumah->proyek_id)
                ->where('status', 'finish')
                ->whereDoesntHave('booking', fn ($q) => $q->where('status', 'aktif'))
                ->when($this->searchProspect, function ($q) {
                    $term = '%'.$this->searchProspect.'%';
                    $q->where(function ($qq) use ($term) {
                        $qq->where('nama_lengkap', 'like', $term)
                            ->orWhere('hp', 'like', $term)
                            ->orWhere('nik', 'like', $term);
                    });
                })
                ->orderBy('nama_lengkap')
                ->limit(30)
                ->get()
            : collect();

        return [
            'prospects' => $prospects,
            'selectedProspect' => $this->selectedProspectId
                ? ProspectCustomer::with('proyek:id,nama_proyek')->find($this->selectedProspectId)
                : null,
            'availableCount' => ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
                ->where('proyek_id', $this->rumah->proyek_id)
                ->where('status', 'finish')
                ->whereDoesntHave('booking', fn ($q) => $q->where('status', 'aktif'))
                ->count(),
        ];
    }

    public function openPicker(): void
    {
        $this->showPicker = true;
        $this->searchProspect = '';
        Flux::modal('booking-pick-prospect')->show();
    }

    public function pickProspect(int $prospectId): void
    {
        $p = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
            ->where('proyek_id', $this->rumah->proyek_id)
            ->where('status', 'finish')
            ->find($prospectId);

        if (! $p) {
            Flux::toast(variant: 'danger', text: 'Prospect tidak ditemukan atau tidak eligible.');
            return;
        }

        $this->selectedProspectId = $p->id;
        Flux::modal('booking-pick-prospect')->close();
        $this->showPicker = false;
    }

    public function clearProspect(): void
    {
        $this->selectedProspectId = null;
    }

    public function updatingSearchProspect(): void
    {
        // trigger re-query
    }

    public function save(): void
    {
        if (! $this->selectedProspectId) {
            Flux::toast(variant: 'danger', text: 'Pilih prospect dulu.');
            return;
        }

        // Re-validate prospect masih eligible
        $prospect = ProspectCustomer::where('sales_id', Auth::guard('sales')->id())
            ->where('proyek_id', $this->rumah->proyek_id)
            ->where('status', 'finish')
            ->find($this->selectedProspectId);

        if (! $prospect) {
            Flux::toast(variant: 'danger', heading: 'Gagal', text: 'Prospect tidak valid (mungkin status berubah). Refresh halaman.');
            return;
        }

        if ($prospect->bi_kol === null || $prospect->bi_dbr === null) {
            Flux::toast(variant: 'danger', heading: 'Gagal', text: 'BI Checking prospect belum lengkap.');
            return;
        }

        $salesId = Auth::guard('sales')->id();

        // Cooldown re-booking: sales yang sama tidak boleh booking ulang unit yg sama
        // dalam 2 hari kerja sejak unit terakhir dilepas (batal / expired).
        $cooldown = Booking::where('sales_id', $salesId)
            ->where('rumah_id', $this->rumah->id)
            ->whereNotNull('unit_dilepas_at')
            ->whereIn('status', ['batal', 'aktif'])
            ->orderByDesc('unit_dilepas_at')
            ->first();
        if ($cooldown) {
            $allowedAt = $cooldown->unit_dilepas_at->copy()->addDays(2);
            if (\Illuminate\Support\Carbon::today()->lt($allowedAt)) {
                Flux::toast(
                    variant: 'danger',
                    heading: 'Cooldown aktif',
                    text: 'Booking ulang unit ini baru bisa pada '.$allowedAt->translatedFormat('d M Y').'.',
                );
                $this->redirect(route('dbos.booking.unit', [
                    'id' => $this->rumah->proyek_id,
                    'blok' => $this->rumah->blok,
                ]), navigate: true);
                return;
            }
        }

        $createdBooking = null;
        try {
            DB::transaction(function () use ($salesId, $prospect, &$createdBooking) {
                // Lock baris rumah — sales lain yg concurrent akan blocked di sini
                // sampai transaction ini commit/rollback.
                $rumahLocked = Rumah::where('id', $this->rumah->id)
                    ->lockForUpdate()
                    ->first();

                if ($rumahLocked->status !== 'available') {
                    throw new \RuntimeException('Unit ini sudah di-booking sales lain.');
                }

                // Cek prospect sudah punya booking aktif?
                $existing = Booking::where('prospect_customer_id', $prospect->id)
                    ->where('status', 'aktif')
                    ->lockForUpdate()
                    ->exists();
                if ($existing) {
                    throw new \RuntimeException('Prospect ini sudah punya booking aktif untuk unit lain.');
                }

                $tglBooking = now()->startOfDay();

                $createdBooking = Booking::create([
                    'sales_id' => $salesId,
                    'proyek_id' => $this->rumah->proyek_id,
                    'rumah_id' => $this->rumah->id,
                    'prospect_customer_id' => $prospect->id,
                    'tanggal_booking' => $tglBooking->toDateString(),
                    // Stage 1: deadline exact 24 jam dari saat booking dibuat
                    'tanggal_expired' => now()->copy()->addDay(),
                    'status' => 'aktif',
                ]);

                $rumahLocked->update(['status' => 'booking']);

                // Log catatan booking di status log prospect (sudah finish, jadi catatan tambahan)
                ProspectCustomerStatusLog::create([
                    'prospect_customer_id' => $prospect->id,
                    'status_dari' => null,
                    'status_ke' => 'finish',
                    'catatan' => 'Booking unit '.$this->rumah->blok.'-'.$this->rumah->nomor_unit.' di '.$this->rumah->proyek?->nama_proyek,
                    'changed_by_sales_id' => $salesId,
                ]);
            });
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', heading: 'Gagal', text: $e->getMessage());
            $this->redirect(route('dbos.booking.unit', [
                'id' => $this->rumah->proyek_id,
                'blok' => $this->rumah->blok,
            ]), navigate: true);
            return;
        }

        if ($createdBooking) {
            $createdBooking->load('rumah', 'prospectCustomer', 'proyek', 'sales');
            BusinessActivityLogger::bookingCreated($createdBooking);
        }

        session()->flash('toast', 'Booking berhasil dibuat. Unit '.$this->rumah->blok.'-'.$this->rumah->nomor_unit.' sekarang BOOKING.');
        $this->redirect(route('dbos.home'), navigate: true);
    }
}; ?>

<section class="px-4 pb-24 pt-5">

    {{-- HEADER --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('dbos.booking.unit', ['id' => $rumah->proyek_id, 'blok' => $rumah->blok]) }}" wire:navigate
           class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-zinc-600 shadow-sm active:scale-95 dark:bg-zinc-900 dark:text-zinc-300">
            <flux:icon.arrow-left class="size-5" />
        </a>
        <div>
            <h1 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Booking Unit') }}</h1>
            <p class="text-xs text-zinc-500">{{ __('Pilih prospect yang sudah FINISH') }}</p>
        </div>
    </div>

    {{-- UNIT SUMMARY --}}
    <div class="mb-4 overflow-hidden rounded-2xl bg-linear-to-r from-orange-600 to-amber-500 p-4 text-white shadow">
        <div class="flex items-center gap-3">
            <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-white/20 text-lg font-bold backdrop-blur-sm">
                {{ $rumah->kode_unit }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate font-bold">{{ $rumah->proyek?->nama_proyek }}</div>
                <div class="truncate text-xs opacity-90">
                    {{ $rumah->tipeRumah?->tipe }} · {{ $rumah->tipeRumah?->nama_tipe }}
                </div>
                @if ($rumah->tipeRumah?->harga_jual > 0)
                    <div class="mt-1 font-mono text-sm">
                        Rp {{ number_format($rumah->tipeRumah->harga_jual, 0, ',', '.') }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <form wire:submit="save" class="space-y-4">

        {{-- ============== PICK PROSPECT FINISH ============== --}}
        <div class="rounded-2xl bg-white shadow-sm dark:bg-zinc-900">
            <div class="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-500 text-white">
                    <flux:icon.user class="size-4" />
                </div>
                <div class="flex-1">
                    <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('Calon Customer') }}</h2>
                    <p class="text-[11px] text-zinc-500">{{ __('Pilih dari prospect FINISH yang sudah BI checking') }}</p>
                </div>
            </div>

            <div class="p-4">
                @if ($selectedProspect)
                    {{-- Ringkasan prospect yang dipilih --}}
                    <div class="space-y-2 rounded-xl border-2 border-orange-300 bg-orange-50 p-4 dark:border-orange-700 dark:bg-orange-950/30">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-orange-700 dark:text-orange-300">
                                    {{ __('Prospect Terpilih') }}
                                </div>
                                <div class="mt-0.5 text-base font-bold text-zinc-900 dark:text-white">
                                    {{ $selectedProspect->nama_lengkap }}
                                </div>
                            </div>
                            <button type="button" wire:click="clearProspect"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-zinc-500 hover:bg-white hover:text-red-600 active:scale-95">
                                <flux:icon.x-mark class="size-4" />
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <div class="text-zinc-500">{{ __('NIK') }}</div>
                                <div class="font-mono">{{ $selectedProspect->nik ?? '—' }}</div>
                            </div>
                            <div>
                                <div class="text-zinc-500">{{ __('HP') }}</div>
                                <div class="font-mono">{{ $selectedProspect->hp }}</div>
                            </div>
                            @if ($selectedProspect->hp_2)
                                <div>
                                    <div class="text-zinc-500">{{ __('HP 2') }}</div>
                                    <div class="font-mono">{{ $selectedProspect->hp_2 }}</div>
                                </div>
                            @endif
                            <div>
                                <div class="text-zinc-500">{{ __('Sumber') }}</div>
                                <div>{{ $selectedProspect->sumber }}</div>
                            </div>
                        </div>

                        {{-- BI Checking summary --}}
                        <div class="mt-2 rounded-lg bg-white px-3 py-2 dark:bg-zinc-800">
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Hasil BI Checking') }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-3 text-xs">
                                <div>
                                    <span class="font-bold">KOL {{ $selectedProspect->bi_kol }}</span>
                                    <span class="text-zinc-500">
                                        — {{ match ($selectedProspect->bi_kol) {
                                            '1' => 'Lancar',
                                            '2' => 'DPK',
                                            '3' => 'Kurang Lancar',
                                            '4' => 'Diragukan',
                                            '5' => 'Macet',
                                            default => '—',
                                        } }}
                                    </span>
                                </div>
                                <div class="text-zinc-300">|</div>
                                <div>
                                    <span class="font-bold">DBR {{ number_format((float) $selectedProspect->bi_dbr, 2, ',', '.') }}%</span>
                                </div>
                            </div>
                            @if ($selectedProspect->bi_keterangan)
                                <div class="mt-1 text-[11px] italic text-zinc-600 dark:text-zinc-400">
                                    "{{ $selectedProspect->bi_keterangan }}"
                                </div>
                            @endif
                        </div>

                        @if ($selectedProspect->foto_ktp)
                            <div class="mt-2">
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Foto KTP') }}</div>
                                <a href="{{ asset('storage/'.$selectedProspect->foto_ktp) }}" target="_blank">
                                    <img src="{{ asset('storage/'.$selectedProspect->foto_ktp) }}" alt="KTP"
                                         class="mt-1 h-20 w-32 rounded border border-zinc-200 object-cover" />
                                </a>
                            </div>
                        @endif
                    </div>
                @else
                    @if ($availableCount === 0)
                        <div class="rounded-xl border-2 border-dashed border-amber-300 bg-amber-50 p-4 text-center dark:border-amber-700 dark:bg-amber-950/30">
                            <flux:icon.exclamation-triangle class="mx-auto size-8 text-amber-500" />
                            <p class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ __('Tidak ada prospect siap booking') }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                                {{ __('Belum ada prospect status FINISH di proyek ini. Tambah / update status prospect dulu di menu Database.') }}
                            </p>
                            <a href="{{ route('dbos.database.index', ['status' => 'finish']) }}" wire:navigate
                               class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-orange-600">
                                {{ __('Buka Database') }} →
                            </a>
                        </div>
                    @else
                        <button type="button" wire:click="openPicker"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-orange-300 bg-orange-50/50 py-5 text-sm font-semibold text-orange-700 active:scale-95 dark:border-orange-800 dark:bg-orange-950/20 dark:text-orange-300">
                            <flux:icon.magnifying-glass class="size-5" />
                            {{ __('Pilih Prospect (:n tersedia)', ['n' => $availableCount]) }}
                        </button>
                    @endif
                @endif
            </div>
        </div>

        {{-- INFO BOX --}}
        <div class="rounded-xl border-l-4 border-blue-500 bg-blue-50 p-3 text-xs text-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
            <div class="font-semibold">{{ __('Setelah booking dibuat:') }}</div>
            <ul class="mt-1 list-inside list-disc space-y-0.5">
                <li>{{ __('Unit otomatis berstatus BOOKING') }}</li>
                <li>{{ __('Prospect tetap FINISH dan terhubung ke booking ini') }}</li>
                <li>{{ __('Sales lain tidak bisa booking unit ini lagi') }}</li>
            </ul>
        </div>

        {{-- ACTIONS --}}
        <div class="grid grid-cols-2 gap-3 pt-2">
            <a href="{{ route('dbos.booking.unit', ['id' => $rumah->proyek_id, 'blok' => $rumah->blok]) }}" wire:navigate
               class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white text-sm font-semibold text-zinc-700 active:scale-95 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                <flux:icon.arrow-left class="size-4" />
                {{ __('Batal') }}
            </a>
            <button type="submit"
                    @if (! $selectedProspectId) disabled @endif
                    @class([
                        'inline-flex h-12 items-center justify-center gap-2 rounded-xl text-sm font-semibold text-white shadow active:scale-95 transition',
                        'bg-orange-600 hover:bg-orange-700' => $selectedProspectId,
                        'bg-zinc-300 cursor-not-allowed dark:bg-zinc-700' => ! $selectedProspectId,
                    ])>
                <flux:icon.check class="size-4" />
                <span wire:loading.remove wire:target="save">{{ __('Simpan Booking') }}</span>
                <span wire:loading wire:target="save">{{ __('Memproses...') }}</span>
            </button>
        </div>

    </form>

    {{-- ====== FULL-SCREEN LOADING OVERLAY saat save booking ====== --}}
    <div wire:loading.flex wire:target="save"
         class="fixed inset-0 z-9999 items-center justify-center bg-zinc-900/70 backdrop-blur-sm"
         style="display: none;">
        <div class="mx-4 flex max-w-xs flex-col items-center gap-4 rounded-2xl bg-white p-6 shadow-2xl dark:bg-zinc-900">
            <div class="relative">
                <div class="h-14 w-14 rounded-full border-4 border-orange-100 dark:border-orange-950/50"></div>
                <div class="absolute inset-0 h-14 w-14 animate-spin rounded-full border-4 border-transparent border-t-orange-600"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <flux:icon.home-modern class="size-5 text-orange-600" />
                </div>
            </div>
            <div class="text-center">
                <div class="text-base font-bold text-zinc-900 dark:text-white">
                    {{ __('Memproses booking...') }}
                </div>
                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Mohon tunggu, jangan tutup atau kembali') }}
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL: Pick Prospect --}}
    <flux:modal name="booking-pick-prospect" class="md:w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Pilih Prospect FINISH') }}</flux:heading>
                <flux:subheading>
                    {{ __('Prospect milik Anda di') }} <span class="font-semibold">{{ $rumah->proyek?->nama_proyek }}</span>
                    · {{ __('status FINISH') }} · {{ __('belum booking') }}
                </flux:subheading>
            </div>

            <flux:input wire:model.live.debounce.300ms="searchProspect" icon="magnifying-glass"
                        :placeholder="__('Cari nama / HP / NIK...')" />

            @if ($prospects->isEmpty())
                <div class="rounded-lg border-2 border-dashed border-zinc-200 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                    @if ($searchProspect)
                        {{ __('Tidak ditemukan.') }}
                    @else
                        {{ __('Tidak ada prospect FINISH yang available untuk di-booking.') }}
                    @endif
                </div>
            @else
                <div class="-mx-1 max-h-96 space-y-1 overflow-y-auto px-1">
                    @foreach ($prospects as $p)
                        <button type="button" wire:click="pickProspect({{ $p->id }})"
                                class="flex w-full items-center gap-3 rounded-lg border border-zinc-200 bg-white p-3 text-left transition hover:border-orange-400 hover:bg-orange-50/50 active:scale-95 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-orange-950/30">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $p->nama_lengkap }}</div>
                                <div class="font-mono text-xs text-zinc-500">{{ $p->hp }}</div>
                                @if ($p->nik)
                                    <div class="font-mono text-[10px] text-zinc-400">NIK: {{ $p->nik }}</div>
                                @endif
                                <div class="mt-1 inline-flex items-center gap-2 text-[10px]">
                                    <span class="rounded bg-green-100 px-1.5 py-0.5 font-bold text-green-700 dark:bg-green-950/50 dark:text-green-300">
                                        KOL {{ $p->bi_kol }}
                                    </span>
                                    <span class="text-zinc-500">DBR {{ number_format((float) $p->bi_dbr, 1, ',', '.') }}%</span>
                                </div>
                            </div>
                            <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400" />
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

</section>
