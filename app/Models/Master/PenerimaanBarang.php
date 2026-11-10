<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PenerimaanBarang extends Model
{
    protected $table = 'penerimaan_barang';

    protected $fillable = [
        'pesanan_pembelian_id',
        'tanggal',
        'nomor_surat_jalan',
        'foto_path',
        'foto_nama',
        'catatan',
        'input_by_user_id',
    ];

    protected $casts = [
        'tanggal' => 'date',
    ];

    public function pesanan(): BelongsTo
    {
        return $this->belongsTo(PesananPembelian::class, 'pesanan_pembelian_id');
    }

    public function item(): HasMany
    {
        return $this->hasMany(PenerimaanBarangItem::class)->orderBy('id');
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by_user_id');
    }
}
