<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerKontakDarurat extends Model
{
    protected $table = 'customer_kontak_darurat';

    protected $fillable = [
        'customer_id',
        'nama',
        'hubungan',
        'nomor_telepon',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
