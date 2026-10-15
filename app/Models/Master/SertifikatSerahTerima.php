<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu penyerahan fisik sertifikat ke bank, beserta satu dokumen BAST.
 */
class SertifikatSerahTerima extends Model
{
    protected $table = 'sertifikat_serah_terima';

    protected $fillable = [
        'proyek_id',
        'tanggal',
        'file_path',
        'file_original_name',
        'catatan',
        'created_by_user_id',
    ];

    protected $casts = [
        'tanggal' => 'date',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function sertifikat(): HasMany
    {
        return $this->hasMany(Sertifikat::class, 'sertifikat_serah_terima_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
