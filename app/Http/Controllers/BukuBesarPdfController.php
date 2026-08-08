<?php

namespace App\Http\Controllers;

use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BukuBesarPdfController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'coa' => 'required|integer|exists:coa,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $coa = Coa::findOrFail((int) $request->query('coa'));
        $from = $request->query('from');
        $to = $request->query('to');
        $perusahaan = Perusahaan::first();

        $mutasi = DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', 'jd.jurnal_id')
            ->where('jd.coa_id', $coa->id)
            ->where('j.status', 'posted')
            ->whereBetween('j.tanggal', [$from, $to])
            ->orderBy('j.tanggal')
            ->orderBy('j.id')
            ->select('j.tanggal', 'j.no_bukti', 'j.keterangan', 'jd.debet', 'jd.kredit')
            ->get();

        $svc = app(LaporanAkuntingService::class);
        $saldoAwal = $svc->saldoAkun($coa, null, date('Y-m-d', strtotime($from.' -1 day')));
        $totalDebet = (float) $mutasi->sum('debet');
        $totalKredit = (float) $mutasi->sum('kredit');
        $saldoAkhir = $svc->saldoAkun($coa, null, $to);

        $pdf = Pdf::loadView('exports.buku-besar-pdf', [
            'coa' => $coa,
            'perusahaan' => $perusahaan,
            'from' => $from,
            'to' => $to,
            'mutasi' => $mutasi,
            'saldoAwal' => $saldoAwal,
            'saldoAkhir' => $saldoAkhir,
            'totalDebet' => $totalDebet,
            'totalKredit' => $totalKredit,
        ])->setPaper('a4', 'landscape');

        $filename = 'BukuBesar-'.$coa->kode.'-'.$from.'-'.$to.'.pdf';

        return $pdf->stream($filename);
    }
}
