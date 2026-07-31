<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Setiap hari jam 00:05: lepas unit dari booking yang sudah lewat tanggal_expired.
Schedule::command('bookings:expire')->dailyAt('00:05');
