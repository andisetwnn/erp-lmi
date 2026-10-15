<?php

namespace App\Services;

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Sertifikat;
use App\Models\Master\SertifikatPengajuan;
use App\Models\Master\SertifikatSerahTerima;
use App\Support\BusinessActivityLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Aturan pecah sertifikat: penomoran, kelayakan unit, dan perpindahan tahap.
 *
 * Tiga tahap berurutan — diajukan, terbit, diserahkan ke bank — dan tiap
 * perpindahan dijaga di sini, bukan di layar. Yang paling penting dijaga:
 * satu unit satu sertifikat, dan satu sertifikat tidak bisa diserahkan dua
 * kali selama masih tercatat di bank.
 */
class SertifikatService
{
    /**
     * Nomor berikutnya, mis. "12/GA/P.SERT/09/2026".
     * Urutan direset tiap bulan per proyek, mengikuti pola surat yang dipakai
     * Rencana Akad supaya arsip suratnya seragam.
     */
    public function nomorBerikutnya(Proyek $proyek, string $tanggal): string
    {
        $tgl = Carbon::parse($tanggal);
        $kode = $proyek->kode_surat ?: 'XX';

        $urut = SertifikatPengajuan::where('proyek_id', $proyek->id)
            ->whereYear('tanggal_pengajuan', $tgl->year)
            ->whereMonth('tanggal_pengajuan', $tgl->month)
            ->count() + 1;

        return sprintf('%d/%s/P.SERT/%02d/%d', $urut, $kode, $tgl->month, $tgl->year);
    }

    /**
     * Unit yang belum pernah diajukan pecah.
     *
     * Unit yang sudah punya baris sertifikat tidak muncul lagi — apa pun
     * tahapnya. Mengajukan ulang unit yang sertifikatnya sudah terbit berarti
     * meminta BPN memecah sesuatu yang sudah dipecah.
     *
     * @return Builder<Rumah>
     */
    public function unitBelumDiajukan(int $proyekId, string $blok = ''): Builder
    {
        return Rumah::query()
            ->where('proyek_id', $proyekId)
            ->whereDoesntHave('sertifikat')
            ->when($blok !== '', fn ($q) => $q->where('blok', $blok))
            ->with('tipeRumah:id,tipe,nama_tipe,luas_tanah')
            ->orderBy('blok')
            // Diurutkan panjang dulu baru isinya, supaya "9" tidak jatuh
            // sesudah "10". CAST ke angka sengaja dihindari: sintaksnya berbeda
            // antara MySQL dan SQLite, dan suite tes berjalan di SQLite.
            ->orderByRaw('LENGTH(nomor_unit)')
            ->orderBy('nomor_unit');
    }

    /** Blok yang masih punya unit belum diajukan, beserta jumlahnya. */
    public function blokTersedia(int $proyekId): Collection
    {
        return Rumah::query()
            ->where('proyek_id', $proyekId)
            ->whereDoesntHave('sertifikat')
            ->selectRaw('blok, COUNT(*) as jml')
            ->groupBy('blok')
            ->orderBy('blok')
            ->pluck('jml', 'blok');
    }

    /**
     * Luas yang diusulkan untuk sebuah unit.
     *
     * Diambil dari luas baku tipenya. Sistem tidak menyimpan luas per unit —
     * kolomnya pernah ada di master rumah lalu dihapus — jadi angka ini usulan
     * yang harus bisa dikoreksi, bukan kebenaran.
     */
    public function luasUsulan(Rumah $rumah): ?float
    {
        $luas = $rumah->tipeRumah?->luas_tanah;

        return $luas !== null ? (float) $luas : null;
    }

