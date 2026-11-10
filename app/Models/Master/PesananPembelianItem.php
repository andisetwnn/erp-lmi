<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PesananPembelianItem extends Model
{
    protected $table = 'pesanan_pembelian_item';

    protected $fillable = [
        'pesanan_pembelian_id',
        'permintaan_pembelian_item_id',
        'nama_barang',
        'spesifikasi',
        'jumlah',
        'jumlah_awal',
        'satuan',
        'harga',
        'jumlah_diterima',
    ];

    protected $casts = [
        'jumlah' => 'decimal:2',
        'jumlah_awal' => 'decimal:2',
        'harga' => 'decimal:2',
        'jumlah_diterima' => 'decimal:2',
    ];

    public function pesanan(): BelongsTo
    {
        return $this->belongsTo(PesananPembelian::class, 'pesanan_pembelian_id');
    }

    public function itemPermintaan(): BelongsTo
    {
        return $this->belongsTo(PermintaanPembelianItem::class, 'permintaan_pembelian_item_id');
    }

    public function subtotal(): float
    {
        return (float) $this->jumlah * (float) $this->harga;
    }

    public function sisaDiterima(): float
    {
        return max(0, round((float) $this->jumlah - (float) $this->jumlah_diterima, 2));
    }
}
