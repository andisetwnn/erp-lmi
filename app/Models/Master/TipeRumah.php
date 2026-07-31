<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipeRumah extends Model
{
    protected $table = 'tipe_rumah';

    protected $fillable = [
        'proyek_id',
        'tipe',
        'nama_tipe',
        'kategori',
        'luas_tanah',
        'luas_bangunan',
        'harga_jual',
        'harga_all_in',
        'plafon_kpr',
        'biaya_administrasi',
        'utj',
        'sbum',
        'spesifikasi',
        'is_aktif',
        'updated_by_user_id',
    ];

    protected $casts = [
        'luas_tanah' => 'decimal:2',
        'luas_bangunan' => 'decimal:2',
        'harga_jual' => 'decimal:2',
        'harga_all_in' => 'decimal:2',
        'plafon_kpr' => 'decimal:2',
        'biaya_administrasi' => 'decimal:2',
        'utj' => 'decimal:2',
        'sbum' => 'decimal:2',
        'is_aktif' => 'boolean',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function rumah(): HasMany
    {
        return $this->hasMany(Rumah::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function getTotalUnitAttribute(): int
    {
        return $this->rumah()->count();
    }

    public function getUnitLaunchingAttribute(): int
    {
        return $this->rumah()->where('status', 'available')->count();
    }
}
