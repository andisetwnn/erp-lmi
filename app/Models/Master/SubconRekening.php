<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubconRekening extends Model
{
    protected $table = 'subcon_rekening';

    protected $fillable = [
        'subcon_id',
        'bank_id',
        'bank_nama',
        'nomor_rekening',
        'atas_nama',
        'is_utama',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'is_utama' => false,
    ];

    protected $casts = [
        'is_utama' => 'boolean',
    ];

    public function subcon(): BelongsTo
    {
        return $this->belongsTo(Subcon::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** Nama bank dari master kalau tertaut, kalau tidak dari ketikan. */
    public function namaBank(): string
    {
        return $this->bank?->nama ?? $this->bank_nama ?? '—';
    }
}
