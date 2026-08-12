<?php

use App\Models\Akunting\AktivaTetap;
use App\Models\Master\Perusahaan;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Aktiva Tetap')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kategori')]
    public string $filterKategori = '';

    #[Url(as: 'status')]
    public string $filterStatus = '';

    #[Url(as: 'per')]
    public string $perPage = '20';

    #[Url(as: 'from')]
    public string $filterFrom = '';

    #[Url(as: 'to')]
    public string $filterTo = '';

    // Form state
    public ?int $editId = null;

    public string $kode = '';

    public string $nama = '';

    public string $kategori = 'Inventaris Kantor';

    public string $tgl_perolehan = '';

    public string $harga_perolehan = '';

    public int $umur_ekonomis_bulan = 0;

    public string $metode_penyusutan = 'garis_lurus';

    public string $nilai_residu = '';

    public string $akumulasi_penyusutan = '';

    public string $lokasi = '';

    public string $keterangan = '';

    public string $status = 'aktif';

    // Confirm hapus modal state
    public ?int $confirmDeleteId = null;

    public string $confirmDeleteLabel = '';

    public function mount(): void
    {
        $this->tgl_perolehan = now()->toDateString();
        if ($this->filterFrom === '') {
            $this->filterFrom = now()->startOfYear()->toDateString();
        }
        if ($this->filterTo === '') {
            $this->filterTo = now()->toDateString();
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'filterKategori', 'filterStatus', 'perPage', 'filterFrom', 'filterTo'])) {
            $this->resetPage();
        }
    }

    protected function parseNominal(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], trim($v));
    }

    public function openCreate(): void
    {
        $this->reset(['editId', 'kode', 'nama', 'lokasi', 'keterangan', 'harga_perolehan', 'nilai_residu', 'akumulasi_penyusutan']);
        $this->kategori = 'Inventaris Kantor';
        $this->tgl_perolehan = now()->toDateString();
        $this->umur_ekonomis_bulan = 60; // default 5 tahun
        $this->metode_penyusutan = 'garis_lurus';
        $this->status = 'aktif';
        $this->resetErrorBag();
        Flux::modal('form-aktiva')->show();
    }

    public function openEdit(int $id): void
    {
        $a = AktivaTetap::findOrFail($id);
        $this->editId = $a->id;
        $this->kode = (string) $a->kode;
        $this->nama = $a->nama;
        $this->kategori = (string) ($a->kategori ?: 'Inventaris Kantor');
        $this->tgl_perolehan = $a->tgl_perolehan?->toDateString() ?? now()->toDateString();
        $this->harga_perolehan = number_format((float) $a->harga_perolehan, 0, ',', '.');
        $this->umur_ekonomis_bulan = (int) $a->umur_ekonomis_bulan;
        $this->metode_penyusutan = $a->metode_penyusutan;
        $this->nilai_residu = number_format((float) $a->nilai_residu, 0, ',', '.');
        $this->akumulasi_penyusutan = number_format((float) $a->akumulasi_penyusutan, 0, ',', '.');
        $this->lokasi = (string) $a->lokasi;
        $this->keterangan = (string) $a->keterangan;
        $this->status = $a->status;
        $this->resetErrorBag();
        Flux::modal('form-aktiva')->show();
    }

    public function simpan(): void
    {
        $this->validate([
            'nama' => 'required|string|max:255',
            'kategori' => 'required|string|max:50',
            'tgl_perolehan' => 'required|date',
            'harga_perolehan' => 'required',
            'metode_penyusutan' => 'required|in:garis_lurus,saldo_menurun,tidak_disusutkan',
            'status' => 'required|in:aktif,dijual,dihapus,rusak',
        ], attributes: [
            'harga_perolehan' => 'Harga Perolehan',
        ]);

        $perusahaanId = Perusahaan::query()->value('id');
        if (! $perusahaanId) {
            Flux::toast('Master Perusahaan belum ada.', variant: 'danger');
            return;
        }

        $data = [
            'perusahaan_id' => $perusahaanId,
            'kode' => $this->kode ?: null,
            'nama' => $this->nama,
            'kategori' => $this->kategori,
            'tgl_perolehan' => $this->tgl_perolehan,
            'harga_perolehan' => $this->parseNominal($this->harga_perolehan),
            'umur_ekonomis_bulan' => $this->umur_ekonomis_bulan,
            'metode_penyusutan' => $this->metode_penyusutan,
            'nilai_residu' => $this->parseNominal($this->nilai_residu),
            'akumulasi_penyusutan' => $this->parseNominal($this->akumulasi_penyusutan),
            'lokasi' => $this->lokasi ?: null,
            'keterangan' => $this->keterangan ?: null,
            'status' => $this->status,
            'created_by_user_id' => Auth::id(),
        ];

        if ($this->editId) {
            $a = AktivaTetap::findOrFail($this->editId);
            $a->update($data);
            Flux::toast('Aktiva tetap berhasil di-update.', variant: 'success');
        } else {
            AktivaTetap::create($data);
            Flux::toast('Aktiva tetap berhasil disimpan.', variant: 'success');
        }

        Flux::modal('form-aktiva')->close();
        $this->reset(['editId', 'kode', 'nama', 'lokasi', 'keterangan', 'harga_perolehan', 'nilai_residu', 'akumulasi_penyusutan']);
    }

    public function openConfirmDelete(int $id, string $label): void
    {
        $this->confirmDeleteId = $id;
        $this->confirmDeleteLabel = $label;
        Flux::modal('confirm-delete-aktiva')->show();
    }

    public function hapus(): void
    {
        if (! $this->confirmDeleteId) {
            return;
        }
        $a = AktivaTetap::findOrFail($this->confirmDeleteId);
        $a->delete();
        $this->reset(['confirmDeleteId', 'confirmDeleteLabel']);
        Flux::modal('confirm-delete-aktiva')->close();
        Flux::toast('Aktiva tetap dihapus.', variant: 'success');
    }

    public function with(): array
    {
        $q = AktivaTetap::query();
        if ($this->search !== '') {
            $q->where(function ($qq) {
                $qq->where('kode', 'like', "%{$this->search}%")
                    ->orWhere('nama', 'like', "%{$this->search}%")
                    ->orWhere('lokasi', 'like', "%{$this->search}%");
            });
        }
        if ($this->filterKategori !== '') {
            $q->where('kategori', $this->filterKategori);
        }
        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        }

        $stats = [
            'total' => AktivaTetap::count(),
            'aktif' => AktivaTetap::where('status', 'aktif')->count(),
            'nilai_perolehan' => (float) AktivaTetap::sum('harga_perolehan'),
            'nilai_buku' => (float) AktivaTetap::sum('harga_perolehan') - (float) AktivaTetap::sum('akumulasi_penyusutan'),
        ];

        // Kategori extra dari DB yg belum ada di enum (biar filter jalan utk data imported)
        $enumKategori = AktivaTetap::KATEGORI;
        $dbKategori = AktivaTetap::query()->whereNotNull('kategori')->distinct()->pluck('kategori')->toArray();
        $extraKategori = array_values(array_diff($dbKategori, $enumKategori));

        return [
            'aktivaList' => $q->orderBy('kategori')->orderBy('tgl_perolehan')->paginate((int) $this->perPage),
            'stats' => $stats,
            'extraKategori' => $extraKategori,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br from-slate-500 to-slate-700 text-white shadow-sm">
                    <flux:icon.building-office class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="xl">{{ __('Aktiva Tetap') }}</flux:heading>
                        <x-info-button title="Aktiva Tetap">
                            <p>Master aset umur panjang perusahaan (kendaraan, inventaris kantor, bangunan) — beda dgn persediaan rumah karena BUKAN untuk dijual, tapi dipakai operasional.</p>
                            <p class="mt-2">Kolom penting di tabel:</p>
                            <ul class="ml-4 mt-1 list-disc space-y-1">
                                <li><strong>Harga Perolehan</strong> — harga beli asli</li>
                                <li><strong>Akumulasi</strong> — total penyusutan sejak beli sampai sekarang</li>
                                <li><strong>Nilai Buku</strong> = Harga Perolehan − Akumulasi (angka yg muncul di Neraca)</li>
                            </ul>
                            <p class="mt-2">Rumus penyusutan per bulan (garis lurus):</p>
                            <p class="ml-4 mt-1 font-mono text-xs text-center bg-zinc-50 dark:bg-zinc-800 py-2 rounded">(Harga Perolehan − Nilai Residu) ÷ Umur Ekonomis (bulan)</p>
                            <p class="mt-2">Cara pakai: klik <strong>Tambah Aktiva</strong> untuk input baru, atau <strong>Cetak PDF/Excel</strong> untuk laporan bulanan. Filter kategori/status/periode di atas tabel.</p>
                            <p class="mt-2 text-xs text-zinc-500">Contoh: mobil Rush 300jt, umur 8 th (96 bln), residu 0 → penyusutan/bulan 3.125.000. Tiap akhir bulan Finance input jurnal AKM: Debet Beban Penyusutan, Kredit Akum Penyusutan.</p>
                        </x-info-button>
                    </div>
                    <flux:subheading>{{ __('Aset berwujud umur > 1 tahun. Nilai menyusut per bulan sesuai umur ekonomis.') }}</flux:subheading>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:button
                    variant="ghost"
                    icon="document-arrow-down"
                    :href="route('akunting.aktiva-tetap.excel', array_filter(['from' => $filterFrom, 'to' => $filterTo, 'kategori' => $filterKategori, 'status' => $filterStatus]))">
                    {{ __('Excel') }}
                </flux:button>
                <flux:button
                    variant="ghost"
                    icon="printer"
                    :href="route('akunting.aktiva-tetap.print', array_filter(['from' => $filterFrom, 'to' => $filterTo, 'kategori' => $filterKategori, 'status' => $filterStatus]))"
                    target="_blank">
                    {{ __('Cetak PDF') }}
                </flux:button>
                @can('aktivatetap.kelola')
                    <flux:button variant="primary" icon="plus" wire:click="openCreate">
                        {{ __('Tambah Aktiva') }}
                    </flux:button>
                @endcan
            </div>
        </div>

        {{-- SUMMARY CARDS --}}
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-xs uppercase tracking-wide text-zinc-500">Total Aktiva</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ $stats['total'] }}</div>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/30">
                <div class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Aktif</div>
                <div class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-900 dark:text-emerald-200">{{ $stats['aktif'] }}</div>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/40 dark:bg-blue-950/30">
                <div class="text-xs uppercase tracking-wide text-blue-700 dark:text-blue-400">Nilai Perolehan</div>
                <div class="mt-1 font-mono text-lg font-bold tabular-nums text-blue-900 dark:text-blue-200">Rp {{ number_format($stats['nilai_perolehan'], 0, ',', '.') }}</div>
            </div>
            <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-900/40 dark:bg-indigo-950/30">
                <div class="text-xs uppercase tracking-wide text-indigo-700 dark:text-indigo-400">Nilai Buku</div>
                <div class="mt-1 font-mono text-lg font-bold tabular-nums text-indigo-900 dark:text-indigo-200">Rp {{ number_format($stats['nilai_buku'], 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- FILTER --}}
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="min-w-56 flex-1">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Cari kode / nama / lokasi..." icon="magnifying-glass" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="filterFrom" label="Dari" size="sm" />
            </div>
            <div>
                <flux:input type="date" wire:model.live="filterTo" label="s/d" size="sm" />
            </div>
            <div>
                <flux:select wire:model.live="filterKategori" placeholder="Semua Kategori">
                    <flux:select.option value="">Semua Kategori</flux:select.option>
                    @foreach (\App\Models\Akunting\AktivaTetap::KATEGORI as $k)
                        <flux:select.option value="{{ $k }}">{{ $k }}</flux:select.option>
                    @endforeach
                    @foreach ($extraKategori as $k)
                        <flux:select.option value="{{ $k }}">{{ $k }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="filterStatus" placeholder="Semua Status">
                    <flux:select.option value="">Semua Status</flux:select.option>
                    @foreach (\App\Models\Akunting\AktivaTetap::STATUS as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500">
                            <th class="px-4 py-3 font-semibold">Kode</th>
                            <th class="px-4 py-3 font-semibold">Nama</th>
                            <th class="px-4 py-3 font-semibold">Kategori</th>
                            <th class="px-4 py-3 font-semibold">Tgl Perolehan</th>
                            <th class="px-4 py-3 text-right font-semibold">Harga Perolehan</th>
                            <th class="px-4 py-3 text-right font-semibold">Akumulasi</th>
                            <th class="px-4 py-3 text-right font-semibold">Nilai Buku</th>
                            <th class="px-4 py-3 text-center font-semibold">Status</th>
                            <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($aktivaList as $a)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs">{{ $a->kode ?: '-' }}</td>
                                <td class="px-4 py-2.5 font-medium">{{ $a->nama }}</td>
                                <td class="px-4 py-2.5 text-xs text-zinc-600">{{ $a->kategori ?: '-' }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-xs">{{ $a->tgl_perolehan?->format('d M Y') ?? '-' }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right font-mono tabular-nums">{{ number_format((float) $a->harga_perolehan, 0, ',', '.') }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right font-mono tabular-nums text-zinc-500">{{ number_format((float) $a->akumulasi_penyusutan, 0, ',', '.') }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right font-mono tabular-nums font-semibold">{{ number_format($a->nilai_buku, 0, ',', '.') }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    @php
                                        $badgeColor = match ($a->status) {
                                            'aktif' => 'emerald',
                                            'dijual' => 'blue',
                                            'dihapus' => 'zinc',
                                            'rusak' => 'rose',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge color="{{ $badgeColor }}" size="sm">{{ \App\Models\Akunting\AktivaTetap::STATUS[$a->status] ?? $a->status }}</flux:badge>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right">
                                    @can('aktivatetap.kelola')
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button size="xs" icon="ellipsis-horizontal" variant="ghost" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil" wire:click="openEdit({{ $a->id }})">Edit</flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item icon="trash" variant="danger" wire:click="openConfirmDelete({{ $a->id }}, '{{ addslashes($a->nama) }}')">Hapus</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12 text-center text-zinc-400">
                                    Belum ada aktiva tetap. Klik <strong>Tambah Aktiva</strong> untuk mulai.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                {{ $aktivaList->links() }}
            </div>
        </div>
    </div>

    {{-- MODAL FORM --}}
    <flux:modal name="form-aktiva" @class(['max-w-3xl'])>
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editId ? 'Edit Aktiva Tetap' : 'Tambah Aktiva Tetap' }}</flux:heading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="kode" label="Kode" placeholder="mis. INV-001 (opsional, auto)" />
                <flux:select wire:model="kategori" label="Kategori" required>
                    @foreach (\App\Models\Akunting\AktivaTetap::KATEGORI as $k)
                        <flux:select.option value="{{ $k }}">{{ $k }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="sm:col-span-2">
                    <flux:input wire:model="nama" label="Nama Aktiva" placeholder="mis. Honda Vario B 5516 TRQ" required />
                </div>
                <flux:input type="date" wire:model="tgl_perolehan" label="Tgl Perolehan" required />
                <flux:input wire:model="lokasi" label="Lokasi" placeholder="mis. Kantor Pusat" />

                <div>
                    <label class="mb-1 block text-sm font-medium">Harga Perolehan <span class="text-red-500">*</span></label>
                    <div x-data="rupiahInputSimple($wire.get('harga_perolehan'))">
                        <input type="text" x-model="display" @input="$wire.set('harga_perolehan', format(display))"
                               class="w-full rounded border border-zinc-300 bg-white px-3 py-1.5 text-right font-mono focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800" placeholder="0" />
                    </div>
                    @error('harga_perolehan') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Nilai Residu</label>
                    <div x-data="rupiahInputSimple($wire.get('nilai_residu'))">
                        <input type="text" x-model="display" @input="$wire.set('nilai_residu', format(display))"
                               class="w-full rounded border border-zinc-300 bg-white px-3 py-1.5 text-right font-mono focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800" placeholder="0" />
                    </div>
                </div>

                <flux:select wire:model="metode_penyusutan" label="Metode Penyusutan" required>
                    @foreach (\App\Models\Akunting\AktivaTetap::METODE as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="number" wire:model="umur_ekonomis_bulan" label="Umur Ekonomis (bulan)" min="0" />

                <div>
                    <label class="mb-1 block text-sm font-medium">Akumulasi Penyusutan</label>
                    <div x-data="rupiahInputSimple($wire.get('akumulasi_penyusutan'))">
                        <input type="text" x-model="display" @input="$wire.set('akumulasi_penyusutan', format(display))"
                               class="w-full rounded border border-zinc-300 bg-white px-3 py-1.5 text-right font-mono focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800" placeholder="0" />
                    </div>
                </div>
                <flux:select wire:model="status" label="Status" required>
                    @foreach (\App\Models\Akunting\AktivaTetap::STATUS as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="keterangan" label="Keterangan" rows="2" />
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpan">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL CONFIRM DELETE --}}
    <flux:modal name="confirm-delete-aktiva" @class(['max-w-md'])>
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400">
                    <flux:icon.trash class="size-5" />
                </div>
                <div>
                    <flux:heading size="lg">Hapus Aktiva Tetap</flux:heading>
                    <flux:subheading>{{ $confirmDeleteLabel }}</flux:subheading>
                </div>
            </div>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Aktiva ini akan dihapus permanen. Aksi tidak bisa dibatalkan.
            </p>
            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="hapus">Hapus</flux:button>
            </div>
        </div>
    </flux:modal>

    @script
    <script>
        Alpine.data('rupiahInputSimple', (initial) => ({
            display: initial ? String(initial).replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '',
            format(v) {
                const digits = String(v).replace(/[^\d]/g, '');
                if (! digits) return '';
                this.display = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                return digits;
            },
        }));
    </script>
    @endscript
</section>
