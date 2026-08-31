<?php

namespace App\Http\Controllers;

use App\Models\Akunting\JurnalLampiran;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menyajikan berkas pendukung jurnal untuk dipratinjau di layar.
 *
 * Terpisah dari unduhan karena Storage::download() selalu memaksa simpan-ke-disk.
 * Di sini header-nya inline, jadi PDF & gambar bisa tampil langsung di modal.
 *
 * Berkasnya ada di disk private, jadi izin tetap diperiksa tiap permintaan —
 * URL-nya tidak boleh jadi jalan pintas melewati hak akses.
 */
class JurnalLampiranController extends Controller
{
    public function __invoke(JurnalLampiran $lampiran): StreamedResponse
    {
        abort_unless(Storage::disk('private')->exists($lampiran->file_path), 404);

        return Storage::disk('private')->response(
            $lampiran->file_path,
            $lampiran->file_original_name,
            [
                'Content-Type' => $lampiran->mime ?: 'application/octet-stream',
                // Cegah browser menebak tipe berkas dan menjalankannya sebagai HTML.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; object-src 'self'",
            ]
        );
    }
}
