<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlasanPembatalan extends Model
{
    protected $table = 'alasan_pembatalan';

    protected $fillable = [
        'nama',
        'dapat_meneruskan_angsuran',
        'is_aktif',
        'updated_by_user_id',
    ];

    protected $casts = [
        'dapat_meneruskan_angsuran' => 'boolean',
        'is_aktif' => 'boolean',
    ];

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
