<?php

namespace App\Services;

use App\Models\Master\Coa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregator laporan akunting: Laba Rugi, Neraca, Buku Bank.
 *
 * Konsep:
 * - Query hanya jurnal `status=posted` (draft tidak masuk laporan).
 * - Saldo per COA = SUM(debet) - SUM(kredit) untuk saldo normal debit,
 *   atau SUM(kredit) - SUM(debet) untuk saldo normal kredit.
 * - Hirarki COA (parent_id) di-agregasi ke header untuk laporan struktur bertingkat.
 */
class LaporanAkuntingService
{
    /**
     * Laporan Laba Rugi periode.
     * Return structure:
     * [
     *   'pendapatan' => [group[]] dgn total per header + total keseluruhan
     *   'beban'      => sama
     *   'laba_rugi'  => angka (positif = laba, negatif = rugi)
     *   'from', 'to'
     * ]
     */
    public function labaRugi(int $perusahaanId, string $from, string $to): array
    {
        $pendapatanGroups = $this->groupSaldoByTipe($perusahaanId, 'pendapatan', $from, $to);
        $bebanGroups = $this->groupSaldoByTipe($perusahaanId, 'beban', $from, $to);

        $totalPendapatan = $this->sumGroupTotal($pendapatanGroups);
        $totalBeban = $this->sumGroupTotal($bebanGroups);

        return [
            'from' => $from,
            'to' => $to,
            'pendapatan' => [
                'groups' => $pendapatanGroups,
                'total' => $totalPendapatan,
            ],
            'beban' => [
                'groups' => $bebanGroups,
                'total' => $totalBeban,
            ],
            'laba_rugi' => $totalPendapatan - $totalBeban,
        ];
    }

    /**
     * Laporan Neraca per tanggal cutoff.
     * Return structure:
     * [
     *   'aset'      => groups + total
     *   'kewajiban' => groups + total
     *   'modal'     => groups + total
     *   'laba_periode' => laba/rugi berjalan (dari awal tahun s/d tanggal)
     *   'total_pasiva' => kewajiban + modal + laba_periode
     *   'balanced' => bool (aset == pasiva)
     *   'tanggal'
     * ]
     */
    public function neraca(int $perusahaanId, string $tanggal): array
    {
        // Aset, Kewajiban, Modal → dari awal (tanpa from date)
        $aset = $this->groupSaldoByTipe($perusahaanId, 'aset', null, $tanggal);
        $kewajiban = $this->groupSaldoByTipe($perusahaanId, 'kewajiban', null, $tanggal);
        $modal = $this->groupSaldoByTipe($perusahaanId, 'modal', null, $tanggal);

        $totalAset = $this->sumGroupTotal($aset);
        $totalKewajiban = $this->sumGroupTotal($kewajiban);
        $totalModal = $this->sumGroupTotal($modal);

        // Laba periode berjalan = pendapatan - beban (dari awal tahun kalender)
        $awalTahun = substr($tanggal, 0, 4).'-01-01';
        $labaData = $this->labaRugi($perusahaanId, $awalTahun, $tanggal);
        $labaPeriode = $labaData['laba_rugi'];

        $totalPasiva = $totalKewajiban + $totalModal + $labaPeriode;

        return [
            'tanggal' => $tanggal,
            'aset' => ['groups' => $aset, 'total' => $totalAset],
            'kewajiban' => ['groups' => $kewajiban, 'total' => $totalKewajiban],
            'modal' => ['groups' => $modal, 'total' => $totalModal],
            'laba_periode' => $labaPeriode,
            'total_pasiva' => $totalPasiva,
            'balanced' => abs($totalAset - $totalPasiva) < 0.01,
        ];
    }

    /**
     * Buku Bank: list COA kas & bank + saldo per tanggal.
     * Return collection of ['coa' => Coa, 'saldo' => float]
     */
    public function bukuBankSaldo(int $perusahaanId, string $tanggal): Collection
    {
        // Ambil semua leaf COA dgn kode di group 1001 (Kas) dan 1002 (Bank)
        $coaList = Coa::where('perusahaan_id', $perusahaanId)
            ->where('is_header', false)
            ->where('is_aktif', true)
            ->where(function ($q) {
                $q->where('kode', 'like', '1001.%')
                    ->orWhere('kode', 'like', '1002.%');
            })
            ->orderBy('kode')
            ->get();

        return $coaList->map(function ($coa) use ($tanggal) {
            $saldo = $this->saldoAkun($coa, null, $tanggal);

            return ['coa' => $coa, 'saldo' => $saldo];
        });
    }

    /**
     * Saldo per akun COA di rentang tanggal (atau sd tanggal cutoff).
     */
    public function saldoAkun(Coa $coa, ?string $from, string $to): float
    {
        $sums = DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', 'jd.jurnal_id')
            ->where('jd.coa_id', $coa->id)
            ->where('j.status', 'posted')
            ->when($from, fn ($q) => $q->whereDate('j.tanggal', '>=', $from))
            ->whereDate('j.tanggal', '<=', $to)
            ->selectRaw('COALESCE(SUM(jd.debet),0) as debet, COALESCE(SUM(jd.kredit),0) as kredit')
            ->first();

        return $coa->saldo_normal === 'debit'
            ? (float) $sums->debet - (float) $sums->kredit
            : (float) $sums->kredit - (float) $sums->debet;
    }

    /**
     * Group saldo per COA menurut tipe, di-grup by parent header.
     * Return: [ ['header' => Coa, 'items' => [['coa'=>Coa,'saldo'=>float],...], 'total' => float], ... ]
     */
    protected function groupSaldoByTipe(int $perusahaanId, string $tipe, ?string $from, string $to): array
    {
        // Ambil leaf COA dgn tipe tsb yg punya movement
        $coaList = Coa::where('perusahaan_id', $perusahaanId)
            ->where('is_header', false)
            ->where('is_aktif', true)
            ->where('tipe', $tipe)
            ->with('parent:id,kode,nama')
            ->orderBy('kode')
            ->get();

        // Group by parent (header), skip yg saldo 0 biar report clean
        $grouped = [];
        foreach ($coaList as $coa) {
            $saldo = $this->saldoAkun($coa, $from, $to);
            if (abs($saldo) < 0.01) {
                continue; // skip 0
            }

            $headerKey = $coa->parent?->id ?? $coa->id;
            $header = $coa->parent ?? $coa;

            if (! isset($grouped[$headerKey])) {
                $grouped[$headerKey] = [
                    'header' => $header,
                    'items' => [],
                    'total' => 0,
                ];
            }

            $grouped[$headerKey]['items'][] = ['coa' => $coa, 'saldo' => $saldo];
            $grouped[$headerKey]['total'] += $saldo;
        }

        // Sort by kode header
        uasort($grouped, fn ($a, $b) => strcmp($a['header']->kode, $b['header']->kode));

        return array_values($grouped);
    }

    protected function sumGroupTotal(array $groups): float
    {
        return array_sum(array_column($groups, 'total'));
    }
}
