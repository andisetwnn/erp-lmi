<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust semua proxy (Hostinger shared hosting sering behind reverse proxy).
        // Wajib supaya Laravel detect scheme https + host asli → signed URL & Livewire kerja.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'pimpinan' => \App\Http\Middleware\EnsurePimpinan::class,
            'sales.lapangan' => \App\Http\Middleware\EnsureSalesLapangan::class,
        ]);

        // Redirect guest ke login page yang sesuai (admin vs DBOS)
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('dbos*')) {
                return route('dbos.login');
            }
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
