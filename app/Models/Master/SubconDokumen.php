<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SubconDokumen extends Model
{
    protected $table = 'subcon_dokumen';

    protected $fillable = [
        'subcon_id',
        'jenis',
        'nomor',
        'berlaku_sampai',
        'file_path',
        'file_original_name',
        'catatan',
        'updated_by_user_id',
    ];

    protected $casts = [
        'berlaku_sampai' => 'date',
    ];

    public const LABEL_JENIS = [
        'npwp' => 'NPWP',
        'siup' => 'SIUP',
        'sbu' => 'SBU',
        'siujk' => 'SIUJK',
        'pkp' => 'PKP',
        'ska' => 'SKA',
    ];

    /** NPWP dan PKP tidak punya masa berlaku. */
    public const TANPA_MASA_BERLAKU = ['npwp', 'pkp'];

    public function subcon(): BelongsTo
    {
        return $this->belongsTo(Subcon::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function perluMasaBerlaku(): bool
    {
        return ! in_array($this->jenis, self::TANPA_MASA_BERLAKU, true);
    }

    public function kadaluarsa(): bool
    {
        return $this->berlaku_sampai !== null && $this->berlaku_sampai->isPast();
    }

    /**
     * Berlaku kalau tidak punya masa berlaku sama sekali (NPWP, PKP), atau
     * tanggalnya belum lewat.
     */
    public function masihBerlaku(): bool
    {
        return ! $this->kadaluarsa();
    }

    /** Akan habis dalam 60 hari ke depan — perlu diurus sebelum telat. */
    public function segeraKadaluarsa(int $hari = 60): bool
    {
        return $this->berlaku_sampai !== null
            && ! $this->kadaluarsa()
            && $this->berlaku_sampai->lte(now()->addDays($hari));
    }

    public function adaDiPenyimpanan(): bool
    {
        return $this->file_path !== null && Storage::disk('private')->exists($this->file_path);
    }

    public function label(): string
    {
        return self::LABEL_JENIS[$this->jenis] ?? strtoupper($this->jenis);
    }
}