    /**
     * Buat pengajuan beserta baris sertifikat kosong untuk tiap unitnya.
     *
     * @param  array<int, float|null>  $luasPerUnit  rumah_id => luas diajukan
     */
    public function ajukan(
        Proyek $proyek,
        string $tanggal,
        string $metode,
        array $luasPerUnit,
        string $blok = '',
        string $catatan = '',
        ?int $userId = null,
    ): SertifikatPengajuan {
        $rumahId = array_keys($luasPerUnit);

        if ($rumahId === []) {
            throw ValidationException::withMessages([
                'unit' => 'Belum ada unit yang dipilih.',
            ]);
        }

        // Diperiksa ulang di sini, bukan sekadar disaring saat menampilkan:
        // daftar pilihan hidup di sisi pengguna dan bisa memuat unit yang
        // sudah diajukan orang lain sejak halaman dibuka.
        $bentrok = Sertifikat::whereIn('rumah_id', $rumahId)->count();

        if ($bentrok > 0) {
            throw ValidationException::withMessages([
                'unit' => $bentrok.' unit sudah pernah diajukan. Muat ulang halamannya.',
            ]);
        }

        return DB::transaction(function () use ($proyek, $tanggal, $metode, $luasPerUnit, $blok, $catatan, $userId) {
            $pengajuan = SertifikatPengajuan::create([
                'proyek_id' => $proyek->id,
                'nomor' => $this->nomorBerikutnya($proyek, $tanggal),
                'tanggal_pengajuan' => $tanggal,
                'metode' => in_array($metode, ['blok', 'kavling'], true) ? $metode : 'kavling',
                'blok' => $metode === 'blok' && $blok !== '' ? $blok : null,
                'catatan' => $catatan !== '' ? $catatan : null,
                'created_by_user_id' => $userId,
            ]);

            foreach ($luasPerUnit as $id => $luas) {
                Sertifikat::create([
                    'rumah_id' => $id,
                    'sertifikat_pengajuan_id' => $pengajuan->id,
                    'luas_diajukan' => $luas,
                    'updated_by_user_id' => $userId,
                ]);
            }

            BusinessActivityLogger::sertifikatDiajukan($pengajuan, count($luasPerUnit));

            return $pengajuan->fresh();
        });
    }

