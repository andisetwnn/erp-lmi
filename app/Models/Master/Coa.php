<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coa extends Model
{
    protected $table = 'coa';

    protected $fillable = [
        'perusahaan_id',
        'kode',
        'nama',
        'tipe',
        'saldo_normal',
        'parent_id',
        'is_header',
        'is_aktif',
        'satuan_hpp',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_header' => 'boolean',
        'is_aktif' => 'boolean',
    ];

    public function perusahaan(): BelongsTo
    {
        return $this->belongsTo(Perusahaan::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Coa::class, 'parent_id');
    }

    public function virtualAccount(): HasMany
    {
        return $this->hasMany(VirtualAccount::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
