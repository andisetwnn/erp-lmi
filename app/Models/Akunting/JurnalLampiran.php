<?php

namespace App\Models\Akunting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class JurnalLampiran extends Model
{
    protected $table = 'jurnal_lampiran';

    protected $fillable = [
        'jurnal_id',
        'file_path',
        'file_original_name',
        'ukuran',
        'mime',
        'keterangan',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'ukuran' => 'integer',
    ];

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function adaDiPenyimpanan(): bool
    {
        return Storage::disk('private')->exists($this->file_path);
    }

    /** Ukuran ringkas untuk ditampilkan, mis. "1,4 MB". */
    public function ukuranTerbaca(): string
    {
        $bytes = (int) $this->ukuran;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 0, ',', '.').' KB';
        }

        return number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
    }
}
