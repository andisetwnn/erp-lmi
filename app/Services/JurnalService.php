<?php

namespace App\Services;

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalDetail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * JurnalService — orchestrator untuk semua operasi jurnal.
 *
 * Konsep:
 * - Jurnal WAJIB balance: total debet == total kredit
 * - Status draft = bisa edit; posted = immutable (cuma bisa di-reverse)
 * - Reversal = bikin jurnal baru dgn debet↔kredit tertukar, link via reversed_from_jurnal_id
 * - No bukti manual (user ketik), tapi validasi unique per perusahaan
 */
class JurnalService
{
    /**
     * Bikin jurnal baru (status draft) + detail-nya.
     *
     * @param  array  $data  keys: perusahaan_id, tanggal, no_bukti, tipe, keterangan
     * @param  array  $details  array of [coa_id, debet, kredit, rekanan_type?, rekanan_id?]
     */
    public function create(array $data, array $details): Jurnal
    {
        $this->validateBalance($details);

        return DB::transaction(function () use ($data, $details) {
            $jurnal = Jurnal::create([
                'perusahaan_id' => $data['perusahaan_id'],
                'tanggal' => $data['tanggal'],
                'no_bukti' => $data['no_bukti'],
                'tipe' => $data['tipe'] ?? 'umum',
                'keterangan' => $data['keterangan'] ?? null,
                'sumber_type' => $data['sumber_type'] ?? null,
                'sumber_id' => $data['sumber_id'] ?? null,
                'status' => 'draft',
                'created_by_user_id' => $data['created_by_user_id'] ?? Auth::id(),
            ]);

            foreach ($details as $d) {
                JurnalDetail::create([
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $d['coa_id'],
                    'debet' => $d['debet'] ?? 0,
                    'kredit' => $d['kredit'] ?? 0,
                    'rekanan_type' => $d['rekanan_type'] ?? null,
                    'rekanan_id' => $d['rekanan_id'] ?? null,
                ]);
            }

            return $jurnal->fresh('detail');
        });
    }

    /**
     * Update jurnal draft (regenerate detail from scratch).
     * Kalau posted → throw error.
     */
    public function update(Jurnal $jurnal, array $data, array $details): Jurnal
    {
        if ($jurnal->isPosted()) {
            throw ValidationException::withMessages([
                'jurnal' => 'Jurnal yang sudah diposting tidak bisa diedit. Buat jurnal reversal saja.',
            ]);
        }

        $this->validateBalance($details);

        return DB::transaction(function () use ($jurnal, $data, $details) {
            $jurnal->update([
                'tanggal' => $data['tanggal'],
                'no_bukti' => $data['no_bukti'],
                'tipe' => $data['tipe'] ?? $jurnal->tipe,
                'keterangan' => $data['keterangan'] ?? null,
            ]);

            $jurnal->detail()->delete();

            foreach ($details as $d) {
                JurnalDetail::create([
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $d['coa_id'],
                    'debet' => $d['debet'] ?? 0,
                    'kredit' => $d['kredit'] ?? 0,
                    'rekanan_type' => $d['rekanan_type'] ?? null,
                    'rekanan_id' => $d['rekanan_id'] ?? null,
                ]);
            }

            return $jurnal->fresh('detail');
        });
    }

    /** Posting: draft → posted. Setelah ini immutable. */
    public function post(Jurnal $jurnal, ?int $userId = null): Jurnal
    {
        if ($jurnal->isPosted()) {
            throw ValidationException::withMessages([
                'jurnal' => 'Jurnal sudah diposting sebelumnya.',
            ]);
        }

        if (! $jurnal->isBalanced()) {
            throw ValidationException::withMessages([
                'jurnal' => 'Total debet tidak sama dengan total kredit. Jurnal harus balance sebelum diposting.',
            ]);
        }

        if ($jurnal->detail()->count() < 2) {
            throw ValidationException::withMessages([
                'jurnal' => 'Jurnal harus punya minimal 2 baris detail.',
            ]);
        }

        $jurnal->update([
            'status' => 'posted',
            'posted_by_user_id' => $userId ?? Auth::id(),
            'posted_at' => now(),
        ]);

        return $jurnal->fresh();
    }

