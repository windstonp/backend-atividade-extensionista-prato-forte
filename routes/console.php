<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('plans:fail-stale')->everyFiveMinutes();
Schedule::command('notifications:meal-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:weekly-summary')->weeklyOn(0, '20:00');
