<?php

namespace App\Observers;

use App\Models\Master\Rumah;
use Illuminate\Support\Facades\Auth;

class RumahObserver
{
    /**
     * Auto-stamp audit fields saat data teknik berubah: progres, LOT, atau subcon.
     * Dashboard direksi memakai waktu ini sebagai "data LOT per tanggal".
     * Log entry (rumah_progres_log) dibuat manual di caller (Livewire) supaya bisa
     * include catatan perubahan — pass via arg controller, bukan magic model property.
     */
    public function updating(Rumah $rumah): void
    {
        if (! $rumah->isDirty(['progres_fisik', 'lot', 'subcon_id'])) {
            return;
        }

        $rumah->progres_updated_at = now();
        $rumah->progres_updated_by_user_id = Auth::id() ?? $rumah->progres_updated_by_user_id;
    }
}
