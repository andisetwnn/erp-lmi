<?php

namespace App\Services;

use App\Models\Master\PromoPenjualan;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan Promo Penjualan: kapan sebuah promo berlaku, berapa potongannya, dan
 * cara melampirkannya ke SPR.
 *
 * Seluruh promo ditanggung developer. Angka di SPR (UTJ, UM, harga) tidak
 * diubah; yang dicatat hanya potongannya, dan yang dibayar konsumen dihitung
 * dari situ — lihat Spr::utjKonsumen() dan Spr::umKonsumen().
 */
class PromoPenjualanService
{
    /**
     * Promo untuk diketahui sales di DBOS, lintas proyek: yang berjalan hari ini
     * dan yang mulai dalam beberapa hari ke depan. Promo nonaktif tidak ikut.
     *
     * @return array{berjalan: Collection<int, PromoPenjualan>, akanDatang: Collection<int, PromoPenjualan>}
     */
    public function untukSales(?CarbonInterface $hari = null, int $hariKeDepan = 30): array
    {
        $hari = Carbon::parse($hari ?? now())->startOfDay();

        $promo = PromoPenjualan::with(['proyek:id,nama_proyek', 'tingkat'])
            ->withCount('rumah')
            ->where('is_aktif', true)
            ->whereDate('berlaku_sampai', '>=', $hari)
            ->whereDate('berlaku_mulai', '<=', $hari->copy()->addDays($hariKeDepan))
            ->orderBy('berlaku_mulai')
            ->orderBy('id')
            ->get();

        return [
            'berjalan' => $promo->filter(fn (PromoPenjualan $p) => $p->berlaku_mulai->lte($hari))->values(),
            'akanDatang' => $promo->filter(fn (PromoPenjualan $p) => $p->berlaku_mulai->gt($hari))->values(),
        ];
    }

    /**
     * Promo aktif lain di proyek yang sama yang masa berlakunya beririsan.
     *
     * Larangan beririsan inilah yang menjamin satu tanggal UTJ hanya punya satu
     * promo — sehingga "satu SPR satu promo" tidak perlu aturan memilih.
     */
    public function bentrok(int $proyekId, string $mulai, string $sampai, ?int $kecualiId = null): ?PromoPenjualan
    {
        return PromoPenjualan::where('proyek_id', $proyekId)
            ->where('is_aktif', true)
            ->when($kecualiId, fn ($q) => $q->whereKeyNot($kecualiId))
            ->whereDate('berlaku_mulai', '<=', $sampai)
            ->whereDate('berlaku_sampai', '>=', $mulai)
            ->orderBy('berlaku_mulai')
            ->first();
    }

    /**
     * Tanggal yang dicocokkan dengan masa promo: tanggal UTJ masuk rekening
     * kalau sudah dikonfirmasi Finance, kalau belum tanggal SPR (hari UTJ
     * diunggah sales).
     */
    public function tanggalAcuan(Spr $spr): ?CarbonInterface
    {
        $tgl = $spr->utj_tanggal_transaksi ?? $spr->tanggal_spr;

        return $tgl ? Carbon::parse($tgl) : null;
    }

    /**
     * Promo yang berlaku untuk unit pada tanggal itu beserta potongannya.
     *
     * Null kalau tidak ada promo, unitnya tidak termasuk, atau — untuk promo
     * usia — konsumennya di luar tabel.
     *
     * @return array{promo: PromoPenjualan, potongan_utj: float, potongan_um: float, keterangan: string}|null
     */
    public function untuk(
        Rumah $rumah,
        CarbonInterface|string $tanggal,
        ?CarbonInterface $tanggalLahir,
        float $utjNormal,
        float $umNet,
    ): ?array {
        $promo = PromoPenjualan::where('proyek_id', $rumah->proyek_id)
            ->berlakuPada($tanggal)
            ->with('tingkat')
            ->get()
            ->first(fn (PromoPenjualan $p) => $p->mencakup($rumah));

        if (! $promo) {
            return null;
        }

        return $this->hitung($promo, Carbon::parse($tanggal), $tanggalLahir, $utjNormal, $umNet);
    }

