<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $table = 'customer';

    protected $fillable = [
        'proyek_id',
        'nama_lengkap',
        'nik',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'agama',
        'status_perkawinan',
        'npwp',
        'hp',
        'hp_2',
        'sumber',
        'tempat_kerja_id',
        'alamat_ktp',
        'rt_rw',
        'provinsi_code',
        'provinsi_nama',
        'kota_code',
        'kota_nama',
        'kecamatan_code',
        'kecamatan_nama',
        'kelurahan_code',
        'kelurahan_nama',
        'catatan',
        'foto_ktp',
        'bank_id',
        'nomor_rekening',
        'rekening_atas_nama',
        'nama_pasangan',
        'nik_pasangan',
        'nama_perusahaan_bu',
        'bentuk_badan_usaha',
        'alamat_perusahaan',
        'no_telp_kantor',
        'jenis_pekerjaan',
        'penghasilan_bulanan',
        'bidang_usaha',
        'bi_kol',
        'bi_dbr',
        'bi_keterangan',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'penghasilan_bulanan' => 'decimal:2',
            'bi_dbr' => 'decimal:2',
            'tanggal_lahir' => 'date',
        ];
    }

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function tempatKerja(): BelongsTo
    {
        return $this->belongsTo(TempatKerja::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function kontakDarurat(): HasMany
    {
        return $this->hasMany(CustomerKontakDarurat::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
