<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    protected $table = 'sales_target';

    protected $fillable = [
        'sales_id',
        'periode',
        'target_prospect',
        'target_booking',
        'set_by_sales_id',
    ];

    protected $casts = [
        'target_prospect' => 'integer',
        'target_booking' => 'integer',
    ];

    public function sales(): BelongsTo
    {
        return $this->belongsTo(Sales::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'set_by_sales_id');
    }

    /** Format periode untuk bulan & tahun sekarang (YYYY-MM). */
    public static function currentPeriode(): string
    {
        return now()->format('Y-m');
    }
}
