<?php

use App\Models\FareQuote;
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

// Drivers: an online driver with no ping for 5 minutes goes offline, and stale positions leave Redis (P8-T2).
Schedule::command('drivers:mark-stale-offline')->everyMinute()->withoutOverlapping()->onOneServer();

// Push receipts (P8-T4): Expo reports tokens that are no longer registered there; those devices are revoked.
Schedule::command('push:check-receipts')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Fare quotes are useful for minutes; the expired ones are deleted a day later.
Schedule::command('model:prune', ['--model' => [FareQuote::class]])->dailyAt('03:50')->withoutOverlapping()->onOneServer();

// Rider wallets (P6-T1): every cached balance is checked against its ledger each night; a mismatch is logged at critical level.
Schedule::command('wallets:reconcile')->dailyAt('02:20')->withoutOverlapping()->onOneServer();
