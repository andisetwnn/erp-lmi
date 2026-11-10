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
        'batas_persetujuan_direktur',
        'batas_po_gantung_hari',
        'updated_by_user_id',
    ];

    protected $casts = [
        'batas_persetujuan_direktur' => 'decimal:2',
        'batas_po_gantung_hari' => 'integer',
    ];

    public function coa(): HasMany
    {
        return $this->hasMany(Coa::class);
    }
}
