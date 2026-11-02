<?php

namespace App\Models\Master;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Booking extends Model
{
    protected $table = 'booking';

    /** Lama sales yang sama tidak bisa booking ulang unit yang sama setelah unit dilepas. */
    public const COOLDOWN_JAM = 48;

    protected $fillable = [
        'sales_id',
        'proyek_id',
        'rumah_id',
        'prospect_customer_id',
        'tanggal_booking',
        'tanggal_expired',
        'unit_dilepas_at',
        'status',
        'keterangan_batal',
    ];

    protected $attributes = [
        'status' => 'aktif',
    ];

    protected $casts = [
        'tanggal_booking' => 'date',
        'tanggal_expired' => 'datetime',
        'unit_dilepas_at' => 'datetime',
    ];

    /** Booking aktif yang sudah lewat tanggal expired (derived). */
    public function isExpired(): bool
    {
        return $this->status === 'aktif'
            && $this->tanggal_expired
            && $this->tanggal_expired->lte(Carbon::now());
    }

    /**
     * Booking yang masih memegang unit. Booking yang lewat batas waktu tetap
     * berstatus 'aktif', jadi status saja tidak cukup untuk membedakannya.
     */
    public function scopeMasihBerlaku(Builder $query): Builder
    {
        return $query->where('status', 'aktif')
            ->where(fn ($q) => $q->whereNull('tanggal_expired')->orWhere('tanggal_expired', '>', Carbon::now()));
    }

    /**
     * Waktu sales boleh booking ulang unit ini lagi (48 jam sejak unit dilepas).
     * Null kalau tidak ada cooldown aktif.
     */
    public function rebookingAllowedAt(): ?CarbonInterface
    {
        if (! $this->unit_dilepas_at) {
            return null;
        }

        return $this->unit_dilepas_at->copy()->addHours(self::COOLDOWN_JAM);
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(Sales::class);
    }

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function rumah(): BelongsTo
    {
        return $this->belongsTo(Rumah::class);
    }

    public function prospectCustomer(): BelongsTo
    {
        return $this->belongsTo(ProspectCustomer::class);
    }

    public function spr(): HasOne
    {
        return $this->hasOne(Spr::class);
    }
}
