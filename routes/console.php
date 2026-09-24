<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled jobs
|--------------------------------------------------------------------------
|
| Keep this file as the single scheduler entrypoint for recurring billing,
| invoice reminders, and related operational commands.
|
*/

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('recurring-billing:process-due')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reminders:process-due')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer();
