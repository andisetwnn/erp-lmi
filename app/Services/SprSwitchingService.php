<?php

namespace App\Services;

use App\Models\Master\AlasanPembatalan;
use App\Models\Master\Booking;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use App\Models\Master\SprSwitching;
use App\Support\BusinessActivityLogger;
use App\Support\SprJadwalTermin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fitur Pindah Kavling (Switching) — PR 2.
 *
 * Konsep:
 * - Setiap pindah unit → SPR lama VOIDED (status=cancelled, alasan=Pindah Kavling)
 * - SPR baru diterbitkan dgn nomor SPR baru, ter-link ke SPR lama via switched_from_spr_id
 * - Realisasi UTJ+UM lama di-repoint ke SPR baru (foreign key spr_id update)
 * - Selisih harga di-handle:
 *   - Unit baru LEBIH mahal → tambah termin UM baru di SPR baru
 *   - Unit baru LEBIH murah → buat realisasi refund_pindah (pending refund)
 *   - Total UM sudah > kebutuhan baru → auto-refund kelebihan
 *
 * Constraint:
 * - Cross-kategori (subsidi ↔ komersial) TIDAK boleh
 * - SPR status akad / cancelled TIDAK boleh switching
 * - Unit tujuan harus status "available"
 */
class SprSwitchingService
{
    /**
     * Pindah satu SPR ke unit baru (unit tujuan harus available).
     *
     * @return Spr SPR baru yang dibuat
     */
    public function pindahUnit(Spr $sprLama, int $rumahBaruId, string $alasan, int $userId): Spr
    {
        $rumahBaru = Rumah::with('tipeRumah')->findOrFail($rumahBaruId);

        $this->guardSprBolehSwitching($sprLama);
        $this->guardRumahBaruTersedia($rumahBaru, $sprLama);
        $this->guardKategoriSama($sprLama, $rumahBaru);

        return DB::transaction(function () use ($sprLama, $rumahBaru, $alasan, $userId) {
            $sprBaru = $this->createSprBaruDariSprLama($sprLama, $rumahBaru, $userId);
            $this->voidSprLama($sprLama, $sprBaru, $alasan, $userId);

            $switching = SprSwitching::create([
                'nomor_switching' => SprSwitching::generateNextNomor(),
                'tipe' => 'pindah',
                'alasan' => $alasan,
                'spr_lama_a_id' => $sprLama->id,
                'spr_baru_a_id' => $sprBaru->id,
                'selisih_a' => (float) $sprBaru->total_harga - (float) $sprLama->total_harga,
                'processed_by_user_id' => $userId,
                'processed_at' => Carbon::now(),
            ]);

            $this->pindahRealisasi($sprLama, $sprBaru, $switching->id);
            $this->recalculateTerminUm($sprBaru);
            $this->handleSelisihHarga($sprBaru, $switching->id);
            $this->releaseRumahLama($sprLama->rumah);
            $this->lockRumahBaru($rumahBaru, $sprBaru);

            $sprBaru->refresh();

            BusinessActivityLogger::sprSwitched($sprLama, $sprBaru, $alasan, $userId);

            return $sprBaru;
        });
    }

