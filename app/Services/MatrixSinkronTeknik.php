<?php

namespace App\Services;

use App\Models\Master\Proyek;
use App\Models\Master\Rumah;
use App\Models\Master\Subcon;
use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use Illuminate\Support\Facades\DB;

/**
 * Salurkan progres bangunan & LOT dari berkas Matrix ke data teknik.
 *
 * Matrix disusun mingguan oleh Admin KPR dan sering lebih mutakhir daripada isi
 * menu Teknik. Selama modul teknik belum dipakai rutin, berkas itulah sumber
 * paling segar untuk progres fisik.
 *
 * Unit yang kolomnya kosong di Matrix sengaja tidak disentuh. Kosong berarti belum
 * diisi, bukan nol persen — menuliskannya sebagai nol akan menghapus progres yang
 * sudah benar.
 */
class MatrixSinkronTeknik
{
    /**
     * @return array{diperbarui: int, progres: int, lot: int, subcon: int, subcon_baru: int, tanpa_unit: int, dilewati: int}
     */
    public function jalankan(MatrixImport $import, ?int $userId = null): array
    {
        $proyekId = $this->cariProyek($import);
        $rumah = $this->rumahTerindeks($proyekId);

        $hasil = [
            'diperbarui' => 0, 'progres' => 0, 'lot' => 0,
            'subcon' => 0, 'subcon_baru' => 0, 'tanpa_unit' => 0, 'dilewati' => 0,
        ];

        $unit = MatrixUnit::where('matrix_import_id', $import->id)
            ->get(['blok', 'nomor_unit', 'progres', 'lot', 'subcon']);

        DB::transaction(function () use ($unit, $rumah, $userId, &$hasil) {
            foreach ($unit as $u) {
                $kunci = strtoupper((string) $u->blok).'-'.ltrim((string) $u->nomor_unit, '0');
                $target = $rumah[$kunci] ?? null;

                if (! $target) {
                    $hasil['tanpa_unit']++;

                    continue;
                }

                $ubah = [];

                if ($u->progres !== null) {
                    $baru = max(0, min(100, (int) round((float) $u->progres)));

                    if ($baru !== (int) $target->progres_fisik) {
                        $ubah['progres_fisik'] = $baru;
                        $hasil['progres']++;
                    }
                }

                if (filled($u->lot) && is_numeric($u->lot) && (int) $u->lot !== (int) $target->lot) {
                    $ubah['lot'] = (int) $u->lot;
                    $hasil['lot']++;
                }

                if (filled($u->subcon)) {
                    $subcon = $this->subconDariNama($u->subcon, $hasil);

                    if ($subcon->id !== $target->subcon_id) {
                        $ubah['subcon_id'] = $subcon->id;
                        $hasil['subcon']++;
                    }
                }

                if ($ubah === []) {
                    $hasil['dilewati']++;

                    continue;
                }

                // Jejak siapa dan kapan tetap dicatat, supaya Admin Teknik tahu
                // angka itu datang dari unggahan dan bisa menimpanya lagi.
                $target->update($ubah + [
                    'progres_updated_at' => now(),
                    'progres_updated_by_user_id' => $userId,
                ]);

                $hasil['diperbarui']++;
            }
        });

        return $hasil;
    }

    /**
     * Subcon dicari berdasarkan namanya; kalau belum ada, didaftarkan.
     *
     * Sebagian catatan lama tersimpan memakai kode ("WIN") karena dulu dipindahkan
     * dari kolom teks bebas. Begitu nama panjangnya diketahui dari legenda berkas,
     * catatan itu dilengkapi — bukan dibiarkan berdampingan sebagai dua pemborong
     * berbeda untuk orang yang sama.
     *
     * @param  array<string, int>  $hasil
     */
    private function subconDariNama(string $nama, array &$hasil): Subcon
    {
        $nama = trim($nama);

        $cocok = Subcon::whereRaw('UPPER(nama) = ?', [strtoupper($nama)])->first();

        if ($cocok) {
            return $cocok;
        }

        // Kode diambil dari huruf awal tiap kata: "EGA K" -> "EK", "WINARTO" -> "WIN".
        // Disaring di PHP, bukan lewat SQL: fungsi panjang teks berbeda nama antar
        // database, dan tabelnya memang kecil.
        $lama = Subcon::all()
            ->filter(fn (Subcon $s) => mb_strlen(trim($s->nama)) <= 4)
            ->first(fn (Subcon $s) => $this->kodeCocok($s->nama, $nama));

        if ($lama) {
            $lama->update(['nama' => $nama]);

            return $lama;
        }

        $hasil['subcon_baru']++;

        return Subcon::create(['nama' => $nama, 'is_aktif' => true]);
    }

    /** Apakah singkatan itu wajar untuk nama tersebut? */
    private function kodeCocok(string $kode, string $nama): bool
    {
        $kode = strtoupper(trim($kode));
        $huruf = strtoupper(preg_replace('/[^A-Za-z ]/', '', $nama) ?? '');
        $kata = array_values(array_filter(explode(' ', $huruf)));

        if ($kata === []) {
            return false;
        }

        // Singkatan dari huruf awal tiap kata, mis. "MUATIARA S MIZAN" -> "MSM".
        $inisial = implode('', array_map(fn (string $k) => $k[0], $kata));

        // Atau tiga huruf pertama kata pertama, mis. "WINARTO" -> "WIN".
        $potong = substr($kata[0], 0, strlen($kode));

        return $kode === $inisial || $kode === $potong;
    }

    /**
     * Nama proyek di berkas Matrix ditulis manual dan ejaannya berubah-ubah
     * ("GRAHA ARYANA" / "GRHA ARYANA"), jadi kalau tidak ketemu dan proyeknya
     * memang cuma satu, itu yang dipakai.
     */
    private function cariProyek(MatrixImport $import): ?int
    {
        if (filled($import->proyek)) {
            $cocok = Proyek::whereRaw('UPPER(nama_proyek) = ?', [strtoupper(trim($import->proyek))])->first();

            if ($cocok) {
                return $cocok->id;
            }
        }

        $semua = Proyek::query()->limit(2)->pluck('id');

        return $semua->count() === 1 ? (int) $semua->first() : null;
    }

    /** @return array<string, Rumah> */
    private function rumahTerindeks(?int $proyekId): array
    {
        return Rumah::query()
            ->when($proyekId, fn ($q) => $q->where('proyek_id', $proyekId))
            ->get(['id', 'blok', 'nomor_unit', 'progres_fisik', 'lot', 'subcon_id'])
            ->mapWithKeys(fn (Rumah $r) => [
                strtoupper($r->blok).'-'.ltrim($r->nomor_unit, '0') => $r,
            ])
            ->all();
    }
}
