<?php

namespace App\Observers;

use App\Models\Master\Rumah;
use Illuminate\Support\Facades\Auth;

class RumahObserver
{
    /**
     * Auto-stamp audit fields saat progres_fisik berubah.
     * Log entry (rumah_progres_log) dibuat manual di caller (Livewire) supaya bisa
     * include catatan perubahan — pass via arg controller, bukan magic model property.
     */
    public function updating(Rumah $rumah): void
    {
        if (! $rumah->isDirty('progres_fisik')) {
            return;
        }

        $rumah->progres_updated_at = now();
        $rumah->progres_updated_by_user_id = Auth::id() ?? $rumah->progres_updated_by_user_id;
    }
}
