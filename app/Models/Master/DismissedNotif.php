<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DismissedNotif extends Model
{
    protected $table = 'dismissed_notif';

    protected $fillable = [
        'sales_id',
        'notif_key',
        'dismissed_until',
    ];

    protected $casts = [
        'dismissed_until' => 'datetime',
    ];

    public function sales(): BelongsTo
    {
        return $this->belongsTo(Sales::class);
    }

    /** Cek apakah notif key sedang di-dismiss untuk sales tertentu. */
    public static function isDismissed(int $salesId, string $key): bool
    {
        return self::where('sales_id', $salesId)
            ->where('notif_key', $key)
            ->where(function ($q) {
                $q->whereNull('dismissed_until')->orWhere('dismissed_until', '>', now());
            })
            ->exists();
    }

    /** Dismiss notif sampai jam tertentu. */
    public static function dismiss(int $salesId, string $key, ?\DateTimeInterface $until = null): self
    {
        return self::updateOrCreate(
            ['sales_id' => $salesId, 'notif_key' => $key],
            ['dismissed_until' => $until],
        );
    }
}
