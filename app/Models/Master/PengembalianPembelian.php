<?php

namespace App\Models\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uang kelebihan bayar yang dikembalikan supplier untuk sebuah PO.
 */
class PengembalianPembelian extends Model
{
    protected $table = 'pengembalian_pembelian';

    protected $fillable = [
        'pesanan_pembelian_id',
        'tanggal',
        'jumlah',
        'rekening_coa_id',
        'bukti_path',
        'bukti_nama',
        'keterangan',
        'input_by_user_id',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
    ];

    public function pesanan(): BelongsTo
    {
        return $this->belongsTo(PesananPembelian::class, 'pesanan_pembelian_id');
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'rekening_coa_id');
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by_user_id');
    }
}
