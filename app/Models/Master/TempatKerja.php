<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TempatKerja extends Model
{
    protected $table = 'tempat_kerja';

    protected $fillable = [
        'nama',
        'alamat',
        'bidang_usaha',
        'no_telepon',
        'updated_by_user_id',
    ];

    /** Perusahaan yang alamatnya belum diisi di master. */
    public function scopeTanpaAlamat(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('alamat')->orWhere('alamat', ''));
    }

    public function customer(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
