<?php

namespace App\Services;

use App\Models\Master\Spr;
use App\Models\Master\SprPemberkasan;
use App\Models\Matrix\MatrixImport;
use App\Models\Matrix\MatrixUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Salurkan tanggal tahapan berkas KPR dari berkas Matrix ke modul Pemberkasan.
 *
 * Hanya mengisi kolom yang masih kosong. Berbeda dengan progres fisik yang memang
 * belum digarap, pemberkasan sudah dipakai Admin KPR sehari-hari — tanggal yang
 * mereka input langsung lebih sahih daripada berkas yang disusun beberapa hari
 * sebelumnya, jadi tidak boleh tergeser oleh unggahan.
 *
 * Exp SP3K sengaja tidak diambil dari Matrix. Di sistem ia turunan SP3K + 90 hari,
 * sedangkan Matrix kadang mencatat +91 atau +92. Biarkan sistem yang menghitung
 * supaya satu aturan saja yang berlaku.
 */
class MatrixSinkronPemberkasan
{
    /** Kolom Matrix yang berpasangan dengan kolom pemberkasan. */
    private const PASANGAN = [
        'bm' => 'bm_tanggal',
        'wcr' => 'wcr_tanggal',
        'sp3k' => 'sp3k_tanggal',
        'lpa' => 'lpa_tanggal',
        'rencana_akad' => 'rencana_akad_tanggal',
    ];

    /** Bank yang dikenali kolom bank_kode. Selain ini diabaikan. */
    private const BANK_SAH = ['CBN', 'BSN', 'NBU', 'BCA'];

    private const MASA_BERLAKU_SP3K_HARI = 90;

    /**
     * @return array{diperbarui: int, kolom: int, baru: int, tanpa_spr: int, dilewati: int}
     */
    public function jalankan(MatrixImport $import, ?int $userId = null): array
    {
        $hasil = ['diperbarui' => 0, 'kolom' => 0, 'baru' => 0, 'tanpa_spr' => 0, 'dilewati' => 0];

        $sprPerUnit = $this->sprTerindeks();

        $unit = MatrixUnit::where('matrix_import_id', $import->id)
            ->get(['blok', 'nomor_unit', 'bm', 'wcr', 'sp3k', 'lpa', 'rencana_akad',
                'bank_ko', 'bank_fl', 'bank_ta']);

        DB::transaction(function () use ($unit, $sprPerUnit, $userId, &$hasil) {
            foreach ($unit as $u) {
                if (! $this->adaIsinya($u)) {
                    continue;
                }

                $kunci = strtoupper((string) $u->blok).'-'.ltrim((string) $u->nomor_unit, '0');
                $spr = $sprPerUnit[$kunci] ?? null;

                if (! $spr) {
                    $hasil['tanpa_spr']++;

                    continue;
                }

                $berkas = SprPemberkasan::firstOrNew(['spr_id' => $spr->id]);
                $baru = ! $berkas->exists;

                $ubah = $this->kolomYangDiisi($u, $berkas);

                if ($ubah === []) {
                    $hasil['dilewati']++;

                    continue;
                }

                $berkas->fill($ubah);
                $berkas->updated_by_user_id = $userId;

                if ($baru) {
                    $berkas->input_by_user_id = $userId;
                }

                $berkas->save();

                $hasil['diperbarui']++;
                $hasil['kolom'] += count($ubah);

                if ($baru) {
                    $hasil['baru']++;
                }
            }
        });

        return $hasil;
    }

    private function adaIsinya(MatrixUnit $u): bool
    {
        foreach (array_keys(self::PASANGAN) as $kolom) {
            if ($u->{$kolom} !== null) {
                return true;
            }
        }

        return $this->kodeBank($u) !== null;
    }

    /**
     * Hanya kolom yang masih kosong di sistem yang diisi.
     *
     * @return array<string, mixed>
     */
    private function kolomYangDiisi(MatrixUnit $u, SprPemberkasan $berkas): array
    {
        $ubah = [];

        foreach (self::PASANGAN as $dariMatrix => $keBerkas) {
            if ($u->{$dariMatrix} !== null && $berkas->{$keBerkas} === null) {
                $ubah[$keBerkas] = $u->{$dariMatrix};
            }
        }

        $bank = $this->kodeBank($u);

        if ($bank !== null && $berkas->bank_kode === null) {
            $ubah['bank_kode'] = $bank;
        }

        // Masa berlaku dihitung dari SP3K yang baru diisi, bukan disalin dari Matrix.
        if (isset($ubah['sp3k_tanggal']) && $berkas->sp3k_expired === null) {
            $ubah['sp3k_expired'] = Carbon::parse($ubah['sp3k_tanggal'])
                ->addDays(self::MASA_BERLAKU_SP3K_HARI)
                ->toDateString();
        }

        return $ubah;
    }

    /** Kode bank pertama yang terisi di antara ketiga kolomnya. */
    private function kodeBank(MatrixUnit $u): ?string
    {
        foreach ([$u->bank_ko, $u->bank_fl, $u->bank_ta] as $kode) {
            $kode = strtoupper(trim((string) $kode));

            if (in_array($kode, self::BANK_SAH, true)) {
                return $kode;
            }
        }

        return null;
    }

    /**
     * SPR yang berkasnya masih diurus. Daftar pemberkasan memuat yang sudah akad
     * juga — LPA dan sertifikat sering baru turun sesudahnya. Pembelian tunai
     * tidak punya berkas bank, jadi tidak ikut.
     *
     * @return array<string, Spr>
     */
    private function sprTerindeks(): array
    {
        return Spr::query()
            ->with('rumah:id,blok,nomor_unit')
            ->whereIn('status', ['approved', 'akad'])
            ->where('jenis_pembayaran', 'kpr')
            ->get(['id', 'rumah_id'])
            ->filter(fn (Spr $s) => $s->rumah !== null)
            ->mapWithKeys(fn (Spr $s) => [
                strtoupper($s->rumah->blok).'-'.ltrim($s->rumah->nomor_unit, '0') => $s,
            ])
            ->all();
    }
}
