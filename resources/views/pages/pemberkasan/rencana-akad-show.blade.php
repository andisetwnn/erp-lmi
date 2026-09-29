<?php

use App\Models\Master\RencanaAkad;
use App\Models\Master\RencanaAkadUnit;
use App\Models\Master\Spr;
use App\Services\RencanaAkadService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detail Rencana Akad')] class extends Component
{
    public int $rencanaId;

    public string $cariUnit = '';

    /** Pengurutan daftar kandidat di layar Tambah Rumah. */
    public string $urutKandidat = 'unit';

    public string $arahKandidat = 'asc';

    public ?int $konfirmasiHapusUnitId = null;

    /** Aksi status yang sedang dikonfirmasi: diajukan|fix|draft|batal */
    public ?string $aksiStatus = null;

    public string $alasanTolak = '';

    public string $tanggalFix = '';

    // ─── Kirim persetujuan Direksi via WA magic link ─────────────────────────
    /** URL signed yang di-generate. Null sampai user tekan "Generate Link". */
    public ?string $urlDireksi = null;

    /**
     * Form biaya sesi — array of ['nama' => string, 'nominal' => string].
     * Dinamis: user boleh tambah/hapus baris sesuai kebutuhan sesi akad
     * (contoh: air mineral, parkir, transport materai, dll).
     *
     * @var array<int, array{nama:string, nominal:string}>
     */
    public array $formBiayaSesi = [];

    public function mount(int $id): void
    {
        $this->rencanaId = $id;
    }

    public function getRencanaProperty(): RencanaAkad
    {
        return RencanaAkad::with(['bank', 'notaris', 'proyek', 'biayaSesi', 'unit.spr.rumah.tipeRumah', 'unit.spr.rumah.subcon',
            'unit.spr.prospectCustomer', 'unit.spr.sales', 'unit.spr.pemberkasan'])
            ->findOrFail($this->rencanaId);
    }

    private function svc(): RencanaAkadService
    {
        return app(RencanaAkadService::class);
    }

    private function pastikanBolehKelola(): void
    {
        abort_unless(Auth::user()?->can('rencanaakad.kelola'), 403);
    }

    public function openTambahUnit(): void
    {
        $this->pastikanBolehKelola();
        $this->cariUnit = '';
        Flux::modal('tambah-unit')->show();
    }

    /** Klik kolom yang sama membalik arahnya; kolom lain mulai dari menaik. */
    public function urutkanKandidat(string $kolom): void
    {
        if (! in_array($kolom, RencanaAkadService::URUT_KANDIDAT, true)) {
            return;
        }

        if ($this->urutKandidat === $kolom) {
            $this->arahKandidat = $this->arahKandidat === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->urutKandidat = $kolom;
        $this->arahKandidat = 'asc';
    }

    public function tambahUnit(int $sprId): void
    {
        $this->pastikanBolehKelola();

        try {
            $this->svc()->tambahUnit($this->rencana, Spr::findOrFail($sprId));
            Flux::toast(variant: 'success', text: 'Unit ditambahkan.');
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());
        }
    }

    public ?int $batalUnitId = null;

    public string $alasanBatalUnit = '';

    /** Batal akad satu unit — konsumen tidak hadir di hari H. */
    public function openBatalUnit(int $unitId): void
    {
        $this->pastikanBolehKelola();

        $this->batalUnitId = RencanaAkadUnit::where('rencana_akad_id', $this->rencanaId)
            ->whereKey($unitId)->value('id');
        $this->alasanBatalUnit = '';
        $this->resetErrorBag();
        Flux::modal('batal-unit')->show();
    }

    public function batalkanUnit(): void
    {
        $this->pastikanBolehKelola();

        $this->validate([
            'alasanBatalUnit' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['alasanBatalUnit' => 'alasan batal']);

        $unit = RencanaAkadUnit::where('rencana_akad_id', $this->rencanaId)
            ->findOrFail($this->batalUnitId);

        try {
            $this->svc()->batalkanUnit($unit, $this->alasanBatalUnit, Auth::id());
            Flux::modal('batal-unit')->close();
            Flux::toast(variant: 'success', text: 'Akad unit dibatalkan. Unit bebas dijadwalkan ulang.');
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());
        }

        $this->reset(['batalUnitId', 'alasanBatalUnit']);
    }

    public function konfirmasiHapusUnit(int $unitId): void
    {
        $this->pastikanBolehKelola();
        $this->konfirmasiHapusUnitId = $unitId;
    }

    public function batalHapusUnit(): void
    {
        $this->konfirmasiHapusUnitId = null;
    }

    public function hapusUnit(): void
    {
        $this->pastikanBolehKelola();

        try {
            $unit = RencanaAkadUnit::where('rencana_akad_id', $this->rencanaId)
                ->findOrFail($this->konfirmasiHapusUnitId);
            $this->svc()->hapusUnit($unit);
            Flux::toast(variant: 'success', text: 'Unit dikeluarkan dari rencana.');
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());
        }

        $this->konfirmasiHapusUnitId = null;
    }

    public function ubahJenisAkad(int $unitId, string $jenis): void
    {
        $this->pastikanBolehKelola();

        $unit = RencanaAkadUnit::where('rencana_akad_id', $this->rencanaId)->findOrFail($unitId);
        abort_unless($this->rencana->bolehDiubah(), 403);

        $unit->update(['jenis_akad' => in_array($jenis, ['ppjb', 'ajb'], true) ? $jenis : 'ppjb']);
    }

    /**
     * Izin yang dibutuhkan tiap perpindahan status.
     *
     * Mengembalikan ke draft adalah penolakan — itu wewenang penyetuju, bukan penyusun.
     * Pembatalan boleh dari kedua sisi: penyusun membatalkan rencananya sendiri, atau
     * penyetuju menggugurkannya.
     *
     * @return array<int, string>
     */
    private function izinUntuk(string $aksi): array
    {
        return match ($aksi) {
            'diajukan' => ['rencanaakad.kelola'],
            'diketahui' => ['rencanaakad.mengetahui'],
            'fix' => ['rencanaakad.approve'],
            // Menolak (mengembalikan ke Draft) boleh dari PM saat Diajukan,
            // atau Direksi saat Diketahui — sama-sama "penyetuju".
            'draft' => ['rencanaakad.mengetahui', 'rencanaakad.approve'],
            'batal' => ['rencanaakad.kelola', 'rencanaakad.mengetahui', 'rencanaakad.approve'],
            default => ['rencanaakad.kelola'],
        };
    }

    private function pastikanBolehAksi(string $aksi): void
    {
        $user = Auth::user();
        $boleh = collect($this->izinUntuk($aksi))->contains(fn ($izin) => (bool) $user?->can($izin));

        abort_unless($boleh, 403);
    }

    public function openAksi(string $aksi): void
    {
        $this->pastikanBolehAksi($aksi);

        $this->aksiStatus = $aksi;
        $this->alasanTolak = '';
        $this->tanggalFix = $this->rencana->tanggal_rencana?->toDateString() ?? now()->toDateString();
        $this->resetErrorBag();
        Flux::modal('konfirmasi-status')->show();
    }

    public function jalankanAksi(): void
    {
        $aksi = $this->aksiStatus;
        abort_if($aksi === null, 400);

        $this->pastikanBolehAksi($aksi);

        if (in_array($aksi, ['draft', 'batal'], true)) {
            $this->validate(['alasanTolak' => ['required', 'string', 'min:3', 'max:500']], [], [
                'alasanTolak' => 'alasan',
            ]);
        }

        if ($aksi === 'fix') {
            $this->validate(['tanggalFix' => ['required', 'date']], [], ['tanggalFix' => 'tanggal akad']);
        }

        try {
            $this->svc()->ubahStatus($this->rencana, $aksi, Auth::id(), [
                'alasan' => $this->alasanTolak ?: null,
                'tanggal_fix' => $aksi === 'fix' ? $this->tanggalFix : null,
            ]);
            Flux::modal('konfirmasi-status')->close();
            Flux::toast(variant: 'success', text: 'Status diperbarui.');
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first());
        }

        $this->reset(['aksiStatus', 'alasanTolak']);
    }

    /**
     * Buka modal "Kirim Persetujuan ke Direksi" (magic link WhatsApp).
     * Link universal — siapa pun dari Direksi (Haryanto/Julianto Boentaran)
     * boleh menerima; mereka gambar TTD langsung di halaman.
     */
    public function openKirimDireksi(): void
    {
        abort_unless($this->rencana->status === 'diketahui', 409);

        // Auto-generate link begitu modal dibuka — user tinggal copy/kirim WA.
        $this->rencana->update([
            'direksi_link_generated_at' => now(),
        ]);

        $this->urlDireksi = \Illuminate\Support\Facades\URL::signedRoute(
            'public.persetujuan-direksi.show',
            ['rencanaId' => $this->rencana->id],
            now()->addDays(7)
        );

        $this->resetErrorBag();
        \Flux\Flux::modal('kirim-direksi')->show();
    }

    /**
     * Regenerate link (kalau yg lama sudah expired atau user butuh URL baru).
     */
    public function regenerateLinkDireksi(): void
    {
        abort_unless($this->rencana->status === 'diketahui', 409);

        $this->rencana->update([
            'direksi_link_generated_at' => now(),
        ]);

        $this->urlDireksi = \Illuminate\Support\Facades\URL::signedRoute(
            'public.persetujuan-direksi.show',
            ['rencanaId' => $this->rencana->id],
            now()->addDays(7)
        );
    }

    /**
     * Boleh mengubah biaya sesi jika:
     * - Rencana masih Draft dan user punya izin kelola (Admin KPR menyusun), ATAU
     * - Rencana Diajukan dan user punya izin approve (PM/Direktur mengoreksi
     *   sebelum mengesahkan).
     * Setelah Fix atau Batal, biaya sesi terkunci — apa yang tercatat itulah
     * yang dibayarkan.
     */
    private function bolehUbahBiayaSesi(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return match ($this->rencana->status) {
            'draft' => (bool) $user->can('rencanaakad.kelola'),
            'diajukan' => (bool) $user->can('rencanaakad.mengetahui'),
            'diketahui' => (bool) $user->can('rencanaakad.approve'),
            default => false,
        };
    }

    public function openBiayaSesi(): void
    {
        abort_unless($this->bolehUbahBiayaSesi(), 403);

        $this->formBiayaSesi = $this->rencana->biayaSesi
            ->map(fn ($bs) => [
                'nama' => $bs->nama,
                'nominal' => (string) (int) $bs->nominal,
            ])
            ->values()
            ->toArray();

        // Kalau belum ada baris sama sekali, kasih satu baris kosong sebagai
        // titik awal supaya user langsung tahu bentuk input yang diharapkan.
        if (empty($this->formBiayaSesi)) {
            $this->formBiayaSesi = [['nama' => '', 'nominal' => '']];
        }

        $this->resetErrorBag();
        Flux::modal('form-biaya-sesi')->show();
    }

    public function tambahBarisBiayaSesi(): void
    {
        abort_unless($this->bolehUbahBiayaSesi(), 403);

        $this->formBiayaSesi[] = ['nama' => '', 'nominal' => ''];
    }

    public function hapusBarisBiayaSesi(int $index): void
    {
        abort_unless($this->bolehUbahBiayaSesi(), 403);

        unset($this->formBiayaSesi[$index]);
        $this->formBiayaSesi = array_values($this->formBiayaSesi);
    }

    public function simpanBiayaSesi(): void
    {
        abort_unless($this->bolehUbahBiayaSesi(), 403);

        // Buang baris kosong (baik namanya kosong maupun nominal 0) sebelum validasi
        // — supaya user tidak dipaksa isi semua slot yang kebetulan disediakan.
        $baris = collect($this->formBiayaSesi)
            ->filter(fn ($b) => trim((string) ($b['nama'] ?? '')) !== '' || (float) ($b['nominal'] ?? 0) > 0)
            ->values();

        $this->formBiayaSesi = $baris->toArray();

        $data = $this->validate([
            'formBiayaSesi' => ['array'],
            'formBiayaSesi.*.nama' => ['required', 'string', 'max:120'],
            'formBiayaSesi.*.nominal' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ], [
            'formBiayaSesi.*.nama.required' => 'Nama biaya wajib diisi.',
            'formBiayaSesi.*.nominal.required' => 'Nominal wajib diisi.',
            'formBiayaSesi.*.nominal.min' => 'Nominal tidak boleh negatif.',
        ]);

        \DB::transaction(function () use ($data) {
            // Ganti semua baris dengan yang baru — bukan merge, supaya user
            // yang menghapus baris di form benar-benar terhapus di DB.
            $this->rencana->biayaSesi()->delete();

            foreach ($data['formBiayaSesi'] as $i => $b) {
                $this->rencana->biayaSesi()->create([
                    'nama' => trim($b['nama']),
                    'nominal' => (float) $b['nominal'],
                    'urutan' => $i,
                ]);
            }
        });

        Flux::modal('form-biaya-sesi')->close();
        Flux::toast(variant: 'success', text: 'Biaya sesi diperbarui.');
    }

    public function with(): array
    {
        $rencana = $this->rencana;
        $user = Auth::user();

        return [
            'rencana' => $rencana,
            'kandidat' => $rencana->bolehDiubah()
                ? $this->svc()->kandidatBeralasan($rencana->proyek_id, $rencana, $this->cariUnit, $this->urutKandidat, $this->arahKandidat)
                : collect(),
            'ringkasanUm' => $rencana->unit->mapWithKeys(
                fn ($u) => [$u->id => $this->svc()->ringkasanUm($u->spr)]
            ),
            'biaya' => $this->svc()->ringkasanBiaya($rencana),
            'bolehKelola' => (bool) $user?->can('rencanaakad.kelola'),
            'bolehMengetahui' => (bool) $user?->can('rencanaakad.mengetahui'),
            'bolehApprove' => (bool) $user?->can('rencanaakad.approve'),
            'bolehUbahBiayaSesi' => $this->bolehUbahBiayaSesi(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 print:hidden">
            <flux:button size="sm" variant="ghost" icon="arrow-left"
                         :href="route('pemberkasan.rencana-akad.index')" wire:navigate>
                Kembali ke daftar
            </flux:button>

            {{-- Cetak & export lampiran --}}
            @if ($rencana->unit->isNotEmpty())
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="primary" icon="document-text"
                                 :href="route('pemberkasan.rencana-akad.cetak.aju-dana', $rencana->id)" target="_blank" rel="noopener">
                        Cetak Aju Dana
                    </flux:button>
                    <flux:button size="sm" variant="ghost" icon="table-cells"
                                 :href="route('pemberkasan.rencana-akad.export.xlsx', $rencana->id)">
                        Export XLSX
                    </flux:button>
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">

            {{-- KOP --}}
            <div class="mb-6 text-center">
                <div class="font-mono text-2xl font-bold">{{ $rencana->nomor }}</div>
                <div class="mt-1 font-semibold">
                    Detail Rencana Akad tanggal
                    {{ $rencana->tanggalBerlaku()?->translatedFormat('d M Y') ?? '—' }}
                </div>
                <div class="font-semibold">
                    {{ $rencana->bank?->nama ?? '—' }} &middot; {{ $rencana->proyek?->nama_proyek }}
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $rencana->notaris?->nama ?? '—' }}</div>

                <div class="mt-3 flex items-center justify-center gap-2">
                    @php
                        $warna = match ($rencana->status) {
                            'draft' => 'amber', 'diajukan' => 'blue',
                            'fix' => 'emerald', default => 'zinc',
                        };
                    @endphp
                    <flux:badge :color="$warna">{{ $rencana->labelStatus() }}</flux:badge>
                    @if ($rencana->tanggal_fix && $rencana->tanggal_fix->ne($rencana->tanggal_rencana))
                        <span class="text-xs text-zinc-500">
                            digeser bank dari {{ $rencana->tanggal_rencana->translatedFormat('d M Y') }}
                        </span>
                    @endif
                </div>

                @if ($rencana->alasan_tolak)
                    <div class="mx-auto mt-3 max-w-xl rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-300">
                        <strong>Dikembalikan:</strong> {{ $rencana->alasan_tolak }}
                    </div>
                @endif
            </div>

            {{-- AKSI --}}
            <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
                @if ($rencana->bolehDiubah() && $bolehKelola)
                    <flux:button size="sm" variant="filled" icon="plus" wire:click="openTambahUnit">
                        Tambah Rumah
                    </flux:button>
                @endif
            </div>

            {{-- TABEL UNIT --}}
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                            <th class="w-10 px-3 py-2 font-semibold">#</th>
                            <th class="px-3 py-2 font-semibold">Lot</th>
                            <th class="px-3 py-2 font-semibold">Tipe</th>
                            <th class="px-3 py-2 font-semibold">Unit</th>
                            <th class="px-3 py-2 font-semibold">Nama</th>
                            <th class="px-3 py-2 font-semibold">Subcont</th>
                            <th class="px-3 py-2 text-right font-semibold">Progres</th>
                            <th class="px-3 py-2 text-right font-semibold">Total UM</th>
                            <th class="px-3 py-2 text-right font-semibold">UM Masuk</th>
                            <th class="px-3 py-2 text-right font-semibold">Target Masuk</th>
                            <th class="px-3 py-2 font-semibold">HGB</th>
                            <th class="px-3 py-2 text-right font-semibold">Plafon</th>
                            <th class="px-3 py-2 font-semibold">Marketing</th>
                            <th class="px-3 py-2 font-semibold">Status</th>
                            <th class="px-3 py-2 font-semibold">Jenis Akad</th>
                            <th class="w-12 px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($rencana->unit as $i => $u)
                            @php
                                $spr = $u->spr;
                                // Diambil ke variabel dulu: tanda panah di dalam {{ }} pada atribut
                                // komponen Flux membuat ">" dikira penutup tag, dan tagnya rusak.
                                $unitId = $u->id;
                                $tipe = $spr->rumah?->tipeRumah;
                                $um = $ringkasanUm[$u->id] ?? null;
                                $warnaUnit = match ($u->status) {
                                    'fix' => 'emerald', 'batal' => 'rose', default => 'zinc',
                                };
                                $labelUnit = match ($u->status) {
                                    'fix' => 'Selesai', 'batal' => 'Batal', default => 'Draft',
                                };
                            @endphp
                            <tr wire:key="unit-{{ $u->id }}">
                                <td class="px-3 py-2 text-xs text-zinc-400">{{ $i + 1 }}</td>
                                <td class="px-3 py-2">{{ $spr->rumah?->lot ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs">
                                    {{-- Kolomnya bernama tipe/nama_tipe, bukan nama --}}
                                    {{ $tipe?->nama_tipe ?? '—' }}
                                    @if ($tipe?->luas_bangunan && $tipe?->luas_tanah)
                                        <div class="text-[11px] text-zinc-400">
                                            {{ (int) $tipe->luas_bangunan }}/{{ (int) $tipe->luas_tanah }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-medium">{{ $spr->rumah?->blok }}-{{ $spr->rumah?->nomor_unit }}</td>
                                <td class="px-3 py-2">{{ $spr->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs">{{ $spr->rumah?->subcon?->nama ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-xs">
                                    {{ $spr->rumah?->progres_fisik !== null
                                        ? $spr->rumah->progres_fisik.'%'
                                        : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-xs">
                                    {{ $um ? number_format($um['seharusnya'], 0, ',', '.') : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-xs">
                                    {{ $um ? number_format($um['terkumpul'], 0, ',', '.') : '—' }}
                                </td>
                                <td @class([
                                    'px-3 py-2 text-right font-mono tabular-nums text-xs',
                                    'font-semibold text-amber-600 dark:text-amber-400' => $um && $um['kurang'] > 0,
                                ])>
                                    {{-- Kekurangan UM tidak menghalangi, tapi harus terlihat --}}
                                    {{ $um && $um['kurang'] > 0 ? number_format($um['kurang'], 0, ',', '.') : '–' }}
                                </td>
                                <td class="px-3 py-2 text-xs">{{ $u->hgb ?: '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-xs">
                                    {{ $spr->pemberkasan?->sp3k_nominal
                                        ? number_format((float) $spr->pemberkasan->sp3k_nominal, 0, ',', '.')
                                        : '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">{{ $spr->sales?->nama ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <flux:badge :color="$warnaUnit" size="sm">{{ $labelUnit }}</flux:badge>
                                    @if ($u->alasan_batal)
                                        <div class="mt-0.5 text-[11px] text-zinc-500">{{ $u->alasan_batal }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($rencana->bolehDiubah() && $bolehKelola)
                                        <select wire:change="ubahJenisAkad({{ $u->id }}, $event.target.value)"
                                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800">
                                            @foreach (\App\Models\Master\RencanaAkadUnit::LABEL_JENIS_AKAD as $kunci => $label)
                                                <option value="{{ $kunci }}" @selected($u->jenis_akad === $kunci)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        {{ \App\Models\Master\RencanaAkadUnit::LABEL_JENIS_AKAD[$u->jenis_akad] ?? $u->jenis_akad }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if ($rencana->bolehDiubah() && $bolehKelola)
                                        @if ($konfirmasiHapusUnitId === $u->id)
                                            <div class="flex justify-end gap-1">
                                                <flux:button size="xs" variant="danger" wire:click="hapusUnit">Keluarkan</flux:button>
                                                <flux:button size="xs" variant="ghost" wire:click="batalHapusUnit">Batal</flux:button>
                                            </div>
                                        @else
                                            <flux:button size="xs" variant="ghost" icon="trash"
                                                         wire:click="konfirmasiHapusUnit({{ $unitId }})" />
                                        @endif
                                    @elseif ($u->status !== 'batal' && $bolehKelola)
                                        {{-- Rencana sudah terkunci, tapi konsumen bisa saja tidak hadir --}}
                                        <flux:button size="xs" variant="ghost" icon="x-circle"
                                                     title="Batal akad unit ini"
                                                     wire:click="openBatalUnit({{ $unitId }})" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="16" class="px-3 py-10 text-center text-sm italic text-zinc-400">
                                    Belum ada unit. Klik <strong>Tambah Rumah</strong> untuk mulai mengisi.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($rencana->unit->isNotEmpty())
                        <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800">
                            <tr class="font-semibold">
                                <td colspan="15" class="px-3 py-2 text-right">Jumlah Unit</td>
                                <td class="px-3 py-2 text-center">{{ $rencana->unit->count() }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- TOMBOL ALUR --}}
            <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                @if ($rencana->status === 'draft' && $bolehKelola)
                    <flux:button variant="primary" icon="paper-airplane" wire:click="openAksi('diajukan')"
                                 :disabled="$rencana->unit->isEmpty()">
                        Ajukan Rencana
                    </flux:button>
                @elseif ($rencana->status === 'diajukan')
                    @if ($bolehMengetahui)
                        <flux:button variant="ghost" wire:click="openAksi('draft')">Kembalikan ke Draft</flux:button>
                        <flux:button variant="primary" icon="check-badge" wire:click="openAksi('diketahui')">
                            Ketahui Rencana (PM)
                        </flux:button>
                    @else
                        <span class="text-sm text-zinc-500">Menunggu Project Manager mengetahui rencana ini.</span>
                    @endif
                @elseif ($rencana->status === 'diketahui')
                    @if ($bolehKelola || $bolehMengetahui || $bolehApprove)
                        <flux:button variant="ghost" wire:click="openAksi('draft')">Kembalikan ke Draft</flux:button>
                        <flux:button variant="primary" icon="paper-airplane" wire:click="openKirimDireksi">
                            Kirim Persetujuan ke Direksi
                        </flux:button>
                    @endif
                    @if ($bolehApprove)
                        <flux:button variant="filled" icon="lock-closed" wire:click="openAksi('fix')">
                            Setujui Manual
                        </flux:button>
                    @endif
                @elseif ($rencana->status === 'fix')
                    <span class="text-sm font-medium text-emerald-700 dark:text-emerald-400">Sudah Selesai.</span>
                @endif

                @if (! in_array($rencana->status, ['fix', 'batal'], true) && ($bolehKelola || $bolehMengetahui || $bolehApprove))
                    <flux:button variant="ghost" wire:click="openAksi('batal')">Batalkan</flux:button>
                @endif
            </div>

            {{-- BIAYA PROSES (Aju Dana) --}}
            @if ($rencana->unit->isNotEmpty())
                <div class="mt-6 max-w-md rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-start justify-between gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                        <div>
                            <div class="text-sm font-semibold">Biaya Proses</div>
                            <div class="text-xs text-zinc-500">
                                Dana yang perlu diajukan ke Finance untuk {{ $biaya['unit'] }} unit
                            </div>
                        </div>
                        @if ($bolehUbahBiayaSesi)
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openBiayaSesi">
                                Ubah biaya sesi
                            </flux:button>
                        @endif
                    </div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($biaya['baris'] as $b)
                                <tr>
                                    <td class="px-4 py-1.5">
                                        {{ $b['nama'] }}
                                        @if ($b['per_unit'])
                                            <span class="text-[11px] text-zinc-400">(dijumlah dari unit)</span>
                                        @else
                                            <span class="text-[11px] text-zinc-400">(biaya sesi)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-1.5 text-right font-mono tabular-nums">
                                        {{ $b['nominal'] > 0 ? number_format($b['nominal'], 0, ',', '.') : '–' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 font-bold dark:border-zinc-600 dark:bg-zinc-800">
                            <tr>
                                <td class="px-4 py-2">Jumlah</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums">
                                    {{ number_format($biaya['jumlah'], 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    @if ($bolehUbahBiayaSesi)
                        <div class="border-t border-zinc-200 bg-amber-50 px-4 py-2 text-[11px] text-amber-800 dark:border-zinc-700 dark:bg-amber-950/30 dark:text-amber-200">
                            @if ($rencana->status === 'diajukan')
                                Rencana sudah diajukan — biaya sesi bisa dikoreksi PM sebelum diketahui. Setelah PM setujui, biaya terkunci.
                            @elseif ($rencana->status === 'diketahui')
                                Rencana sudah diketahui PM — Direksi bisa mengoreksi biaya sesi sebelum menandatangani. Setelah Selesai, biaya terkunci.
                            @else
                                Biaya sesi masih bisa diubah selama status Draft.
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            {{-- RIWAYAT ATURAN --}}
            <div class="mt-6 border-t border-zinc-200 pt-4 text-xs text-zinc-500 dark:border-zinc-700">
                <p class="mb-1 font-semibold">Syarat unit boleh dijadwalkan akad:</p>
                <ul class="ml-4 list-disc space-y-0.5">
                    <li>SPR sudah disetujui</li>
                    <li>Unit belum dijadwalkan di rencana lain yang masih berjalan</li>
                    <li>Sertifikat boleh belum ada, titipan boleh nol</li>
                    <li>Uang muka boleh belum lunas &mdash; kekurangannya muncul di kolom
                        <strong>Target Masuk</strong> dan dilunasi sambil proses berjalan</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- MODAL TAMBAH UNIT --}}
    <flux:modal name="tambah-unit" @class(['max-w-5xl'])>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Tambah Rumah</flux:heading>
                <flux:subheading>
                    Unit yang belum memenuhi syarat tetap ditampilkan berikut sebabnya.
                </flux:subheading>
            </div>

            <flux:input wire:model.live.debounce.300ms="cariUnit" icon="magnifying-glass"
                        placeholder="Cari nomor SPR, nama konsumen, atau blok..." />

            <div class="max-h-96 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-zinc-50 dark:bg-zinc-800">
                        @php
                            $kolomUrut = [
                                'unit' => ['Unit', ''],
                                'konsumen' => ['Konsumen', ''],
                                'um' => ['UM', 'justify-end'],
                                'bangunan' => ['Bangunan', ''],
                                'berkas' => ['Berkas', ''],
                            ];
                        @endphp
                        <tr class="text-left text-xs uppercase text-zinc-500 dark:text-zinc-400">
                            @foreach ($kolomUrut as $kunci => [$judul, $rata])
                                <th class="px-3 py-2 font-semibold">
                                    <button type="button" wire:click="urutkanKandidat('{{ $kunci }}')"
                                            @class([
                                                '-mx-1 -my-0.5 inline-flex w-full items-center gap-1 rounded px-1.5 py-0.5 uppercase transition hover:bg-zinc-100 dark:hover:bg-zinc-700',
                                                $rata,
                                                'text-zinc-900 dark:text-white' => $urutKandidat === $kunci,
                                            ])>
                                        <span>{{ $judul }}</span>
                                        @if ($urutKandidat === $kunci)
                                            @if ($arahKandidat === 'asc')
                                                <flux:icon.chevron-up class="size-3" />
                                            @else
                                                <flux:icon.chevron-down class="size-3" />
                                            @endif
                                        @else
                                            <flux:icon.chevron-up-down class="size-3 opacity-40" />
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                            <th class="px-3 py-2 font-semibold">Keterangan</th>
                            <th class="w-20 px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($kandidat as $k)
                            @php
                                $spr = $k['spr'];
                                $sprId = $spr->id;
                                $berkas = $spr->pemberkasan;
                                $tahap = $berkas?->tahapTerakhir();
                                $sisaSp3k = $berkas?->sp3kExpiredIn();

                                // Nol disebut "belum dicatat", bukan "belum dibangun" —
                                // kolomnya berdefault nol dan sebagian besar unit
                                // bernilai nol karena datanya memang belum diisi.
                                // Istilah yang sama dipakai dashboard teknik.
                                $progres = (int) ($spr->rumah?->progres_fisik ?? 0);
                            @endphp
                            <tr wire:key="kandidat-{{ $spr->id }}" @class(['opacity-60' => $k['alasan']])>
                                <td class="px-3 py-2 font-medium">
                                    {{ $spr->rumah?->blok }}-{{ $spr->rumah?->nomor_unit }}
                                </td>
                                <td class="px-3 py-2">{{ $spr->prospectCustomer?->nama_lengkap ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-xs">
                                    {{ number_format($k['um']['terkumpul'], 0, ',', '.') }}
                                    <div class="text-[11px] text-zinc-400">
                                        dari {{ number_format($k['um']['seharusnya'], 0, ',', '.') }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    @if ($progres === 0)
                                        <span class="text-xs text-zinc-400">belum dicatat</span>
                                    @else
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 w-16 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                                <div @class([
                                                    'h-full rounded-full',
                                                    'bg-emerald-500' => $progres >= 100,
                                                    'bg-amber-500' => $progres >= 50 && $progres < 100,
                                                    'bg-rose-500' => $progres < 50,
                                                ]) style="width: {{ max(2, min(100, $progres)) }}%"></div>
                                            </div>
                                            <span class="font-mono text-xs tabular-nums">{{ $progres }}%</span>
                                        </div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @if (! $tahap)
                                        <span class="text-zinc-400">belum ada</span>
                                    @else
                                        <div class="font-medium">{{ $tahap }}</div>
                                        <div class="text-[11px] text-zinc-400">
                                            {{ $berkas->progressCount() }}/{{ $berkas->totalTahap() }} tahap
                                            @if ($berkas->bank_kode)
                                                &middot; {{ $berkas->bank_kode }}
                                            @endif
                                        </div>
                                        @if ($sisaSp3k !== null && $sisaSp3k < 0)
                                            <div class="text-[11px] font-semibold text-rose-600 dark:text-rose-400">
                                                SP3K lewat {{ abs($sisaSp3k) }} hari
                                            </div>
                                        @elseif ($sisaSp3k !== null && $sisaSp3k <= 30)
                                            <div class="text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                                                SP3K sisa {{ $sisaSp3k }} hari
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($k['alasan'])
                                        <span class="text-amber-600 dark:text-amber-400">{{ $k['alasan'] }}</span>
                                    @else
                                        <span class="text-emerald-600 dark:text-emerald-400">Siap dijadwalkan</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if (! $k['alasan'])
                                        <flux:button size="xs" variant="primary"
                                                     wire:click="tambahUnit({{ $sprId }})">
                                            Tambah
                                        </flux:button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-sm italic text-zinc-400">
                                    Tidak ada unit yang cocok.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Selesai</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL BATAL AKAD PER UNIT --}}
    <flux:modal name="batal-unit" @class(['max-w-md'])>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Batal Akad Unit Ini</flux:heading>
                <flux:subheading>
                    Unit lain di rencana ini tetap berjalan. Unit yang dibatalkan bebas
                    dijadwalkan di rencana berikutnya, dan tidak ikut ditagihkan ke Finance.
                </flux:subheading>
            </div>

            <flux:textarea wire:model="alasanBatalUnit" rows="3" label="Alasan"
                           placeholder="mis. konsumen tidak hadir di hari akad" />
            @error('alasanBatalUnit')<div class="text-xs text-rose-600">{{ $message }}</div>@enderror

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="batalkanUnit">Batalkan Akad Unit</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL KONFIRMASI STATUS --}}
    <flux:modal name="konfirmasi-status" @class(['max-w-md'])>
        @php
            $peta = [
                'diajukan' => ['Ajukan Rencana', 'Rencana diteruskan ke Project Manager untuk disetujui. Setelah diajukan, unitnya tidak bisa diubah lagi.'],
                'diketahui' => ['Ketahui Rencana', 'Rencana disetujui Project Manager. Setelah ini, akan disiapkan link persetujuan untuk Direksi.'],
                'fix' => ['Setujui & Selesaikan', 'Rencana disetujui Direksi dan tanggal akad dikunci. Tanggal ini otomatis mengisi kolom Rencana Akad di Pemberkasan.'],
                'draft' => ['Kembalikan ke Draft', 'Rencana dikembalikan supaya bisa diperbaiki. Sebutkan apa yang perlu dibetulkan.'],
                'batal' => ['Batalkan Rencana', 'Rencana dibatalkan dan seluruh unitnya bebas dijadwalkan ulang.'],
            ];
            $info = $peta[$aksiStatus ?? 'diajukan'] ?? $peta['diajukan'];
        @endphp
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $info[0] }}</flux:heading>
                <flux:subheading>{{ $info[1] }}</flux:subheading>
            </div>

            @if ($aksiStatus === 'fix')
                <flux:input type="date" wire:model="tanggalFix" label="Tanggal Akad (dari bank)" />
            @endif

            @if (in_array($aksiStatus, ['draft', 'batal'], true))
                <flux:textarea wire:model="alasanTolak" rows="3"
                               label="Alasan" placeholder="Sebutkan alasannya..." />
                @error('alasanTolak')<div class="text-xs text-rose-600">{{ $message }}</div>@enderror
            @endif

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="jalankanAksi">{{ $info[0] }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL UBAH BIAYA SESI --}}
    <flux:modal name="form-biaya-sesi" @class(['max-w-2xl'])>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Ubah Biaya Sesi</flux:heading>
                <flux:subheading>
                    Kebutuhan sesi akad — nama &amp; nominal bebas.
                    Yang <strong>dijumlah dari unit</strong> (By Proses Akad, BPHTB, PPh 4(2)) di-hitung otomatis dari data per unit, jadi tidak ada di sini.
                </flux:subheading>
            </div>

            {{-- TABEL BARIS BIAYA SESI --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-[11px] font-semibold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th class="w-8 px-2 py-2 text-center">#</th>
                            <th class="px-2 py-2">Nama Kebutuhan</th>
                            <th class="w-40 px-2 py-2 text-right">Nominal</th>
                            <th class="w-10 px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($formBiayaSesi as $index => $baris)
                            <tr wire:key="biaya-sesi-{{ $index }}">
                                <td class="px-2 py-1.5 text-center text-xs text-zinc-500">{{ $index + 1 }}</td>
                                <td class="px-2 py-1.5">
                                    <flux:input size="sm" wire:model="formBiayaSesi.{{ $index }}.nama"
                                                placeholder="cth: Dana Koordinasi / Sewa Kursi / Snack..." />
                                    @error("formBiayaSesi.{$index}.nama")
                                        <div class="mt-0.5 text-[11px] text-rose-600">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td class="px-2 py-1.5">
                                    {{--
                                        Input nominal dengan format ribuan bertitik supaya gampang dibaca
                                        (225.000 lebih jelas dari 225000). Alpine.js menghandle format tampilan;
                                        yang disimpan ke Livewire tetap digit polos supaya validasi numeric jalan.
                                    --}}
                                    <div x-data="{
                                        display: '',
                                        format(v) {
                                            v = String(v ?? '').replace(/\D/g, '');
                                            return v ? v.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
                                        },
                                        onInput(e) {
                                            const digits = e.target.value.replace(/\D/g, '');
                                            this.display = this.format(digits);
                                            $wire.set('formBiayaSesi.{{ $index }}.nominal', digits, false);
                                        }
                                    }"
                                    x-init="display = format(@js((string) ($baris['nominal'] ?? '')))"
                                    class="w-full">
                                        <input type="text" inputmode="numeric"
                                               :value="display"
                                               @input="onInput($event)"
                                               @blur="$wire.$refresh()"
                                               placeholder="0"
                                               class="block w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-right font-mono text-sm tabular-nums placeholder:text-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                    </div>
                                    @error("formBiayaSesi.{$index}.nominal")
                                        <div class="mt-0.5 text-right text-[11px] text-rose-600">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td class="px-2 py-1.5 text-center">
                                    <flux:button size="xs" variant="ghost" icon="trash"
                                                 wire:click="hapusBarisBiayaSesi({{ $index }})"
                                                 class="text-rose-500 hover:bg-rose-50 hover:text-rose-700" />
                                </td>
                            </tr>
                        @endforeach
                        @if (empty($formBiayaSesi))
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-xs text-zinc-400">
                                    Belum ada baris. Klik <em>Tambah baris</em> untuk mulai.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between">
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="tambahBarisBiayaSesi">
                    Tambah baris
                </flux:button>
                @if (! empty($formBiayaSesi))
                    <div class="text-xs text-zinc-500">
                        Subtotal biaya sesi:
                        <span class="ml-1 font-mono font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">
                            {{ number_format((float) collect($formBiayaSesi)->sum(fn ($b) => (float) ($b['nominal'] ?? 0)), 0, ',', '.') }}
                        </span>
                    </div>
                @endif
            </div>

            <div class="rounded-md bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                @if ($rencana->status === 'diajukan')
                    <strong>Kamu sedang mengoreksi rencana yang sudah diajukan.</strong>
                    Setelah kamu tekan <em>Ketahui Rencana</em>, angka ini terkunci sampai Direksi mengoreksinya.
                @elseif ($rencana->status === 'diketahui')
                    <strong>Kamu sedang mengoreksi rencana yang sudah diketahui PM.</strong>
                    Setelah kamu tekan <em>Setujui &amp; Selesaikan</em>, angka ini terkunci.
                @else
                    Angka otomatis dikasih titik pemisah ribuan biar gampang dibaca (mis. <span class="font-mono">225.000</span>).
                    Baris kosong (nama &amp; nominal 0) akan otomatis diabaikan saat disimpan.
                @endif
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">Batal</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="simpanBiayaSesi">Simpan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- MODAL: KIRIM PERSETUJUAN KE DIREKSI (WA magic link universal) --}}
    <flux:modal name="kirim-direksi" @class(['max-w-lg'])>
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">
                    <flux:icon.paper-airplane class="size-5" />
                </div>
                <div>
                    <flux:heading size="lg">Kirim Persetujuan ke Direksi</flux:heading>
                    <flux:subheading>
                        Salin link atau pesan di bawah, lalu kirim via WhatsApp ke
                        <strong>Bapak Haryanto</strong> atau <strong>Bapak Julianto Boentaran</strong>
                        (siapa pun yang hadir). Direksi cukup gambar tanda tangan di layar.
                    </flux:subheading>
                </div>
            </div>

            @php
                $pesanWa = "🏠 *ERP LMI — Persetujuan Rencana Akad*\n\n"
                    ."Yth. Bapak Direksi,\n\n"
                    ."Mohon persetujuan atas rencana akad berikut:\n\n"
                    ."📋 Nomor: {$rencana->nomor}\n"
                    ."🏘 Proyek: ".($rencana->proyek?->nama_proyek ?? '-')."\n"
                    ."🏦 Bank: ".($rencana->bank?->nama ?? '-')."\n"
                    ."📅 Tanggal: ".($rencana->tanggal_rencana?->translatedFormat('d M Y') ?? '-')."\n"
                    ."👥 Jumlah unit: {$rencana->unit->count()} unit\n\n"
                    ."👉 Cek & tanda tangan di:\n{$urlDireksi}\n\n"
                    ."_Link berlaku 7 hari. Hubungi Admin KPR bila ada pertanyaan._";

                $urlWa = 'https://wa.me/?text='.rawurlencode($pesanWa);
            @endphp

            <div class="space-y-3">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm dark:border-emerald-800 dark:bg-emerald-950/30">
                    ✓ Link berlaku sampai
                    <strong>{{ ($rencana->direksi_link_generated_at ?? now())->addDays(7)->translatedFormat('d F Y H:i') }}</strong>.
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold text-zinc-500">URL Persetujuan</label>
                    <div class="flex gap-2">
                        <input type="text" readonly value="{{ $urlDireksi }}"
                               class="flex-1 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-800"
                               onclick="this.select();" />
                        <flux:button size="sm" variant="filled" icon="clipboard-document"
                                     x-data="{}"
                                     x-on:click="navigator.clipboard.writeText($event.currentTarget.previousElementSibling.value); $event.currentTarget.innerText='Tersalin!';">
                            Copy
                        </flux:button>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold text-zinc-500">Pesan WhatsApp</label>
                    <textarea readonly rows="8"
                              class="w-full rounded-md border border-zinc-300 bg-zinc-50 p-3 text-xs dark:border-zinc-600 dark:bg-zinc-800"
                              onclick="this.select();">{{ $pesanWa }}</textarea>
                    <div class="mt-1 text-[11px] text-zinc-400">
                        Klik textarea untuk highlight semuanya, atau tombol "Buka WhatsApp" di bawah untuk kirim langsung.
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button variant="ghost" icon="arrow-path" wire:click="regenerateLinkDireksi">
                    Regenerate Link
                </flux:button>
                <flux:modal.close><flux:button variant="ghost">Tutup</flux:button></flux:modal.close>
                <a href="{{ $urlWa }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                    <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.174.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    Buka WhatsApp
                </a>
            </div>
        </div>
    </flux:modal>
</section>
