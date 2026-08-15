<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('erp:sweep-reservations')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('erp:rebuild-rollups')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('erp:rebuild-rollups', ['--date' => now()->subDay()->toDateString()])
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('erp:bill-subscriptions')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('erp:raise-payment-links')
    ->dailyAt('01:45')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('erp:backup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('erp:verify-backup')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer();