    /**
     * Potongan sebuah promo untuk satu konsumen.
     *
     * @return array{promo: PromoPenjualan, potongan_utj: float, potongan_um: float, keterangan: string}|null
     */
    public function hitung(
        PromoPenjualan $promo,
        CarbonInterface $tanggal,
        ?CarbonInterface $tanggalLahir,
        float $utjNormal,
        float $umNet,
    ): ?array {
        if ($promo->jenis === 'um') {
            // Dibatasi pada bagian UM yang dicicil, supaya UTJ tidak ikut tergerus.
            $potongan = min((float) $promo->potongan_um, max(0, $umNet - $utjNormal));

            return $potongan > 0 ? [
                'promo' => $promo,
                'potongan_utj' => 0.0,
                'potongan_um' => $potongan,
                'keterangan' => $promo->nama,
            ] : null;
        }

        if ($promo->jenis === 'booking_fee_tetap') {
            // Satu nominal untuk semua konsumen — tanggal lahir tidak dibaca.
            $potongan = max(0, $utjNormal - (float) $promo->booking_fee_tetap);

            return $potongan > 0 ? [
                'promo' => $promo,
                'potongan_utj' => $potongan,
                'potongan_um' => 0.0,
                'keterangan' => $promo->nama,
            ] : null;
        }

        if (! $tanggalLahir) {
            return null;
        }

        $nilai = $promo->dasar_usia === 'tahun_lahir'
            ? (int) $tanggalLahir->year
            : (int) $tanggalLahir->diffInYears($tanggal);

        $tingkat = $promo->tingkat->first(fn ($t) => $nilai >= $t->dari && $nilai <= $t->sampai);

        if (! $tingkat) {
            return null;
        }

        $potongan = max(0, $utjNormal - (float) $tingkat->booking_fee);

        if ($potongan <= 0) {
            return null;
        }

        $penjelas = $promo->dasar_usia === 'tahun_lahir' ? 'lahir '.$nilai : 'usia '.$nilai;

        return [
            'promo' => $promo,
            'potongan_utj' => $potongan,
            'potongan_um' => 0.0,
            'keterangan' => $promo->nama.' ('.($tingkat->label ?: $penjelas).')',
        ];
    }

