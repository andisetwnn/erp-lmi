<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $table = 'supplier';

    protected $fillable = [
        'nama',
        'kontak_nama',
        'hp',
        'email',
        'alamat',
        'npwp',
        'is_pkp',
        'bank_id',
        'nomor_rekening',
        'rekening_atas_nama',
        'is_aktif',
        'keterangan',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'is_pkp' => false,
        'is_aktif' => true,
    ];

    protected $casts = [
        'is_pkp' => 'boolean',
        'is_aktif' => 'boolean',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function pesanan(): HasMany
    {
        return $this->hasMany(PesananPembelian::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