    /**
     * Swap 2 SPR: A pindah ke unit B, B pindah ke unit A (atomic).
     *
     * @return array{Spr, Spr} [SPR A baru, SPR B baru]
     */
    public function swapSpr(Spr $sprA, Spr $sprB, string $alasan, int $userId): array
    {
        $this->guardSprBolehSwitching($sprA);
        $this->guardSprBolehSwitching($sprB);
        $this->guardKategoriSama($sprA, $sprB->rumah);
        $this->guardKategoriSama($sprB, $sprA->rumah);

        if ($sprA->id === $sprB->id) {
            throw ValidationException::withMessages(['spr' => 'SPR tujuan swap tidak boleh sama.']);
        }

        return DB::transaction(function () use ($sprA, $sprB, $alasan, $userId) {
            $rumahAsalA = $sprA->rumah;
            $rumahAsalB = $sprB->rumah;

            // Bikin SPR baru untuk masing2 (dgn unit swap)
            $sprBaruA = $this->createSprBaruDariSprLama($sprA, $rumahAsalB, $userId);
            $sprBaruB = $this->createSprBaruDariSprLama($sprB, $rumahAsalA, $userId);

            // Void kedua SPR lama, link ke SPR baru masing2
            $this->voidSprLama($sprA, $sprBaruA, $alasan.' (swap dgn SPR '.$sprB->nomor_spr.')', $userId);
            $this->voidSprLama($sprB, $sprBaruB, $alasan.' (swap dgn SPR '.$sprA->nomor_spr.')', $userId);

            $switching = SprSwitching::create([
                'nomor_switching' => SprSwitching::generateNextNomor(),
                'tipe' => 'swap',
                'alasan' => $alasan,
                'spr_lama_a_id' => $sprA->id,
                'spr_baru_a_id' => $sprBaruA->id,
                'spr_lama_b_id' => $sprB->id,
                'spr_baru_b_id' => $sprBaruB->id,
                'selisih_a' => (float) $sprBaruA->total_harga - (float) $sprA->total_harga,
                'selisih_b' => (float) $sprBaruB->total_harga - (float) $sprB->total_harga,
                'processed_by_user_id' => $userId,
                'processed_at' => Carbon::now(),
            ]);

            // Pindah realisasi
            $this->pindahRealisasi($sprA, $sprBaruA, $switching->id);
            $this->pindahRealisasi($sprB, $sprBaruB, $switching->id);

            // Recalculate termin + selisih untuk masing2
            $this->recalculateTerminUm($sprBaruA);
            $this->recalculateTerminUm($sprBaruB);
            $this->handleSelisihHarga($sprBaruA, $switching->id);
            $this->handleSelisihHarga($sprBaruB, $switching->id);

            // Status rumah — release+lock silang
            // Rumah asal A → sekarang punya SPR baru B
            // Rumah asal B → sekarang punya SPR baru A
            $this->lockRumahBaru($rumahAsalB, $sprBaruA);
            $this->lockRumahBaru($rumahAsalA, $sprBaruB);

            $sprBaruA->refresh();
            $sprBaruB->refresh();

            BusinessActivityLogger::sprSwapped($sprA, $sprB, $sprBaruA, $sprBaruB, $alasan, $userId);

            return [$sprBaruA, $sprBaruB];
        });
    }

    // ============ GUARDS ============

    private function guardSprBolehSwitching(Spr $spr): void
    {
        // Hanya SPR "SELESAI" (approved + sudah bermeterai) yang boleh dipindah.
        // SPR "DIPROSES" (draft/submitted/approved tanpa meterai) belum di-commit customer —
        // kalau mau ganti unit, cukup batalkan + buat SPR baru manual.
        if ($spr->status !== 'approved' || $spr->spr_finalized_at === null) {
            throw ValidationException::withMessages([
                'spr' => "SPR {$spr->nomor_spr} belum berstatus SELESAI. Pindah Kavling hanya untuk SPR yang sudah disetujui dan bermeterai.",
            ]);
        }

        if ($spr->status === 'akad') {
            throw ValidationException::withMessages([
                'spr' => "SPR {$spr->nomor_spr} sudah akad kredit. Tidak bisa dipindah.",
            ]);
        }
    }

    private function guardRumahBaruTersedia(Rumah $rumahBaru, Spr $sprLama): void
    {
        if ($rumahBaru->id === $sprLama->rumah_id) {
            throw ValidationException::withMessages(['rumah' => 'Unit tujuan sama dengan unit sekarang.']);
        }
        if ($rumahBaru->status !== 'available') {
            throw ValidationException::withMessages(['rumah' => "Unit {$rumahBaru->blok}-{$rumahBaru->nomor_unit} status '{$rumahBaru->status}', tidak tersedia untuk switching."]);
        }
        if ($rumahBaru->proyek_id !== $sprLama->rumah?->proyek_id) {
            throw ValidationException::withMessages(['rumah' => 'Unit tujuan harus di proyek yang sama.']);
        }
    }

    private function guardKategoriSama(Spr $spr, Rumah $rumahBaru): void
    {
        $katLama = $spr->rumah?->tipeRumah?->kategori ?? $spr->kategori;
        $katBaru = $rumahBaru->tipeRumah?->kategori;

        if ($katLama && $katBaru && $katLama !== $katBaru) {
            throw ValidationException::withMessages([
                'kategori' => "Kategori berbeda: SPR lama '{$katLama}', unit baru '{$katBaru}'. Switching lintas kategori tidak diizinkan.",
            ]);
        }
    }

    // ============ CORE OPERATIONS ============

