<?php

namespace App\Models\Akunting;

use App\Models\Master\Coa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JurnalDetail extends Model
{
    protected $table = 'jurnal_detail';

    protected $fillable = [
        'jurnal_id',
        'coa_id',
        'debet',
        'kredit',
        'rekanan_type',
        'rekanan_id',
    ];

    protected $casts = [
        'debet' => 'decimal:2',
        'kredit' => 'decimal:2',
    ];

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class);
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(Coa::class);
    }

    /** Rekanan bisa Customer / Sales / Notaris / User (polymorphic). */
    public function rekanan(): MorphTo
    {
        return $this->morphTo();
    }
}
