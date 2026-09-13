<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('membership-requests:apply-approved')
    ->dailyAt('00:05')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('lesson-reminders:send')
    ->dailyAt((string) config('musako.notifications.lesson_reminder_time'))
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping(10)
    ->onOneServer();
