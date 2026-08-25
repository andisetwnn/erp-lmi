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
     * Bisa di-boost dari data legacy lewat env LEGACY_MAX_NOMOR_KWITANSI
     * (lihat config/legacy.php). Nomor baru = max(DB MAX, LEGACY MAX) + 1.
     *
     * Increment-safe pakai pessimistic lock. WAJIB dipanggil di dalam
     * DB::transaction — lock berlaku sampai transaction commit.
     */
    public static function generateNextNomor(): string
    {
        $driver = \DB::connection()->getDriverName();
        // Buku kuitansi UM terpisah dari biaya tambahan — cukup cek tabel sendiri.
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

        $lastNum = max($last, (int) config('legacy.max_nomor_kwitansi', 0));

        return str_pad((string) ($lastNum + 1), 5, '0', STR_PAD_LEFT);
    }
}
