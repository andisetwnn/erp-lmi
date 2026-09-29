<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris biaya sesi rencana akad — nama & nominalnya bebas.
 * Contoh: "Sewa Kursi 176.000", "Snack 176.000", "Parkir tamu 50.000".
 *
 * Baris ini TIDAK termasuk biaya per unit (By Proses Akad, BPHTB, PPh 4(2)) —
 * biaya per unit tetap disimpan di rencana_akad_unit dan dijumlahkan dari sana.
 */
class RencanaAkadBiayaSesi extends Model
{
    protected $table = 'rencana_akad_biaya_sesi';

    protected $fillable = [
        'rencana_akad_id',
        'nama',
        'nominal',
        'urutan',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
    ];

    public function rencanaAkad(): BelongsTo
    {
        return $this->belongsTo(RencanaAkad::class);
    }
}
