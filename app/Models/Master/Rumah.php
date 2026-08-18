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

    public function biayaTambahanRealisasi(): HasMany
    {
        return $this->hasMany(BiayaTambahanRealisasi::class);
    }

    public function bookingAktif(): ?Booking
    {
        return $this->booking()->where('status', 'aktif')->latest()->first();
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Kode unit display, mis. "DC-08". Nomor unit selalu zero-padded 2 digit
     * biar konsisten (DC-8 vs DC-08 di UI/laporan operasional).
     */
    protected function kodeUnit(): Attribute
    {
        return Attribute::make(
            get: fn () => "{$this->blok}-".str_pad((string) $this->nomor_unit, 2, '0', STR_PAD_LEFT),
        );
    }

    /** Nomor unit dgn zero-pad 2 digit ("8" → "08"). Untuk display standalone. */
    protected function nomorUnitPadded(): Attribute
    {
        return Attribute::make(
            get: fn () => str_pad((string) $this->nomor_unit, 2, '0', STR_PAD_LEFT),
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
     * Harga jual efektif untuk unit ini = harga master tipe.
     * Field `biaya_tambahan` (hook/view/dll) diproses TERPISAH via tabel
     * `biaya_tambahan_realisasi`, tidak masuk ke SPR / total harga.
     */
    protected function hargaJual(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->tipeRumah?->harga_jual ?? 0),
        );
    }

    /**
     * Total nominal realisasi biaya tambahan yg sudah dibayar (tidak refunded).
     * Butuh eager-load biayaTambahanRealisasi supaya tidak N+1.
     */
    protected function totalRealisasiBiayaTambahan(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->biayaTambahanRealisasi
                ->where('is_refunded', false)
                ->sum('jumlah'),
        );
    }

    /** Sisa biaya tambahan yg belum dibayar = biaya_tambahan − total realisasi. */
    protected function sisaBiayaTambahan(): Attribute
    {
        return Attribute::make(
            get: fn () => max(0, (float) $this->biaya_tambahan - $this->total_realisasi_biaya_tambahan),
        );
    }
}
