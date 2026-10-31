<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Finance (Milestone 5)
Schedule::command('finance:run late-fees')->dailyAt('01:30')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer();
Schedule::command('finance:run reconcile')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('billing:run')->dailyAt('02:15')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer();

// Operations (Milestone 8): heartbeat for the scheduler check, then alerts.
Schedule::call(fn () => \App\Support\Ops\HealthChecks::beat())->everyMinute()->name('ops-heartbeat')->onOneServer();
Schedule::command('ops:health --alert')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
