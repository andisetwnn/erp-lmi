<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $table = 'booking';

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
        'unit_dilepas_at' => 'date',
    ];

    /** Booking aktif yang sudah lewat tanggal expired (derived). */
    public function isExpired(): bool
    {
        return $this->status === 'aktif'
            && $this->tanggal_expired
            && $this->tanggal_expired->lte(\Illuminate\Support\Carbon::now());
    }

    /**
     * Tanggal sales boleh booking ulang unit ini lagi (cooldown 2 hari = 48 jam
     * sejak unit dilepas). Null kalau tidak ada cooldown aktif.
     */
    public function rebookingAllowedAt(): ?\Carbon\CarbonInterface
    {
        if (! $this->unit_dilepas_at) {
            return null;
        }
        return $this->unit_dilepas_at->copy()->addDays(2);
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