    /**
     * Serahkan beberapa sertifikat ke bank sekaligus.
     *
     * Yang belum terbit atau sudah tercatat di bank ditolak — bukan dilewati
     * diam-diam, karena operator perlu tahu bahwa yang ia centang tidak semuanya
     * ikut terserahkan.
     *
     * @param  list<int>  $sertifikatId
     */
    public function serahkanKeBank(
        Proyek $proyek,
        string $tanggal,
        array $sertifikatId,
        ?string $filePath = null,
        ?string $fileNama = null,
        string $catatan = '',
        ?int $userId = null,
    ): SertifikatSerahTerima {
        if ($sertifikatId === []) {
            throw ValidationException::withMessages([
                'unit' => 'Belum ada sertifikat yang dipilih.',
            ]);
        }

        $daftar = Sertifikat::whereIn('id', $sertifikatId)->get();

        $belumTerbit = $daftar->filter(fn (Sertifikat $s) => ! $s->sudahTerbit());
        if ($belumTerbit->isNotEmpty()) {
            throw ValidationException::withMessages([
                'unit' => $belumTerbit->count().' unit belum punya nomor sertifikat — isi datanya dulu.',
            ]);
        }

        $sudahDiBank = $daftar->filter(fn (Sertifikat $s) => $s->sudahDiBank());
        if ($sudahDiBank->isNotEmpty()) {
            throw ValidationException::withMessages([
                'unit' => $sudahDiBank->count().' sertifikat sudah tercatat diserahkan ke bank.',
            ]);
        }

        return DB::transaction(function () use ($proyek, $tanggal, $daftar, $filePath, $fileNama, $catatan, $userId) {
            $serah = SertifikatSerahTerima::create([
                'proyek_id' => $proyek->id,
                'tanggal' => $tanggal,
                'file_path' => $filePath,
                'file_original_name' => $fileNama,
                'catatan' => $catatan !== '' ? $catatan : null,
                'created_by_user_id' => $userId,
            ]);

            Sertifikat::whereIn('id', $daftar->pluck('id'))->update([
                'sertifikat_serah_terima_id' => $serah->id,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            BusinessActivityLogger::sertifikatKeBank($serah, $daftar->count());

            return $serah->fresh();
        });
    }

    /**
     * Batalkan satu penyerahan — sertifikatnya kembali berstatus Terbit.
     *
     * Dipakai saat salah input. Berkas BAST-nya ikut dilepas dari basis data,
     * tapi penghapusannya tercatat di log aktivitas supaya tetap ada jejaknya.
     */
    public function batalkanSerahTerima(SertifikatSerahTerima $serah, ?string $alasan = null): void
    {
        DB::transaction(function () use ($serah, $alasan) {
            $terdampak = Sertifikat::with('rumah:id,blok,nomor_unit')
                ->where('sertifikat_serah_terima_id', $serah->id)
                ->get();

            // Dicatat sebelum relasinya dilepas, selagi masih bisa dibaca unit
            // mana saja yang terbawa.
            BusinessActivityLogger::sertifikatBastDibatalkan(
                $terdampak,
                $serah->tanggal?->format('Y-m-d'),
                $alasan,
            );

            Sertifikat::where('sertifikat_serah_terima_id', $serah->id)
                ->update(['sertifikat_serah_terima_id' => null, 'updated_at' => now()]);

            $serah->delete();
        });
    }

    /**
     * Ringkasan satu proyek untuk kartu di atas tabel.
     *
     * "Belum diajukan" dihitung dari unit rumah, bukan dari tabel sertifikat —
     * unit yang belum pernah diajukan memang belum punya barisnya.
     *
     * @return array{luas: float, belum_diajukan: int, proses: int, belum_ajb: int, belum_ke_bank: int, di_bank: int}
     */
    public function ringkasan(int $proyekId): array
    {
        $sertifikat = Sertifikat::whereHas('rumah', fn ($q) => $q->where('proyek_id', $proyekId));

        $terbit = (clone $sertifikat)->whereNotNull('nomor_sertifikat');

        return [
            // Luas yang terbit dipakai kalau ada; selama belum, yang diajukan.
            'luas' => (float) (clone $sertifikat)->sum(
                DB::raw('COALESCE(luas_sertifikat, luas_diajukan, 0)')
            ),
            'belum_diajukan' => Rumah::where('proyek_id', $proyekId)->whereDoesntHave('sertifikat')->count(),
            'proses' => (clone $sertifikat)->whereNull('nomor_sertifikat')->count(),
            'belum_ajb' => (clone $terbit)->whereDoesntHave(
                'rumah.sprAktif.rencanaAkadUnit',
                fn ($q) => $q->whereNotNull('tanggal_ajb')
            )->count(),
            'belum_ke_bank' => (clone $terbit)->whereNull('sertifikat_serah_terima_id')->count(),
            // Dinamai apa adanya: yang dihitung "sudah diserahkan ke bank", bukan
            // "prosesnya beres". Keduanya belum tentu sama — sertifikat bisa sampai
            // di bank untuk unit yang belum AJB, dan selama itu masih mungkin,
            // menyebutnya selesai membuat kartunya menghitung hal yang berbeda dari
            // namanya. Definisi "selesai" yang sebenarnya menunggu jawaban Legal.
            'di_bank' => (clone $sertifikat)->whereNotNull('sertifikat_serah_terima_id')->count(),
        ];
    }

    /**
     * Riwayat satu sertifikat: ketiga tahapnya, lalu catatan perubahan.
     *
     * Tahap pengajuan dan serah terima diturunkan dari datanya sendiri —
     * kedua tabelnya menyimpan tanggal kejadian, pencatat, dan waktu
     * pencatatan selengkapnya. Tahap terbit tidak bisa begitu: `updated_at`
     * baris sertifikat ikut tertimpa saat ia diserahkan ke bank, jadi jejak
     * pengisiannya dibaca dari log aktivitas.
     *
     * Tahap yang belum tercapai tetap dikembalikan dengan tercapai=false,
     * supaya layarnya bisa menunjukkan sampai mana prosesnya berjalan.
     *
     * Tanggalnya dikembalikan sudah berupa teks, bukan objek waktu: hasilnya
     * disimpan di property Livewire, dan yang melintasi batas itu harus berupa
     * nilai sederhana.
     *
     * @return array{tahap: list<array<string, mixed>>, catatan: list<array<string, mixed>>}
     */
    public function riwayat(Sertifikat $s): array
    {
        $s->loadMissing(['rumah:id,blok,nomor_unit', 'pengajuan.createdBy:id,name', 'serahTerima.createdBy:id,name']);

        $log = Activity::query()
            ->where('subject_type', Sertifikat::class)
            ->where('subject_id', $s->id)
            ->with('causer')
            ->orderBy('created_at')
            ->get();

        $terbit = $log->firstWhere('event', 'sertifikat.terbit');

        $p = $s->pengajuan;
        $t = $s->serahTerima;

        $tahap = [
            [
                'kunci' => 'diajukan',
                'judul' => 'Diajukan',
                'tercapai' => $p !== null,
                'tanggal' => $p?->tanggal_pengajuan?->format('d/m/Y'),
                'oleh' => $p?->createdBy?->name,
                'dicatat' => $p?->created_at?->translatedFormat('d M Y, H:i'),
                'rincian' => array_filter([
                    'Nomor pengajuan' => $p?->nomor,
                    'Cara pengajuan' => $p?->labelMetode(),
                    'Luas diajukan' => $s->luas_diajukan !== null ? self::luasTeks((float) $s->luas_diajukan) : null,
                ]),
                // Dipisah dari rincian: isinya kalimat bebas yang panjangnya tak
                // tentu, dan kalau ikut masuk kisi ia menghimpit kolom sebelahnya.
                'catatan' => $p?->catatan,
                'berkas' => null,
                'kosong' => 'Belum diajukan.',
            ],
            [
                'kunci' => 'terbit',
                'judul' => 'Sertifikat terbit',
                'tercapai' => $s->sudahTerbit(),
                'tanggal' => $s->tanggal_terbit?->format('d/m/Y'),
                'oleh' => $terbit?->causer?->name,
                'dicatat' => $terbit?->created_at?->translatedFormat('d M Y, H:i'),
                'rincian' => array_filter([
                    'No. Sertifikat' => $s->nomor_sertifikat,
                    'No. NIB' => $s->nib,
                    'No. HGB Induk' => $s->hgb_induk,
                    'Luas sertifikat' => $s->luas_sertifikat !== null ? self::luasTeks((float) $s->luas_sertifikat) : null,
                ]),
                'catatan' => null,
                'berkas' => $s->file_path ? ['jenis' => 'sertifikat', 'nama' => $s->file_original_name ?: 'Berkas sertifikat'] : null,
                'kosong' => 'Nomor sertifikatnya belum diisi.',
            ],
            [
                'kunci' => 'di_bank',
                'judul' => 'Diserahkan ke bank',
                'tercapai' => $s->sudahDiBank(),
                'tanggal' => $t?->tanggal?->format('d/m/Y'),
                'oleh' => $t?->createdBy?->name,
                'dicatat' => $t?->created_at?->translatedFormat('d M Y, H:i'),
                'rincian' => [],
                'catatan' => $t?->catatan,
                'berkas' => $t?->file_path ? ['jenis' => 'bast', 'nama' => $t->file_original_name ?: 'Dokumen serah terima'] : null,
                'kosong' => 'Belum diserahkan ke bank.',
            ],
        ];

        // Terbaru di atas: yang dicari orang saat membuka riwayat biasanya
        // koreksi yang paling akhir, bukan yang paling awal.
        $catatan = $log
            ->whereIn('event', ['sertifikat.diperbarui', 'sertifikat.bast_dibatalkan'])
            ->sortByDesc('created_at')
            ->map(fn (Activity $a) => [
                'judul' => BusinessActivityLogger::labelFor($a->event),
                'waktu' => $a->created_at?->translatedFormat('d M Y, H:i'),
                'oleh' => $a->causer?->name,
                'perubahan' => (array) ($a->properties['perubahan'] ?? []),
                'alasan' => $a->properties['alasan'] ?? null,
            ])
            ->values()
            ->all();

        return ['tahap' => $tahap, 'catatan' => $catatan];
    }

    /** "72,4 m²" — nol di belakang koma dibuang supaya tidak ramai. */
    private static function luasTeks(float $luas): string
    {
        return rtrim(rtrim(number_format($luas, 2, ',', '.'), '0'), ',').' m²';
    }

    /**
     * Sertifikat yang sudah terbit tapi belum diserahkan ke bank.
     *
     * @return Builder<Sertifikat>
     */
    public function siapDiserahkan(int $proyekId, string $cari = ''): Builder
    {
        return Sertifikat::query()
            ->whereNotNull('nomor_sertifikat')
            ->whereNull('sertifikat_serah_terima_id')
            ->whereHas('rumah', fn ($q) => $q->where('proyek_id', $proyekId))
            ->with(['rumah:id,blok,nomor_unit,proyek_id,tipe_rumah_id', 'rumah.tipeRumah:id,tipe'])
            ->when($cari !== '', function ($q) use ($cari) {
                $q->where(function ($qq) use ($cari) {
                    $qq->where('nomor_sertifikat', 'like', "%$cari%")
                        ->orWhere('nib', 'like', "%$cari%")
                        ->orWhereHas('rumah', fn ($r) => $r->where('blok', 'like', "%$cari%"));
                });
            });
    }
}
