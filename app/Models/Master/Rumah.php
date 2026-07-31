<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rumah extends Model
{
    protected $table = 'rumah';

    protected $fillable = [
        'proyek_id',
        'tipe_rumah_id',
        'blok',
        'nomor_unit',
        'biaya_tambahan',
        'discount',
        'ppn',
        'status',
        'tanggal_launching',
        'siteplan_x',
        'siteplan_y',
        'siteplan_w',
        'siteplan_h',
        'siteplan_points',
        'updated_by_user_id',
    ];

    protected $casts = [
        'biaya_tambahan' => 'decimal:2',
        'discount' => 'decimal:2',
        'ppn' => 'decimal:2',
        'tanggal_launching' => 'date',
        'siteplan_x' => 'decimal:2',
        'siteplan_y' => 'decimal:2',
        'siteplan_w' => 'decimal:2',
        'siteplan_h' => 'decimal:2',
        'siteplan_points' => 'array',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function tipeRumah(): BelongsTo
    {
        return $this->belongsTo(TipeRumah::class);
    }

    public function virtualAccount(): HasMany
    {
        return $this->hasMany(VirtualAccount::class);
    }

    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function bookingAktif(): ?Booking
    {
        return $this->booking()->where('status', 'aktif')->latest()->first();
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    protected function kodeUnit(): Attribute
    {
        return Attribute::make(
            get: fn () => "{$this->blok}-{$this->nomor_unit}",
        );
    }

    /** Luas tanah = ambil dari tipe (semua unit tipe sama luasnya). */
    protected function luasTanah(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->tipeRumah?->luas_tanah ?? 0),
        );
    }

    /** Luas bangunan ikut tipe (gak berubah per-unit). */
    protected function luasBangunan(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->tipeRumah?->luas_bangunan ?? 0),
        );
    }

    /**
     * Harga jual efektif untuk unit ini:
     *   = tipe.harga_jual + biaya_tambahan (unit hook/tambahan)
     */
    protected function hargaJual(): Attribute
    {
        return Attribute::make(
            get: function () {
                $base = (float) ($this->tipeRumah?->harga_jual ?? 0);
                $tambahan = (float) ($this->biaya_tambahan ?? 0);

                return $base + $tambahan;
            },
        );
    }
}
