<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RumahProgresLog extends Model
{
    protected $table = 'rumah_progres_log';

    /** created_at diisi manual saat insert (dari observer). */
    public $timestamps = false;

    protected $fillable = [
        'rumah_id',
        'progres_dari',
        'progres_ke',
        'catatan',
        'updated_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'progres_dari' => 'integer',
        'progres_ke' => 'integer',
        'created_at' => 'datetime',
    ];

    public function rumah(): BelongsTo
    {
        return $this->belongsTo(Rumah::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
