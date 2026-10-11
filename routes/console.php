<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit log retention (config activitylog.clean_after_days); `--force` because it runs unattended.
Schedule::command('activitylog:clean --force')->dailyAt('03:15')->withoutOverlapping()->onOneServer();

// Mobile sign-in housekeeping: expired access tokens (Sanctum), then expired one-time codes and refresh tokens.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('mobile-auth:prune')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