    /**
     * Reversal: bikin jurnal baru dgn debet↔kredit tertukar.
     * Jurnal asal WAJIB posted (kalau draft tinggal edit/delete).
     */
    public function reverse(Jurnal $jurnalAsal, string $keterangan, ?int $userId = null): Jurnal
    {
        if (! $jurnalAsal->isPosted()) {
            throw ValidationException::withMessages([
                'jurnal' => 'Cuma jurnal yang sudah posted yang bisa di-reverse. Untuk draft, edit/delete langsung.',
            ]);
        }

        return DB::transaction(function () use ($jurnalAsal, $keterangan, $userId) {
            $reversal = Jurnal::create([
                'perusahaan_id' => $jurnalAsal->perusahaan_id,
                'tanggal' => now()->toDateString(),
                'no_bukti' => 'REV-'.$jurnalAsal->no_bukti,
                'tipe' => $jurnalAsal->tipe,
                'keterangan' => 'Reversal: '.$keterangan.' (asal: '.$jurnalAsal->no_bukti.')',
                'status' => 'posted',
                'posted_by_user_id' => $userId ?? Auth::id(),
                'posted_at' => now(),
                'reversed_from_jurnal_id' => $jurnalAsal->id,
                'created_by_user_id' => $userId ?? Auth::id(),
            ]);

            foreach ($jurnalAsal->detail as $d) {
                JurnalDetail::create([
                    'jurnal_id' => $reversal->id,
                    'coa_id' => $d->coa_id,
                    // Swap debet & kredit
                    'debet' => $d->kredit,
                    'kredit' => $d->debet,
                    'rekanan_type' => $d->rekanan_type,
                    'rekanan_id' => $d->rekanan_id,
                ]);
            }

            return $reversal->fresh('detail');
        });
    }

    /** Delete jurnal draft (posted tidak bisa di-hapus, cuma reverse). */
    public function delete(Jurnal $jurnal): void
    {
        if ($jurnal->isPosted()) {
            throw ValidationException::withMessages([
                'jurnal' => 'Jurnal yang sudah diposting tidak bisa dihapus. Gunakan reversal.',
            ]);
        }

        $jurnal->delete();
    }

    /**
     * Validasi balance: total debet == total kredit + tiap baris cuma debet ATAU kredit (bukan dua-duanya).
     *
     * @throws ValidationException
     */
    public function validateBalance(array $details): void
    {
        $totalDebet = 0;
        $totalKredit = 0;

        foreach ($details as $i => $d) {
            $debet = (float) ($d['debet'] ?? 0);
            $kredit = (float) ($d['kredit'] ?? 0);

            if ($debet < 0 || $kredit < 0) {
                throw ValidationException::withMessages([
                    "details.$i" => 'Nominal debet/kredit tidak boleh negatif.',
                ]);
            }

            if ($debet > 0 && $kredit > 0) {
                throw ValidationException::withMessages([
                    "details.$i" => 'Baris ke-'.($i + 1).' tidak boleh punya debet DAN kredit sekaligus. Pilih salah satu.',
                ]);
            }

            if ($debet == 0 && $kredit == 0) {
                throw ValidationException::withMessages([
                    "details.$i" => 'Baris ke-'.($i + 1).' harus punya nilai debet atau kredit.',
                ]);
            }

            $totalDebet += $debet;
            $totalKredit += $kredit;
        }

        if (abs($totalDebet - $totalKredit) >= 0.01) {
            throw ValidationException::withMessages([
                'balance' => 'Total debet (Rp '.number_format($totalDebet, 0, ',', '.').') tidak sama dengan total kredit (Rp '.number_format($totalKredit, 0, ',', '.').'). Selisih: Rp '.number_format(abs($totalDebet - $totalKredit), 0, ',', '.'),
            ]);
        }
    }
}
