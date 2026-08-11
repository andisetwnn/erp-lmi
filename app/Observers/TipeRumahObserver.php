<?php

namespace App\Observers;

use App\Models\Master\TipeRumah;
use App\Support\BusinessActivityLogger;

class TipeRumahObserver
{
    /**
     * Field master yg kalau berubah dianggap penting untuk di-log.
     * Semua kolom fillable kecuali FK, siteplan geometry, dan audit column.
     */
    private const TRACKED_FIELDS = [
        'tipe',
        'nama_tipe',
        'kategori',
        'luas_tanah',
        'luas_bangunan',
        'harga_jual',
        'harga_all_in',
        'plafon_kpr',
        'biaya_administrasi',
        'utj',
        'sbum',
        'spesifikasi',
        'is_aktif',
    ];

    public function created(TipeRumah $tipe): void
    {
        BusinessActivityLogger::tipeRumahCreated($tipe);
    }

    public function updated(TipeRumah $tipe): void
    {
        $changes = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if ($tipe->wasChanged($field)) {
                $changes[$field] = [
                    'from' => $tipe->getOriginal($field),
                    'to' => $tipe->{$field},
                ];
            }
        }
        if (! empty($changes)) {
            BusinessActivityLogger::tipeRumahUpdated($tipe, $changes);
        }
    }

    public function deleted(TipeRumah $tipe): void
    {
        BusinessActivityLogger::tipeRumahDeleted($tipe);
    }
}
