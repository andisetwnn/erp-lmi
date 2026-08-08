<?php

namespace App\Http\Controllers;

use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class LaporanAkuntingPdfController extends Controller
{
    /** GET /akunting/laba-rugi/print?from=Y-m-d&to=Y-m-d */
    public function labaRugi(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $perusahaan = Perusahaan::first();
        $data = app(LaporanAkuntingService::class)->labaRugi(
            $perusahaan->id,
            $request->query('from'),
            $request->query('to'),
        );

        $pdf = Pdf::loadView('exports.laba-rugi-pdf', [
            'perusahaan' => $perusahaan,
            'data' => $data,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('LabaRugi-'.$request->query('from').'-'.$request->query('to').'.pdf');
    }

    /** GET /akunting/neraca/print?tgl=Y-m-d */
    public function neraca(Request $request)
    {
        $request->validate([
            'tgl' => 'required|date',
        ]);

        $perusahaan = Perusahaan::first();
        $data = app(LaporanAkuntingService::class)->neraca(
            $perusahaan->id,
            $request->query('tgl'),
        );

        $pdf = Pdf::loadView('exports.neraca-pdf', [
            'perusahaan' => $perusahaan,
            'data' => $data,
            'tanggal' => $request->query('tgl'),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('Neraca-'.$request->query('tgl').'.pdf');
    }
}
