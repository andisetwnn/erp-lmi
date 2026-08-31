<?php

namespace App\Http\Controllers;

use App\Exports\Akunting\AktivaTetapExport;
use App\Exports\Akunting\ArusKasExport;
use App\Exports\Akunting\LabaRugiExport;
use App\Exports\Akunting\NeracaExport;
use App\Exports\Akunting\NeracaLajurExport;
use App\Exports\Akunting\NeracaSaldoExport;
use App\Models\Akunting\AktivaTetap;
use App\Models\Master\Perusahaan;
use App\Services\LaporanAkuntingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LaporanAkuntingPdfController extends Controller
{
    /** GET /akunting/laba-rugi/print?from=Y-m-d&to=Y-m-d */
    public function labaRugi(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'versi' => 'nullable|in:detail,resume',
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
            // Cetakan harus sama dengan yang barusan dilihat di layar.
            'rinci' => $request->query('versi') !== 'resume',
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('LabaRugi-'.$request->query('from').'-'.$request->query('to').'.pdf');
    }

    /** GET /akunting/neraca/print?tgl=Y-m-d&from=Y-m-d */
    public function labaRugiTahunan(Request $request)
    {
        $request->validate([
            'tahun' => 'required|integer|min:2000|max:2100',
            'versi' => 'nullable|in:detail,resume',
        ]);

        $perusahaan = Perusahaan::first();
        $data = app(LaporanAkuntingService::class)->labaRugiTahunan(
            $perusahaan->id,
            (int) $request->query('tahun'),
        );

        $pdf = Pdf::loadView('exports.laba-rugi-tahunan-pdf', [
            'perusahaan' => $perusahaan,
            'data' => $data,
            'rinci' => $request->query('versi') !== 'resume',
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('LabaRugiTahunan-'.$request->query('tahun').'.pdf');
    }

    public function neraca(Request $request)
    {
        $request->validate([
            'tgl' => 'required|date',
            'from' => 'nullable|date',
            'versi' => 'nullable|in:detail,resume',
        ]);

        $perusahaan = Perusahaan::first();
        $data = app(LaporanAkuntingService::class)->neraca(
            $perusahaan->id,
            $request->query('tgl'),
            $request->query('from'),
        );

        $pdf = Pdf::loadView('exports.neraca-pdf', [
            'perusahaan' => $perusahaan,
            'data' => $data,
            'tanggal' => $request->query('tgl'),
            'rinci' => $request->query('versi') !== 'resume',
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('Neraca-'.$request->query('tgl').'.pdf');
    }

    /** GET /akunting/aktiva-tetap/print?from=Y-m-d&to=Y-m-d */
    public function aktivaTetap(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'kategori' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        $perusahaan = Perusahaan::first();
        $from = $request->query('from') ?: now()->startOfYear()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        $q = AktivaTetap::query()->where('perusahaan_id', $perusahaan->id);
        if ($request->filled('kategori')) {
            $q->where('kategori', $request->query('kategori'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        $rows = $q->orderBy('kategori')->orderBy('tgl_perolehan')->orderBy('id')->get();

        // Grouping by kategori
        $grouped = $rows->groupBy(fn ($r) => $r->kategori ?: 'Lainnya');

        // Hitung penyusutan bulan periode (default periode akhir = $to)
        $endDate = Carbon::parse($to);

        $pdf = Pdf::loadView('exports.aktiva-tetap-pdf', [
            'perusahaan' => $perusahaan,
            'grouped' => $grouped,
            'from' => $from,
            'to' => $to,
            'endDate' => $endDate,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('AktivaTetap-'.$from.'-'.$to.'.pdf');
    }

    // ═══════════════ EXCEL EXPORTS ═══════════════

    public function labaRugiExcel(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = $request->query('from');
        $to = $request->query('to');

        return Excel::download(new LabaRugiExport($from, $to), "LabaRugi-{$from}-{$to}.xlsx");
    }

    public function neracaExcel(Request $request)
    {
        $request->validate(['tgl' => 'required|date', 'from' => 'nullable|date']);
        $tgl = $request->query('tgl');

        return Excel::download(new NeracaExport($tgl, $request->query('from')), "Neraca-{$tgl}.xlsx");
    }

    public function aktivaTetapExcel(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'kategori' => 'nullable|string',
            'status' => 'nullable|string',
        ]);
        $from = $request->query('from') ?: now()->startOfYear()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return Excel::download(
            new AktivaTetapExport($from, $to, $request->query('kategori'), $request->query('status')),
            "AktivaTetap-{$from}-{$to}.xlsx"
        );
    }

    // ═══════════════ NERACA SALDO ═══════════════

    public function neracaSaldo(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = $request->query('from');
        $to = $request->query('to');
        $perusahaan = Perusahaan::first();
        $data = app(LaporanAkuntingService::class)->neracaSaldo($perusahaan->id, $from, $to);

        return Pdf::loadView('exports.neraca-saldo-pdf', compact('perusahaan', 'data', 'from', 'to'))
            ->setPaper('a4', 'portrait')
            ->stream("NeracaSaldo-{$from}-{$to}.pdf");
    }

    public function neracaSaldoExcel(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = $request->query('from');
        $to = $request->query('to');

        return Excel::download(new NeracaSaldoExport($from, $to), "NeracaSaldo-{$from}-{$to}.xlsx");
    }

    // ═══════════════ ARUS KAS ═══════════════

    public function arusKas(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = $request->query('from');
        $to = $request->query('to');
        $perusahaan = Perusahaan::first();
        $data = app(LaporanAkuntingService::class)->arusKas($perusahaan->id, $from, $to);

        return Pdf::loadView('exports.arus-kas-pdf', compact('perusahaan', 'data', 'from', 'to'))
            ->setPaper('a4', 'portrait')
            ->stream("ArusKas-{$from}-{$to}.pdf");
    }

    public function arusKasExcel(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = $request->query('from');
        $to = $request->query('to');

        return Excel::download(new ArusKasExport($from, $to), "ArusKas-{$from}-{$to}.xlsx");
    }

    // ═══════════════ NERACA LAJUR (WORKSHEET) ═══════════════

    public function neracaLajur(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = $request->query('from');
        $to = $request->query('to');
        $perusahaan = Perusahaan::first();
        $data = app(LaporanAkuntingService::class)->neracaLajur($perusahaan->id, $from, $to);

        return Pdf::loadView('exports.neraca-lajur-pdf', compact('perusahaan', 'data', 'from', 'to'))
            ->setPaper('a4', 'landscape')
            ->stream("NeracaLajur-{$from}-{$to}.pdf");
    }

    public function neracaLajurExcel(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = $request->query('from');
        $to = $request->query('to');

        return Excel::download(new NeracaLajurExport($from, $to), "NeracaLajur-{$from}-{$to}.xlsx");
    }
}
