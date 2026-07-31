<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectCustomerKontakDarurat extends Model
{
    protected $table = 'prospect_customer_kontak_darurat';

    /** Opsi hubungan untuk dropdown. */
    public const HUBUNGAN_OPTIONS = [
        'orang_tua' => 'Orang Tua',
        'saudara' => 'Saudara',
        'pasangan' => 'Pasangan',
        'anak' => 'Anak',
        'teman' => 'Teman',
        'lainnya' => 'Lainnya',
    ];

    protected $fillable = [
        'prospect_customer_id',
        'nama',
        'hubungan',
        'nomor_telepon',
    ];

    public function prospectCustomer(): BelongsTo
    {
        return $this->belongsTo(ProspectCustomer::class);
    }
}
