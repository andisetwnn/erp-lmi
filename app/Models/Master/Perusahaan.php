<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Perusahaan extends Model
{
    protected $table = 'perusahaan';

    protected $fillable = [
        'nama',
        'kode_surat',
        'logo',
        'alamat',
        'no_telepon',
        'updated_by_user_id',
    ];

    public function coa(): HasMany
    {
        return $this->hasMany(Coa::class);
    }
}