    /**
     * Simpan promo baru atau ubahan.
     *
     * @param  array{proyek_id: int, nama: string, jenis: string, berlaku_mulai: string, berlaku_sampai: string, potongan_um?: ?float, booking_fee_tetap?: ?float, dasar_usia?: ?string, semua_unit: bool, keterangan?: ?string}  $data
     * @param  list<int>  $rumahIds
     * @param  list<array{dari: int, sampai: int, booking_fee: float, label?: ?string}>  $tingkat
     */
    public function simpan(array $data, array $rumahIds, array $tingkat, ?int $userId, ?PromoPenjualan $promo = null): PromoPenjualan
    {
        $galat = [];

        if ($data['berlaku_sampai'] < $data['berlaku_mulai']) {
            $galat['berlaku_sampai'] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        }

        if ($data['jenis'] === 'um' && (float) ($data['potongan_um'] ?? 0) <= 0) {
            $galat['potongan_um'] = 'Isi potongan UM.';
        }

        if ($data['jenis'] === 'booking_fee_tetap' && (float) ($data['booking_fee_tetap'] ?? 0) <= 0) {
            $galat['booking_fee_tetap'] = 'Isi booking fee yang dibayar konsumen.';
        }

        if ($data['jenis'] === 'booking_fee') {
            if ($tingkat === []) {
                $galat['tingkat'] = 'Isi minimal satu baris tabel booking fee.';
            }

            foreach ($tingkat as $i => $t) {
                if ($t['sampai'] < $t['dari']) {
                    $galat['tingkat'] = 'Baris '.($i + 1).': batas akhir lebih kecil dari batas awal.';
                }
            }

            $urut = collect($tingkat)->sortBy('dari')->values();
            for ($i = 1; $i < $urut->count(); $i++) {
                if ($urut[$i]['dari'] <= $urut[$i - 1]['sampai']) {
                    $galat['tingkat'] = 'Rentang di tabel booking fee saling tumpang tindih.';
                }
            }
        }

        if (! $data['semua_unit'] && $rumahIds === []) {
            $galat['rumah'] = 'Pilih minimal satu unit, atau centang semua unit.';
        }

        // Promo yang sudah dipakai SPR tidak boleh berubah dasar hitungnya —
        // SPR yang sudah mendapatkannya akan kehilangan dasar potongannya.
        if ($promo && $promo->spr()->exists()) {
            $berubah = $promo->jenis !== $data['jenis']
                || $promo->berlaku_mulai->toDateString() !== $data['berlaku_mulai']
                || $promo->berlaku_sampai->toDateString() !== $data['berlaku_sampai']
                || (float) $promo->potongan_um !== (float) ($data['potongan_um'] ?? $promo->potongan_um)
                || (float) $promo->booking_fee_tetap !== (float) ($data['booking_fee_tetap'] ?? $promo->booking_fee_tetap);

            if ($berubah) {
                $galat['berlaku_mulai'] = 'Promo ini sudah dipakai '.$promo->spr()->count().' SPR. Jenis, tanggal, dan nominalnya tidak bisa diubah lagi.';
            }
        }

        if ($galat === [] && ($data['is_aktif'] ?? true)) {
            $tabrak = $this->bentrok((int) $data['proyek_id'], $data['berlaku_mulai'], $data['berlaku_sampai'], $promo?->id);

            if ($tabrak) {
                $galat['berlaku_mulai'] = 'Bertabrakan dengan '.$tabrak->nama.' ('
                    .$tabrak->berlaku_mulai->translatedFormat('d M Y').' – '
                    .$tabrak->berlaku_sampai->translatedFormat('d M Y').'). Dalam satu proyek promo tidak boleh berjalan bersamaan.';
            }
        }

        if ($galat !== []) {
            throw ValidationException::withMessages($galat);
        }

        return DB::transaction(function () use ($data, $rumahIds, $tingkat, $userId, $promo) {
            $promo ??= new PromoPenjualan(['created_by_user_id' => $userId]);

            $promo->fill([
                'proyek_id' => $data['proyek_id'],
                'nama' => $data['nama'],
                'jenis' => $data['jenis'],
                'berlaku_mulai' => $data['berlaku_mulai'],
                'berlaku_sampai' => $data['berlaku_sampai'],
                'potongan_um' => $data['jenis'] === 'um' ? (float) $data['potongan_um'] : null,
                'booking_fee_tetap' => $data['jenis'] === 'booking_fee_tetap' ? (float) $data['booking_fee_tetap'] : null,
                'dasar_usia' => $data['jenis'] === 'booking_fee' ? ($data['dasar_usia'] ?? 'usia') : null,
                'semua_unit' => $data['semua_unit'],
                'keterangan' => $data['keterangan'] ?? null,
                'is_aktif' => $data['is_aktif'] ?? true,
                'updated_by_user_id' => $userId,
            ])->save();

            $promo->rumah()->sync($data['semua_unit'] ? [] : $rumahIds);

            if (! $promo->spr()->exists()) {
                $promo->tingkat()->delete();

                if ($data['jenis'] === 'booking_fee') {
                    foreach ($tingkat as $t) {
                        $promo->tingkat()->create($t);
                    }
                }
            }

            return $promo->fresh(['tingkat', 'rumah']);
        });
    }

