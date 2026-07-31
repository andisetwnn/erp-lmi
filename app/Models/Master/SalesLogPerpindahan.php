<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLogPerpindahan extends Model
{
    protected $table = 'sales_log_perpindahan';

    public $timestamps = false;

    protected $fillable = [
        'sales_id',
        'dari_grup_id',
        'ke_grup_id',
        'dari_jenis',
        'ke_jenis',
        'catatan',
        'dipindah_oleh_user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function sales(): BelongsTo
    {
        return $this->belongsTo(Sales::class);
    }

    public function dariGrup(): BelongsTo
    {
        return $this->belongsTo(SalesGrup::class, 'dari_grup_id');
    }

    public function keGrup(): BelongsTo
    {
        return $this->belongsTo(SalesGrup::class, 'ke_grup_id');
    }
}
