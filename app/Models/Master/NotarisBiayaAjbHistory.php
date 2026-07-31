<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotarisBiayaAjbHistory extends Model
{
    protected $table = 'notaris_biaya_ajb_history';

    public $timestamps = false;

    protected $fillable = [
        'notaris_id',
        'nominal_biaya_ajb',
        'pph_promo_ajb',
        'berlaku_mulai',
        'created_by_user_id',
    ];

    protected $casts = [
        'nominal_biaya_ajb' => 'decimal:2',
        'pph_promo_ajb' => 'decimal:2',
        'berlaku_mulai' => 'date',
        'created_at' => 'datetime',
    ];

    public function notaris(): BelongsTo
    {
        return $this->belongsTo(Notaris::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
