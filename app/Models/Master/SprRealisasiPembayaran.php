<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SprRealisasiPembayaran extends Model
{
    protected $table = 'spr_realisasi_pembayaran';

    public const JENIS_OPTIONS = [
        'bf' => 'UTJ (Booking Fee)',
        'um' => 'Uang Muka (UM)',
        'sbum' => 'SBUM (Subsidi)',
        'kpr' => 'Pencairan KPR',
    ];

    protected $fillable = [
        'spr_id',
        'switching_id',
        'jenis',
        'tanggal_bayar',
        'jumlah',
        'nomor_kwitansi',
        'metode',
        'keterangan',
        'input_by_user_id',
    ];

    protected $casts = [
        'tanggal_bayar' => 'date',
        'jumlah' => 'decimal:2',
    ];

    public function spr(): BelongsTo
    {
        return $this->belongsTo(Spr::class);
    }

    public function switching(): BelongsTo
    {
        return $this->belongsTo(SprSwitching::class, 'switching_id');
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by_user_id');
    }

    /**
     * Generate nomor kuitansi berikutnya (5-digit sequential global).
     *
     * Increment-safe pakai pessimistic lock supaya concurrent generation tidak collide.
     * WAJIB dipanggil di dalam DB::transaction — lock berlaku sampai transaction commit.
     */
    public static function generateNextNomor(): string
    {
        $driver = \DB::connection()->getDriverName();
        // MySQL: REGEXP + CAST AS UNSIGNED. SQLite (test): GLOB pattern + CAST AS INTEGER.
        if ($driver === 'mysql') {
            $query = static::whereNotNull('nomor_kwitansi')
                ->where('nomor_kwitansi', 'REGEXP', '^[0-9]+$')
                ->lockForUpdate();
            $last = (int) $query->max(\DB::raw('CAST(nomor_kwitansi AS UNSIGNED)'));
        } else {
            $query = static::whereNotNull('nomor_kwitansi')
                ->where('nomor_kwitansi', 'GLOB', '[0-9]*')
                ->lockForUpdate();
            $last = (int) $query->max(\DB::raw('CAST(nomor_kwitansi AS INTEGER)'));
        }

        return str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
