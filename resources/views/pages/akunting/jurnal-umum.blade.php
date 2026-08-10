<?php

use App\Models\Akunting\Jurnal;
use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Services\JurnalService;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Jurnal Umum')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'from')]
    public string $filterFrom = '';

    #[Url(as: 'to')]
    public string $filterTo = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    #[Url(as: 'per')]
    public string $perPage = '20';

    // Form state
    public ?int $editId = null;

    public string $tanggal = '';

    public string $no_bukti = '';

    public string $kategori_bukti = 'KAS';

    public string $keterangan = '';

    /** @var array<int, array{coa_id:?int, debet:string, kredit:string}> */
    public array $detail = [];

    // View detail modal state
    public ?int $viewId = null;

    // Confirm modal state (untuk post/reverse/hapus)
    public ?string $confirmAction = null;

    public ?int $confirmJurnalId = null;

    public string $confirmJurnalLabel = '';

    public function mount(): void
    {
        $this->tanggal = now()->toDateString();
        $this->resetDetail();
    }

    protected function resetDetail(): void
    {
        $this->detail = [
            ['coa_id' => null, 'debet' => '', 'kredit' => ''],
            ['coa_id' => null, 'debet' => '', 'kredit' => ''],
        ];
    }

    /** Auto-generate no bukti berdasarkan kategori + tanggal. */
    protected function generateNoBukti(?string $tanggal = null, ?string $kategori = null): string
    {
        $perusahaanId = Perusahaan::query()->value('id');
        $kat = $kategori ?? $this->kategori_bukti ?? 'KAS';
        $tgl = $tanggal ?? $this->tanggal ?? now()->toDateString();

        return app(JurnalService::class)->generateNoBukti($perusahaanId, $kat, $tgl);
    }

    /** Refresh no_bukti otomatis saat kategori atau tanggal berubah (form create). */
    public function updatedKategoriBukti(): void
    {
        if (! $this->editId) {
            $this->no_bukti = $this->generateNoBukti();
        }
    }

    public function updatedTanggal(): void
    {
        if (! $this->editId) {
            $this->no_bukti = $this->generateNoBukti();
        }
    }

    public function updated($property): void
    {
        if (str_starts_with($property, 'filter') || $property === 'search' || $property === 'perPage') {
            $this->resetPage();
        }
    }

    public function tambahBaris(): void
    {
        $this->detail[] = ['coa_id' => null, 'debet' => '', 'kredit' => ''];
    }

    public function hapusBaris(int $index): void
    {
        if (count($this->detail) <= 2) {
            Flux::toast('Minimal 2 baris detail.', variant: 'warning');
            return;
        }
        unset($this->detail[$index]);
        $this->detail = array_values($this->detail);
    }

    public function getTotalDebetProperty(): float
    {
        return collect($this->detail)->sum(fn ($r) => (float) str_replace([',', '.'], ['.', ''], $r['debet'] ?: '0'));
    }

    public function getTotalKreditProperty(): float
    {
        return collect($this->detail)->sum(fn ($r) => (float) str_replace([',', '.'], ['.', ''], $r['kredit'] ?: '0'));
    }

    public function getSelisihProperty(): float
    {
        return $this->totalDebet - $this->totalKredit;
    }

    public function getIsBalancedProperty(): bool
    {
        return abs($this->selisih) < 0.01 && $this->totalDebet > 0;
    }

    public function openCreate(): void
    {
        $this->reset(['editId', 'keterangan']);
        $this->tanggal = now()->toDateString();
        $this->kategori_bukti = 'KAS';
        $this->no_bukti = $this->generateNoBukti($this->tanggal, $this->kategori_bukti);
        $this->resetDetail();
        $this->resetErrorBag();
        Flux::modal('form-jurnal')->show();
    }

    public function openView(int $id): void
    {
        $this->viewId = $id;
        Flux::modal('view-jurnal')->show();
    }

    /** Buka modal konfirmasi untuk post/reverse/hapus. */
    public function openConfirm(string $action, int $id, string $label): void
    {
        $this->confirmAction = $action;
        $this->confirmJurnalId = $id;
        $this->confirmJurnalLabel = $label;
        Flux::modal('confirm-jurnal')->show();
    }

    public function executeConfirm(): void
    {
        if (! $this->confirmAction || ! $this->confirmJurnalId) {
            return;
        }
        $id = $this->confirmJurnalId;
        $action = $this->confirmAction;

        // Reset state dulu biar modal close bersih
        $this->reset(['confirmAction', 'confirmJurnalId', 'confirmJurnalLabel']);
        Flux::modal('confirm-jurnal')->close();

        match ($action) {
            'post' => $this->postJurnal($id),
            'reverse' => $this->reverseJurnal($id),
            'hapus' => $this->hapusJurnal($id),
            default => null,
        };
    }

    public function openEdit(int $id): void
    {
        $j = Jurnal::with('detail')->findOrFail($id);
        if ($j->isPosted()) {
            Flux::toast('Jurnal sudah posted, tidak bisa diedit.', variant: 'warning');
            return;
        }
        $this->editId = $j->id;
        $this->tanggal = $j->tanggal->toDateString();
        $this->no_bukti = $j->no_bukti;
        $this->kategori_bukti = $j->kategori_bukti ?: 'KAS';
        $this->keterangan = (string) $j->keterangan;
        $this->detail = $j->detail->map(fn ($d) => [
            'coa_id' => $d->coa_id,
            'debet' => $d->debet > 0 ? number_format((float) $d->debet, 0, ',', '.') : '',
            'kredit' => $d->kredit > 0 ? number_format((float) $d->kredit, 0, ',', '.') : '',
        ])->toArray();
        $this->resetErrorBag();
        Flux::modal('form-jurnal')->show();
    }

    protected function parseNominal(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], trim($v));
    }

    public function simpan(bool $langsungPost = false): void
    {
        $this->validate([
            'tanggal' => 'required|date',
            'no_bukti' => 'required|string|max:50',
            'detail' => 'required|array|min:2',
            'detail.*.coa_id' => 'required|exists:coa,id',
        ], attributes: [
            'no_bukti' => 'No Bukti',
            'detail.*.coa_id' => 'Kode Akun',
        ]);

        $perusahaanId = Perusahaan::query()->value('id');
        if (! $perusahaanId) {
            Flux::toast('Master Perusahaan belum ada.', variant: 'danger');
            return;
        }

        // Cek unique no_bukti (per perusahaan)
        $exists = Jurnal::where('perusahaan_id', $perusahaanId)
            ->where('no_bukti', $this->no_bukti)
            ->when($this->editId, fn ($q) => $q->where('id', '!=', $this->editId))
            ->exists();
        if ($exists) {
            $this->addError('no_bukti', 'No Bukti sudah dipakai. Gunakan nomor lain.');
            return;
        }

        // Prep detail
        $details = collect($this->detail)->map(fn ($r) => [
            'coa_id' => (int) $r['coa_id'],
            'debet' => $this->parseNominal($r['debet']),
            'kredit' => $this->parseNominal($r['kredit']),
        ])->toArray();

        $svc = app(JurnalService::class);

        try {
            if ($this->editId) {
                $jurnal = Jurnal::findOrFail($this->editId);
                $jurnal->kategori_bukti = $this->kategori_bukti;
                $jurnal->save();
                $jurnal = $svc->update($jurnal, [
                    'tanggal' => $this->tanggal,
                    'no_bukti' => $this->no_bukti,
                    'keterangan' => $this->keterangan ?: null,
                ], $details);
            } else {
                $jurnal = $svc->create([
                    'perusahaan_id' => $perusahaanId,
                    'tanggal' => $this->tanggal,
                    'no_bukti' => $this->no_bukti,
                    'tipe' => 'umum',
                    'kategori_bukti' => $this->kategori_bukti,
                    'keterangan' => $this->keterangan ?: null,
                ], $details);
            }

            if ($langsungPost) {
                $svc->post($jurnal);
                Flux::toast('Jurnal berhasil disimpan & diposting.', variant: 'success');
            } else {
                Flux::toast('Jurnal berhasil disimpan sebagai draft.', variant: 'success');
            }

            Flux::modal('form-jurnal')->close();
            $this->reset(['editId', 'no_bukti', 'keterangan']);
            $this->resetDetail();
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $key => $msg) {
                $this->addError($key, is_array($msg) ? $msg[0] : $msg);
            }
        } catch (\Throwable $e) {
            Flux::toast('Gagal simpan: '.$e->getMessage(), variant: 'danger');
        }
    }

    public function simpanDanPost(): void
    {
        $this->simpan(langsungPost: true);
    }

    public function postJurnal(int $id): void
    {
        try {
            $j = Jurnal::findOrFail($id);
            app(JurnalService::class)->post($j);
            Flux::toast('Jurnal berhasil di-posting.', variant: 'success');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(collect($e->errors())->flatten()->first(), variant: 'danger');
        }
    }

    public function reverseJurnal(int $id, string $alasan = 'Pembalikan manual'): void
    {
        try {
            $j = Jurnal::findOrFail($id);
            app(JurnalService::class)->reverse($j, $alasan);
            Flux::toast('Jurnal berhasil di-reverse.', variant: 'success');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(collect($e->errors())->flatten()->first(), variant: 'danger');
        }
    }

    public function hapusJurnal(int $id): void
    {
        try {
            $j = Jurnal::findOrFail($id);
            app(JurnalService::class)->delete($j);
            Flux::toast('Jurnal draft berhasil dihapus.', variant: 'success');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(collect($e->errors())->flatten()->first(), variant: 'danger');
        }
    }

    public function with(): array
    {
        $q = Jurnal::query()
            ->where('tipe', 'umum')
            ->with(['detail', 'createdBy:id,name']);

        if ($this->search !== '') {
            $q->where(function ($qq) {
                $qq->where('no_bukti', 'like', "%{$this->search}%")
                    ->orWhere('keterangan', 'like', "%{$this->search}%");
            });
        }
        if ($this->filterFrom !== '') {
            $q->whereDate('tanggal', '>=', $this->filterFrom);
        }
        if ($this->filterTo !== '') {
            $q->whereDate('tanggal', '<=', $this->filterTo);
        }
        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        }

        // Summary stats — semua jurnal umum (tidak kena filter list)
        $totalUmum = Jurnal::where('tipe', 'umum')->count();
        $totalPosted = Jurnal::where('tipe', 'umum')->where('status', 'posted')->count();
        $totalDraft = Jurnal::where('tipe', 'umum')->where('status', 'draft')->count();
        $nilaiBulanIni = (float) \DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', 'jd.jurnal_id')
            ->where('j.tipe', 'umum')
            ->where('j.status', 'posted')
            ->whereBetween('j.tanggal', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('jd.debet');

        // View detail jurnal
        $viewJurnal = null;
        if ($this->viewId) {
            $viewJurnal = Jurnal::with(['detail.coa:id,kode,nama', 'createdBy:id,name', 'postedBy:id,name'])
                ->find($this->viewId);
        }

        return [
            'jurnalList' => $q->orderByDesc('tanggal')->orderByDesc('id')
                ->paginate((int) $this->perPage),
            'coaOptions' => Coa::query()
                ->where('is_aktif', true)
                ->where('is_header', false)
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
            'stats' => [
                'total' => $totalUmum,
                'posted' => $totalPosted,
                'draft' => $totalDraft,
                'nilai_bulan_ini' => $nilaiBulanIni,
            ],
            'viewJurnal' => $viewJurnal,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-amber-500 to-amber-700 text-white shadow-sm">
                    <flux:icon.calculator class="size-6" />
                </div>
                <div>
                    <flux:heading size="xl">{{ __('Jurnal Umum') }}</flux:heading>
                    <flux:subheading>{{ __('Input jurnal untuk semua jenis kode rekening.') }}</flux:subheading>
                </div>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreate">
                {{ __('Buat Jurnal Baru') }}
            </flux:button>
        </div>

        {{-- SUMMARY CARDS --}}
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Total Jurnal Umum</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($stats['total']) }}</div>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/30">
                <div class="text-xs font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Posted</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-200">{{ number_format($stats['posted']) }}</div>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/40 dark:bg-amber-950/30">
                <div class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-400">Draft</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-amber-900 dark:text-amber-200">{{ number_format($stats['draft']) }}</div>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/40 dark:bg-blue-950/30">
                <div class="text-xs font-medium uppercase tracking-wide text-blue-700 dark:text-blue-400">Nilai Bulan Ini</div>
                <div class="mt-1 font-mono text-lg font-bold tabular-nums text-blue-900 dark:text-blue-200">Rp {{ number_format($stats['nilai_bulan_ini'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="min-w-56 flex-1">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Cari no bukti / keterangan..." icon="magnifying-glass" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="filterFrom" label="Dari Tgl" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="filterTo" label="Sampai Tgl" />
            </div>
            <div>
                <flux:select wire:model.live="filterStatus" placeholder="Semua Status">
                    <flux:select.option value="">Semua Status</flux:select.option>
                    <flux:select.option value="draft">Draft</flux:select.option>
                    <flux:select.option value="posted">Posted</flux:select.option>
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="perPage">
                    <flux:select.option value="20">20 / halaman</flux:select.option>
                    <flux:select.option value="50">50 / halaman</flux:select.option>
                    <flux:select.option value="100">100 / halaman</flux:select.option>
                </flux:select>
            </div>
        </div>

        {{-- LIST --}}
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                        <th class="px-4 py-3 font-semibold">Tanggal</th>
                        <th class="px-4 py-3 font-semibold">No Bukti</th>
                        <th class="px-4 py-3 font-semibold">Keterangan</th>
                        <th class="px-4 py-3 text-right font-semibold">Total Debet</th>
                        <th class="px-4 py-3 text-right font-semibold">Total Kredit</th>
                        <th class="px-4 py-3 text-center font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold">Dibuat oleh</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($jurnalList as $j)
                        @php
                            $totalDebet = $j->detail->sum('debet');
                            $totalKredit = $j->detail->sum('kredit');
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs">
                                {{ $j->tanggal->format('d M Y') }}
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs font-semibold">
                                {{ $j->no_bukti }}
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="line-clamp-2">{{ $j->keterangan ?: '-' }}</span>
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-right font-mono tabular-nums">
                                {{ number_format((float) $totalDebet, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-right font-mono tabular-nums">
                                {{ number_format((float) $totalKredit, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                @if ($j->status === 'posted')
                                    <flux:badge color="emerald" size="sm">Posted</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm">Draft</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-xs text-zinc-600 dark:text-zinc-400">
                                {{ $j->createdBy?->name ?? '-' }}
                            </td>
                            <td class="px-4 py-2.5 whitespace-nowrap text-right">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" icon="ellipsis-horizontal" variant="ghost" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="openView({{ $j->id }})">Lihat Detail</flux:menu.item>
                                        @if ($j->isDraft())
                                            <flux:menu.item icon="pencil" wire:click="openEdit({{ $j->id }})">Edit</flux:menu.item>
                                            <flux:menu.item icon="check-circle" wire:click="openConfirm('post', {{ $j->id }}, '{{ $j->no_bukti }}')">
                                                Posting
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="openConfirm('hapus', {{ $j->id }}, '{{ $j->no_bukti }}')">
                                                Hapus
                                            </flux:menu.item>
                                        @else
                                            <flux:menu.item icon="arrow-uturn-left" wire:click="openConfirm('reverse', {{ $j->id }}, '{{ $j->no_bukti }}')">
                                                Reverse
                                            </flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-zinc-400 dark:text-zinc-500">
                                Belum ada jurnal umum. Klik <strong>Buat Jurnal Baru</strong> untuk mulai.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- PAGINATION --}}
        <div class="mt-4">
            {{ $jurnalList->links() }}
        </div>

    </div>

    {{-- MODAL FORM --}}
    <flux:modal name="form-jurnal" @class(['max-w-5xl'])>
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editId ? 'Edit Jurnal Umum' : 'Buat Jurnal Umum Baru' }}
                </flux:heading>
                <flux:subheading>
                    Total debet harus sama dengan total kredit sebelum jurnal bisa diposting.
                </flux:subheading>
            </div>

            {{-- Header form --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:select wire:model.live="kategori_bukti" label="Kategori Jurnal" required>
                    @foreach (\App\Models\Akunting\Jurnal::KATEGORI_BUKTI as $kode => $label)
                        <flux:select.option value="{{ $kode }}">{{ $kode }} — {{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model.live="tanggal" label="Tanggal" required />
                <flux:input wire:model="no_bukti" label="No Bukti" placeholder="auto-generate" required
                            description="Auto berdasarkan kategori + tanggal. Bisa di-override manual." />
                <div class="sm:col-span-3">
                    <flux:textarea wire:model="keterangan" label="Keterangan" rows="2"
                                   placeholder="Deskripsi transaksi (muncul di buku besar)" />
                </div>
            </div>

            {{-- Detail multi-row --}}
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <flux:heading size="sm">Detail Jurnal</flux:heading>
                    <flux:button size="xs" variant="ghost" icon="plus" wire:click="tambahBaris">Tambah Baris</flux:button>
                </div>

                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                                <th class="w-8 px-2 py-2"></th>
                                <th class="px-3 py-2 font-semibold">Kode Akun</th>
                                <th class="w-40 px-3 py-2 text-right font-semibold">Debet</th>
                                <th class="w-40 px-3 py-2 text-right font-semibold">Kredit</th>
                                <th class="w-10 px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($detail as $i => $row)
                                <tr>
                                    <td class="px-2 py-2 text-center text-xs text-zinc-400">{{ $i + 1 }}</td>
                                    <td class="px-3 py-1.5 min-w-56">
                                        <x-coa-picker
                                            :wire-property="'detail.'.$i.'.coa_id'"
                                            placeholder="Pilih akun..."
                                            :options="$coaOptions"
                                            compact
                                        />
                                        @error("detail.$i.coa_id")
                                            <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                                        @enderror
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <div x-data="rupiahInput()" x-init="init('detail.{{ $i }}.debet')">
                                            <input type="text" x-model="display" @input="onInput"
                                                   class="w-full rounded border border-zinc-300 bg-white px-2 py-1 text-right font-mono text-sm placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800"
                                                   placeholder="0" />
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <div x-data="rupiahInput()" x-init="init('detail.{{ $i }}.kredit')">
                                            <input type="text" x-model="display" @input="onInput"
                                                   class="w-full rounded border border-zinc-300 bg-white px-2 py-1 text-right font-mono text-sm placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800"
                                                   placeholder="0" />
                                        </div>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <flux:button size="xs" variant="ghost" icon="trash"
                                                     wire:click="hapusBaris({{ $i }})" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800">
                            <tr class="font-semibold">
                                <td colspan="2" class="px-3 py-2 text-right">TOTAL</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">
                                    {{ number_format($this->totalDebet, 0, ',', '.') }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">
                                    {{ number_format($this->totalKredit, 0, ',', '.') }}
                                </td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="2" class="px-3 py-2 text-right text-xs text-zinc-500">Selisih (Debet - Kredit)</td>
                                <td colspan="2" class="px-3 py-2 text-right font-mono tabular-nums font-semibold
                                                       {{ $this->isBalanced ? 'text-emerald-600' : 'text-rose-600' }}">
                                    @if ($this->isBalanced)
                                        ✓ Balance
                                    @else
                                        {{ number_format($this->selisih, 0, ',', '.') }}
                                    @endif
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @error('balance')<div class="mt-2 text-sm text-rose-600">{{ $message }}</div>@enderror
                @error('no_bukti')<div class="mt-2 text-sm text-rose-600">{{ $message }}</div>@enderror
                @error('jurnal')<div class="mt-2 text-sm text-rose-600">{{ $message }}</div>@enderror
            </div>

            {{-- Footer buttons --}}
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="filled" wire:click="simpan">Simpan Draft</flux:button>
                <flux:button variant="primary" wire:click="simpanDanPost" :disabled="!$this->isBalanced">
                    Simpan & Posting
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL VIEW DETAIL (read-only) --}}
    <flux:modal name="view-jurnal" @class(['max-w-4xl'])>
        @if ($viewJurnal)
            <div class="space-y-4">
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="lg">Detail Jurnal — {{ $viewJurnal->no_bukti }}</flux:heading>
                        <flux:subheading>
                            {{ $viewJurnal->tanggal->translatedFormat('d F Y') }} ·
                            @if ($viewJurnal->status === 'posted')
                                <span class="text-emerald-600">Posted</span>
                            @else
                                <span class="text-amber-600">Draft</span>
                            @endif
                        </flux:subheading>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 rounded-lg bg-zinc-50 p-4 text-sm sm:grid-cols-2 dark:bg-zinc-800/40">
                    <div>
                        <div class="text-xs text-zinc-500">Tanggal</div>
                        <div class="font-medium">{{ $viewJurnal->tanggal->translatedFormat('d F Y') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500">No Bukti</div>
                        <div class="font-mono font-medium">{{ $viewJurnal->no_bukti }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <div class="text-xs text-zinc-500">Keterangan</div>
                        <div>{{ $viewJurnal->keterangan ?: '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500">Dibuat oleh</div>
                        <div>{{ $viewJurnal->createdBy?->name ?? '—' }} · {{ $viewJurnal->created_at->translatedFormat('d M Y H:i') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500">Posted oleh</div>
                        <div>
                            @if ($viewJurnal->posted_at)
                                {{ $viewJurnal->postedBy?->name ?? '—' }} · {{ $viewJurnal->posted_at->translatedFormat('d M Y H:i') }}
                            @else
                                <span class="italic text-zinc-400">Belum diposting</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Detail table --}}
                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                                <th class="px-3 py-2 font-semibold">Kode Akun</th>
                                <th class="px-3 py-2 text-right font-semibold">Debet</th>
                                <th class="px-3 py-2 text-right font-semibold">Kredit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($viewJurnal->detail as $d)
                                <tr>
                                    <td class="px-3 py-2">
                                        <span class="font-mono text-xs text-zinc-500">{{ $d->coa->kode }}</span>
                                        <span class="ml-2">{{ $d->coa->nama }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">
                                        {{ $d->debet > 0 ? number_format((float) $d->debet, 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">
                                        {{ $d->kredit > 0 ? number_format((float) $d->kredit, 0, ',', '.') : '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 font-bold dark:border-zinc-600 dark:bg-zinc-800">
                            <tr>
                                <td class="px-3 py-2 text-right uppercase">TOTAL</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">
                                    {{ number_format((float) $viewJurnal->detail->sum('debet'), 0, ',', '.') }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">
                                    {{ number_format((float) $viewJurnal->detail->sum('kredit'), 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if ($viewJurnal->reversed_from_jurnal_id)
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm dark:border-blue-900/40 dark:bg-blue-950/30">
                        <flux:icon.information-circle class="inline size-4 text-blue-600" />
                        Jurnal ini adalah <strong>reversal</strong> dari jurnal ID #{{ $viewJurnal->reversed_from_jurnal_id }}.
                    </div>
                @endif

                <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:modal.close><flux:button variant="ghost">Tutup</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- MODAL KONFIRMASI (post/reverse/hapus) --}}
    <flux:modal name="confirm-jurnal" @class(['max-w-md'])>
        @php
            $confirmMap = [
                'post' => ['icon' => 'check-circle', 'color' => 'emerald',
                    'title' => 'Posting Jurnal', 'action_label' => 'Posting',
                    'desc' => 'Setelah diposting, jurnal ini akan muncul di Buku Besar & laporan. TIDAK bisa diedit lagi (kalau salah, harus dibuat jurnal reversal).'],
                'reverse' => ['icon' => 'arrow-uturn-left', 'color' => 'amber',
                    'title' => 'Reverse Jurnal', 'action_label' => 'Buat Reversal',
                    'desc' => 'Akan dibuat jurnal pembalik baru (debet↔kredit tertukar) sebagai koreksi. Jurnal asli tetap ada di sistem.'],
                'hapus' => ['icon' => 'trash', 'color' => 'rose',
                    'title' => 'Hapus Jurnal Draft', 'action_label' => 'Hapus',
                    'desc' => 'Jurnal draft ini akan dihapus permanen dari sistem. Aksi ini tidak bisa dibatalkan.'],
            ];
            $c = $confirmMap[$confirmAction ?? 'post'] ?? $confirmMap['post'];
        @endphp
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-{{ $c['color'] }}-100 text-{{ $c['color'] }}-600 dark:bg-{{ $c['color'] }}-950/40 dark:text-{{ $c['color'] }}-400">
                    <flux:icon name="{{ $c['icon'] }}" class="size-5" />
                </div>
                <div>
                    <flux:heading size="lg">{{ $c['title'] }}</flux:heading>
                    <flux:subheading>Jurnal: <span class="font-mono font-semibold">{{ $confirmJurnalLabel }}</span></flux:subheading>
                </div>
            </div>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $c['desc'] }}</p>
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="{{ $confirmAction === 'hapus' ? 'danger' : 'primary' }}" wire:click="executeConfirm">
                    {{ $c['action_label'] }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Alpine components --}}
    @script
    <script>
        // Format thousand separator input (rupiah)
        Alpine.data('rupiahInput', () => ({
            display: '',
            wireProp: null,
            init(prop) {
                this.wireProp = prop;
                // Load initial value dari wire
                const raw = this.$wire.get(prop);
                this.display = this.format(raw);
            },
            format(v) {
                if (v === null || v === undefined || v === '') return '';
                // Parse: strip semua kecuali digit
                const digits = String(v).replace(/[^\d]/g, '');
                if (! digits) return '';
                // Format dengan titik tiap 3 digit dari belakang
                return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            },
            onInput(e) {
                const digits = e.target.value.replace(/[^\d]/g, '');
                this.display = this.format(digits);
                this.$wire.set(this.wireProp, digits);
            },
        }));

    </script>
    @endscript
</section>
