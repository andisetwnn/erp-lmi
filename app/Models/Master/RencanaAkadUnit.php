<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RencanaAkadUnit extends Model
{
    protected $table = 'rencana_akad_unit';

    protected $fillable = [
        'rencana_akad_id',
        'spr_id',
        'urutan',
        'jenis_akad',
        'nominal_ppjb',
        'hgb',
        'status',
        'alasan_batal',
        'dibatalkan_at',
        'dibatalkan_by_user_id',
        'nilai_ajb',
        'bp2bt',
        'setoran_ajb',
        'setoran_bphtb',
        'promo_um',
        'promo_ajb',
        'promo_bphtb',
        'bayar_ps4a2',
        'bayar_bi_notaris',
        'bayar_bphtb',
        'tanggal_ppjb',
        'tanggal_ajb',
        'ntpn',
        'catatan',
        'updated_by_user_id',
    ];

    /** Default milik model, supaya objek baru tidak punya status null. */
    protected $attributes = [
        'status' => 'draft',
        'jenis_akad' => 'ppjb',
    ];

    protected $casts = [
        'nominal_ppjb' => 'decimal:2',
        'nilai_ajb' => 'decimal:2',
        'dibatalkan_at' => 'datetime',
        'bp2bt' => 'decimal:2',
        'setoran_ajb' => 'decimal:2',
        'setoran_bphtb' => 'decimal:2',
        'promo_um' => 'decimal:2',
        'promo_ajb' => 'decimal:2',
        'promo_bphtb' => 'decimal:2',
        'bayar_ps4a2' => 'decimal:2',
        'bayar_bi_notaris' => 'decimal:2',
        'bayar_bphtb' => 'decimal:2',
        'tanggal_ppjb' => 'date',
        'tanggal_ajb' => 'date',
    ];

    public const LABEL_JENIS_AKAD = [
        'ppjb' => 'PPJB',
        'ajb' => 'AJB',
    ];

    public function rencanaAkad(): BelongsTo
    {
        return $this->belongsTo(RencanaAkad::class);
    }

    public function spr(): BelongsTo
    {
        return $this->belongsTo(Spr::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** Akadnya sudah benar-benar terjadi, bukan sekadar dijadwalkan. */
    public function sudahAkad(): bool
    {
        return $this->tanggal_ppjb !== null || $this->tanggal_ajb !== null;
    }
}
