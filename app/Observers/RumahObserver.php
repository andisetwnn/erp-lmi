<?php

namespace App\Observers;

use App\Models\Master\Rumah;
use App\Support\BusinessActivityLogger;

class RumahObserver
{
    /** Field master yg kalau berubah dianggap penting untuk di-log. */
    private const TRACKED_FIELDS = [
        'proyek_id',
        'tipe_rumah_id',
        'blok',
        'nomor_unit',
        'biaya_tambahan',
        'discount',
        'ppn',
        'tanggal_launching',
    ];

    public function created(Rumah $rumah): void
    {
        BusinessActivityLogger::unitCreated($rumah);
    }

    public function updated(Rumah $rumah): void
    {
        // Kalau status berubah → log status_changed
        if ($rumah->wasChanged('status')) {
            BusinessActivityLogger::unitStatusChanged(
                $rumah,
                (string) $rumah->getOriginal('status'),
                (string) $rumah->status,
            );
        }

        // Kalau field master berubah → log unit_updated (skip kalau cuma status)
        $masterChanges = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if ($rumah->wasChanged($field)) {
                $masterChanges[$field] = [
                    'from' => $rumah->getOriginal($field),
                    'to' => $rumah->{$field},
                ];
            }
        }
        if (! empty($masterChanges)) {
            BusinessActivityLogger::unitUpdated($rumah, $masterChanges);
        }
    }
}
