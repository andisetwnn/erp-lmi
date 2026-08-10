<?php

namespace App\Services;

use App\Models\Master\Coa;
use Carbon\Carbon;
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
    public function neraca(int $perusahaanId, string $tanggal, ?string $from = null): array
    {
        // Aset, Kewajiban, Modal → dari awal (tanpa from date)
        $aset = $this->groupSaldoByTipe($perusahaanId, 'aset', null, $tanggal);
        $kewajiban = $this->groupSaldoByTipe($perusahaanId, 'kewajiban', null, $tanggal);
        $modal = $this->groupSaldoByTipe($perusahaanId, 'modal', null, $tanggal);

        $totalAset = $this->sumGroupTotal($aset);
        $totalKewajiban = $this->sumGroupTotal($kewajiban);
        $totalModal = $this->sumGroupTotal($modal);

        // Laba periode berjalan = pendapatan - beban (default: dari awal tahun kalender)
        $awalPeriode = $from ?: substr($tanggal, 0, 4).'-01-01';
        $labaData = $this->labaRugi($perusahaanId, $awalPeriode, $tanggal);
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
     * Berdasarkan TIPE (bukan saldo_normal), supaya contra-account (mis. Akumulasi
     * Penyusutan tipe=aset saldo_normal=kredit) tampil negatif — sesuai posisi di Neraca.
     * - aset, beban       → debet - kredit
     * - kewajiban, modal, pendapatan → kredit - debet
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

        return in_array($coa->tipe, ['aset', 'beban'])
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

        // Group by parent (header). Skip yg saldo 0 EXACT (contra account tetap ditampilkan).
        $grouped = [];
        foreach ($coaList as $coa) {
            $saldo = $this->saldoAkun($coa, $from, $to);
            if ($saldo == 0) {
                continue; // skip persis 0
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

    /**
     * Neraca Saldo (Trial Balance) per periode / cutoff.
     * List SEMUA akun yang punya movement + kolom Debet & Kredit sejajar.
     *
     * Return:
     * [
     *   'rows' => [ ['coa' => Coa, 'debet' => float, 'kredit' => float, 'saldo_debet' => float, 'saldo_kredit' => float], ... ],
     *   'total_debet', 'total_kredit', 'total_saldo_debet', 'total_saldo_kredit', 'balanced'
     * ]
     */
    public function neracaSaldo(int $perusahaanId, ?string $from, string $to): array
    {
        $movements = DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', 'jd.jurnal_id')
            ->join('coa as c', 'c.id', 'jd.coa_id')
            ->where('c.perusahaan_id', $perusahaanId)
            ->where('j.status', 'posted')
            ->when($from, fn ($q) => $q->whereDate('j.tanggal', '>=', $from))
            ->whereDate('j.tanggal', '<=', $to)
            ->groupBy('c.id')
            ->selectRaw('c.id as coa_id, COALESCE(SUM(jd.debet),0) as tot_debet, COALESCE(SUM(jd.kredit),0) as tot_kredit')
            ->get()
            ->keyBy('coa_id');

        if ($movements->isEmpty()) {
            return [
                'rows' => [],
                'total_debet' => 0,
                'total_kredit' => 0,
                'total_saldo_debet' => 0,
                'total_saldo_kredit' => 0,
                'balanced' => true,
            ];
        }

        $coaList = Coa::whereIn('id', $movements->keys())->orderBy('kode')->get();

        $rows = [];
        $totalDebet = 0;
        $totalKredit = 0;
        $totalSaldoDebet = 0;
        $totalSaldoKredit = 0;

        foreach ($coaList as $coa) {
            $m = $movements[$coa->id];
            $debet = (float) $m->tot_debet;
            $kredit = (float) $m->tot_kredit;
            $net = $debet - $kredit;

            // Saldo akhir jatuh di sisi normal-nya (aset/beban di debet, lainnya di kredit)
            $normalDebit = in_array($coa->tipe, ['aset', 'beban']);
            $saldoDebet = 0;
            $saldoKredit = 0;
            if ($normalDebit) {
                if ($net >= 0) {
                    $saldoDebet = $net;
                } else {
                    $saldoKredit = -$net;
                }
            } else {
                if ($net <= 0) {
                    $saldoKredit = -$net;
                } else {
                    $saldoDebet = $net;
                }
            }

            $rows[] = [
                'coa' => $coa,
                'debet' => $debet,
                'kredit' => $kredit,
                'saldo_debet' => $saldoDebet,
                'saldo_kredit' => $saldoKredit,
            ];

            $totalDebet += $debet;
            $totalKredit += $kredit;
            $totalSaldoDebet += $saldoDebet;
            $totalSaldoKredit += $saldoKredit;
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_debet' => $totalDebet,
            'total_kredit' => $totalKredit,
            'total_saldo_debet' => $totalSaldoDebet,
            'total_saldo_kredit' => $totalSaldoKredit,
            'balanced' => abs($totalDebet - $totalKredit) < 0.01
                && abs($totalSaldoDebet - $totalSaldoKredit) < 0.01,
        ];
    }

    /**
     * Arus Kas (Cash Flow Statement) — Direct Method.
     * Track mutasi akun kas & bank (kode 1001.* & 1002.*), classify berdasarkan lawan akun ke 3 section:
     *   - OPERASI:   lawan tipe pendapatan/beban, persediaan (1016.*), piutang (1013.*), hutang pajak (2011.*)
     *   - INVESTASI: lawan aktiva tetap (14xx.*), akumulasi (1501.*), investasi (1005.*)
     *   - PENDANAAN: lawan pinjaman (2001-2010 di kewajiban jangka panjang), modal (3xxx)
     *
     * Return:
     * [
     *   'operasi' => ['items' => [['lawan_coa' => Coa, 'masuk' => float, 'keluar' => float, 'net' => float], ...], 'net' => float],
     *   'investasi' => sama,
     *   'pendanaan' => sama,
     *   'kas_awal' => float, 'kas_akhir' => float,
     *   'kenaikan_bersih' => float,
     * ]
     */
    public function arusKas(int $perusahaanId, string $from, string $to): array
    {
        // Ambil semua akun kas & bank (kode 1001.* dan 1002.*)
        $kasBankIds = Coa::where('perusahaan_id', $perusahaanId)
            ->where('is_header', false)
            ->where(function ($q) {
                $q->where('kode', 'like', '1001.%')->orWhere('kode', 'like', '1002.%');
            })
            ->pluck('id')
            ->all();

        if (empty($kasBankIds)) {
            return $this->emptyArusKas($from, $to);
        }

        // Saldo awal kas & bank (sd sehari sebelum from)
        $tglAwal = Carbon::parse($from)->subDay()->toDateString();
        $kasAwal = DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', 'jd.jurnal_id')
            ->whereIn('jd.coa_id', $kasBankIds)
            ->where('j.status', 'posted')
            ->whereDate('j.tanggal', '<=', $tglAwal)
            ->selectRaw('COALESCE(SUM(jd.debet),0) - COALESCE(SUM(jd.kredit),0) as saldo')
            ->value('saldo');
        $kasAwal = (float) $kasAwal;

        // Ambil semua jurnal yang menyentuh kas/bank dalam periode
        $jurnalIds = DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', 'jd.jurnal_id')
            ->whereIn('jd.coa_id', $kasBankIds)
            ->where('j.status', 'posted')
            ->whereDate('j.tanggal', '>=', $from)
            ->whereDate('j.tanggal', '<=', $to)
            ->pluck('j.id')
            ->unique();

        // Aggregate mutasi kas per lawan-akun
        // Untuk tiap jurnal yg touch kas: kalau kas di DEBET (kas masuk) → lawan di kredit
        //                                 kalau kas di KREDIT (kas keluar) → lawan di debet
        // Kita group by lawan_coa.
        $rows = DB::table('jurnal_detail as jd_kas')
            ->join('jurnal as j', 'j.id', 'jd_kas.jurnal_id')
            ->join('jurnal_detail as jd_lawan', function ($join) {
                $join->on('jd_lawan.jurnal_id', 'jd_kas.jurnal_id')
                    ->whereColumn('jd_lawan.id', '!=', 'jd_kas.id');
            })
            ->join('coa as c_lawan', 'c_lawan.id', 'jd_lawan.coa_id')
            ->whereIn('jd_kas.coa_id', $kasBankIds)
            ->whereNotIn('jd_lawan.coa_id', $kasBankIds) // lawan bukan kas/bank juga
            ->where('j.status', 'posted')
            ->whereDate('j.tanggal', '>=', $from)
            ->whereDate('j.tanggal', '<=', $to)
            ->groupBy('c_lawan.id')
            ->selectRaw('
                c_lawan.id as coa_id,
                SUM(CASE WHEN jd_kas.debet > 0 THEN jd_lawan.kredit ELSE 0 END) as masuk,
                SUM(CASE WHEN jd_kas.kredit > 0 THEN jd_lawan.debet ELSE 0 END) as keluar
            ')
            ->get();

        // Fetch COA info
        $coaIds = $rows->pluck('coa_id')->all();
        $coaMap = Coa::whereIn('id', $coaIds)->get()->keyBy('id');

        // Classify each lawan-akun ke section
        $sections = [
            'operasi' => [],
            'investasi' => [],
            'pendanaan' => [],
        ];

        foreach ($rows as $r) {
            $coa = $coaMap[$r->coa_id] ?? null;
            if (! $coa) {
                continue;
            }
            $masuk = (float) $r->masuk;
            $keluar = (float) $r->keluar;
            if (abs($masuk) < 0.01 && abs($keluar) < 0.01) {
                continue;
            }

            $section = $this->classifyArusKasSection($coa);
            $sections[$section][] = [
                'lawan_coa' => $coa,
                'masuk' => $masuk,
                'keluar' => $keluar,
                'net' => $masuk - $keluar,
            ];
        }

        // Sort tiap section by kode
        foreach ($sections as $key => $items) {
            usort($items, fn ($a, $b) => strcmp($a['lawan_coa']->kode, $b['lawan_coa']->kode));
            $sections[$key] = [
                'items' => $items,
                'net' => array_sum(array_column($items, 'net')),
            ];
        }

        $kenaikanBersih = $sections['operasi']['net'] + $sections['investasi']['net'] + $sections['pendanaan']['net'];

        return [
            'from' => $from,
            'to' => $to,
            'operasi' => $sections['operasi'],
            'investasi' => $sections['investasi'],
            'pendanaan' => $sections['pendanaan'],
            'kas_awal' => $kasAwal,
            'kenaikan_bersih' => $kenaikanBersih,
            'kas_akhir' => $kasAwal + $kenaikanBersih,
        ];
    }

    /** Klasifikasi lawan akun ke section arus kas. */
    protected function classifyArusKasSection(Coa $coa): string
    {
        $kode = $coa->kode;
        $tipe = $coa->tipe;

        // PENDANAAN: modal + pinjaman bank jangka panjang
        if ($tipe === 'modal') {
            return 'pendanaan';
        }
        // Pinjaman bank / hutang jangka panjang (kode 2005+ biasanya, atau anak dari 2005 Hutang Bank)
        // Simplifikasi: kalau tipe=kewajiban DAN kode >= 2005, treat as financing
        if ($tipe === 'kewajiban' && $this->isPendanaanKewajiban($kode)) {
            return 'pendanaan';
        }

        // INVESTASI: aktiva tetap + akumulasi + investasi jangka panjang
        // Kode range aktiva tetap biasanya 1401-1499, akumulasi 1501
        if ($tipe === 'aset' && $this->isInvestasiAset($kode)) {
            return 'investasi';
        }

        // Sisanya OPERASI (pendapatan, beban, persediaan, piutang, hutang usaha, pajak)
        return 'operasi';
    }

    protected function isPendanaanKewajiban(string $kode): bool
    {
        // Pendanaan (financing) — pinjaman & setoran pemegang saham:
        //   2009.* Hutang Leasing
        //   2080.* Pinjaman Bank (Nobu PRK / OD)
        //   2006.023 Hutang Kepemegang Saham (setoran langsung dari owner)
        if (str_starts_with($kode, '2009.')) {
            return true;
        }
        if (str_starts_with($kode, '2080.')) {
            return true;
        }
        if ($kode === '2006.023') {
            return true;
        }

        return false;
    }

    protected function isInvestasiAset(string $kode): bool
    {
        // Aset tetap (1500.*) & Akumulasi Penyusutan (1501.*) — kalau ada mutasi vs kas, itu investing
        return str_starts_with($kode, '1500.') || str_starts_with($kode, '1501.');
    }

    protected function emptyArusKas(string $from, string $to): array
    {
        $empty = ['items' => [], 'net' => 0];

        return [
            'from' => $from, 'to' => $to,
            'operasi' => $empty, 'investasi' => $empty, 'pendanaan' => $empty,
            'kas_awal' => 0, 'kas_akhir' => 0, 'kenaikan_bersih' => 0,
        ];
    }
}
