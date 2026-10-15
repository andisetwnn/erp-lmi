<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu berkas pengajuan pecah sertifikat ke BPN, memuat banyak unit.
 */
class SertifikatPengajuan extends Model
{
    protected $table = 'sertifikat_pengajuan';

    protected $fillable = [
        'proyek_id',
        'nomor',
        'tanggal_pengajuan',
        'metode',
        'blok',
        'catatan',
        'created_by_user_id',
    ];

    protected $casts = [
        'tanggal_pengajuan' => 'date',
    ];

    protected $attributes = [
        'metode' => 'kavling',
    ];

    public const LABEL_METODE = [
        'blok' => 'Kolektif se-blok',
        'kavling' => 'Per kavling',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function sertifikat(): HasMany
    {
        return $this->hasMany(Sertifikat::class, 'sertifikat_pengajuan_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function labelMetode(): string
    {
        return self::LABEL_METODE[$this->metode] ?? $this->metode;
    }
}
