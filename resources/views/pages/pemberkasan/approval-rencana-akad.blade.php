<?php

use App\Models\Master\Bank;
use App\Models\Master\Notaris;
use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkad;
use App\Services\RencanaAkadService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Halaman Approval Rencana Akad (Project Manager).
 *
 * Tampilkan rencana berstatus 'diajukan' yang menunggu persetujuan PM.
 * Setelah PM klik "Ketahui", status naik ke 'diketahui' dan slot "Mengetahui"
 * pada lembar Aju Dana otomatis terisi TTD PM.
 *
 * Halaman ini terpisah dari list Rencana Akad supaya PM tidak perlu buka detail
 * satu-satu: cukup review ringkas, klik Ketahui, dan lanjut. Untuk penolakan
 * (kembalikan ke Draft) PM masuk halaman detail supaya bisa memberi alasan.
 */
new #[Title('Approval Rencana Akad')] class extends Component
{
    use WithPagination;

    #[Url(as: 'bulan')]
    public string $bulan = '';

    #[Url(as: 'proyek')]
    public ?int $proyekId = null;

    #[Url(as: 'bank')]
    public ?int $bankId = null;

    #[Url(as: 'notaris')]
    public ?int $notarisId = null;

    #[Url(as: 'q')]
    public string $cari = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.mengetahui'), 403);

        if ($this->bulan === '') {
            $this->bulan = now()->format('Y-m');
        }
        $this->proyekId ??= Proyek::query()->value('id');
    }

    public function updated($property): void
    {
        if (in_array($property, ['bulan', 'proyekId', 'bankId', 'notarisId', 'cari'], true)) {
            $this->resetPage();
        }
    }

    private function svc(): RencanaAkadService
    {
        return app(RencanaAkadService::class);
    }

    /** Rencana yang sedang dikonfirmasi lewat modal (null = tidak ada). */
    public ?int $konfirmasiId = null;

    /** Alasan penolakan (kembalikan ke Draft). */
    public string $alasanTolak = '';

    /**
     * Buka modal konfirmasi. Menampilkan ringkas info rencana supaya PM tidak
     * salah klik — nomor rencana saja terlalu kering, ini kasih konteks.
     */
    public function konfirmasiKetahui(int $id): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.mengetahui'), 403);

        $this->konfirmasiId = $id;
        $this->resetErrorBag();
        Flux::modal('konfirmasi-ketahui')->show();
    }

    /**
     * Buka modal penolakan. Alasan wajib supaya Admin KPR tahu apa yang perlu
     * dibetulkan sebelum diajukan ulang.
     */
    public function konfirmasiTolak(int $id): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.mengetahui'), 403);

        $this->konfirmasiId = $id;
        $this->alasanTolak = '';
        $this->resetErrorBag();
        Flux::modal('konfirmasi-tolak')->show();
    }

    /**
     * PM menyetujui rencana → status naik ke 'diketahui'. Dipanggil dari modal
     * konfirmasi supaya PM tidak salah klik (native confirm() rentan spam-click).
     */
    public function ketahui(): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.mengetahui'), 403);
        abort_if($this->konfirmasiId === null, 400);

        $rencana = RencanaAkad::query()->findOrFail($this->konfirmasiId);

        try {
            $this->svc()->ubahStatus($rencana, 'diketahui', Auth::id());
            Flux::modal('konfirmasi-ketahui')->close();
            Flux::toast(
                variant: 'success',
                heading: 'Rencana Diketahui',
                text: "Nomor {$rencana->nomor} sudah kamu setujui. Selanjutnya bisa dikirim ke Direksi via link."
            );
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());
        }

        $this->reset(['konfirmasiId']);
    }

    /**
     * PM menolak rencana → dikembalikan ke Draft dengan alasan.
     */
    public function tolak(): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.mengetahui'), 403);
        abort_if($this->konfirmasiId === null, 400);

        $this->validate(
            ['alasanTolak' => ['required', 'string', 'min:3', 'max:500']],
            [],
            ['alasanTolak' => 'alasan']
        );

        $rencana = RencanaAkad::query()->findOrFail($this->konfirmasiId);

        try {
            $this->svc()->ubahStatus($rencana, 'draft', Auth::id(), ['alasan' => $this->alasanTolak]);
            Flux::modal('konfirmasi-tolak')->close();
            Flux::toast(
                variant: 'warning',
                heading: 'Rencana Dikembalikan',
                text: "Nomor {$rencana->nomor} dikembalikan ke Draft untuk diperbaiki Admin KPR."
            );
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());
        }

        $this->reset(['konfirmasiId', 'alasanTolak']);
    }

    public function with(): array
    {
        $q = RencanaAkad::query()
            ->with([
                'bank:id,nama',
                'notaris:id,nama',
                'proyek:id,nama_proyek',
                'diajukanBy:id,name',
            ])
            ->withCount('unit')
            ->withSum('unit as total_kpr', 'setoran_ajb') // rough proxy; ganti kalau perlu
            ->where('status', 'diajukan')
            ->when($this->proyekId, fn ($x) => $x->where('proyek_id', $this->proyekId))
            ->when($this->bankId, fn ($x) => $x->where('bank_id', $this->bankId))
            ->when($this->notarisId, fn ($x) => $x->where('notaris_id', $this->notarisId))
            ->when($this->cari !== '', fn ($x) => $x->where('nomor', 'like', "%{$this->cari}%"));

        if ($this->bulan !== '') {
            [$tahun, $bulan] = array_pad(explode('-', $this->bulan), 2, null);
            if ($tahun && $bulan) {
                $q->whereYear('tanggal_rencana', $tahun)->whereMonth('tanggal_rencana', $bulan);
            }
        }

        return [
            'daftar' => $q->orderBy('tanggal_rencana')->orderByDesc('id')->paginate(15),
            'proyekList' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']),
            'bankList' => Bank::orderBy('nama')->get(['id', 'nama']),
            'notarisList' => Notaris::orderBy('nama')->get(['id', 'nama']),
            'jumlahMenunggu' => RencanaAkad::query()->where('status', 'diajukan')->count(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-violet-700 text-white shadow-sm">
                    <flux:icon.check-badge class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Approval Rencana Akad') }}</flux:heading>
                        <x-info-button title="Approval Rencana Akad">
                            <p>Halaman ini khusus <strong>Project Manager</strong>: rencana akad yang sudah diajukan Admin KPR menunggu persetujuan kamu di sini.</p>
                            <p class="mt-2">Klik <strong>Ketahui</strong> untuk menyetujui rencana. TTD kamu otomatis muncul di kotak <em>Mengetahui</em> pada lembar Aju Dana.</p>
                            <p class="mt-2">Kalau ada yang perlu direvisi, buka <strong>Detail</strong> untuk mengembalikan ke Draft dengan alasan.</p>
                            <p class="mt-2 text-xs text-zinc-500">Setelah kamu Ketahui, sistem akan menyiapkan link persetujuan untuk Direksi.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>Rencana akad menunggu persetujuan Project Manager.</flux:subheading>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="rounded-lg border border-violet-200 bg-violet-50 px-4 py-2 dark:border-violet-900 dark:bg-violet-950/40">
                    <div class="text-xs font-medium text-violet-600 dark:text-violet-300">Menunggu Approval</div>
                    <div class="text-2xl font-bold text-violet-700 dark:text-violet-200">{{ $jumlahMenunggu }}</div>
                </div>
            </div>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <flux:input type="month" wire:model.live="bulan" label="Bulan" />
            </div>
            <div class="min-w-44">
                <flux:select wire:model.live="proyekId" label="Proyek">
                    @foreach ($proyekList as $p)
                        <option value="{{ $p->id }}">{{ $p->nama_proyek }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="min-w-44">
                <flux:select wire:model.live="bankId" label="Bank">
                    <option value="">Semua Bank</option>
                    @foreach ($bankList as $b)
                        <option value="{{ $b->id }}">{{ $b->nama }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="min-w-44">
                <flux:select wire:model.live="notarisId" label="Notaris">
                    <option value="">Semua Notaris</option>
                    @foreach ($notarisList as $n)
                        <option value="{{ $n->id }}">{{ $n->nama }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="min-w-52 flex-1">
                <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass"
                            label="Cari" placeholder="Nomor rencana..." />
            </div>
        </div>

        {{-- TABEL --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                            <th class="w-10 px-4 py-3 font-semibold">#</th>
                            <th class="px-4 py-3 font-semibold">Nomor</th>
                            <th class="px-4 py-3 font-semibold">Proyek</th>
                            <th class="px-4 py-3 font-semibold">Notaris</th>
                            <th class="px-4 py-3 font-semibold">Bank</th>
                            <th class="px-4 py-3 font-semibold">Tgl Rencana</th>
                            <th class="px-4 py-3 text-center font-semibold">Jml Unit</th>
                            <th class="px-4 py-3 font-semibold">Diajukan Oleh</th>
                            <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($daftar as $r)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-4 py-2.5 text-zinc-400">{{ $loop->iteration + ($daftar->currentPage() - 1) * $daftar->perPage() }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs">{{ $r->nomor }}</td>
                                <td class="px-4 py-2.5">{{ $r->proyek?->nama_proyek }}</td>
                                <td class="px-4 py-2.5">{{ $r->notaris?->nama ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $r->bank?->nama ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $r->tanggal_rencana?->translatedFormat('d/m/Y') }}</td>
                                <td class="px-4 py-2.5 text-center font-semibold">{{ $r->unit_count }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="text-sm">{{ $r->diajukanBy?->name ?? '—' }}</div>
                                    <div class="text-xs text-zinc-400">
                                        {{ $r->diajukan_at?->translatedFormat('d M Y H:i') }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <flux:button size="xs" variant="ghost"
                                                     :href="route('pemberkasan.rencana-akad.show', $r->id)" wire:navigate>
                                            Detail
                                        </flux:button>
                                        <flux:button size="xs" variant="danger" icon="x-mark"
                                                     wire:click="konfirmasiTolak({{ $r->id }})">
                                            Tolak
                                        </flux:button>
                                        <flux:button size="xs" variant="primary" icon="check"
                                                     wire:click="konfirmasiKetahui({{ $r->id }})">
                                            Ketahui
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12 text-center text-zinc-400">
                                    Tidak ada rencana akad yang menunggu persetujuan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($daftar->hasPages())
                <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    {{ $daftar->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- MODAL KONFIRMASI KETAHUI --}}
    <flux:modal name="konfirmasi-ketahui" @class(['max-w-lg'])>
        @php
            $rencanaKonfirmasi = $konfirmasiId
                ? \App\Models\Master\RencanaAkad::with(['proyek:id,nama_proyek', 'bank:id,nama', 'notaris:id,nama'])
                    ->withCount('unit')
                    ->find($konfirmasiId)
                : null;
        @endphp
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">
                    <flux:icon.check-badge class="size-5" />
                </div>
                <div>
                    <flux:heading size="lg">Ketahui Rencana Akad</flux:heading>
                    <flux:subheading>
                        Kamu setuju rencana ini bisa diteruskan ke Direksi untuk ditandatangani.
                        TTD kamu akan muncul di kotak <em>Mengetahui</em> pada lembar Aju Dana.
                    </flux:subheading>
                </div>
            </div>

            @if ($rencanaKonfirmasi)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <div class="text-xs text-zinc-500">Nomor</div>
                            <div class="font-mono font-semibold">{{ $rencanaKonfirmasi->nomor }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-zinc-500">Proyek</div>
                            <div class="font-semibold">{{ $rencanaKonfirmasi->proyek?->nama_proyek }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-zinc-500">Jml Unit</div>
                            <div class="font-semibold">{{ $rencanaKonfirmasi->unit_count }} unit</div>
                        </div>
                        <div>
                            <div class="text-xs text-zinc-500">Bank</div>
                            <div>{{ $rencanaKonfirmasi->bank?->nama ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-zinc-500">Notaris</div>
                            <div>{{ $rencanaKonfirmasi->notaris?->nama ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-zinc-500">Tgl Rencana</div>
                            <div>{{ $rencanaKonfirmasi->tanggal_rencana?->translatedFormat('d M Y') }}</div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" icon="check" wire:click="ketahui">
                    Ya, Ketahui Rencana
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL KONFIRMASI TOLAK --}}
    <flux:modal name="konfirmasi-tolak" @class(['max-w-lg'])>
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-950 dark:text-rose-300">
                    <flux:icon.x-mark class="size-5" />
                </div>
                <div>
                    <flux:heading size="lg">Kembalikan ke Draft</flux:heading>
                    <flux:subheading>
                        Rencana dikembalikan supaya Admin KPR bisa memperbaiki. Sebutkan
                        apa yang perlu dibetulkan.
                    </flux:subheading>
                </div>
            </div>

            <flux:textarea wire:model="alasanTolak" rows="4" label="Alasan"
                           placeholder="mis. tanggal akad bentrok dengan proyek lain, biaya notaris tidak sesuai..." />
            @error('alasanTolak')<div class="text-xs text-rose-600">{{ $message }}</div>@enderror

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="danger" icon="arrow-uturn-left" wire:click="tolak">
                    Kembalikan ke Draft
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
