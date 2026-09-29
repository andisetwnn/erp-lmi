<?php

namespace App\Services;

use App\Models\Master\Proyek;
use App\Models\Master\RencanaAkad;
use App\Models\Master\RencanaAkadUnit;
use App\Models\Master\Spr;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan Rencana Akad: penomoran, kelayakan unit, dan perpindahan status.
 *
 * SYARAT UNIT:
 * - SPR sudah disetujui
 * - unit belum dipegang rencana lain yang masih berjalan
 *
 * Uang muka SENGAJA tidak dijadikan penghalang. Lembar Aju Dana yang selama ini
 * dipakai memuat unit dengan UM baru 16% — kekurangannya dicatat di kolom
 * "Target Masuk UM" dan dilunasi sambil proses berjalan. Aturan tertulis "UM 100%"
 * di sistem lama ternyata tidak dijalankan seperti itu di lapangan.
 *
 * Yang dilakukan di sini: menghitung dan menampilkan kekurangannya, supaya penyetuju
 * melihat angkanya sebelum memutuskan — bukan menyembunyikan unitnya.
 *
 * UTJ ikut dihitung sebagai uang muka yang sudah masuk, karena ia uang konsumen yang
 * dibayarkan lebih dulu.
 */
class RencanaAkadService
{
    /** Status SPR yang boleh dijadwalkan akad. */
    private const STATUS_SPR_LAYAK = ['approved', 'akad'];

    /**
     * Nomor berikutnya, mis. "67/GA/R.AKAD/01/2026".
     * Urutan direset tiap bulan per proyek, mengikuti pola surat yang sudah dipakai.
     */
    public function nomorBerikutnya(Proyek $proyek, string $tanggal): string
    {
        $tgl = Carbon::parse($tanggal);
        $kode = $proyek->kode_surat ?: 'XX';

        $urut = RencanaAkad::where('proyek_id', $proyek->id)
            ->whereYear('tanggal_rencana', $tgl->year)
            ->whereMonth('tanggal_rencana', $tgl->month)
            ->count() + 1;

        return sprintf('%d/%s/R.AKAD/%02d/%d', $urut, $kode, $tgl->month, $tgl->year);
    }

    /**
     * Hitungan uang muka satu SPR.
     *
     * @return array{seharusnya:float, um:float, utj:float, terkumpul:float, kurang:float, lunas:bool}
     */
    public function ringkasanUm(Spr $spr): array
    {
        $seharusnya = (float) $spr->um_net;
        $um = (float) $spr->realisasiPembayaran()->where('jenis', 'um')->sum('jumlah');
        $utj = (float) $spr->utj_nominal;
        $terkumpul = $um + $utj;

        return [
            'seharusnya' => $seharusnya,
            'um' => $um,
            'utj' => $utj,
            'terkumpul' => $terkumpul,
            'kurang' => (float) max(0, $seharusnya - $terkumpul),
            'lunas' => $seharusnya > 0 && $terkumpul >= $seharusnya,
        ];
    }

    /**
     * Alasan kenapa satu SPR tidak boleh dijadwalkan. Null berarti boleh.
     *
     * Uang muka yang belum lunas BUKAN penghalang — lihat catatan kelas. Kekurangannya
     * dilaporkan lewat ringkasanUm() supaya terlihat, bukan menggugurkan unitnya.
     */
    public function alasanTidakLayak(Spr $spr, ?RencanaAkad $kecuali = null): ?string
    {
        if (! in_array($spr->status, self::STATUS_SPR_LAYAK, true)) {
            return 'SPR belum disetujui (status: '.$spr->status.')';
        }

        $pemegang = $this->rencanaPemegang($spr, $kecuali);
        if ($pemegang) {
            return 'Sudah dijadwalkan di '.$pemegang->nomor.' ('.$pemegang->labelStatus().')';
        }

        return null;
    }

    public function layak(Spr $spr, ?RencanaAkad $kecuali = null): bool
    {
        return $this->alasanTidakLayak($spr, $kecuali) === null;
    }

