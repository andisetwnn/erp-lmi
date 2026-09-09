<?php

namespace App\Models\Matrix;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatrixImport extends Model
{
    protected $table = 'matrix_import';

    protected $fillable = [
        'nama_file',
        'proyek',
        'minggu_ke',
        'periode',
        'jumlah_baris',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_baris' => 'integer',
        ];
    }

    public function unit(): HasMany
    {
        return $this->hasMany(MatrixUnit::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Unggahan paling akhir — itu yang dipakai semua laporan Matrix. */
    public static function terakhir(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
