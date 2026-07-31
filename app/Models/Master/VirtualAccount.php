<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualAccount extends Model
{
    protected $table = 'virtual_account';

    protected $fillable = [
        'proyek_id',
        'rumah_id',
        'bank_id',
        'nomor_va',
        'is_aktif',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_aktif' => 'boolean',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function rumah(): BelongsTo
    {
        return $this->belongsTo(Rumah::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