    /**
     * Lampirkan promo ke SPR yang terlanjur dibuat tanpa promo.
     *
     * Potongan UM dikurangkan dari termin cicilan terakhir yang belum dibayar,
     * supaya jadwalnya tetap berjumlah sama dengan UM yang dibayar konsumen.
     */
    public function lampirkan(Spr $spr, PromoPenjualan $promo, string $alasan, ?int $userId): Spr
    {
        $spr->loadMissing('rumah', 'prospectCustomer');
        $tanggal = $this->tanggalAcuan($spr);

        $tolak = match (true) {
            $spr->status === 'cancelled' => 'SPR sudah dibatalkan.',
            $spr->promo_penjualan_id !== null => 'SPR ini sudah mendapat promo. Satu SPR hanya satu promo.',
            ! $spr->rumah || ! $promo->mencakup($spr->rumah) => 'Unit ini tidak termasuk promo '.$promo->nama.'.',
            ! $tanggal || $tanggal->lt($promo->berlaku_mulai) || $tanggal->gt($promo->berlaku_sampai) => 'Tanggal UTJ SPR ini di luar masa promo '.$promo->nama.'.',
            default => null,
        };

        $hasil = $tolak ? null : $this->hitung(
            $promo->loadMissing('tingkat'),
            $tanggal,
            $spr->prospectCustomer?->tanggal_lahir,
            (float) $spr->utj_nominal,
            (float) $spr->um_net,
        );

        if (! $tolak && ! $hasil) {
            $tolak = match ($promo->jenis) {
                'booking_fee' => 'Usia konsumen tidak masuk tabel promo '.$promo->nama.'.',
                'booking_fee_tetap' => 'Booking fee normal SPR ini tidak lebih besar dari booking fee promo '.$promo->nama.'.',
                default => 'Tidak ada sisa UM yang bisa dipotong.',
            };
        }

        if ($tolak) {
            throw ValidationException::withMessages(['promo' => $tolak]);
        }

        return DB::transaction(function () use ($spr, $promo, $hasil, $alasan, $userId) {
            $spr->update([
                'promo_penjualan_id' => $promo->id,
                'promo_potongan_utj' => $hasil['potongan_utj'],
                'promo_potongan_um' => $hasil['potongan_um'],
                'promo_alasan' => $alasan,
                'promo_oleh_user_id' => $userId,
                'promo_at' => now(),
            ]);

            $this->geserTermin($spr, -$hasil['potongan_um']);

            return $spr->fresh('promoPenjualan');
        });
    }

    /** Lepas promo yang keliru dilampirkan; termin dikembalikan. */
    public function lepas(Spr $spr, string $alasan, ?int $userId): Spr
    {
        return DB::transaction(function () use ($spr, $alasan, $userId) {
            $this->geserTermin($spr, (float) $spr->promo_potongan_um);

            $spr->update([
                'promo_penjualan_id' => null,
                'promo_potongan_utj' => 0,
                'promo_potongan_um' => 0,
                'promo_alasan' => 'Dilepas: '.$alasan,
                'promo_oleh_user_id' => $userId,
                'promo_at' => now(),
            ]);

            return $spr->fresh();
        });
    }

    /**
     * Ubah jumlah termin cicilan UM yang belum dibayar, mulai dari yang terakhir.
     * Selisih negatif mengurangi, positif menambah kembali.
     */
    private function geserTermin(Spr $spr, float $selisih): void
    {
        if ($selisih == 0.0) {
            return;
        }

        $termin = $spr->terminPembayaran()
            ->where('jenis', 'um')
            ->whereNull('tanggal_realisasi')
            ->reorder('urutan', 'desc')
            ->get();

        if ($termin->isEmpty()) {
            return;
        }

        if ($selisih > 0) {
            $termin->first()->increment('jumlah_jadwal', $selisih);

            return;
        }

        $sisa = -$selisih;
        foreach ($termin as $t) {
            $kurangi = min($sisa, (float) $t->jumlah_jadwal);
            $t->update(['jumlah_jadwal' => (float) $t->jumlah_jadwal - $kurangi]);
            $sisa -= $kurangi;

            if ($sisa <= 0) {
                break;
            }
        }
    }
}
