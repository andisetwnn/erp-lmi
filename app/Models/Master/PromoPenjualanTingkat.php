<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris tabel booking fee promo usia, mis. lahir 1997–2005 → Rp 300.000.
 * `dari` dan `sampai` dibaca sebagai usia atau tahun lahir, mengikuti promonya.
 */
class PromoPenjualanTingkat extends Model
{
    protected $table = 'promo_penjualan_tingkat';

    public $timestamps = false;

    protected $fillable = [
        'promo_penjualan_id',
        'dari',
        'sampai',
        'booking_fee',
        'label',
    ];

    protected $casts = [
        'dari' => 'integer',
        'sampai' => 'integer',
        'booking_fee' => 'decimal:2',
    ];

    public function promo(): BelongsTo
    {
        return $this->belongsTo(PromoPenjualan::class, 'promo_penjualan_id');
    }
}
