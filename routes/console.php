<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('orders:send-reminders')
    ->everyMinute()
    ->timezone((string) config('app.timezone'))
    ->withoutOverlapping();

Schedule::command('app:backup-database')
    ->dailyAt('02:00')
    ->timezone((string) config('app.timezone'))
    ->withoutOverlapping(120)
    ->environments(['production']);
