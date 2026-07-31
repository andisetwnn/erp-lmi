<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $table = 'bank';

    protected $fillable = [
        'nama',
        'alamat',
        'updated_by_user_id',
    ];

    public function customer(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sales::class);
    }

    public function notaris(): HasMany
    {
        return $this->hasMany(Notaris::class);
    }

    public function virtualAccount(): HasMany
    {
        return $this->hasMany(VirtualAccount::class);
    }
}
