<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiayaTambahanRealisasi extends Model
{
    protected $table = 'biaya_tambahan_realisasi';

    protected $fillable = [
        'rumah_id',
        'spr_id',
        'tanggal_bayar',
        'nomor_kuitansi',
        'jumlah',
        'metode',
        'keterangan',
        'is_refunded',
        'refunded_at',
        'refunded_by_user_id',
        'refund_keterangan',
        'input_by_user_id',
    ];

    protected $casts = [
        'tanggal_bayar' => 'date',
        'jumlah' => 'decimal:2',
        'is_refunded' => 'boolean',
        'refunded_at' => 'date',
    ];

    public const METODE = [
        'cash' => 'Cash',
        'transfer' => 'Transfer',
    ];

    public function rumah(): BelongsTo
    {
        return $this->belongsTo(Rumah::class);
    }

    public function spr(): BelongsTo
    {
        return $this->belongsTo(Spr::class);
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by_user_id');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by_user_id');
    }

    /**
     * Generate nomor kuitansi berikutnya untuk BUKU BIAYA TAMBAHAN (terpisah dari buku UM).
     * Boleh nomor sama dengan UM karena buku kuitansi fisik berbeda.
     * WAJIB dipanggil di dalam DB::transaction — lock berlaku sampai commit.
     */
    public static function generateNextNomor(): string
    {
        $driver = \DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $query = static::whereNotNull('nomor_kuitansi')
                ->where('nomor_kuitansi', 'REGEXP', '^[0-9]+$')
                ->lockForUpdate();
            $last = (int) $query->max(\DB::raw('CAST(nomor_kuitansi AS UNSIGNED)'));
        } else {
            $query = static::whereNotNull('nomor_kuitansi')
                ->where('nomor_kuitansi', 'GLOB', '[0-9]*')
                ->lockForUpdate();
            $last = (int) $query->max(\DB::raw('CAST(nomor_kuitansi AS INTEGER)'));
        }

        return str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
