<?php

use App\Models\Master\Spr;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard Admin KPR — posisi tiap berkas, mana yang mendesak, dan berapa lama
 * tiap tahap biasanya ditempuh.
 *
 * Dua hal yang sengaja dihindari:
 *
 * 1. Diagram tahapan yang menampilkan "BM: 0". Data historis hanya memuat tanggal
 *    SP3K; tahap lain kosong bukan karena dilewati tapi karena belum pernah
 *    dicatat. Karena itu kartu tahap dihitung dari yang datanya ada saja, dan
 *    kinerja selalu menyebut dari berapa berkas angkanya diambil.
 *
 * 2. Rata-rata untuk durasi. Satu tanggal salah ketik — dan di data berjalan ada
 *    yang tahunnya 0026 — sudah cukup menggeser rata-rata sampai tidak berarti.
 *    Yang dipakai median: satu-dua tanggal aneh tidak menggerakkannya.
 */
new #[Title('Dashboard')] class extends Component
{
    /** Selisih hari yang dianggap salah catat, bukan lama pengerjaan sebenarnya. */
    private const BATAS_WAJAR_HARI = 1095;

    public ?int $filterProyek = null;

    public function mount(): void
    {
        $this->filterProyek = session('active_proyek_id');
    }

    #[On('active-proyek-changed')]
    public function syncFromGlobalPicker(int $proyekId): void
    {
        $this->filterProyek = $proyekId;
    }

    /** SPR aktif (sudah disetujui, belum akad) — ini yang jadi tanggung jawab admin KPR. */
    protected function sprAktif()
    {
        return Spr::query()
            ->where('spr.status', 'approved')
            ->when($this->filterProyek, fn ($q) => $q->whereHas('rumah', fn ($r) => $r->where('proyek_id', $this->filterProyek)));
    }

    /**
     * Berkas milik SPR yang sudah disetujui.
     *
     * $termasukAkad dipakai untuk hitungan kinerja: yang sudah akad justru berkas
     * yang perjalanannya paling lengkap, jadi tidak boleh dibuang dari rata-rata
     * lama pengerjaan — meskipun tidak lagi masuk daftar yang perlu dikejar.
     */
    protected function berkas(bool $termasukAkad = false)
    {
        return DB::table('spr_pemberkasan as pb')
            ->join('spr as s', 's.id', '=', 'pb.spr_id')
            ->when($this->filterProyek, fn ($q) => $q->join('rumah as rf', 'rf.id', '=', 's.rumah_id')->where('rf.proyek_id', $this->filterProyek))
            ->whereIn('s.status', $termasukAkad ? ['approved', 'akad'] : ['approved']);
    }

    /**
     * Lama tempuh antar dua tanggal, dalam hari.
     *
     * Dikembalikan median beserta jumlah berkas yang ikut dihitung, supaya angka
     * yang cuma berdasar tiga berkas tidak terbaca sederajat dengan yang berdasar
     * seratus. Selisih negatif dan yang melebihi batas wajar dibuang — itu salah
     * catat, bukan lama pengerjaan.
     *
     * @return array{median: ?int, jumlah: int}
     */
    protected function lamaTempuh(string $dari, string $ke): array
    {
        $selisih = $this->berkas(termasukAkad: true)
            ->whereNotNull($dari)
            ->whereNotNull($ke)
            ->get([DB::raw("$dari as mulai"), DB::raw("$ke as selesai")])
            ->map(function ($b) {
                try {
                    $mulai = \Carbon\CarbonImmutable::parse($b->mulai)->startOfDay();
                    $selesai = \Carbon\CarbonImmutable::parse($b->selesai)->startOfDay();
                } catch (\Throwable) {
                    return null;
                }

                $hari = (int) $mulai->diffInDays($selesai, false);

                return $hari >= 0 && $hari <= self::BATAS_WAJAR_HARI ? $hari : null;
            })
            ->filter(fn ($h) => $h !== null)
            ->sort()
            ->values();

        if ($selisih->isEmpty()) {
            return ['median' => null, 'jumlah' => 0];
        }

        $tengah = intdiv($selisih->count(), 2);

        return [
            'median' => $selisih->count() % 2
                ? $selisih[$tengah]
                : (int) round(($selisih[$tengah - 1] + $selisih[$tengah]) / 2),
            'jumlah' => $selisih->count(),
        ];
    }

    public function with(): array
    {
        $hariIni = now()->startOfDay();
        $batas30 = $hariIni->copy()->addDays(30);

        // Belum ada berkas = SPR disetujui yang baris pemberkasannya belum dibuka,
        // ditambah yang barisnya sudah ada tapi tanggal berkas masuknya masih kosong.
        $tanpaBerkas = (clone $this->sprAktif())
            ->where(fn ($q) => $q->whereDoesntHave('pemberkasan')
                ->orWhereHas('pemberkasan', fn ($p) => $p->whereNull('bm_tanggal')))
            ->count();

        // Tahapan dibuat saling lepas supaya satu berkas hanya dihitung di satu kartu.
        $menungguWawancara = $this->berkas()->whereNotNull('pb.bm_tanggal')->whereNull('pb.wcr_tanggal')->count();
        $menungguSp3k = $this->berkas()->whereNotNull('pb.wcr_tanggal')->whereNull('pb.sp3k_tanggal')->count();
        $sp3kTerbit = $this->berkas()->whereNotNull('pb.sp3k_tanggal')->count();

        $sp3kLewat = $this->berkas()->whereNotNull('pb.sp3k_expired')->where('pb.sp3k_expired', '<', $hariIni)->count();
        $sp3kSegera = $this->berkas()->whereNotNull('pb.sp3k_expired')->whereBetween('pb.sp3k_expired', [$hariIni, $batas30])->count();

        $kinerja = [
            ['label' => 'Berkas → Wawancara', 'data' => $this->lamaTempuh('pb.bm_tanggal', 'pb.wcr_tanggal')],
            ['label' => 'Wawancara → SP3K', 'data' => $this->lamaTempuh('pb.wcr_tanggal', 'pb.sp3k_tanggal')],
            ['label' => 'SP3K → Akad', 'data' => $this->lamaTempuh('pb.sp3k_tanggal', 's.tgl_akad')],
            ['label' => 'Berkas → Akad', 'data' => $this->lamaTempuh('pb.bm_tanggal', 's.tgl_akad')],
        ];

        $perluTindakan = $this->berkas()
            ->join('rumah as r', 'r.id', '=', 's.rumah_id')
            ->leftJoin('prospect_customer as pc', 'pc.id', '=', 's.prospect_customer_id')
            ->whereNotNull('pb.sp3k_expired')
            ->where('pb.sp3k_expired', '<=', $batas30)
            ->orderBy('pb.sp3k_expired')
            ->limit(10)
            ->get([
                DB::raw("CONCAT(r.blok,'-',r.nomor_unit) as unit"),
                'pc.nama_lengkap as customer',
                'pb.bank_kode',
                'pb.sp3k_expired',
            ]);

        $perBank = $this->berkas()
            ->groupBy('pb.bank_kode')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['pb.bank_kode', DB::raw('COUNT(*) as jml')]);

        return compact(
            'tanpaBerkas', 'sp3kLewat', 'sp3kSegera',
            'menungguWawancara', 'menungguSp3k', 'sp3kTerbit', 'kinerja',
            'perluTindakan', 'perBank'
        );
    }
}; ?>

