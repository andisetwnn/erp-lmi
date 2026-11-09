<?php

namespace App\Services;

use App\Models\Akunting\Jurnal;
use App\Models\Akunting\JurnalDetail;
use App\Support\BusinessActivityLogger;
use Carbon\Carbon;
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

        $jurnal = DB::transaction(function () use ($data, $details) {
            $jurnal = Jurnal::create([
                'perusahaan_id' => $data['perusahaan_id'],
                'tanggal' => $data['tanggal'],
                'no_bukti' => $data['no_bukti'],
                'tipe' => $data['tipe'] ?? 'umum',
                'kategori_bukti' => $data['kategori_bukti'] ?? null,
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

        BusinessActivityLogger::jurnal($jurnal, 'dibuat');

        return $jurnal;
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

        $nomorLama = $jurnal->no_bukti;

        $hasil = DB::transaction(function () use ($jurnal, $data, $details) {
            $kategori = $data['kategori_bukti'] ?? $jurnal->kategori_bukti;

            // Nomor bukti mengikuti kategori dan bulan. Selama keduanya tetap, nomornya tetap;
            // kalau berganti, nomor lama ditinggalkan supaya tidak ada KAS/... berkategori AKM.
            $noBukti = $kategori && ! str_starts_with($jurnal->no_bukti, $this->prefixNoBukti($kategori, $data['tanggal']))
                ? $this->generateNoBukti($jurnal->perusahaan_id, $kategori, $data['tanggal'])
                : $jurnal->no_bukti;

            $jurnal->update([
                'tanggal' => $data['tanggal'],
                'no_bukti' => $noBukti,
                'kategori_bukti' => $kategori,
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

        BusinessActivityLogger::jurnal($hasil, 'diubah', $hasil->no_bukti !== $nomorLama ? "Nomor bukti {$nomorLama} → {$hasil->no_bukti}" : null);

        return $hasil;
    }

    /** Posting: draft → posted. Setelah ini immutable. */
    public function post(Jurnal $jurnal, ?int $userId = null): Jurnal
    {
        if ($jurnal->isPosted()) {
            throw ValidationException::withMessages([
                'jurnal' => 'Jurnal sudah diposting sebelumnya.',
            ]);
        }

        if ($alasan = $this->alasanBelumSiapPosting($jurnal->loadMissing('detail'))) {
            throw ValidationException::withMessages(['jurnal' => $alasan]);
        }

        $jurnal->update([
            'status' => 'posted',
            'posted_by_user_id' => $userId ?? Auth::id(),
            'posted_at' => now(),
        ]);

        BusinessActivityLogger::jurnal($jurnal, 'diposting');

        return $jurnal->fresh();
    }

    /**
     * Posting banyak draft sekaligus. Draft yang belum layak dilewati beserta alasannya,
     * bukan menggagalkan semuanya; yang sudah posted diabaikan. Dicatat sekali di
     * Monitoring — siapa dan kapan memposting tetap tersimpan di tiap jurnal.
     *
     * @param  iterable<int|string>  $ids
     * @return array{diposting: list<string>, dilewati: array<string, string>, nilai: float}
     */
    public function postBanyak(iterable $ids, ?int $userId = null): array
    {
        $hasil = DB::transaction(function () use ($ids, $userId) {
            $hasil = ['diposting' => [], 'dilewati' => [], 'nilai' => 0.0];

            $draft = Jurnal::with('detail')
                ->whereIn('id', collect($ids)->map(fn ($id) => (int) $id)->all())
                ->where('status', 'draft')
                ->orderBy('tanggal')->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($draft as $jurnal) {
                if ($alasan = $this->alasanBelumSiapPosting($jurnal)) {
                    $hasil['dilewati'][$jurnal->no_bukti] = $alasan;

                    continue;
                }

                $jurnal->update(['status' => 'posted', 'posted_by_user_id' => $userId ?? Auth::id(), 'posted_at' => now()]);
                $hasil['diposting'][] = $jurnal->no_bukti;
                $hasil['nilai'] += $jurnal->totalDebet();
            }

            return $hasil;
        });

        if ($hasil['diposting'] !== []) {
            BusinessActivityLogger::jurnalDipostingMassal($hasil['diposting'], $hasil['nilai']);
        }

        return $hasil;
    }

    private function alasanBelumSiapPosting(Jurnal $jurnal): ?string
    {
        return match (true) {
            ! $jurnal->isBalanced() => 'Total debet tidak sama dengan total kredit. Jurnal harus balance sebelum diposting.',
            $jurnal->detail->count() < 2 => 'Jurnal harus punya minimal 2 baris detail.',
            default => null,
        };
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

        $reversal = DB::transaction(function () use ($jurnalAsal, $keterangan, $userId) {
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

        BusinessActivityLogger::jurnal($reversal, 'dibalik', "Membalik {$jurnalAsal->no_bukti}: {$keterangan}");

        return $reversal;
    }

    /**
     * Auto-generate no bukti pattern {KATEGORI}/mm/yy/xxxx.
     * Sequence increment per perusahaan + kategori + bulan.
     */
    public function generateNoBukti(int $perusahaanId, string $kategori, string $tanggal): string
    {
        $prefix = $this->prefixNoBukti($kategori, $tanggal);

        $lastSeq = Jurnal::where('perusahaan_id', $perusahaanId)
            ->where('no_bukti', 'like', $prefix.'%')
            ->pluck('no_bukti')
            ->map(fn ($n) => (int) preg_replace('/^.*\//', '', $n))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($lastSeq + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Awalan no bukti untuk kategori + bulan, mis. "AKM/10/26/". */
    public function prefixNoBukti(string $kategori, string $tanggal): string
    {
        return strtoupper($kategori).'/'.Carbon::parse($tanggal)->format('m/y').'/';
    }

    /** Delete jurnal draft (posted tidak bisa di-hapus, cuma reverse). */
    public function delete(Jurnal $jurnal): void
    {
        if ($jurnal->isPosted()) {
            throw ValidationException::withMessages([
                'jurnal' => 'Jurnal yang sudah diposting tidak bisa dihapus. Gunakan reversal.',
            ]);
        }

        BusinessActivityLogger::jurnal($jurnal, 'dihapus');

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
