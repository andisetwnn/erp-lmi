<?php

namespace App\Http\Controllers;

use App\Exports\RencanaAkadExport;
use App\Models\Master\Perusahaan;
use App\Models\Master\RencanaAkad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cetak & export lampiran untuk 1 Rencana Akad.
 */
class RencanaAkadCetakController extends Controller
{
    /**
     * Aju Dana — cetak persis lembar Excel yang selama ini dipakai.
     * Format landscape A4, tabel unit lengkap dengan kolom TYPE (LB/LT),
     * Uang Muka (Total/Akumulasi/Target), Legal (HGB), dan biaya per unit
     * (By Proses Akad, AJB Notaris, BPHTB, PPh 4(2)).
     * Di bawahnya: kotak "Biaya Proses" (dijumlah + biaya sesi) dan blok
     * tanda tangan Dibuat/Mengetahui/Disetujui.
     */
    public function ajuDana(int $id): Response
    {
        $rencana = $this->ambilRencana($id);

        return $this->buatPdfAjuDana($rencana);
    }

    /**
     * Render PDF Aju Dana tanpa cek permission — dipanggil dari route publik
     * (signed link Persetujuan Direksi). Signed URL sudah menjadi otorisasi
     * di layer route, jadi tidak perlu double-check di sini.
     */
    public function ajuDanaPublik(int $id): Response
    {
        $rencana = RencanaAkad::query()
            ->with([
                'proyek', 'bank', 'notaris', 'biayaSesi',
                'unit' => fn ($q) => $q->orderBy('urutan')->orderBy('id'),
                'unit.spr.prospectCustomer',
                'unit.spr.rumah.tipeRumah',
                'unit.spr.sales',
                'createdBy', 'diajukanBy', 'diketahuiBy', 'disetujuiBy', 'fixBy',
            ])
            ->findOrFail($id);

        return $this->buatPdfAjuDana($rencana);
    }

    private function buatPdfAjuDana(RencanaAkad $rencana): Response
    {
        // F4/Folio landscape sebagai custom size — Dompdf tidak selalu kenal
        // string 'folio', tapi kalau kita kasih array [0,0,W,H] dalam poin,
        // pasti dipakai. 1008 x 612 pt = 355.6 x 215.9 mm (Folio landscape),
        // 58mm lebih lebar dari A4 landscape sehingga semua kolom Aju Dana
        // muat di 1 halaman. Waktu print di kertas A4, tinggal pilih
        // "Fit to page" di dialog printer.
        $pdf = Pdf::loadView('exports.rencana-akad-ajudana', [
            'rencana' => $rencana,
            'perusahaan' => Perusahaan::query()->first(),
        ])->setPaper([0, 0, 1008, 612]);

        $filename = 'AjuDana-'.$this->slug($rencana->nomor).'.pdf';

        return $pdf->stream($filename);
    }

    public function xlsx(int $id): Response
    {
        $rencana = $this->ambilRencana($id);
        $filename = 'RencanaAkad-'.$this->slug($rencana->nomor).'.xlsx';

        return Excel::download(new RencanaAkadExport($rencana), $filename);
    }

    private function ambilRencana(int $id): RencanaAkad
    {
        abort_unless(Auth::user()?->can('rencanaakad.lihat') || Auth::user()?->can('rencanaakad.kelola'), 403);

        return RencanaAkad::query()
            ->with([
                'proyek',
                'bank',
                'notaris',
                'biayaSesi',
                'unit' => fn ($q) => $q->orderBy('urutan')->orderBy('id'),
                'unit.spr.rumah.tipeRumah',
                'unit.spr.rumah.subcon',
                'unit.spr.prospectCustomer',
                'unit.spr.sales',
                'unit.spr.realisasiPembayaran',
                'diajukanBy',
                'disetujuiBy',
                'fixBy',
                'createdBy',
            ])
            ->findOrFail($id);
    }

    private function slug(string $s): string
    {
        return str_replace(['/', ' ', ':'], '-', $s);
    }
}
