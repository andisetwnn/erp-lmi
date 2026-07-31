<?php

namespace App\Observers;

use App\Models\Master\Rumah;
use App\Models\Master\Spr;

class SprObserver
{
    /**
     * Sinkronkan rumah.status mengikuti status SPR.
     * - approved              → rumah 'terjual'
     * - approved → rejected   → rumah 'booking' (booking masih aktif, bisa re-submit)
     * - approved → cancelled  → rumah 'available' (unit benar-benar dilepas)
     */
    public function saved(Spr $spr): void
    {
        // SPR baru dibuat langsung dengan status approved (mis. import historic)
        if ($spr->wasRecentlyCreated && ! $spr->wasChanged('status')) {
            if ($spr->status === 'approved') {
                $this->lockRumah($spr);
            }

            return;
        }

        if (! $spr->wasChanged('status')) {
            return;
        }

        $newStatus = $spr->status;
        $oldStatus = $spr->getOriginal('status');

        if ($newStatus === 'approved' && $oldStatus !== 'approved') {
            $this->lockRumah($spr);
        } elseif ($oldStatus === 'approved' && $newStatus === 'cancelled') {
            // Pembatalan: unit dilepas total
            $this->releaseRumah($spr);
        } elseif ($oldStatus === 'approved' && $newStatus !== 'approved') {
            // Reject / revert: kembali ke booking
            $this->unlockRumah($spr);
        }
    }

    private function lockRumah(Spr $spr): void
    {
        Rumah::where('id', $spr->rumah_id)
            ->where('status', '!=', 'terjual')
            ->update(['status' => 'terjual']);
    }

    private function unlockRumah(Spr $spr): void
    {
        Rumah::where('id', $spr->rumah_id)
            ->where('status', 'terjual')
            ->update(['status' => 'booking']);
    }

    private function releaseRumah(Spr $spr): void
    {
        Rumah::where('id', $spr->rumah_id)
            ->whereIn('status', ['terjual', 'booking'])
            ->update(['status' => 'available']);
    }
}
