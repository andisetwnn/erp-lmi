<?php

namespace App\Models\Akunting;

use App\Models\Master\Perusahaan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Jurnal extends Model
{
    protected $table = 'jurnal';

    protected $fillable = [
        'perusahaan_id',
        'tanggal',
        'no_bukti',
        'tipe',
        'kategori_bukti',
        'keterangan',
        'sumber_type',
        'sumber_id',
        'status',
        'posted_by_user_id',
        'posted_at',
        'reversed_from_jurnal_id',
        'created_by_user_id',
    ];

    /** 6 kategori bukti — untuk auto-generate prefix no bukti. */
    public const KATEGORI_BUKTI = [
        'BANK' => 'Bank',
        'KAS' => 'Kas',
        'PENJ' => 'Penjualan',
        'AKM' => 'Akuntansi Memorial',
        'RJE' => 'Reversing Journal Entry',
        'HPP' => 'HPP (Harga Pokok Penjualan)',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'posted_at' => 'datetime',
    ];

    public function perusahaan(): BelongsTo
    {
        return $this->belongsTo(Perusahaan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function detail(): HasMany
    {
        return $this->hasMany(JurnalDetail::class);
    }

    /** Polymorphic sumber: SPR / Kwitansi / dll (kalau auto-generated). */
    public function sumber(): MorphTo
    {
        return $this->morphTo();
    }

    /** Jurnal asal kalau ini reversal. */
    public function reversedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_from_jurnal_id');
    }

    /** Jurnal reversal yg pernah dibuat untuk membalik ini. */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_from_jurnal_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function totalDebet(): float
    {
        return (float) $this->detail->sum('debet');
    }

    public function totalKredit(): float
    {
        return (float) $this->detail->sum('kredit');
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebet() - $this->totalKredit()) < 0.01;
    }
}
