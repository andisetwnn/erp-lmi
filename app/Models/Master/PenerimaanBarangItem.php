<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenerimaanBarangItem extends Model
{
    protected $table = 'penerimaan_barang_item';

    protected $fillable = [
        'penerimaan_barang_id',
        'pesanan_pembelian_item_id',
        'jumlah',
    ];

    protected $casts = [
        'jumlah' => 'decimal:2',
    ];

    public function penerimaan(): BelongsTo
    {
        return $this->belongsTo(PenerimaanBarang::class, 'penerimaan_barang_id');
    }

    public function itemPesanan(): BelongsTo
    {
        return $this->belongsTo(PesananPembelianItem::class, 'pesanan_pembelian_item_id');
    }
}
