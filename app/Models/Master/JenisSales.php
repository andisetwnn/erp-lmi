<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JenisSales extends Model
{
    protected $table = 'jenis_sales';

    protected $fillable = [
        'nama',
        'keterangan',
        'updated_by_user_id',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sales::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
