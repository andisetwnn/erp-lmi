<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Jejak satu orang membaca satu versi panduan: kapan dibuka dan kapan ia
 * mengonfirmasi sudah membacanya. Diisi lewat App\Support\Panduan.
 */
class PanduanBaca extends Model
{
    protected $table = 'panduan_baca';

    protected $fillable = [
        'pembaca_type',
        'pembaca_id',
        'kunci',
        'versi',
        'jumlah_dibuka',
        'dibuka_pertama_at',
        'dibuka_terakhir_at',
        'dibaca_at',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_dibuka' => 'integer',
            'dibuka_pertama_at' => 'datetime',
            'dibuka_terakhir_at' => 'datetime',
            'dibaca_at' => 'datetime',
        ];
    }

    public function pembaca(): MorphTo
    {
        return $this->morphTo();
    }
}
