<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiayaTambahanRealisasi extends Model
{
    protected $table = 'biaya_tambahan_realisasi';

    protected $fillable = [
        'rumah_id',
        'spr_id',
        'tanggal_bayar',
        'nomor_kuitansi',
        'jumlah',
        'metode',
        'keterangan',
        'is_refunded',
        'refunded_at',
        'refunded_by_user_id',
        'refund_keterangan',
        'input_by_user_id',
    ];

    protected $casts = [
        'tanggal_bayar' => 'date',
        'jumlah' => 'decimal:2',
        'is_refunded' => 'boolean',
        'refunded_at' => 'date',
    ];

    public const METODE = [
        'cash' => 'Cash',
        'transfer' => 'Transfer',
    ];

    public function rumah(): BelongsTo
    {
        return $this->belongsTo(Rumah::class);
    }

    public function spr(): BelongsTo
    {
        return $this->belongsTo(Spr::class);
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by_user_id');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by_user_id');
    }
}
