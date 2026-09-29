<?php

namespace App\Http\Controllers;

use App\Models\Master\RencanaAkad;
use App\Services\RencanaAkadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persetujuan Direksi via magic link (universal — bukan per-user).
 *
 * Alur:
 *   1. Admin KPR klik "Generate Link" di detail rencana (status Diketahui).
 *      URL signed berisi rencana_id + expiry 7 hari.
 *   2. Admin KPR share URL ke Haryanto atau Julianto Boentaran (bebas siapa
 *      yang hadir). Nama di lembar Aju Dana sudah hardcoded
 *      "Haryanto / Julianto Boentaran" — tidak perlu tahu siapa yang tanda
 *      tangan.
 *   3. Direksi buka link, gambar TTD di canvas HTML, tekan "Setujui".
 *      Data base64 dikirim, disimpan sebagai PNG di storage.
 *   4. Status naik ke Fix; TTD tampil di kotak "Disetujui" pada PDF Aju Dana.
 *
 * Keamanan:
 *   - Signed URL (HMAC + APP_KEY), expiry 7 hari
 *   - CSRF pada POST setuju
 *   - Idempotent: sekali Fix, link berikutnya kasih pesan "sudah disetujui"
 *   - IP + user agent direkam
 */
class PersetujuanDireksiController extends Controller
{
    public function __construct(private RencanaAkadService $svc) {}

    public function show(Request $request, int $rencanaId)
    {
        $rencana = $this->loadRencana($rencanaId);

        return view('public.persetujuan-direksi', [
            'rencana' => $rencana,
            'sudahDitandatangani' => $rencana->status === 'fix',
        ]);
    }

    /**
     * Stream PDF Aju Dana untuk direksi (dipanggil dari iframe di halaman
     * preview). Signed URL sama seperti show — supaya PDF juga tidak bisa
     * dibuka tanpa link resmi.
     */
    public function pdf(Request $request, int $rencanaId)
    {
        $rencana = $this->loadRencana($rencanaId);

        // Delegasi ke controller cetak yang sudah ada (versi publik, tanpa
        // permission check — signed URL sudah menjadi otorisasi).
        return app(RencanaAkadCetakController::class)->ajuDanaPublik($rencana->id);
    }

    public function setuju(Request $request, int $rencanaId)
    {
        $rencana = RencanaAkad::query()->findOrFail($rencanaId);

        if ($rencana->status === 'fix') {
            return redirect()->to($request->headers->get('referer', url('/')))
                ->with('info', 'Rencana ini sudah disetujui sebelumnya.');
        }

        if ($rencana->status !== 'diketahui') {
            abort(409, 'Rencana ini tidak dalam status Diketahui.');
        }

        $data = $request->validate([
            'tanda_tangan' => ['required', 'string', 'starts_with:data:image/png;base64,'],
        ], [
            'tanda_tangan.required' => 'Tanda tangan wajib digambar dulu.',
            'tanda_tangan.starts_with' => 'Format tanda tangan tidak valid.',
        ]);

        // Simpan TTD sebagai PNG.
        $ttdPath = $this->simpanTtd($rencana, $data['tanda_tangan']);

        // Catat audit trail + path TTD.
        $rencana->update([
            'direksi_ttd_path' => $ttdPath,
            'direksi_setuju_ip' => $request->ip(),
            'direksi_setuju_user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        // disetujui_by pakai createdBy user id sebagai proxy — nama di PDF
        // dihardcode di template, jadi user_id di sini tidak berpengaruh ke
        // tampilan cetak, tapi tetap dicatat supaya migrasi audit lama tidak
        // patah dan trail "kapan Fix" tetap ada.
        $userIdProxy = $rencana->created_by_user_id ?? $rencana->diketahui_by_user_id ?? 0;

        $this->svc->ubahStatus($rencana, 'fix', $userIdProxy, [
            'tanggal_fix' => $rencana->tanggal_rencana?->toDateString(),
        ]);

        return redirect()->to($request->headers->get('referer', url('/')))
            ->with('success', 'Terima kasih. Persetujuan Anda sudah dicatat.');
    }

    /**
     * Decode base64 PNG dari canvas, simpan ke storage.
     * Path relatif ke disk 'public' — kompatibel dengan {@see rencana-akad-ajudana.blade.php}.
     */
    private function simpanTtd(RencanaAkad $rencana, string $base64): string
    {
        $data = substr($base64, strlen('data:image/png;base64,'));
        $binary = base64_decode($data, true);

        if ($binary === false) {
            abort(422, 'Data tanda tangan tidak valid.');
        }

        // Batasi ukuran (canvas 600x200 harusnya <100 KB).
        if (strlen($binary) > 500 * 1024) {
            abort(422, 'Ukuran tanda tangan terlalu besar.');
        }

        $path = 'ttd-direksi/rencana-'.$rencana->id.'-'.Str::random(8).'.png';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    private function loadRencana(int $id): RencanaAkad
    {
        return RencanaAkad::query()
            ->with([
                'proyek:id,nama_proyek,kode_surat',
                'bank:id,nama',
                'notaris:id,nama',
                'unit.spr.prospectCustomer:id,nama_lengkap',
                'unit.spr.rumah:id,blok,nomor_unit,proyek_id',
                'biayaSesi',
                'createdBy:id,name',
                'diketahuiBy:id,name,tanda_tangan_path',
            ])
            ->findOrFail($id);
    }
}
