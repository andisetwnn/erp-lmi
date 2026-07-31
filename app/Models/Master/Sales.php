<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Sales extends Authenticatable
{
    use Notifiable;

    protected $table = 'sales';

    protected $fillable = [
        'jenis_sales_id',
        'sales_grup_id',
        'kode',
        'nama',
        'alamat',
        'telepon',
        'bank_id',
        'nomor_rekening',
        'nama_pemilik_rekening',
        'tanda_tangan_path',
        'is_aktif',
        'dbos_username',
        'dbos_password',
        'last_login_at',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_aktif' => 'boolean',
        'dbos_password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

    protected $hidden = [
        'dbos_password',
        'remember_token',
    ];

    // ===== AUTH OVERRIDES (multi-guard DBOS) =====
    public function getAuthPassword(): string
    {
        return $this->dbos_password ?? '';
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function jenisSales(): BelongsTo
    {
        return $this->belongsTo(JenisSales::class, 'jenis_sales_id');
    }

    public function grup(): BelongsTo
    {
        return $this->belongsTo(SalesGrup::class, 'sales_grup_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function logPerpindahan(): HasMany
    {
        return $this->hasMany(SalesLogPerpindahan::class);
    }

    public function prospectCustomer(): HasMany
    {
        return $this->hasMany(ProspectCustomer::class);
    }

    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SalesTarget::class);
    }

    /** Target sales untuk periode tertentu (YYYY-MM). Null kalau belum di-set. */
    public function targetForPeriode(string $periode): ?SalesTarget
    {
        return SalesTarget::where('sales_id', $this->id)
            ->where('periode', $periode)
            ->first();
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** Grup yang dipimpin oleh sales ini (kalau dia pimpinan). Null kalau bukan pimpinan. */
    public function grupYangDipimpin(): ?SalesGrup
    {
        return SalesGrup::where('pimpinan_id', $this->id)->first();
    }

    /** Cek apakah sales ini adalah pimpinan dari grup tertentu. */
    public function isPimpinan(): bool
    {
        return SalesGrup::where('pimpinan_id', $this->id)->exists();
    }
}
