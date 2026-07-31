<?php

namespace App\Http\Middleware;

use App\Models\Master\Sales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSalesLapangan
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Sales|null $sales */
        $sales = Auth::guard('sales')->user();

        if ($sales && $sales->isPimpinan()) {
            return redirect()->route('dbos.home');
        }

        return $next($request);
    }
}
