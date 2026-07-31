<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/tanda-tangan', 'pages::settings.tanda-tangan')->name('tanda-tangan.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Appearance dihapus — aplikasi paksa light mode.

    Route::livewire('settings/security', 'pages::settings.security')
        ->name('security.edit');
});
