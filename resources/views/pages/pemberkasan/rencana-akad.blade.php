<?php

use App\Models\Master\BankKpr;
use App\Models\Master\Notaris;
use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkad;
use App\Services\RencanaAkadService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Rencana Akad')] class extends Component
{
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = 'draft';

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

    // Form buat rencana baru
    public string $formTanggal = '';

    public ?int $formBankId = null;

    public ?int $formNotarisId = null;

    public string $formCatatan = '';

    public function mount(): void
    {
        if ($this->bulan === '') {
            $this->bulan = now()->format('Y-m');
        }
        $this->proyekId ??= Proyek::query()->value('id');
    }

    public function updated($property): void
    {
        if (in_array($property, ['tab', 'bulan', 'proyekId', 'bankId', 'notarisId', 'cari'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.kelola'), 403);

        $this->reset(['formBankId', 'formNotarisId', 'formCatatan']);
        $this->formTanggal = now()->toDateString();
        $this->resetErrorBag();
        Flux::modal('form-rencana')->show();
    }

    public function simpan(): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.kelola'), 403);

        $this->validate([
            'formTanggal' => ['required', 'date'],
            'formBankId' => ['required', 'exists:bank_kpr,id'],
            'formNotarisId' => ['required', 'exists:notaris,id'],
            'formCatatan' => ['nullable', 'string', 'max:500'],
            'proyekId' => ['required', 'exists:proyek,id'],
        ], [], [
            'formTanggal' => 'tanggal rencana akad',
            'formBankId' => 'bank',
            'formNotarisId' => 'notaris',
            'proyekId' => 'proyek',
        ]);

        $proyek = Proyek::findOrFail($this->proyekId);

        $rencana = RencanaAkad::create([
            'proyek_id' => $proyek->id,
            'nomor' => app(RencanaAkadService::class)->nomorBerikutnya($proyek, $this->formTanggal),
            'tanggal_rencana' => $this->formTanggal,
            'bank_kpr_id' => $this->formBankId,
            'notaris_id' => $this->formNotarisId,
            'catatan' => $this->formCatatan ?: null,
            'created_by_user_id' => Auth::id(),
        ]);

        Flux::modal('form-rencana')->close();

        $this->redirectRoute('pemberkasan.rencana-akad.show', $rencana->id, navigate: true);
    }

    public function with(): array
    {
        $q = RencanaAkad::query()
            ->with(['bank:id,nama', 'notaris:id,nama', 'proyek:id,nama_proyek'])
            ->withCount('unit')
            ->when($this->tab !== 'all', fn ($x) => $x->where('status', $this->tab))
            ->when($this->proyekId, fn ($x) => $x->where('proyek_id', $this->proyekId))
            ->when($this->bankId, fn ($x) => $x->where('bank_kpr_id', $this->bankId))
            ->when($this->notarisId, fn ($x) => $x->where('notaris_id', $this->notarisId))
            ->when($this->cari !== '', fn ($x) => $x->where('nomor', 'like', "%{$this->cari}%"));

        // Saring per bulan berdasarkan tanggal yang berlaku — kalau sudah fix, yang
        // dipakai tanggal dari bank, bukan usulan awal.
        if ($this->bulan !== '') {
            [$tahun, $bulan] = array_pad(explode('-', $this->bulan), 2, null);
            if ($tahun && $bulan) {
                $q->where(function ($x) use ($tahun, $bulan) {
                    $x->where(function ($a) use ($tahun, $bulan) {
                        $a->whereNotNull('tanggal_fix')
                            ->whereYear('tanggal_fix', $tahun)->whereMonth('tanggal_fix', $bulan);
                    })->orWhere(function ($a) use ($tahun, $bulan) {
                        $a->whereNull('tanggal_fix')
                            ->whereYear('tanggal_rencana', $tahun)->whereMonth('tanggal_rencana', $bulan);
                    });
                });
            }
        }

        $user = Auth::user();

        return [
            'daftar' => $q->orderByDesc('tanggal_rencana')->orderByDesc('id')->paginate(15),
            'proyekList' => Proyek::orderBy('nama_proyek')->get(['id', 'nama_proyek']),
            'bankList' => BankKpr::where('is_aktif', true)->orderBy('nama')->get(['id', 'nama']),
            'notarisList' => Notaris::orderBy('nama')->get(['id', 'nama']),
            'jumlahPerTab' => $this->jumlahPerTab(),
            'bolehKelola' => (bool) $user?->can('rencanaakad.kelola'),
        ];
    }

    /** @return array<string, int> */
    private function jumlahPerTab(): array
    {
        $dasar = RencanaAkad::query()
            ->when($this->proyekId, fn ($x) => $x->where('proyek_id', $this->proyekId));

        $hitung = (clone $dasar)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'draft' => (int) ($hitung['draft'] ?? 0),
            'diajukan' => (int) ($hitung['diajukan'] ?? 0),
            'diketahui' => (int) ($hitung['diketahui'] ?? 0),
            'fix' => (int) ($hitung['fix'] ?? 0),
            'all' => (int) $hitung->sum(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-orange-500 to-orange-700 text-white shadow-sm">
                    <flux:icon.building-library class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Rencana Akad') }}</flux:heading>
                        <x-info-button title="Rencana Akad">
                            <p>Satu Rencana Akad adalah satu sesi akad berjamaah: <strong>satu notaris, satu bank, satu tanggal</strong>, memuat beberapa unit sekaligus.</p>
                            <p>Alurnya empat tahap:</p>
                            <ol class="ml-4 mt-1 list-decimal space-y-1">
                                <li><strong>Draft</strong> — disusun, unit boleh keluar-masuk</li>
                                <li><strong>Diajukan</strong> — menunggu persetujuan Project Manager</li>
                                <li><strong>Diketahui</strong> — PM sudah setujui, tinggal Direksi tanda tangan via link</li>
                                <li><strong>Selesai</strong> — Direksi sudah setujui &amp; tanggal terkunci, unit tidak bisa diubah lagi</li>
                            </ol>
                            <p class="mt-2">Syarat unit boleh dijadwalkan: <strong>uang muka lunas 100%</strong> (realisasi UM + UTJ). Sertifikat boleh belum ada, titipan boleh nol.</p>
                            <p class="mt-2 text-xs text-zinc-500">Begitu status Selesai, tanggalnya otomatis mengisi kolom Rencana Akad di Pemberkasan — tidak perlu diketik dua kali.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Penjadwalan akad per bank & notaris.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:button variant="ghost" icon="squares-2x2"
                             :href="route('pemberkasan.rencana-akad.blok', ['bulan' => $bulan, 'proyek' => $proyekId])" wire:navigate>
                    {{ __('Berdasarkan Blok') }}
                </flux:button>
                @if ($bolehKelola)
                    <flux:button variant="primary" icon="plus" wire:click="openCreate">
                        {{ __('Buat Rencana Akad') }}
                    </flux:button>
                @endif
            </div>
        </div>

        {{-- TAB STATUS --}}
        @php
            $tabs = [
                'draft' => 'Draft',
                'diajukan' => 'Diajukan',
                'diketahui' => 'Diketahui',
                'fix' => 'Selesai',
                'all' => 'Semua',
            ];
        @endphp
        <div class="mb-4 border-b border-zinc-200 dark:border-zinc-700">
            <nav class="-mb-px flex gap-1 overflow-x-auto">
                @foreach ($tabs as $kunci => $label)
                    <button type="button" wire:click="$set('tab', '{{ $kunci }}')"
                            @class([
                                'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition',
                                'border-orange-600 text-orange-700 dark:border-orange-400 dark:text-orange-300' => $tab === $kunci,
                                'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400' => $tab !== $kunci,
                            ])>
                        {{ $label }}
                        <span class="ml-1 rounded-full bg-zinc-100 px-1.5 text-xs dark:bg-zinc-700">
                            {{ $jumlahPerTab[$kunci] ?? 0 }}
                        </span>
                    </button>
                @endforeach
            </nav>
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
                            <th class="px-4 py-3 font-semibold">Notaris</th>
                            <th class="px-4 py-3 font-semibold">Bank</th>
                            <th class="px-4 py-3 font-semibold">Tgl Rencana Akad</th>
                            <th class="px-4 py-3 text-center font-semibold">Jml Unit</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                            <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($daftar as $i => $r)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-4 py-2.5 text-xs text-zinc-400">
                                    {{ $daftar->firstItem() + $i }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs font-medium">
                                    {{ $r->nomor }}
                                </td>
                                <td class="px-4 py-2.5">{{ $r->notaris?->nama ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $r->bank?->nama ?? '—' }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    {{ $r->tanggalBerlaku()?->translatedFormat('d/m/Y') ?? '—' }}
                                    @if ($r->tanggal_fix && $r->tanggal_fix->ne($r->tanggal_rencana))
                                        {{-- Bank menggeser jadwal; usulan awal tetap ditampilkan --}}
                                        <div class="text-[11px] text-zinc-400">
                                            usulan {{ $r->tanggal_rencana->translatedFormat('d/m/Y') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-center font-semibold">
                                    {{ $r->unit_count }}
                                </td>
                                <td class="px-4 py-2.5">
                                    @php
                                        $warna = match ($r->status) {
                                            'draft' => 'amber', 'diajukan' => 'blue',
                                            'diketahui' => 'violet',
                                            'fix' => 'emerald', default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge :color="$warna" size="sm">{{ $r->labelStatus() }}</flux:badge>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <flux:dropdown align="end">
                                        <flux:button size="xs" variant="filled" icon:trailing="chevron-down">
                                            Aksi
                                        </flux:button>
                                        <flux:menu>
                                            <flux:menu.item icon="eye"
                                                            :href="route('pemberkasan.rencana-akad.show', $r->id)"
                                                            wire:navigate>
                                                Detail
                                            </flux:menu.item>
                                            @if ($r->unit_count > 0)
                                                <flux:menu.separator />
                                                <flux:menu.item icon="document-text"
                                                                :href="route('pemberkasan.rencana-akad.cetak.aju-dana', $r->id)"
                                                                target="_blank" rel="noopener">
                                                    Cetak Aju Dana
                                                </flux:menu.item>
                                                <flux:menu.item icon="table-cells"
                                                                :href="route('pemberkasan.rencana-akad.export.xlsx', $r->id)">
                                                    Export XLSX
                                                </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-zinc-400">
                                    Belum ada rencana akad di saringan ini.
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

    {{-- MODAL BUAT RENCANA --}}
    <flux:modal name="form-rencana" @class(['max-w-lg'])>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Buat Rencana Akad</flux:heading>
                <flux:subheading>Nomornya dibuat otomatis mengikuti bulan dan proyek.</flux:subheading>
            </div>

            @if ($notarisList->isEmpty())
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-300">
                    Master notaris masih kosong. Isi dulu di <strong>Master Data &rarr; Notaris</strong>
                    sebelum bisa membuat rencana akad.
                </div>
            @endif

            <flux:input type="date" wire:model="formTanggal" label="Tanggal Rencana Akad" />

            <flux:select wire:model="formBankId" label="Bank">
                <option value="">Pilih bank...</option>
                @foreach ($bankList as $b)
                    <option value="{{ $b->id }}">{{ $b->nama }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model="formNotarisId" label="Notaris">
                <option value="">Pilih notaris...</option>
                @foreach ($notarisList as $n)
                    <option value="{{ $n->id }}">{{ $n->nama }}</option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="formCatatan" label="Catatan (opsional)" rows="2" />

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpan" :disabled="$notarisList->isEmpty()">
                    Buat &amp; Lanjut Isi Unit
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
