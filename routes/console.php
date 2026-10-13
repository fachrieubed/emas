<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pembaruan data emas otomatis setiap hari pukul 17:30 WIB.
// Dijalankan oleh `php artisan schedule:run` (lihat auto-update-gold.bat /
// Windows Task Scheduler) dan sekaligus melatih ulang model bila ada data baru.
Schedule::command('gold:update --days=90')
    ->dailyAt('17:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
