<?php

/*
 * Platform-level settings for the admin panel. Currencies and the display timezone are
 * the fallback for the open "launch country / currencies" decision (P6-T6 moves currencies
 * into the database); the defaults follow the template (Armenia, AMD).
 */
return [

    // Minor-unit exponent per currency: amounts are stored as integers in minor units.
    'currencies' => [
        ['code' => 'AMD', 'symbol' => '֏', 'decimals' => 0],
        ['code' => 'USD', 'symbol' => '$', 'decimals' => 2],
        ['code' => 'EUR', 'symbol' => '€', 'decimals' => 2],
    ],

    'base_currency' => env('BASE_CURRENCY', 'AMD'),

    // Used when the admin user has no personal timezone set.
    'display_timezone' => env('ADMIN_DISPLAY_TIMEZONE', 'Asia/Yerevan'),

    'admin' => [
        // Admins are signed out after this many minutes without a request (P3-T1).
        // Keep SESSION_LIFETIME at least as long so the session survives until then.
        'idle_timeout_minutes' => (int) env('ADMIN_IDLE_TIMEOUT', 60),

        // Rows per page on admin lists; a request may ask for any of these.
        'per_page_options' => [10, 25, 50],
    ],

];
