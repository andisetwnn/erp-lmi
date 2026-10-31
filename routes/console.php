<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tiap 10 menit: lepas unit dari booking yang sudah lewat tanggal_expired.
Schedule::command('bookings:expire')->everyTenMinutes();
