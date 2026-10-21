<?php

namespace App\Models\Master;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promo Penjualan — ditanggung developer, dikelola Admin Sales.
 *
 * Lihat migrasinya untuk aturan lengkap: satu SPR satu promo, masa berlaku
 * dicocokkan dengan tanggal UTJ, dan tidak boleh beririsan dalam satu proyek.
 */
class PromoPenjualan extends Model
{
    protected $table = 'promo_penjualan';

    public const JENIS = [
        'um' => 'Potongan UM',
        'booking_fee_tetap' => 'Booking fee spesial',
        'booking_fee' => 'Booking fee sesuai usia',
    ];

    public const DASAR_USIA = [
        'usia' => 'Usia (tahun)',
        'tahun_lahir' => 'Tahun lahir',
    ];

    protected $fillable = [
        'proyek_id',
        'nama',
        'jenis',
        'berlaku_mulai',
        'berlaku_sampai',
        'potongan_um',
        'booking_fee_tetap',
        'dasar_usia',
        'semua_unit',
        'keterangan',
        'is_aktif',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'berlaku_mulai' => 'date',
        'berlaku_sampai' => 'date',
        'potongan_um' => 'decimal:2',
        'booking_fee_tetap' => 'decimal:2',
        'semua_unit' => 'boolean',
        'is_aktif' => 'boolean',
    ];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function rumah(): BelongsToMany
    {
        return $this->belongsToMany(Rumah::class, 'promo_penjualan_rumah');
    }

    public function tingkat(): HasMany
    {
        return $this->hasMany(PromoPenjualanTingkat::class)->orderBy('dari');
    }

    public function spr(): HasMany
    {
        return $this->hasMany(Spr::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Promo aktif yang masa berlakunya mencakup tanggal itu. */
    public function scopeBerlakuPada(Builder $q, CarbonInterface|string $tanggal): Builder
    {
        $tgl = $tanggal instanceof CarbonInterface ? $tanggal->toDateString() : $tanggal;

        return $q->where('is_aktif', true)
            ->whereDate('berlaku_mulai', '<=', $tgl)
            ->whereDate('berlaku_sampai', '>=', $tgl);
    }

    public function mencakup(Rumah $rumah): bool
    {
        if ((int) $rumah->proyek_id !== (int) $this->proyek_id) {
            return false;
        }

        return $this->semua_unit || $this->rumah()->whereKey($rumah->id)->exists();
    }

    public function labelJenis(): string
    {
        return self::JENIS[$this->jenis] ?? $this->jenis;
    }

    /**
     * Ringkasan potongan dalam satu kalimat untuk sales, mis. "Booking fee
     * sesuai usia 23–40 th · Rp 230.000 – Rp 400.000". Butuh relasi tingkat.
     */
    public function ringkasanPotongan(): string
    {
        $rp = fn (float $n) => 'Rp '.number_format($n, 0, ',', '.');

        if ($this->jenis === 'um') {
            return 'Potongan UM '.$rp((float) $this->potongan_um);
        }

        if ($this->jenis === 'booking_fee_tetap') {
            return 'Booking fee '.$rp((float) $this->booking_fee_tetap).' · semua konsumen';
        }

        $tingkat = $this->tingkat;
        if ($tingkat->isEmpty()) {
            return $this->labelJenis();
        }

        $rentang = $this->dasar_usia === 'tahun_lahir'
            ? 'lahir '.$tingkat->min('dari').'–'.$tingkat->max('sampai')
            : 'usia '.$tingkat->min('dari').'–'.$tingkat->max('sampai').' th';
        $terkecil = (float) $tingkat->min('booking_fee');
        $terbesar = (float) $tingkat->max('booking_fee');

        return 'Booking fee sesuai '.$rentang.' · '.$rp($terkecil)
            .($terbesar > $terkecil ? ' – '.$rp($terbesar) : '');
    }

    public function sudahLewat(): bool
    {
        return $this->berlaku_sampai->lt(now()->startOfDay());
    }

    /** "Berjalan", "Akan datang", "Berakhir", atau "Nonaktif". */
    public function labelStatus(): string
    {
        return match (true) {
            ! $this->is_aktif => 'Nonaktif',
            $this->sudahLewat() => 'Berakhir',
            $this->berlaku_mulai->gt(now()->startOfDay()) => 'Akan datang',
            default => 'Berjalan',
        };
    }
}
