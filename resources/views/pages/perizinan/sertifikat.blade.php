<?php

use App\Models\Master\Notaris;
use App\Models\Master\Rumah;
use App\Models\Master\Sertifikat;
use App\Services\SertifikatService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Perizinan — Data Sertifikat. Hanya untuk melihat dan mencari.
 *
 * Pengisiannya ada di halaman tersendiri lewat menu "Kelola Sertifikat",
 * supaya layar ini tidak berpindah-pindah keadaan antara membaca dan mengisi.
 *
 * Saringan tidak berjalan otomatis: tabelnya lebar dan datanya banyak, jadi
 * ia menunggu tombol Tampilkan ditekan. Proyek diambil dari picker di sidebar
 * supaya seragam dengan Pemberkasan dan Rencana Akad.
 */
new #[Title('Data Sertifikat')] class extends Component
{
    use WithPagination;

    #[Url(as: 'proyek')]
    public ?int $proyekId = null;

    // ── Saringan yang sedang diketik ───────────────────────
    public ?int $notarisId = null;

    public string $status = '';

    public string $ajbDari = '';

    public string $ajbSampai = '';

    public string $bastDari = '';

    public string $bastSampai = '';

    public string $cari = '';

    /**
     * Saringan yang sudah ditekan Tampilkan.
     *
     * Dipisah dari isian di atas supaya mengetik di kotak tanggal tidak
     * memicu kueri berat tiap ketukan.
     *
     * @var array<string, mixed>
     */
    public array $terapkan = [];

    public function mount(): void
    {
        $this->proyekId = \App\Support\ProyekAktif::sinkron($this->proyekId);

        $this->tampilkan();
    }

    #[On('active-proyek-changed')]
    public function syncProyek(int $proyekId): void
    {
        $this->proyekId = $proyekId;
        $this->resetPage();
        $this->tampilkan();
    }

    public function tampilkan(): void
    {
        $this->terapkan = [
            'notaris' => $this->notarisId,
            'status' => $this->status,
            'ajb_dari' => $this->ajbDari,
            'ajb_sampai' => $this->ajbSampai,
            'bast_dari' => $this->bastDari,
            'bast_sampai' => $this->bastSampai,
            'cari' => $this->cari,
        ];

        $this->resetPage();
    }

    public function reset_saringan(): void
    {
        $this->reset(['notarisId', 'status', 'ajbDari', 'ajbSampai', 'bastDari', 'bastSampai', 'cari']);
        $this->tampilkan();
    }

    // ── Riwayat per unit ───────────────────────────────────
    public ?int $riwayatId = null;

    public string $riwayatJudul = '';

    /** @var array{tahap: list<array<string, mixed>>, catatan: list<array<string, mixed>>}|null */
    public ?array $riwayat = null;

    public function openRiwayat(int $id): void
    {
        abort_unless($this->bolehLihat(), 403);

        $s = Sertifikat::with('rumah:id,blok,nomor_unit')->findOrFail($id);

        $this->riwayatId = $s->id;
        $this->riwayatJudul = (string) $s->rumah?->kode_unit;
        $this->riwayat = app(SertifikatService::class)->riwayat($s);

        Flux::modal('riwayat-sertifikat')->show();
    }

    public function unduhBerkas(int $id): mixed
    {
        abort_unless($this->bolehLihat(), 403);

        $s = Sertifikat::findOrFail($id);

        abort_unless($s->file_path && Storage::disk('private')->exists($s->file_path), 404);

        return Storage::disk('private')->download($s->file_path, $s->file_original_name ?? 'sertifikat.pdf');
    }

    /** Dokumen serah terima ke bank — satu berkas dipakai bersama se-penyerahan. */
    public function unduhBast(int $id): mixed
    {
        abort_unless($this->bolehLihat(), 403);

        $t = Sertifikat::findOrFail($id)->serahTerima;

        abort_unless($t?->file_path && Storage::disk('private')->exists($t->file_path), 404);

        return Storage::disk('private')->download($t->file_path, $t->file_original_name ?? 'serah-terima.pdf');
    }

    /**
     * Berkasnya ada di disk private, jadi izinnya diperiksa tiap permintaan —
     * lolos ke halaman ini tidak otomatis berarti boleh mengunduh isinya.
     */
    protected function bolehLihat(): bool
    {
        return (bool) Auth::user()?->canAny([
            'legal.lihat',
            'legal.pengajuan.kelola',
            'legal.sertifikat.kelola',
            'legal.serahterima.kelola',
        ]);
    }

    public function with(): array
    {
        return [
            'notarisList' => Notaris::orderBy('nama')->get(['id', 'nama']),
            'ringkasan' => $this->proyekId ? app(SertifikatService::class)->ringkasan($this->proyekId) : null,
            'baris' => $this->proyekId ? $this->daftar() : null,
        ];
    }

    protected function daftar()
    {
        $f = $this->terapkan;

        $q = Sertifikat::query()
            ->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->proyekId))
            ->with([
                'rumah:id,blok,nomor_unit,proyek_id',
                'rumah.sprAktif',
                'rumah.sprAktif.prospectCustomer:id,nama_lengkap,nik,hp',
                'rumah.sprAktif.pemberkasan:id,spr_id,bank_kode',
                // Kolomnya sengaja tidak dibatasi: relasi ini memakai
                // latestOfMany, yang menggabungkan tabel dengan dirinya sendiri,
                // sehingga 'spr_id' tanpa awalan tabel jadi ambigu dan ditolak
                // MySQL. SQLite membiarkannya, jadi suite tes tidak menangkapnya.
                'rumah.sprAktif.rencanaAkadUnit',
                'rumah.sprAktif.rencanaAkadUnit.rencanaAkad:id,notaris_id',
                'rumah.sprAktif.rencanaAkadUnit.rencanaAkad.notaris:id,nama',
                'serahTerima:id,tanggal',
            ]);

        if (($f['cari'] ?? '') !== '') {
            $cari = trim($f['cari']);

            // Kolom Blok menampilkan "AD-18" — gabungan dua kolom yang tidak ada
            // bentuknya di basis data. Yang diketik orang justru persis itu, jadi
            // bentuk gabungannya dipecah dulu sebelum dicari.
            $gabungan = preg_match('/^(.+?)\s*-\s*(.+)$/', $cari, $m)
                ? ['blok' => trim($m[1]), 'unit' => trim($m[2])]
                : null;

            $q->where(function ($qq) use ($cari, $gabungan) {
                $qq->where('nomor_sertifikat', 'like', "%$cari%")
                    ->orWhere('nib', 'like', "%$cari%")
                    ->orWhere('hgb_induk', 'like', "%$cari%")
                    ->orWhereHas('rumah', function ($r) use ($cari, $gabungan) {
                        $r->where('blok', 'like', "%$cari%")
                            ->orWhere('nomor_unit', 'like', '%'.Rumah::normalCari($cari).'%');

                        if ($gabungan) {
                            // LIKE tanpa jokaritas, bukan '=': di SQLite '='
                            // membedakan huruf besar-kecil sedangkan LIKE tidak,
                            // dan pencarian tidak boleh peka huruf.
                            $r->orWhere(fn ($x) => $x
                                ->where('blok', 'like', $gabungan['blok'])
                                ->where('nomor_unit', 'like', Rumah::normalCari($gabungan['unit']).'%'));
                        }
                    })
                    ->orWhereHas('rumah.sprAktif.prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%$cari%"));
            });
        }

        // Status bukan kolom — disaring lewat keadaan datanya.
        match ($f['status'] ?? '') {
            'diajukan' => $q->whereNull('nomor_sertifikat'),
            'terbit' => $q->whereNotNull('nomor_sertifikat')->whereNull('sertifikat_serah_terima_id'),
            'di_bank' => $q->whereNotNull('sertifikat_serah_terima_id'),
            default => null,
        };

        if (($f['bast_dari'] ?? '') !== '' || ($f['bast_sampai'] ?? '') !== '') {
            $q->whereHas('serahTerima', function ($t) use ($f) {
                $t->when($f['bast_dari'] !== '', fn ($x) => $x->whereDate('tanggal', '>=', $f['bast_dari']))
                    ->when($f['bast_sampai'] !== '', fn ($x) => $x->whereDate('tanggal', '<=', $f['bast_sampai']));
            });
        }

        // Notaris dan tanggal AJB datang dari rencana akad unitnya — sertifikat
        // tidak punya kolomnya, dan memang tidak perlu punya.
        if (($f['notaris'] ?? null) || ($f['ajb_dari'] ?? '') !== '' || ($f['ajb_sampai'] ?? '') !== '') {
            $q->whereHas('rumah.sprAktif.rencanaAkadUnit', function ($u) use ($f) {
                $u->when($f['ajb_dari'] !== '', fn ($x) => $x->whereDate('tanggal_ajb', '>=', $f['ajb_dari']))
                    ->when($f['ajb_sampai'] !== '', fn ($x) => $x->whereDate('tanggal_ajb', '<=', $f['ajb_sampai']))
                    ->when($f['notaris'], fn ($x) => $x->whereHas('rencanaAkad', fn ($r) => $r->where('notaris_id', $f['notaris'])));
            });
        }

        return $q->orderByDesc('id')->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Data Sertifikat') }}</flux:heading>
                <flux:subheading>{{ __('Arsip dan status sertifikat per unit.') }}</flux:subheading>
            </div>

            @canany(['legal.pengajuan.kelola', 'legal.sertifikat.kelola', 'legal.serahterima.kelola'])
                <flux:dropdown position="bottom" align="end">
                    <flux:button variant="primary" icon-trailing="chevron-down">{{ __('Kelola Sertifikat') }}</flux:button>
                    <flux:menu>
                        @can('legal.pengajuan.kelola')
                            <flux:menu.item icon="paper-airplane" :href="route('perizinan.sertifikat.pengajuan')" wire:navigate>
                                {{ __('Input Pengajuan Sertifikat') }}
                            </flux:menu.item>
                        @endcan
                        @can('legal.sertifikat.kelola')
                            <flux:menu.item icon="pencil-square" :href="route('perizinan.sertifikat.input')" wire:navigate>
                                {{ __('Input Data Sertifikat') }}
                            </flux:menu.item>
                        @endcan
                        @can('legal.serahterima.kelola')
                            <flux:menu.item icon="building-library" :href="route('perizinan.sertifikat.serah-bank')" wire:navigate>
                                {{ __('Serah Terima Bank') }}
                            </flux:menu.item>
                        @endcan
                    </flux:menu>
                </flux:dropdown>
            @endcanany
        </div>

        {{-- SARINGAN — selalu tampil, bahkan sebelum proyek dipilih --}}
        <div class="mb-5 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            {{-- Baris pertama: pilihan singkat + pencarian --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <flux:select wire:model="notarisId" :label="__('Notaris')">
                    <flux:select.option value="">{{ __('Semua notaris') }}</flux:select.option>
                    @foreach ($notarisList as $n)
                        <flux:select.option value="{{ $n->id }}">{{ $n->nama }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="status" :label="__('Status')">
                    <flux:select.option value="">{{ __('Semua status') }}</flux:select.option>
                    @foreach (\App\Models\Master\Sertifikat::STATUS as $k => $label)
                        <flux:select.option value="{{ $k }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="cari" icon="magnifying-glass" wire:keydown.enter="tampilkan"
                            :label="__('Pencarian')"
                            :placeholder="__('AD-18, no. sertifikat, NIB, konsumen')" />
            </div>

            {{-- Baris kedua: dua rentang tanggal diberi ruang sendiri, karena
                 masing-masing butuh sepasang kotak dan akan berdesakan kalau
                 dipaksa sebaris dengan pilihan di atas.

                 Sengaja memakai kotak tanggal bawaan peramban seperti modul
                 lain. Flatpickr sempat dicoba demi format d/m/Y, tapi kalendernya
                 ditempelkan ke body sehingga terpotong begitu dipakai di dalam
                 modal. --}}
            <div class="mt-3 flex flex-col gap-3 lg:flex-row lg:items-end">
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium">{{ __('Rentang tanggal AJB') }}</label>
                    <div class="flex items-center gap-2">
                        <flux:input type="date" wire:model="ajbDari" class="flex-1" />
                        <span class="shrink-0 text-sm text-zinc-400">&ndash;</span>
                        <flux:input type="date" wire:model="ajbSampai" class="flex-1" />
                    </div>
                </div>

                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium">{{ __('Rentang BAST ke bank') }}</label>
                    <div class="flex items-center gap-2">
                        <flux:input type="date" wire:model="bastDari" class="flex-1" />
                        <span class="shrink-0 text-sm text-zinc-400">&ndash;</span>
                        <flux:input type="date" wire:model="bastSampai" class="flex-1" />
                    </div>
                </div>

                <div class="flex shrink-0 gap-2">
                    <flux:button variant="primary" icon="magnifying-glass" wire:click="tampilkan">
                        {{ __('Tampilkan') }}
                    </flux:button>
                    <flux:button variant="ghost" icon="arrow-path" wire:click="reset_saringan"
                                 :title="__('Reset saringan')" />
                </div>
            </div>
        </div>

        @if (! $proyekId)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                {{ __('Pilih proyek dulu di picker "Proyek Aktif" pada sidebar kiri untuk menampilkan datanya.') }}
            </div>
        @else

            {{-- KARTU RINGKASAN --}}
            @php
                $kartu = [
                    ['Total Luas', number_format($ringkasan['luas'], 0, ',', '.').' m²', 'map', 'zinc'],
                    ['Belum Diajukan', number_format($ringkasan['belum_diajukan']), 'exclamation-circle', 'rose'],
                    ['Proses', number_format($ringkasan['proses']), 'clock', 'amber'],
                    ['Belum AJB', number_format($ringkasan['belum_ajb']), 'calendar-days', 'sky'],
                    ['Belum ke Bank', number_format($ringkasan['belum_ke_bank']), 'building-library', 'orange'],
                    ['Di Bank', number_format($ringkasan['di_bank']), 'building-library', 'emerald'],
                ];
                $nada = [
                    'zinc' => ['border-zinc-200 dark:border-zinc-700', 'text-zinc-500', 'bg-zinc-100 dark:bg-zinc-800'],
                    'rose' => ['border-rose-200 dark:border-rose-900/40', 'text-rose-600 dark:text-rose-400', 'bg-rose-50 dark:bg-rose-950/40'],
                    'amber' => ['border-amber-200 dark:border-amber-900/40', 'text-amber-600 dark:text-amber-400', 'bg-amber-50 dark:bg-amber-950/40'],
                    'sky' => ['border-sky-200 dark:border-sky-900/40', 'text-sky-600 dark:text-sky-400', 'bg-sky-50 dark:bg-sky-950/40'],
                    'orange' => ['border-orange-200 dark:border-orange-900/40', 'text-orange-600 dark:text-orange-400', 'bg-orange-50 dark:bg-orange-950/40'],
                    'emerald' => ['border-emerald-200 dark:border-emerald-900/40', 'text-emerald-600 dark:text-emerald-400', 'bg-emerald-50 dark:bg-emerald-950/40'],
                ];
            @endphp

            <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-6">
                @foreach ($kartu as [$judul, $angka, $ikon, $warna])
                    @php
                        [$bingkai, $teks, $latar] = $nada[$warna];
                    @endphp
                    <div class="rounded-xl border bg-white p-4 shadow-sm dark:bg-zinc-900 {{ $bingkai }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-500">{{ $judul }}</div>
                                <div class="mt-1 truncate font-mono text-xl font-bold tabular-nums">{{ $angka }}</div>
                            </div>
                            <div class="rounded-lg p-2 {{ $latar }} {{ $teks }}">
                                <flux:icon :name="$ikon" class="size-4" />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- TABEL --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-zinc-50 uppercase text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Blok') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('L Tanah') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('No Sertifikat') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('No NIB') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('No HGB Induk') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Customer') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Bank') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Notaris') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Type Akad') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Tgl PPJB') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Tgl AJB') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Tgl ke Bank') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Ket') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Riwayat') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($baris as $s)
                                @php
                                    $spr = $s->rumah?->sprAktif;
                                    $unitAkad = $spr?->rencanaAkadUnit;
                                    $warnaStatus = match ($s->status()) {
                                        'di_bank' => 'blue',
                                        'terbit' => 'emerald',
                                        default => 'amber',
                                    };
                                @endphp
                                <tr wire:key="s-{{ $s->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="whitespace-nowrap px-3 py-2 font-mono font-semibold">
                                        {{ $s->rumah?->kode_unit }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">
                                        {{ $s->luasBerlaku() !== null ? rtrim(rtrim(number_format($s->luasBerlaku(), 2, ',', '.'), '0'), ',') : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono">{{ $s->nomor_sertifikat ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-zinc-500">{{ $s->nib ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-zinc-500">{{ $s->hgb_induk ?: '—' }}</td>
                                    <td class="px-3 py-2">{{ $spr?->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-500">{{ $spr?->pemberkasan?->bank_kode ?? '—' }}</td>
                                    <td class="px-3 py-2 text-zinc-500">{{ $unitAkad?->rencanaAkad?->notaris?->nama ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        {{ $unitAkad ? strtoupper($unitAkad->jenis_akad) : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $unitAkad?->tanggal_ppjb?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $unitAkad?->tanggal_ajb?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $s->serahTerima?->tanggal?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <flux:badge :color="$warnaStatus" size="sm">{{ $s->labelStatus() }}</flux:badge>
                                    </td>
                                    {{-- Hanya riwayat. Unduhan berkasnya ada di dalam
                                         modal itu, bersama tanggal dan pencatatnya —
                                         ikon jepit kertas terpisah di sini cuma
                                         mengulang jalan ke berkas yang sama. --}}
                                    <td class="px-3 py-2 text-right">
                                        <button type="button" wire:click="openRiwayat({{ $s->id }})"
                                                class="rounded p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                                title="{{ __('Lihat riwayat') }}">
                                            <flux:icon.clock class="size-4" />
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="px-3 py-12 text-center text-sm italic text-zinc-400">
                                        {{ __('Tidak ada sertifikat yang cocok dengan saringan.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($baris)
                <div class="mt-3">{{ $baris->links() }}</div>
            @endif

        @endif

        {{-- RIWAYAT PER UNIT --}}
        <flux:modal name="riwayat-sertifikat" class="md:w-2xl">
            <div class="space-y-5">
                <div class="flex items-start gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400">
                        <flux:icon.clock class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <flux:heading size="lg">
                            {{ __('Riwayat Sertifikat') }}{{ $riwayatJudul ? ' · '.$riwayatJudul : '' }}
                        </flux:heading>
                        <flux:subheading>{{ __('Perjalanan unit ini dari pengajuan sampai diserahkan ke bank.') }}</flux:subheading>
                    </div>
                </div>

                <div class="max-h-[60vh] space-y-5 overflow-y-auto pr-1">
                    @if ($riwayat)
                        {{-- Tiga tahap, selalu ditampilkan seluruhnya. Tahap yang
                             belum tercapai sengaja tidak disembunyikan supaya
                             terlihat prosesnya berhenti di mana.

                             Penanda dan garisnya memakai kolom sendiri, bukan
                             posisi absolut: yang absolut keluar dari bidang
                             modal dan bulatannya terpotong tepi. --}}
                        <ol>
                            @foreach ($riwayat['tahap'] as $i => $tahap)
                                @php
                                    $aktif = $tahap['tercapai'];
                                    $terakhir = $i === count($riwayat['tahap']) - 1;
                                    $bulat = $aktif
                                        ? 'bg-emerald-500 text-white'
                                        : 'border-2 border-dashed border-zinc-300 bg-white text-zinc-400 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-500';
                                @endphp
                                <li class="flex gap-3">
                                    <div class="flex shrink-0 flex-col items-center">
                                        <span class="flex size-6 items-center justify-center rounded-full {{ $bulat }}">
                                            @if ($aktif)
                                                <flux:icon.check class="size-3.5" />
                                            @else
                                                <flux:icon.minus class="size-3.5" />
                                            @endif
                                        </span>
                                        @unless ($terakhir)
                                            <span class="w-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
                                        @endunless
                                    </div>

                                    <div class="min-w-0 flex-1 {{ $terakhir ? '' : 'pb-6' }}">
                                        <div class="flex flex-wrap items-baseline gap-x-2">
                                            <span class="text-sm font-semibold {{ $aktif ? '' : 'text-zinc-400 dark:text-zinc-500' }}">
                                                {{ __($tahap['judul']) }}
                                            </span>
                                            @if ($tahap['tanggal'])
                                                <span class="font-mono text-sm tabular-nums text-zinc-700 dark:text-zinc-300">{{ $tahap['tanggal'] }}</span>
                                            @endif
                                        </div>

                                        @if ($aktif)
                                            @if ($tahap['dicatat'] || $tahap['oleh'])
                                                <div class="mt-0.5 text-[11px] text-zinc-500">
                                                    {{ __('Dicatat') }} {{ $tahap['dicatat'] ?: '—' }}@if ($tahap['oleh']) · {{ $tahap['oleh'] }}@endif
                                                </div>
                                            @endif

                                            {{-- Label di atas nilainya, bukan di sampingnya: nomor
                                                 sertifikat dan HGB induk panjangnya tidak seragam,
                                                 dan sebaris membuatnya saling mendorong. --}}
                                            @if ($tahap['rincian'])
                                                <dl class="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-2.5 sm:grid-cols-4">
                                                    @foreach ($tahap['rincian'] as $label => $nilai)
                                                        <div class="min-w-0">
                                                            <dt class="text-[10px] uppercase tracking-wide text-zinc-400">{{ __($label) }}</dt>
                                                            <dd class="mt-0.5 text-xs font-medium wrap-break-word">{{ $nilai }}</dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            @endif

                                            @if ($tahap['catatan'])
                                                <div class="mt-2.5">
                                                    <div class="text-[10px] uppercase tracking-wide text-zinc-400">{{ __('Catatan') }}</div>
                                                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">{{ $tahap['catatan'] }}</p>
                                                </div>
                                            @endif

                                            @if ($tahap['berkas'])
                                                <button type="button"
                                                        wire:click="{{ $tahap['berkas']['jenis'] === 'bast' ? 'unduhBast' : 'unduhBerkas' }}({{ $riwayatId }})"
                                                        class="mt-2.5 inline-flex max-w-full items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-blue-600 transition hover:bg-blue-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-blue-400 dark:hover:bg-zinc-700">
                                                    <flux:icon.arrow-down-tray class="size-3.5 shrink-0" />
                                                    <span class="truncate">{{ $tahap['berkas']['nama'] }}</span>
                                                </button>
                                            @endif
                                        @else
                                            <div class="mt-0.5 text-[11px] italic text-zinc-400">{{ __($tahap['kosong']) }}</div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        @if ($riwayat['catatan'])
                            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <div class="mb-2 text-[10px] font-bold uppercase tracking-wide text-zinc-500">
                                    {{ __('Koreksi & pembatalan') }}
                                </div>

                                <div class="space-y-3">
                                    @foreach ($riwayat['catatan'] as $c)
                                        <div class="rounded-lg border border-zinc-200 p-2.5 dark:border-zinc-700">
                                            <div class="flex flex-wrap items-baseline gap-2 text-xs">
                                                <span class="font-semibold">{{ $c['judul'] }}</span>
                                                <span class="text-zinc-500">{{ $c['waktu'] }}</span>
                                                @if ($c['oleh'])
                                                    <span class="ms-auto text-[11px] text-zinc-400">{{ $c['oleh'] }}</span>
                                                @endif
                                            </div>

                                            @if ($c['alasan'])
                                                <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
                                                    {{ __('Alasan') }}: {{ $c['alasan'] }}
                                                </div>
                                            @endif

                                            @if ($c['perubahan'])
                                                <div class="mt-2 space-y-1.5">
                                                    @foreach ($c['perubahan'] as $label => $ubah)
                                                        <div class="min-w-0">
                                                            <div class="text-[10px] uppercase tracking-wide text-zinc-400">{{ $label }}</div>
                                                            <div class="flex flex-wrap items-baseline gap-1.5 text-xs">
                                                                <span class="text-zinc-400 line-through">{{ $ubah[0] ?? '—' }}</span>
                                                                <flux:icon.arrow-right class="size-3 shrink-0 text-zinc-400" />
                                                                <span class="font-medium">{{ $ubah[1] ?? '—' }}</span>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="flex justify-end border-t border-zinc-200 pt-3 dark:border-zinc-700">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Tutup') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>

    </div>
</section>
