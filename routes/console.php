<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit log retention (config activitylog.clean_after_days); `--force` because it runs unattended.
Schedule::command('activitylog:clean --force')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
