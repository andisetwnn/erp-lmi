<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SprRealisasiPembayaran extends Model
{
    protected $table = 'spr_realisasi_pembayaran';

    public const JENIS_OPTIONS = [
        'bf' => 'UTJ (Booking Fee)',
        'um' => 'Uang Muka (UM)',
        'sbum' => 'SBUM (Subsidi)',
        'kpr' => 'Pencairan KPR',
    ];

    protected $fillable = [
        'spr_id',
        'jenis',
        'tanggal_bayar',
        'jumlah',
        'nomor_kwitansi',
        'metode',
        'keterangan',
        'input_by_user_id',
    ];

    protected $casts = [
        'tanggal_bayar' => 'date',
        'jumlah' => 'decimal:2',
    ];

    public function spr(): BelongsTo
    {
        return $this->belongsTo(Spr::class);
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'input_by_user_id');
    }

    /**
     * Generate nomor kuitansi berikutnya (5-digit sequential global).
     */
    public static function generateNextNomor(): string
    {
        $last = (int) static::whereNotNull('nomor_kwitansi')
            ->where('nomor_kwitansi', 'REGEXP', '^[0-9]+$')
            ->max(\DB::raw('CAST(nomor_kwitansi AS UNSIGNED)'));

        return str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
