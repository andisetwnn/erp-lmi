<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SprTerminPembayaran extends Model
{
    protected $table = 'spr_termin_pembayaran';

    /** Opsi jenis termin pembayaran. */
    public const JENIS_OPTIONS = [
        'bf' => 'Booking Fee (UTJ)',
        'um' => 'Uang Muka (UM)',
        'sbum' => 'SBUM (Subsidi)',
    ];

    protected $fillable = [
        'spr_id',
        'jenis',
        'urutan',
        'tanggal_jadwal',
        'jumlah_jadwal',
        'nomor_kwitansi',
        'tanggal_realisasi',
        'jumlah',
        'keterangan',
        'input_by_user_id',
    ];

    protected $casts = [
        'tanggal_jadwal' => 'date',
        'tanggal_realisasi' => 'date',
        'jumlah_jadwal' => 'decimal:2',
        'jumlah' => 'decimal:2',
        'urutan' => 'integer',
    ];

    public function spr(): BelongsTo
    {
        return $this->belongsTo(Spr::class);
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'input_by_user_id');
    }

    /**
     * Label tampil: "BF", "UM-1", "UM-2", ..., "SBUM"
     */
    public function label(): string
    {
        return match ($this->jenis) {
            'bf' => 'BF',
            'sbum' => 'SBUM',
            'um' => 'UM-'.$this->urutan,
            default => strtoupper($this->jenis),
        };
    }

    public function isPaid(): bool
    {
        return $this->tanggal_realisasi !== null && $this->jumlah > 0;
    }
}
