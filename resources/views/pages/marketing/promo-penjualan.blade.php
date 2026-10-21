<?php

use App\Models\Master\PromoPenjualan;
use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Services\PromoPenjualanService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Promo Penjualan — dikelola Admin Sales, seluruhnya ditanggung developer.
 *
 * Proyek diambil dari picker di sidebar, seragam dengan Pemberkasan dan
 * Perizinan. Promo yang sudah dipakai SPR tidak bisa diubah jenis, tanggal,
 * maupun nominalnya — aturannya dijaga PromoPenjualanService.
 */
new #[Title('Promo Penjualan')] class extends Component
{
    #[Url(as: 'proyek')]
    public ?int $proyekId = null;

    // ── Form ───────────────────────────────────────────
    public ?int $editId = null;

    public string $nama = '';

    public string $jenis = 'um';

    public string $mulai = '';

    public string $sampai = '';

    public string $potonganUm = '';

    public string $bookingFeeTetap = '';

    public string $dasarUsia = 'usia';

    /** @var list<array{dari: string, sampai: string, booking_fee: string, label: string}> */
    public array $tingkat = [];

    public bool $semuaUnit = true;

    /** @var array<int, bool> rumah_id => terpilih */
    public array $unit = [];

    public string $cariUnit = '';

    public string $keterangan = '';

    // Isi cepat tabel usia, mis. 23–40 × Rp 10.000.
    public string $isiDari = '23';

    public string $isiSampai = '40';

    public string $isiKelipatan = '10.000';

    // ── Rincian & hapus ────────────────────────────────
    public ?int $rincianId = null;

    public ?int $hapusId = null;

    public function mount(): void
    {
        $this->proyekId = \App\Support\ProyekAktif::sinkron($this->proyekId);
    }

    #[On('active-proyek-changed')]
    public function syncProyek(int $proyekId): void
    {
        $this->proyekId = $proyekId;
    }

    private function svc(): PromoPenjualanService
    {
        return app(PromoPenjualanService::class);
    }

    private static function angka(string $teks): float
    {
        return (float) preg_replace('/\D/', '', $teks);
    }

    public function updatedPotonganUm(): void
    {
        $a = self::angka($this->potonganUm);
        $this->potonganUm = $a > 0 ? number_format($a, 0, ',', '.') : '';
    }

    public function buat(): void
    {
        abort_unless($this->proyekId, 403);

        $this->resetForm();
        $this->mulai = now()->toDateString();
        $this->sampai = now()->toDateString();
        Flux::modal('promo-form')->show();
    }

    public function ubah(int $id): void
    {
        $p = PromoPenjualan::with(['tingkat', 'rumah:id'])->where('proyek_id', $this->proyekId)->findOrFail($id);

        $this->resetForm();
        $this->editId = $p->id;
        $this->nama = $p->nama;
        $this->jenis = $p->jenis;
        $this->mulai = $p->berlaku_mulai->toDateString();
        $this->sampai = $p->berlaku_sampai->toDateString();
        $this->potonganUm = $p->potongan_um ? number_format((float) $p->potongan_um, 0, ',', '.') : '';
        $this->bookingFeeTetap = $p->booking_fee_tetap ? number_format((float) $p->booking_fee_tetap, 0, ',', '.') : '';
        $this->dasarUsia = $p->dasar_usia ?? 'usia';
        $this->tingkat = $p->tingkat->map(fn ($t) => [
            'dari' => (string) $t->dari,
            'sampai' => (string) $t->sampai,
            'booking_fee' => number_format((float) $t->booking_fee, 0, ',', '.'),
            'label' => (string) $t->label,
        ])->all();
        $this->semuaUnit = $p->semua_unit;
        $this->unit = $p->rumah->mapWithKeys(fn ($r) => [$r->id => true])->all();
        $this->keterangan = (string) $p->keterangan;

        Flux::modal('promo-form')->show();
    }

    public function tambahTingkat(): void
    {
        $this->tingkat[] = ['dari' => '', 'sampai' => '', 'booking_fee' => '', 'label' => ''];
    }

    public function hapusTingkat(int $i): void
    {
        unset($this->tingkat[$i]);
        $this->tingkat = array_values($this->tingkat);
    }

    /** Usia 23–40 × 10.000 → 18 baris: usia 23 = 230.000, dst. */
    public function isiCepat(): void
    {
        $dari = (int) $this->isiDari;
        $sampai = (int) $this->isiSampai;
        $kali = self::angka($this->isiKelipatan);

        if ($dari <= 0 || $sampai < $dari || $kali <= 0 || $sampai - $dari > 60) {
            Flux::toast(variant: 'warning', text: 'Isi rentang usia dan kelipatannya dengan benar.');

            return;
        }

        $this->dasarUsia = 'usia';
        $this->tingkat = array_map(fn ($u) => [
            'dari' => (string) $u,
            'sampai' => (string) $u,
            'booking_fee' => number_format($u * $kali, 0, ',', '.'),
            'label' => '',
        ], range($dari, $sampai));
    }

    public function pilihSemuaTerlihat(): void
    {
        foreach ($this->daftarUnit() as $r) {
            $this->unit[$r->id] = true;
        }
    }

    public function simpan(): void
    {
        $this->validate([
            'nama' => ['required', 'string', 'max:150'],
            'jenis' => ['required', 'in:'.implode(',', array_keys(PromoPenjualan::JENIS))],
            'mulai' => ['required', 'date'],
            'sampai' => ['required', 'date'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ], [], ['mulai' => 'tanggal mulai UTJ', 'sampai' => 'tanggal selesai UTJ']);

        $tingkat = collect($this->tingkat)
            ->filter(fn ($t) => $t['dari'] !== '' || $t['sampai'] !== '' || $t['booking_fee'] !== '')
            ->map(fn ($t) => [
                'dari' => (int) $t['dari'],
                'sampai' => (int) $t['sampai'],
                'booking_fee' => self::angka($t['booking_fee']),
                'label' => trim($t['label']) ?: null,
            ])->values()->all();

        $promo = $this->editId ? PromoPenjualan::where('proyek_id', $this->proyekId)->findOrFail($this->editId) : null;

        try {
            $this->svc()->simpan([
                'proyek_id' => $this->proyekId,
                'nama' => trim($this->nama),
                'jenis' => $this->jenis,
                'berlaku_mulai' => $this->mulai,
                'berlaku_sampai' => $this->sampai,
                'potongan_um' => self::angka($this->potonganUm),
                'booking_fee_tetap' => self::angka($this->bookingFeeTetap),
                'dasar_usia' => $this->dasarUsia,
                'semua_unit' => $this->semuaUnit,
                'keterangan' => trim($this->keterangan) ?: null,
                'is_aktif' => $promo?->is_aktif ?? true,
            ], array_keys(array_filter($this->unit)), $tingkat, Auth::id(), $promo);
        } catch (ValidationException $e) {
            // Galat service dipetakan ke nama isian di form ini.
            $peta = ['berlaku_mulai' => 'mulai', 'berlaku_sampai' => 'sampai', 'potongan_um' => 'potonganUm', 'booking_fee_tetap' => 'bookingFeeTetap', 'rumah' => 'unit'];
            foreach ($e->errors() as $kunci => $pesan) {
                $this->addError($peta[$kunci] ?? $kunci, $pesan[0]);
            }

            return;
        }

        Flux::modal('promo-form')->close();
        Flux::toast(variant: 'success', text: $promo ? 'Promo diperbarui.' : 'Promo ditambahkan.');
        $this->resetForm();
    }

    public function alihAktif(int $id): void
    {
        $p = PromoPenjualan::where('proyek_id', $this->proyekId)->findOrFail($id);

        // Mengaktifkan kembali harus lolos pemeriksaan bentrok seperti promo baru.
        if (! $p->is_aktif && $tabrak = $this->svc()->bentrok($p->proyek_id, $p->berlaku_mulai->toDateString(), $p->berlaku_sampai->toDateString(), $p->id)) {
            Flux::toast(variant: 'warning', text: 'Tidak bisa diaktifkan: bertabrakan dengan '.$tabrak->nama.'.');

            return;
        }

        $p->update(['is_aktif' => ! $p->is_aktif, 'updated_by_user_id' => Auth::id()]);
        Flux::toast(variant: 'success', text: $p->is_aktif ? 'Promo diaktifkan.' : 'Promo dinonaktifkan.');
    }

    public function konfirmasiHapus(int $id): void
    {
        $this->hapusId = $id;
        Flux::modal('promo-hapus')->show();
    }

    public function hapus(): void
    {
        $p = PromoPenjualan::withCount('spr')->where('proyek_id', $this->proyekId)->findOrFail($this->hapusId);

        if ($p->spr_count > 0) {
            Flux::modal('promo-hapus')->close();
            Flux::toast(variant: 'warning', text: 'Promo ini sudah dipakai '.$p->spr_count.' SPR. Nonaktifkan saja supaya rekapnya tetap utuh.');

            return;
        }

        $p->delete();
        $this->hapusId = null;
        Flux::modal('promo-hapus')->close();
        Flux::toast(variant: 'success', text: 'Promo dihapus.');
    }

    public function lihatRincian(int $id): void
    {
        $this->rincianId = $id;
        Flux::modal('promo-rincian')->show();
    }

    private function resetForm(): void
    {
        $this->reset(['editId', 'nama', 'potonganUm', 'bookingFeeTetap', 'tingkat', 'unit', 'cariUnit', 'keterangan']);
        $this->jenis = 'um';
        $this->dasarUsia = 'usia';
        $this->semuaUnit = true;
        $this->resetErrorBag();
    }

    private function daftarUnit()
    {
        return Rumah::where('proyek_id', $this->proyekId)
            ->when($this->cariUnit !== '', fn ($q) => $q->where('blok', 'like', '%'.strtoupper(trim($this->cariUnit)).'%'))
            ->orderBy('blok')->orderByRaw('LENGTH(nomor_unit)')->orderBy('nomor_unit')
            ->get(['id', 'blok', 'nomor_unit', 'status']);
    }

    public function with(): array
    {
        $promo = $this->proyekId
            ? PromoPenjualan::where('proyek_id', $this->proyekId)
                ->withCount(['spr', 'rumah'])
                ->withSum('spr as total_utj', 'promo_potongan_utj')
                ->withSum('spr as total_um', 'promo_potongan_um')
                ->with('tingkat')
                ->orderByDesc('berlaku_mulai')
                ->get()
            : collect();

        $rincian = $this->rincianId
            ? PromoPenjualan::with(['spr' => fn ($q) => $q->with(['rumah:id,blok,nomor_unit', 'prospectCustomer:id,nama_lengkap'])->orderBy('tanggal_spr')])
                ->find($this->rincianId)
            : null;

        return [
            'proyek' => $this->proyekId ? Proyek::find($this->proyekId) : null,
            'promo' => $promo,
            'daftarUnit' => $this->proyekId && ! $this->semuaUnit ? $this->daftarUnit() : collect(),
            'jumlahTerpilih' => count(array_filter($this->unit)),
            'rincian' => $rincian,
            'promoTerpakai' => $this->editId ? PromoPenjualan::find($this->editId)?->spr()->exists() : false,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Promo Penjualan') }}</flux:heading>
                <flux:subheading>
                    {{ __('Promo ditanggung developer. Angka di SPR tidak berubah — yang ditampilkan potongannya dan berapa yang dibayar konsumen.') }}
                </flux:subheading>
            </div>
            @if ($proyekId)
                <flux:button variant="primary" icon="plus" wire:click="buat" class="self-start sm:self-auto">
                    {{ __('Promo Baru') }}
                </flux:button>
            @endif
        </div>

        @if (! $proyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                {{ __('Pilih proyek dulu di picker "Proyek Aktif" pada sidebar kiri.') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Promo') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Masa berlaku UTJ') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Unit') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Potongan') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Dipakai') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Status') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($promo as $p)
                                @php
                                    $warna = match ($p->labelStatus()) {
                                        'Berjalan' => 'emerald',
                                        'Akan datang' => 'sky',
                                        'Berakhir' => 'zinc',
                                        default => 'rose',
                                    };
                                    $ditanggung = (float) $p->total_utj + (float) $p->total_um;
                                @endphp
                                <tr wire:key="promo-{{ $p->id }}">
                                    <td class="px-3 py-2">
                                        <div class="font-semibold">{{ $p->nama }}</div>
                                        <div class="text-xs text-zinc-500">{{ $p->labelJenis() }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                                        {{ $p->berlaku_mulai->translatedFormat('d M Y') }} – {{ $p->berlaku_sampai->translatedFormat('d M Y') }}
                                    </td>
                                    <td class="px-3 py-2">{{ $p->semua_unit ? __('Semua unit') : $p->rumah_count.' '.__('unit') }}</td>
                                    <td class="px-3 py-2">
                                        @if ($p->jenis === 'um')
                                            <span class="font-mono tabular-nums">UM − Rp {{ number_format((float) $p->potongan_um, 0, ',', '.') }}</span>
                                        @elseif ($p->jenis === 'booking_fee_tetap')
                                            <span class="text-xs">
                                                {{ __('Booking fee') }}
                                                <span class="font-mono tabular-nums">Rp {{ number_format((float) $p->booking_fee_tetap, 0, ',', '.') }}</span>
                                                <span class="text-zinc-500">({{ __('semua konsumen') }})</span>
                                            </span>
                                        @else
                                            <span class="text-xs">
                                                {{ __('Booking fee') }}
                                                Rp {{ number_format((float) $p->tingkat->min('booking_fee'), 0, ',', '.') }}
                                                – {{ number_format((float) $p->tingkat->max('booking_fee'), 0, ',', '.') }}
                                                <span class="text-zinc-500">({{ $p->tingkat->count() }} {{ __('tingkat') }}, {{ $p->dasar_usia === 'tahun_lahir' ? __('tahun lahir') : __('usia') }})</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        @if ($p->spr_count > 0)
                                            <button type="button" wire:click="lihatRincian({{ $p->id }})" class="text-left text-blue-600 hover:underline dark:text-blue-400">
                                                {{ $p->spr_count }} SPR
                                                <span class="block font-mono text-xs tabular-nums text-zinc-500">Rp {{ number_format($ditanggung, 0, ',', '.') }}</span>
                                            </button>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2"><flux:badge :color="$warna" size="sm">{{ $p->labelStatus() }}</flux:badge></td>
                                    <td class="px-3 py-2 text-right">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil-square" wire:click="ubah({{ $p->id }})">{{ __('Ubah') }}</flux:menu.item>
                                                <flux:menu.item icon="{{ $p->is_aktif ? 'pause-circle' : 'play-circle' }}" wire:click="alihAktif({{ $p->id }})">
                                                    {{ $p->is_aktif ? __('Nonaktifkan') : __('Aktifkan') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="konfirmasiHapus({{ $p->id }})">{{ __('Hapus') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-3 py-10 text-center text-sm italic text-zinc-400">
                                        {{ __('Belum ada promo untuk proyek ini.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-3 text-xs text-zinc-500">
                {{ __('Dalam satu proyek promo tidak boleh berjalan bersamaan, dan satu SPR hanya mendapat satu promo. Promo dilampirkan otomatis saat SPR dibuat, berdasarkan tanggal UTJ.') }}
            </p>
        @endif

        {{-- FORM --}}
        <flux:modal name="promo-form" class="md:w-3xl" focusable>
            <form wire:submit="simpan" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $editId ? __('Ubah Promo') : __('Promo Baru') }} · {{ $proyek?->nama_proyek }}</flux:heading>
                    @if ($promoTerpakai)
                        <flux:subheading class="text-amber-600">
                            {{ __('Promo ini sudah dipakai SPR — jenis, tanggal, nominal, dan tabelnya terkunci. Nama, unit, dan keterangan masih bisa diubah.') }}
                        </flux:subheading>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Nama promo') }} <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="nama" placeholder="Promo 11.11" />
                        <flux:error name="nama" />
                    </flux:field>

                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Jenis') }}</flux:label>
                        <flux:radio.group wire:model.live="jenis" variant="segmented" :disabled="$promoTerpakai">
                            @foreach (\App\Models\Master\PromoPenjualan::JENIS as $kodeJenis => $labelJenis)
                                <flux:radio value="{{ $kodeJenis }}" :label="__($labelJenis)" />
                            @endforeach
                        </flux:radio.group>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Tanggal mulai UTJ') }}</flux:label>
                        <flux:input type="date" wire:model="mulai" :disabled="$promoTerpakai" />
                        <flux:error name="mulai" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Tanggal selesai UTJ') }}</flux:label>
                        <flux:input type="date" wire:model="sampai" :disabled="$promoTerpakai" />
                        <flux:error name="sampai" />
                    </flux:field>
                </div>

                @if ($jenis === 'um')
                    <flux:field>
                        <flux:label>{{ __('Potongan UM (Rp)') }} <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model.live.debounce.300ms="potonganUm" inputmode="numeric" placeholder="900.000" :disabled="$promoTerpakai" />
                        <flux:description>{{ __('Mengurangi UM yang dicicil konsumen. UTJ tidak ikut terpotong.') }}</flux:description>
                        <flux:error name="potonganUm" />
                    </flux:field>
                @elseif ($jenis === 'booking_fee_tetap')
                    <flux:field>
                        <flux:label>{{ __('Booking fee yang dibayar konsumen (Rp)') }} <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model.live.debounce.300ms="bookingFeeTetap" inputmode="numeric" placeholder="101.000" :disabled="$promoTerpakai" />
                        <flux:description>{{ __('Satu nominal untuk semua konsumen, berapa pun usianya. Selisih dengan UTJ normal ditanggung developer.') }}</flux:description>
                        <flux:error name="bookingFeeTetap" />
                    </flux:field>
                @else
                    <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex flex-wrap items-end gap-3">
                            <flux:field>
                                <flux:label>{{ __('Tabel dibaca dari') }}</flux:label>
                                <flux:select wire:model.live="dasarUsia" :disabled="$promoTerpakai">
                                    <flux:select.option value="usia">{{ __('Usia (tahun)') }}</flux:select.option>
                                    <flux:select.option value="tahun_lahir">{{ __('Tahun lahir') }}</flux:select.option>
                                </flux:select>
                            </flux:field>

                            @unless ($promoTerpakai)
                                <div class="flex items-end gap-2 text-xs">
                                    <flux:input wire:model="isiDari" class="w-16" :label="__('Usia')" />
                                    <span class="pb-2">–</span>
                                    <flux:input wire:model="isiSampai" class="w-16" />
                                    <span class="pb-2">×</span>
                                    <flux:input wire:model="isiKelipatan" class="w-24" />
                                    <flux:button size="sm" type="button" wire:click="isiCepat">{{ __('Isi cepat') }}</flux:button>
                                </div>
                            @endunless
                        </div>

                        <table class="w-full text-sm">
                            <thead class="text-xs uppercase text-zinc-500">
                                <tr>
                                    <th class="py-1 text-left">{{ $dasarUsia === 'tahun_lahir' ? __('Lahir dari') : __('Usia dari') }}</th>
                                    <th class="py-1 text-left">{{ __('sampai') }}</th>
                                    <th class="py-1 text-left">{{ __('Booking fee (Rp)') }}</th>
                                    <th class="py-1 text-left">{{ __('Label') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tingkat as $i => $t)
                                    <tr wire:key="tingkat-{{ $i }}">
                                        <td class="py-1 pe-2"><flux:input size="sm" wire:model="tingkat.{{ $i }}.dari" inputmode="numeric" :disabled="$promoTerpakai" /></td>
                                        <td class="py-1 pe-2"><flux:input size="sm" wire:model="tingkat.{{ $i }}.sampai" inputmode="numeric" :disabled="$promoTerpakai" /></td>
                                        <td class="py-1 pe-2"><flux:input size="sm" wire:model="tingkat.{{ $i }}.booking_fee" inputmode="numeric" :disabled="$promoTerpakai" /></td>
                                        <td class="py-1 pe-2"><flux:input size="sm" wire:model="tingkat.{{ $i }}.label" placeholder="Gen Z" :disabled="$promoTerpakai" /></td>
                                        <td class="py-1 text-right">
                                            @unless ($promoTerpakai)
                                                <flux:button size="xs" variant="ghost" icon="x-mark" type="button" wire:click="hapusTingkat({{ $i }})" />
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @unless ($promoTerpakai)
                            <flux:button size="sm" variant="ghost" icon="plus" type="button" wire:click="tambahTingkat">{{ __('Tambah baris') }}</flux:button>
                        @endunless
                        <flux:error name="tingkat" />
                        <p class="text-xs text-zinc-500">
                            {{ __('Usia dihitung dari tanggal lahir di KTP pada tanggal UTJ. Konsumen di luar tabel membayar booking fee normal.') }}
                        </p>
                    </div>
                @endif

                <div class="space-y-2">
                    <flux:checkbox wire:model.live="semuaUnit" :label="__('Berlaku untuk semua unit di proyek ini')" />

                    @unless ($semuaUnit)
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="mb-2 flex items-center gap-2">
                                <flux:input size="sm" wire:model.live.debounce.300ms="cariUnit" icon="magnifying-glass" :placeholder="__('Cari blok…')" class="flex-1" />
                                <flux:button size="sm" type="button" wire:click="pilihSemuaTerlihat">{{ __('Pilih semua') }}</flux:button>
                                <span class="text-xs text-zinc-500">{{ $jumlahTerpilih }} {{ __('terpilih') }}</span>
                            </div>
                            <div class="grid max-h-56 grid-cols-3 gap-1 overflow-y-auto sm:grid-cols-6">
                                @foreach ($daftarUnit as $r)
                                    <label wire:key="u-{{ $r->id }}" class="flex items-center gap-1.5 rounded px-1.5 py-1 text-xs hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                        <input type="checkbox" wire:model.live="unit.{{ $r->id }}" class="rounded" />
                                        <span class="font-mono">{{ $r->kode_unit }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <flux:error name="unit" />
                        </div>
                    @endunless
                </div>

                <flux:field>
                    <flux:label>{{ __('Keterangan') }}</flux:label>
                    <flux:input wire:model="keterangan" placeholder="{{ __('opsional') }}" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Simpan') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- RINCIAN SPR --}}
        <flux:modal name="promo-rincian" class="md:w-2xl">
            @if ($rincian)
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ $rincian->nama }}</flux:heading>
                        <flux:subheading>{{ $rincian->spr->count() }} {{ __('SPR mendapat promo ini') }}</flux:subheading>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase text-zinc-500">
                            <tr>
                                <th class="py-1 text-left">{{ __('SPR') }}</th>
                                <th class="py-1 text-left">{{ __('Unit') }}</th>
                                <th class="py-1 text-left">{{ __('Konsumen') }}</th>
                                <th class="py-1 text-right">{{ __('Ditanggung developer') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($rincian->spr as $s)
                                <tr>
                                    <td class="py-1.5">
                                        <a href="{{ route('marketing.spr.show', $s->id) }}" class="font-mono text-blue-600 hover:underline dark:text-blue-400" wire:navigate>{{ $s->nomor_display ?? $s->nomor_spr }}</a>
                                        @if ($s->status === 'cancelled')
                                            <flux:badge color="rose" size="sm">{{ __('Batal') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="py-1.5 font-mono">{{ $s->rumah?->kode_unit }}</td>
                                    <td class="py-1.5">{{ $s->prospectCustomer?->nama_lengkap }}</td>
                                    <td class="py-1.5 text-right font-mono tabular-nums">{{ number_format($s->totalPromo(), 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-zinc-300 font-semibold dark:border-zinc-600">
                                <td colspan="3" class="py-1.5">{{ __('Total') }}</td>
                                <td class="py-1.5 text-right font-mono tabular-nums">{{ number_format($rincian->spr->sum(fn ($s) => $s->totalPromo()), 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </flux:modal>

        {{-- HAPUS --}}
        <flux:modal name="promo-hapus" class="md:w-96">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Hapus promo ini?') }}</flux:heading>
                <flux:subheading>{{ __('Promo yang sudah dipakai SPR tidak bisa dihapus — nonaktifkan saja.') }}</flux:subheading>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" type="button">{{ __('Batal') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" icon="trash" wire:click="hapus">{{ __('Hapus') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </div>
</section>