<section class="w-full">
    <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">

        {{-- HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-dashboard-switcher current="kpr" />
            </div>
        </div>

        {{-- KARTU PER TAHAP --}}
        @php
            $kartu = [
                ['Belum Ada Berkas', $tanpaBerkas, 'folder-open', 'rose', 'SPR disetujui, berkasnya belum masuk'],
                ['Menunggu Wawancara', $menungguWawancara, 'chat-bubble-left-right', 'amber', 'Berkas sudah masuk, wawancara belum jalan'],
                ['Menunggu SP3K', $menungguSp3k, 'clock', 'sky', 'Sudah wawancara, keputusan bank belum turun'],
                ['SP3K Terbit', $sp3kTerbit, 'check-badge', 'emerald', 'Disetujui bank, tinggal menuju akad'],
                ['Jatuh Tempo 30 Hari', $sp3kSegera, 'bell-alert', 'orange', 'Masih bisa dikejar akadnya'],
                ['Lewat Tanggal', $sp3kLewat, 'x-circle', 'red', 'Harus diurus ulang ke bank sebelum akad'],
            ];

            // Kelas ditulis utuh, bukan dirangkai dari variabel — Tailwind memindai
            // berkas sebagai teks dan tidak membuatkan kelas yang baru terbentuk
            // saat halaman dijalankan.
            $nada = [
                'rose' => ['border-rose-200 from-rose-50 dark:border-rose-900/40 dark:from-rose-950/30', 'text-rose-700 dark:text-rose-400', 'bg-rose-600'],
                'amber' => ['border-amber-200 from-amber-50 dark:border-amber-900/40 dark:from-amber-950/30', 'text-amber-700 dark:text-amber-400', 'bg-amber-600'],
                'sky' => ['border-sky-200 from-sky-50 dark:border-sky-900/40 dark:from-sky-950/30', 'text-sky-700 dark:text-sky-400', 'bg-sky-600'],
                'emerald' => ['border-emerald-200 from-emerald-50 dark:border-emerald-900/40 dark:from-emerald-950/30', 'text-emerald-700 dark:text-emerald-400', 'bg-emerald-600'],
                'orange' => ['border-orange-200 from-orange-50 dark:border-orange-900/40 dark:from-orange-950/30', 'text-orange-700 dark:text-orange-400', 'bg-orange-600'],
                'red' => ['border-red-200 from-red-50 dark:border-red-900/40 dark:from-red-950/30', 'text-red-700 dark:text-red-400', 'bg-red-600'],
            ];
        @endphp

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
            @foreach ($kartu as [$judul, $jumlah, $ikon, $warna, $ket])
                @php
                    [$bingkai, $teks, $latar] = $nada[$warna];
                @endphp
                <div class="rounded-xl border bg-linear-to-br to-white p-4 shadow-sm dark:to-zinc-900 {{ $bingkai }}">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wide {{ $teks }}">{{ $judul }}</div>
                            <div class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($jumlah) }}</div>
                        </div>
                        <div class="rounded-lg p-2 text-white shadow-sm {{ $latar }}">
                            <flux:icon :name="$ikon" class="size-5" />
                        </div>
                    </div>
                    <div class="mt-2 text-[10px] text-zinc-500">{{ $ket }}</div>
                </div>
            @endforeach
        </div>

        {{-- KINERJA — lama tempuh tiap tahap --}}
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <flux:icon.chart-bar class="size-5 text-indigo-600" />
                <h2 class="text-base font-bold">Kinerja Pemberkasan</h2>
                <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-zinc-500">
                    Median, dihitung dari berkas yang kedua tanggalnya tercatat
                </span>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ($kinerja as $k)
                    <div class="rounded-lg border border-zinc-100 px-3 py-3 dark:border-zinc-800">
                        <div class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ $k['label'] }}</div>
                        @if ($k['data']['median'] === null)
                            <div class="mt-1 text-sm text-zinc-400">Belum ada datanya</div>
                        @else
                            <div class="mt-1 font-mono text-2xl font-bold tabular-nums">
                                {{ number_format($k['data']['median']) }}<span class="ml-1 text-xs font-normal text-zinc-500">hari</span>
                            </div>
                            <div class="mt-1 text-[10px] text-zinc-500">dari {{ number_format($k['data']['jumlah']) }} berkas</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- DAFTAR PERLU TINDAKAN --}}
            <div class="lg:col-span-2 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.exclamation-triangle class="size-5 text-rose-600" />
                    <h2 class="text-base font-bold">Paling Mendesak</h2>
                    <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-zinc-500">SP3K ≤ 30 hari</span>
                </div>

                @if ($perluTindakan->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Tidak ada berkas yang mendesak.</div>
                @else
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold">Unit</th>
                                    <th class="px-3 py-2 text-left font-semibold">Konsumen</th>
                                    <th class="px-3 py-2 text-left font-semibold">Bank</th>
                                    <th class="px-3 py-2 text-left font-semibold">Berlaku Sampai</th>
                                    <th class="px-3 py-2 text-right font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($perluTindakan as $b)
                                    @php
                                        $tgl = \Carbon\CarbonImmutable::parse($b->sp3k_expired);
                                        $sisa = (int) now()->startOfDay()->diffInDays($tgl->startOfDay(), false);
                                    @endphp
                                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                        <td class="whitespace-nowrap px-3 py-2 font-semibold">{{ $b->unit }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $b->customer ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-zinc-500">{{ $b->bank_kode ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-zinc-600 dark:text-zinc-400">{{ $tgl->translatedFormat('d M Y') }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                                'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400' => $sisa < 0,
                                                'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400' => $sisa >= 0,
                                            ])>{{ $sisa < 0 ? 'lewat '.abs($sisa).' hari' : 'sisa '.$sisa.' hari' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- SEBARAN BANK --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.building-library class="size-5 text-blue-600" />
                    <h2 class="text-base font-bold">Sebaran Bank</h2>
                </div>
                @if ($perBank->isEmpty())
                    <div class="py-8 text-center text-sm text-zinc-500">Belum ada berkas.</div>
                @else
                    <div class="space-y-2">
                        @foreach ($perBank as $b)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                <span class="text-sm font-semibold">{{ $b->bank_kode ?? 'Belum ditentukan' }}</span>
                                <span class="font-mono text-sm font-bold tabular-nums">{{ number_format($b->jml) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

    </div>
</section>
