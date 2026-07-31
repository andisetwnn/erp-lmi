<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notaris extends Model
{
    protected $table = 'notaris';

    protected $fillable = [
        'nama',
        'nik',
        'bank_id',
        'nomor_rekening',
        'hp',
        'alamat',
        'updated_by_user_id',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function biayaAjbHistory(): HasMany
    {
        return $this->hasMany(NotarisBiayaAjbHistory::class)->orderByDesc('berlaku_mulai');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function biayaAjbAktif(?string $tanggal = null): ?NotarisBiayaAjbHistory
    {
        return $this->biayaAjbHistory()
            ->where('berlaku_mulai', '<=', $tanggal ?? now()->toDateString())
            ->orderByDesc('berlaku_mulai')
            ->first();
    }
}
