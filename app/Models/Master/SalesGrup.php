<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesGrup extends Model
{
    protected $table = 'sales_grup';

    protected $fillable = [
        'nama',
        'pimpinan_id',
        'updated_by_user_id',
    ];

    public function pimpinan(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'pimpinan_id');
    }

    public function anggota(): HasMany
    {
        return $this->hasMany(Sales::class, 'sales_grup_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
