<?php

namespace App\Observers;

use App\Models\Master\Rumah;
use App\Models\Master\Spr;

class SprObserver
{
    /**
     * Status SPR yang mengunci unit. Akad adalah kelanjutan dari approved — unit yang
     * sudah akad justru paling tidak boleh muncul sebagai tersedia.
     */
    private const STATUS_MENGUNCI = ['approved', 'akad'];

    /**
     * Sinkronkan rumah.status mengikuti status SPR.
     * - approved / akad            → rumah 'terjual'
     * - approved → akad            → tetap 'terjual' (dua-duanya mengunci)
     * - mengunci → cancelled       → rumah 'available' (unit benar-benar dilepas)
     * - mengunci → rejected/draft  → rumah 'booking' (booking masih aktif, bisa re-submit)
     */
    public function saved(Spr $spr): void
    {
        // SPR baru dibuat langsung dalam status mengunci (mis. import historis)
        if ($spr->wasRecentlyCreated && ! $spr->wasChanged('status')) {
            if ($this->mengunci($spr->status)) {
                $this->lockRumah($spr);
            }

            return;
        }

        if (! $spr->wasChanged('status')) {
            return;
        }

        $baru = $spr->status;
        $lama = $spr->getOriginal('status');

        if ($this->mengunci($baru) && ! $this->mengunci($lama)) {
            $this->lockRumah($spr);
        } elseif ($this->mengunci($lama) && $baru === 'cancelled') {
            // Pembatalan: unit dilepas total
            $this->releaseRumah($spr);
        } elseif ($this->mengunci($lama) && ! $this->mengunci($baru)) {
            // Reject / revert: kembali ke booking
            $this->unlockRumah($spr);
        }

        // approved → akad: dua-duanya mengunci, unit sengaja tidak disentuh
    }

    private function mengunci(?string $status): bool
    {
        return in_array($status, self::STATUS_MENGUNCI, true);
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