    /** Buat SPR baru dgn snapshot data + harga dari tipe rumah baru. */
    private function createSprBaruDariSprLama(Spr $sprLama, Rumah $rumahBaru, int $userId): Spr
    {
        $tipe = $rumahBaru->tipeRumah;
        $kategori = $tipe?->kategori ?? $sprLama->kategori;
        $hargaJual = (float) ($tipe?->harga_jual ?? 0);
        $biayaTambahan = (float) ($tipe?->biaya_administrasi ?? 0) + (float) ($rumahBaru->biaya_tambahan ?? 0);
        $diskon = (float) ($rumahBaru->discount ?? 0);
        $ppn = (float) ($rumahBaru->ppn ?? 0);
        $totalHarga = max(0, $hargaJual + $biayaTambahan + $ppn - $diskon);

        // Recalc KPR / UM / SBUM tergantung kategori
        if ($kategori === 'komersial') {
            $sbum = 0;
            $dpNominal = 0;
            $umNet = 0;
            $nilaiKpr = $totalHarga;
            $jenisPembayaran = 'kpr';
        } else {
            $sbum = (float) ($tipe?->sbum ?? 0);
            $plafonKpr = (float) ($tipe?->plafon_kpr ?? 0);
            $jenisPembayaran = $sprLama->jenis_pembayaran ?: 'kpr';
            $nilaiKpr = $jenisPembayaran === 'kpr' ? $plafonKpr : 0;
            $dpNominal = $jenisPembayaran === 'kpr' ? max(0, $totalHarga - $plafonKpr) : $totalHarga;
            $umNet = max(0, $dpNominal - $sbum);
        }

        $dpPersen = $totalHarga > 0 ? round(($dpNominal / $totalHarga) * 100, 2) : 0;

        $now = Carbon::now();

        // Booking baru utk unit tujuan (kolom spr.booking_id UNIQUE — tidak bisa reuse booking lama).
        $bookingBaru = Booking::create([
            'sales_id' => $sprLama->sales_id,
            'proyek_id' => $rumahBaru->proyek_id,
            'rumah_id' => $rumahBaru->id,
            'prospect_customer_id' => $sprLama->prospect_customer_id,
            'tanggal_booking' => $now->toDateString(),
            'tanggal_expired' => $now->copy()->addDays(7)->toDateString(),
            'status' => 'sukses',
        ]);

        $sprBaru = Spr::create([
            'booking_id' => $bookingBaru->id,
            'sales_id' => $sprLama->sales_id,
            'prospect_customer_id' => $sprLama->prospect_customer_id,
            'rumah_id' => $rumahBaru->id,
            'kategori' => $kategori,
            'nomor_spr' => Spr::generateNextNomor($now),
            'tanggal_spr' => $now->toDateString(),
            'harga_jual' => $hargaJual,
            'diskon' => $diskon,
            'biaya_tambahan' => $biayaTambahan,
            'ppn' => $ppn,
            'kelebihan_tanah_m2' => 0,
            'harga_per_m2' => 0,
            'total_harga' => $totalHarga,
            'jenis_pembayaran' => $jenisPembayaran,
            'bank_kpr_id' => $sprLama->bank_kpr_id,
            'dp_persen' => $dpPersen,
            'dp_nominal' => $dpNominal,
            'sbum' => $sbum,
            'um_net' => $umNet,
            'nilai_kpr' => $nilaiKpr,
            'catatan_angsuran' => $sprLama->catatan_angsuran,
            // UTJ sudah cair — tidak perlu bayar ulang, tapi field UTJ tetap ada di SPR baru sbg reference
            'utj_nominal' => $sprLama->utj_nominal,
            'utj_tanggal_bayar' => $sprLama->utj_tanggal_bayar,
            'utj_tanggal_transaksi' => $sprLama->utj_tanggal_transaksi,
            'utj_metode' => $sprLama->utj_metode ?: 'transfer',
            'utj_nominal_aktual' => $sprLama->utj_nominal_aktual,
            'utj_confirmed_by_user_id' => $sprLama->utj_confirmed_by_user_id,
            'utj_confirmed_at' => $sprLama->utj_confirmed_at,
            'utj_bukti_path' => $sprLama->utj_bukti_path,
            'utj_keterangan' => $sprLama->utj_keterangan,
            'status' => $sprLama->status,
            // Trace switching
            'switched_from_spr_id' => $sprLama->id,
            'catatan' => "PINDAH DARI SPR {$sprLama->nomor_spr} (unit {$sprLama->rumah?->blok}-{$sprLama->rumah?->nomor_unit}) pada ".$now->format('d/m/Y H:i').'.',
            'ttd_sales_path' => $sprLama->ttd_sales_path,
        ]);

        return $sprBaru;
    }

    /** Void SPR lama: status=cancelled, alasan=Pindah Kavling, arah ke SPR baru. */
    private function voidSprLama(Spr $sprLama, Spr $sprBaru, string $alasan, int $userId): void
    {
        $alasanId = AlasanPembatalan::where('nama', 'Pindah Kavling')->value('id');

        $sprLama->update([
            'status' => 'cancelled',
            'alasan_pembatalan_id' => $alasanId,
            'cancel_keterangan' => $alasan,
            'cancelled_at' => Carbon::now(),
            'cancelled_by_user_id' => $userId,
            'switched_to_spr_id' => $sprBaru->id,
            'refund_status' => 'tidak_ada_refund', // realisasi pindah, tidak di-refund per SPR lama
        ]);
    }

