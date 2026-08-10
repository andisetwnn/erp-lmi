<?php

namespace App\Models\Akunting;

use App\Models\Master\Coa;
use App\Models\Master\Perusahaan;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AktivaTetap extends Model
{
    protected $table = 'aktiva_tetap';

    protected $fillable = [
        'perusahaan_id',
        'kode',
        'nama',
        'kategori',
        'tgl_perolehan',
        'harga_perolehan',
        'umur_ekonomis_bulan',
        'metode_penyusutan',
        'nilai_residu',
        'akumulasi_penyusutan',
        'lokasi',
        'keterangan',
        'status',
        'tgl_status_akhir',
        'coa_aset_id',
        'coa_akumulasi_id',
        'coa_penyusutan_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'tgl_perolehan' => 'date',
        'tgl_status_akhir' => 'date',
        'harga_perolehan' => 'decimal:2',
        'nilai_residu' => 'decimal:2',
        'akumulasi_penyusutan' => 'decimal:2',
        'umur_ekonomis_bulan' => 'integer',
    ];

    public const KATEGORI = [
        'Tanah',
        'Bangunan',
        'Kendaraan',
        'Inventaris Kantor',
        'Mesin & Peralatan',
        'Lainnya',
    ];

    public const METODE = [
        'garis_lurus' => 'Garis Lurus',
        'saldo_menurun' => 'Saldo Menurun',
        'tidak_disusutkan' => 'Tidak Disusutkan',
    ];

    public const STATUS = [
        'aktif' => 'Aktif',
        'dijual' => 'Dijual',
        'dihapus' => 'Dihapus',
        'rusak' => 'Rusak',
    ];

    public function perusahaan(): BelongsTo
    {
        return $this->belongsTo(Perusahaan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function coaAset(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'coa_aset_id');
    }

    public function coaAkumulasi(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'coa_akumulasi_id');
    }

    public function coaPenyusutan(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'coa_penyusutan_id');
    }

    /** Nilai Buku = Harga Perolehan - Akumulasi Penyusutan. */
    protected function nilaiBuku(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->harga_perolehan - (float) $this->akumulasi_penyusutan,
        );
    }

    /** Penyusutan per bulan (garis lurus). */
    protected function penyusutanPerBulan(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->metode_penyusutan === 'tidak_disusutkan' || $this->umur_ekonomis_bulan <= 0) {
                    return 0.0;
                }
                $baseValue = (float) $this->harga_perolehan - (float) $this->nilai_residu;

                return $baseValue / $this->umur_ekonomis_bulan;
            },
        );
    }
}
