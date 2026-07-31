<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Custom LoginResponse untuk web guard (admin/keuangan/PM/direktur/admin-kpr).
 *
 * Masalah yang di-fix: kalau user pernah akses URL DBOS (misal `/dbos/spr`),
 * middleware `auth:sales` save intended URL = `/dbos/...`. Terus kalau user login
 * via `/login` (admin), Fortify redirect ke intended URL itu → kena `auth:sales`
 * middleware lagi → auto-redirect balik ke `/dbos/login`. User bingung.
 *
 * Fix: kalau intended URL adalah URL DBOS, abaikan dan redirect ke /dashboard.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $intended = session()->pull('url.intended');

        // Kalau intended URL adalah URL DBOS (guard beda), abaikan
        if ($intended && str_contains($intended, '/dbos')) {
            $intended = null;
        }

        return redirect()->to($intended ?: route('dashboard'));
    }
}