    /**
     * Repoint realisasi (UTJ+UM) dari SPR lama → SPR baru.
     * Set switching_id supaya kwitansi tetap bisa dilihat dari detail SPR lama
     * lewat query realisasi.switching_id = spr_lama.switching().id.
     */
    private function pindahRealisasi(Spr $sprLama, Spr $sprBaru, int $switchingId): void
    {
        SprRealisasiPembayaran::where('spr_id', $sprLama->id)
            ->update([
                'spr_id' => $sprBaru->id,
                'switching_id' => $switchingId,
            ]);
    }

    /**
     * Buat termin BF + UM untuk SPR baru (mengikuti pola form SPR create).
     * BF = 1 termin (nominal UTJ). UM = split ke termin equal kalau UM > 0.
     */
    private function recalculateTerminUm(Spr $sprBaru): void
    {
        // Bikin termin BF (UTJ) — 1 termin
        $sprBaru->terminPembayaran()->create([
            'jenis' => 'bf',
            'urutan' => 0,
            'tanggal_jadwal' => null,
            'jumlah_jadwal' => (float) $sprBaru->utj_nominal,
            'input_by_user_id' => null,
        ]);

        // Kalau komersial atau um_net=0, skip UM termin
        if ($sprBaru->kategori === 'komersial' || (float) $sprBaru->um_net <= 0) {
            return;
        }

        // Split UM ke 4 termin (default subsidi KPR)
        $jumlahTermin = 4;
        $perTermin = round((float) $sprBaru->um_net / $jumlahTermin, 0);
        // Anchor prioritas: tanggal transfer UTJ → tanggal SPR → now (fallback).
        $anchor = SprJadwalTermin::toAnchor($sprBaru->utj_tanggal_transaksi)
            ?? SprJadwalTermin::toAnchor($sprBaru->tanggal_spr)
            ?? Carbon::now();

        foreach (SprJadwalTermin::generate($anchor, $jumlahTermin, $perTermin) as $row) {
            $sprBaru->terminPembayaran()->create([
                'jenis' => 'um',
                'urutan' => $row['urutan'],
                'tanggal_jadwal' => $row['tanggal'],
                'jumlah_jadwal' => $row['jumlah'],
                'input_by_user_id' => null,
            ]);
        }

        // SBUM (hanya subsidi + KPR)
        if ((float) $sprBaru->sbum > 0) {
            $sprBaru->terminPembayaran()->create([
                'jenis' => 'sbum',
                'urutan' => 0,
                'jumlah_jadwal' => (float) $sprBaru->sbum,
                'input_by_user_id' => null,
            ]);
        }
    }

    /**
     * Hitung selisih total realisasi UM (yg sudah cair) vs UM baru.
     * - Kalau selisih > 0 (customer overpaid) → buat realisasi refund_pindah pending
     * - Kalau selisih < 0 (customer masih kurang) → nothing (termin UM baru sudah cover)
     */
    private function handleSelisihHarga(Spr $sprBaru, int $switchingId): void
    {
        $totalUmDibayar = (float) SprRealisasiPembayaran::where('spr_id', $sprBaru->id)
            ->whereIn('jenis', ['um'])
            ->sum('jumlah');

        $umNet = (float) $sprBaru->um_net;

        // Overpaid: customer sudah bayar UM lebih dari kebutuhan unit baru → refund kelebihan
        if ($totalUmDibayar > $umNet && $totalUmDibayar > 0) {
            $kelebihan = $totalUmDibayar - $umNet;

            SprRealisasiPembayaran::create([
                'spr_id' => $sprBaru->id,
                'switching_id' => $switchingId,
                'jenis' => 'refund_pindah',
                'tanggal_bayar' => Carbon::now()->toDateString(),
                'jumlah' => $kelebihan,
                'nomor_kwitansi' => null, // di-generate saat keuangan proses refund
                'metode' => 'transfer',
                'keterangan' => 'Refund kelebihan UM akibat pindah kavling ke unit lebih murah.',
                'input_by_user_id' => null,
            ]);
        }
        // Underpaid: selisih ditutup lewat termin UM sisa → nothing to do
    }

    private function releaseRumahLama(?Rumah $rumahLama): void
    {
        if (! $rumahLama) {
            return;
        }
        $rumahLama->update(['status' => 'available']);
    }

    private function lockRumahBaru(Rumah $rumahBaru, Spr $sprBaru): void
    {
        // Sesuaikan status rumah dgn status SPR baru
        $status = match ($sprBaru->status) {
            'approved', 'akad' => 'terjual',
            'draft', 'submitted' => 'booking',
            default => 'booking',
        };
        $rumahBaru->update(['status' => $status]);
    }
}
