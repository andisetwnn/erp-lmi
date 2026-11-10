<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermintaanPembelianItem extends Model
{
    protected $table = 'permintaan_pembelian_item';

    protected $fillable = [
        'permintaan_pembelian_id',
        'nama_barang',
        'spesifikasi',
        'jumlah',
        'satuan',
        'harga_perkiraan',
    ];

    protected $casts = [
        'jumlah' => 'decimal:2',
        'harga_perkiraan' => 'decimal:2',
    ];

    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(PermintaanPembelian::class, 'permintaan_pembelian_id');
    }

    /** Baris PO yang memesan barang ini (satu barang bisa dipecah ke beberapa supplier). */
    public function itemPesanan(): HasMany
    {
        return $this->hasMany(PesananPembelianItem::class, 'permintaan_pembelian_item_id');
    }

    /** Jumlah yang belum masuk PO mana pun (PO batal tidak dihitung). Butuh relasi itemPesanan.pesanan. */
    public function sisaBelumDipesan(): float
    {
        $dipesan = (float) $this->itemPesanan->filter(fn (PesananPembelianItem $p) => $p->pesanan?->status !== 'batal')->sum('jumlah');

        return max(0, round((float) $this->jumlah - $dipesan, 2));
    }

    public function subtotal(): float
    {
        return (float) $this->jumlah * (float) $this->harga_perkiraan;
    }
}
