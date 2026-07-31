<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proyek extends Model
{
    protected $table = 'proyek';

    protected $fillable = [
        'logo',
        'siteplan',
        'nama_proyek',
        'nama_perumahan',
        'desa',
        'kelurahan',
        'kecamatan',
        'kota_kabupaten',
        'kode_surat',
        'kode_akuntansi',
        'kode_virtual_account',
        'keterangan',
        'updated_by_user_id',
    ];

    public function tipeRumah(): HasMany
    {
        return $this->hasMany(TipeRumah::class);
    }

    public function rumah(): HasMany
    {
        return $this->hasMany(Rumah::class);
    }

    public function customer(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function prospectCustomer(): HasMany
    {
        return $this->hasMany(ProspectCustomer::class);
    }

    public function booking(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    // Auto count dari rumah
    public function getTotalUnitAttribute(): int
    {
        return $this->rumah()->count();
    }

    public function getUnitLaunchingAttribute(): int
    {
        return $this->rumah()->where('status', 'available')->count();
    }
}
