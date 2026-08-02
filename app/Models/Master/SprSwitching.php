<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SprSwitching extends Model
{
    protected $table = 'spr_switching';

    protected $fillable = [
        'nomor_switching',
        'tipe',
        'alasan',
        'spr_lama_a_id',
        'spr_baru_a_id',
        'spr_lama_b_id',
        'spr_baru_b_id',
        'selisih_a',
        'selisih_b',
        'processed_by_user_id',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'selisih_a' => 'decimal:2',
        'selisih_b' => 'decimal:2',
    ];

    public function sprLamaA(): BelongsTo
    {
        return $this->belongsTo(Spr::class, 'spr_lama_a_id');
    }

    public function sprBaruA(): BelongsTo
    {
        return $this->belongsTo(Spr::class, 'spr_baru_a_id');
    }

    public function sprLamaB(): BelongsTo
    {
        return $this->belongsTo(Spr::class, 'spr_lama_b_id');
    }

    public function sprBaruB(): BelongsTo
    {
        return $this->belongsTo(Spr::class, 'spr_baru_b_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function realisasi(): HasMany
    {
        return $this->hasMany(SprRealisasiPembayaran::class, 'switching_id');
    }

    /** Generate nomor switching: PK/YYYY/MM/XXXX (reset per bulan). */
    public static function generateNextNomor(?\DateTimeInterface $for = null): string
    {
        $for = $for ?: now();
        $prefix = sprintf('PK/%s/%s/', $for->format('Y'), $for->format('m'));

        $last = self::where('nomor_switching', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('nomor_switching');

        $num = 0;
        if ($last && preg_match('#^'.preg_quote($prefix, '#').'(\d+)$#', $last, $m)) {
            $num = (int) $m[1];
        }

        return $prefix.str_pad((string) ($num + 1), 4, '0', STR_PAD_LEFT);
    }
}
