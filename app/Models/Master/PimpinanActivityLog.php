<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PimpinanActivityLog extends Model
{
    protected $table = 'pimpinan_activity_log';

    public $timestamps = false;

    protected $fillable = [
        'pimpinan_sales_id',
        'action',
        'subject',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function pimpinan(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'pimpinan_sales_id');
    }

    /** Helper untuk log aksi pimpinan. */
    public static function log(int $pimpinanId, string $action, ?string $subject = null, array $meta = []): self
    {
        return self::create([
            'pimpinan_sales_id' => $pimpinanId,
            'action' => $action,
            'subject' => $subject,
            'meta' => $meta,
        ]);
    }
}
