<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetMarketing extends Model
{
    protected $table = 'target_marketing';

    protected $fillable = [
        'proyek_id',
        'tahun',
        'bulan',
        'target_akad',
        'target_penjualan',
        'catatan',
        'updated_by_user_id',
    ];

    protected $casts = [
        'tahun' => 'integer',
        'bulan' => 'integer',
        'target_akad' => 'integer',
        'target_penjualan' => 'integer',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