    /**
     * Rencana aktif yang sedang memegang unit ini, kalau ada.
     *
     * Unit yang dibatalkan (konsumen tidak hadir) tidak dihitung memegang — justru
     * itu gunanya dibatalkan: supaya bisa dijadwalkan ulang.
     */
    public function rencanaPemegang(Spr $spr, ?RencanaAkad $kecuali = null): ?RencanaAkad
    {
        return RencanaAkad::query()
            ->whereIn('status', RencanaAkad::STATUS_MENGUNCI)
            ->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali->getKey()))
            ->whereHas('unit', fn ($q) => $q->where('spr_id', $spr->id)->where('status', '!=', 'batal'))
            ->first();
    }

    /**
     * Batalkan satu unit — konsumen tidak hadir di hari H. Unit lain tetap jalan,
     * dan unit ini bebas masuk rencana berikutnya.
     */
    public function batalkanUnit(RencanaAkadUnit $unit, string $alasan, int $userId): RencanaAkadUnit
    {
        if ($unit->status === 'batal') {
            throw ValidationException::withMessages(['unit' => 'Unit ini sudah dibatalkan.']);
        }

        $unit->update([
            'status' => 'batal',
            'alasan_batal' => $alasan,
            'dibatalkan_at' => now(),
            'dibatalkan_by_user_id' => $userId,
        ]);

        return $unit->fresh();
    }

    /**
     * Kotak "Biaya Proses" di lembar Aju Dana.
     *
     * Tiga baris pertama dijumlahkan dari unit yang tidak dibatalkan; sisanya biaya
     * sesi yang diketik sekali. Unit batal tidak ikut dibiayai.
     *
     * @return array{baris: array<int, array{nama:string, nominal:float, per_unit:bool}>, jumlah:float, unit:int, tanpa_tarif:int}
     */
    public function ringkasanBiaya(RencanaAkad $rencana): array
    {
        $aktif = $rencana->unit->where('status', '!=', 'batal');

        // Unit yang biaya proses akadnya belum terisi — biasanya karena tarif
        // banknya belum diisi di master. Dihitung terpisah supaya bisa
        // diperingatkan: nol di lembar Aju Dana terbaca sebagai "gratis",
        // padahal artinya "belum diketahui".
        $tanpaTarif = $aktif->whereNull('bayar_bi_notaris')->count();

        // Baris "dijumlah dari unit" — nominal per unit yang harus ada apa pun
        // isi biaya sesinya.
        $baris = [
            ['nama' => 'By Proses Akad', 'nominal' => (float) $aktif->sum('bayar_bi_notaris'), 'per_unit' => true],
            ['nama' => 'BPHTB', 'nominal' => (float) $aktif->sum('bayar_bphtb'), 'per_unit' => true],
            ['nama' => 'PPh 4 (2)', 'nominal' => (float) $aktif->sum('bayar_ps4a2'), 'per_unit' => true],
        ];

        // Baris biaya sesi — bebas, sesuai apa yang diketik user.
        foreach ($rencana->biayaSesi as $bs) {
            $baris[] = [
                'nama' => $bs->nama,
                'nominal' => (float) $bs->nominal,
                'per_unit' => false,
            ];
        }

        return [
            'baris' => $baris,
            'jumlah' => array_sum(array_column($baris, 'nominal')),
            'unit' => $aktif->count(),
            'tanpa_tarif' => $tanpaTarif,
        ];
    }

    /**
     * SPR yang siap dijadwalkan di satu proyek — sudah approved, UM lunas, dan
     * belum dipegang rencana lain.
     *
     * @return Collection<int, Spr>
     */
    public function unitSiap(int $proyekId, ?RencanaAkad $kecuali = null, string $cari = ''): Collection
    {
        return $this->kandidat($proyekId, $cari)
            ->get()
            ->filter(fn (Spr $spr) => $this->layak($spr, $kecuali))
            ->values();
    }

    /** Kolom yang boleh dipakai mengurutkan daftar kandidat. */
    public const URUT_KANDIDAT = ['unit', 'konsumen', 'um', 'bangunan', 'berkas'];

    /**
     * Semua kandidat beserta alasannya — dipakai layar penambahan unit supaya yang
     * belum memenuhi tetap terlihat berikut sebabnya, bukan hilang tanpa penjelasan.
     *
     * Yang layak selalu di atas, apa pun kolom yang dipilih: unit yang tidak layak
     * tidak punya tombol Tambah, jadi menaikkannya ke puncak hanya menyulitkan.
     * Kolom pilihan jadi kunci kedua.
     *
     * @return Collection<int, array{spr:Spr, um:array, alasan:?string}>
     */
    public function kandidatBeralasan(
        int $proyekId,
        ?RencanaAkad $kecuali = null,
        string $cari = '',
        string $urut = 'unit',
        string $arah = 'asc',
    ): Collection {
        $urut = in_array($urut, self::URUT_KANDIDAT, true) ? $urut : 'unit';
        $turun = $arah === 'desc';

        $daftar = $this->kandidat($proyekId, $cari)
            ->get()
            ->map(fn (Spr $spr) => [
                'spr' => $spr,
                'um' => $this->ringkasanUm($spr),
                'alasan' => $this->alasanTidakLayak($spr, $kecuali),
            ]);

        $kunci = fn (array $k) => match ($urut) {
            'konsumen' => (string) $k['spr']->prospectCustomer?->nama_lengkap,
            // Persentase, bukan nominal: uang muka tiap unit beda besarnya,
            // jadi "sudah 80%" lebih bisa dibandingkan daripada "sudah 12 juta".
            'um' => $k['um']['seharusnya'] > 0
                ? $k['um']['terkumpul'] / $k['um']['seharusnya']
                : 0.0,
            'bangunan' => (int) ($k['spr']->rumah?->progres_fisik ?? 0),
            'berkas' => $k['spr']->pemberkasan?->progressCount() ?? 0,
            // Nomor unit dijadikan angka supaya "AB-9" tidak jatuh sesudah "AB-10".
            default => sprintf('%s-%04d',
                (string) $k['spr']->rumah?->blok,
                (int) $k['spr']->rumah?->nomor_unit,
            ),
        };

        return $daftar
            ->sortBy([
                fn ($a, $b) => ($a['alasan'] === null ? 0 : 1) <=> ($b['alasan'] === null ? 0 : 1),
                fn ($a, $b) => $turun
                    ? $this->bandingkan($kunci($b), $kunci($a))
                    : $this->bandingkan($kunci($a), $kunci($b)),
            ])
            ->values();
    }

    /** Teks dibandingkan tanpa peduli huruf besar-kecil; angka secara numerik. */
    private function bandingkan(mixed $a, mixed $b): int
    {
        return is_string($a) && is_string($b)
            ? strcasecmp($a, $b)
            : $a <=> $b;
    }

    private function kandidat(int $proyekId, string $cari = ''): Builder
    {
        return Spr::query()
            ->whereIn('status', self::STATUS_SPR_LAYAK)
            ->whereHas('rumah', fn ($q) => $q->where('proyek_id', $proyekId))
            ->with(['rumah.tipeRumah', 'prospectCustomer', 'sales', 'pemberkasan'])
            ->when($cari !== '', function ($q) use ($cari) {
                $q->where(function ($qq) use ($cari) {
                    $qq->where('nomor_spr', 'like', "%$cari%")
                        ->orWhereHas('prospectCustomer', fn ($p) => $p->where('nama_lengkap', 'like', "%$cari%"))
                        ->orWhereHas('rumah', fn ($r) => $r->where('blok', 'like', "%$cari%"));
                });
            })
            ->orderBy('nomor_spr');
    }

    /** Lampirkan satu unit. Menolak kalau syaratnya belum terpenuhi. */
    public function tambahUnit(RencanaAkad $rencana, Spr $spr): RencanaAkadUnit
    {
        if (! $rencana->bolehDiubah()) {
            throw ValidationException::withMessages([
                'unit' => 'Rencana '.$rencana->nomor.' sudah '.$rencana->labelStatus().', unitnya tidak bisa diubah lagi.',
            ]);
        }

        $alasan = $this->alasanTidakLayak($spr, $rencana);
        if ($alasan) {
            throw ValidationException::withMessages(['unit' => $alasan]);
        }

        return RencanaAkadUnit::create([
            'rencana_akad_id' => $rencana->id,
            'spr_id' => $spr->id,
            'urutan' => (int) $rencana->unit()->max('urutan') + 1,
            'jenis_akad' => 'ppjb',
            // Biaya proses akad besarnya per bank, bukan per unit, jadi diambil
            // dari banknya. Tetap disimpan per unit karena unit yang dibatalkan
            // tidak ikut dibiayai — dan tarifnya bisa berubah setelah rencana
            // lama tersusun, sementara lembar yang sudah dicetak harus tetap
            // memperlihatkan angka saat itu.
            'bayar_bi_notaris' => $rencana->bank?->biaya_proses_akad,
        ]);
    }

    public function hapusUnit(RencanaAkadUnit $unit): void
    {
        $rencana = $unit->rencanaAkad;

        if (! $rencana->bolehDiubah()) {
            throw ValidationException::withMessages([
                'unit' => 'Rencana sudah '.$rencana->labelStatus().', unitnya tidak bisa dikeluarkan.',
            ]);
        }

        $unit->delete();
    }

    /**
     * Pindahkan status. Urutannya dijaga di sini supaya tidak ada yang melompat
     * dari draft langsung ke fix tanpa melewati persetujuan.
     */
    public function ubahStatus(RencanaAkad $rencana, string $tujuan, int $userId, array $opsi = []): RencanaAkad
    {
        // Alur baru: draft → diajukan → diketahui → fix.
        // 'diketahui' = persetujuan Project Manager di dalam sistem.
        // 'fix' hanya boleh dicapai setelah PM menyetujui. Direksi menyetujui
        // via magic link (mengisi disetujui_by/at + fix_by/at sekaligus)
        // pada fase berikutnya.
        $urutan = [
            'draft' => ['diajukan', 'batal'],
            'diajukan' => ['diketahui', 'draft', 'batal'],
            'diketahui' => ['fix', 'diajukan', 'batal'],
            'fix' => ['batal'],
            'batal' => ['draft'],
        ];

        if (! in_array($tujuan, $urutan[$rencana->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => 'Tidak bisa langsung dari '.$rencana->labelStatus().' ke '.(RencanaAkad::LABEL_STATUS[$tujuan] ?? $tujuan).'.',
            ]);
        }

        if ($tujuan === 'diajukan' && $rencana->unit()->count() === 0) {
            throw ValidationException::withMessages([
                'status' => 'Rencana kosong tidak bisa diajukan — lampirkan unit dulu.',
            ]);
        }

        return DB::transaction(function () use ($rencana, $tujuan, $userId, $opsi) {
            $data = ['status' => $tujuan];

            match ($tujuan) {
                'diajukan' => $data = $data + ['diajukan_at' => now(), 'diajukan_by_user_id' => $userId, 'alasan_tolak' => null],
                'diketahui' => $data = $data + [
                    'diketahui_at' => now(),
                    'diketahui_by_user_id' => $userId,
                    'alasan_tolak' => null,
                ],
                'fix' => $data = $data + [
                    'disetujui_at' => now(),
                    'disetujui_by_user_id' => $userId,
                    'fix_at' => now(),
                    'fix_by_user_id' => $userId,
                    // Tanggal dari bank. Kalau tidak diisi, usulan yang dipakai.
                    'tanggal_fix' => $opsi['tanggal_fix'] ?? $rencana->tanggal_rencana,
                ],
                'draft' => $data = $data + [
                    'alasan_tolak' => $opsi['alasan'] ?? null,
                    // Reset trail supaya kalau direvisi lalu diajukan lagi,
                    // slot "Mengetahui/Disetujui" tidak menampilkan approver lama
                    // yang belum melihat perubahan baru.
                    'diajukan_at' => null,
                    'diajukan_by_user_id' => null,
                    'diketahui_at' => null,
                    'diketahui_by_user_id' => null,
                ],
                'batal' => $data = $data + ['alasan_tolak' => $opsi['alasan'] ?? null],
                default => null,
            };

            $rencana->update($data);

            // Unit ikut terkunci saat rencananya fix, dan tanggal rencana akad di
            // pemberkasan diisi dari sini — supaya tidak ada dua sumber tanggal.
            // Unit yang sudah dibatalkan tidak ikut terkunci maupun dihidupkan lagi —
            // pembatalannya keputusan tersendiri, bukan efek samping status rencana.
            if ($tujuan === 'fix') {
                // Kunci status unit + isi tanggal PPJB/AJB per unit sesuai
                // jenis_akad-nya. View "Berdasarkan Blok" pakai kolom ini
                // untuk deteksi unit sudah diakadkan atau belum.
                $tanggalFix = $rencana->fresh()->tanggalBerlaku()?->toDateString();
                if ($tanggalFix) {
                    $rencana->unit()->where('status', '!=', 'batal')
                        ->where('jenis_akad', 'ppjb')
                        ->whereNull('tanggal_ppjb')
                        ->update(['tanggal_ppjb' => $tanggalFix]);

                    $rencana->unit()->where('status', '!=', 'batal')
                        ->where('jenis_akad', 'ajb')
                        ->whereNull('tanggal_ajb')
                        ->update(['tanggal_ajb' => $tanggalFix]);
                }

                $rencana->unit()->where('status', '!=', 'batal')->update(['status' => 'fix']);
                $this->tulisTanggalKePemberkasan($rencana);
            }

            if ($tujuan === 'batal') {
                $rencana->unit()->where('status', '!=', 'batal')->update(['status' => 'draft']);
            }

            // Invalidate badge cache di sidebar supaya jumlah pending PM
            // langsung akurat setelah transisi status.
            Cache::forget('sidebar:pending-approval-rencana-akad');

            return $rencana->fresh();
        });
    }

    /** Tanggal akad yang sudah pasti diteruskan ke pemberkasan tiap SPR. */
    private function tulisTanggalKePemberkasan(RencanaAkad $rencana): void
    {
        $tanggal = $rencana->tanggalBerlaku();

        if (! $tanggal) {
            return;
        }

        DB::table('spr_pemberkasan')
            ->whereIn('spr_id', $rencana->unit()->where('status', '!=', 'batal')->pluck('spr_id'))
            ->update([
                'rencana_akad_tanggal' => $tanggal->toDateString(),
                'updated_at' => now(),
            ]);
    }
}
