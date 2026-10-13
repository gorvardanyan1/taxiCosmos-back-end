<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Maps (P5-T1): address search, geocoding and routes behind App\Contracts\GeoProvider. MAPS_PROVIDER is google or 2gis;
    // `fake` (no key, no network, made-up answers) is the default in the testing and e2e environments and is refused anywhere
    // but local, testing and e2e (AppServiceProvider).
    'maps' => [
        'provider' => env('MAPS_PROVIDER', in_array(env('APP_ENV'), ['testing', 'e2e'], true) ? 'fake' : 'google'),
        'google' => ['key' => env('GOOGLE_MAPS_API_KEY')],
        '2gis' => ['key' => env('TWOGIS_API_KEY')],
        // Seconds a provider call may take before it counts as a failure.
        'timeout' => (int) env('MAPS_TIMEOUT', 5),
        // Autocomplete prefers places within this many metres of the user (Google allows up to 50 000).
        'bias_radius_meters' => (int) env('MAPS_BIAS_RADIUS_METERS', 50000),
        // Provider calls cost money: reverse geocodes and routes are remembered (docs/geo.md). TTL in seconds, 0 = off.
        'cache' => [
            'precision' => (int) env('MAPS_CACHE_PRECISION', 4),
            'reverse_ttl' => (int) env('MAPS_CACHE_REVERSE_TTL', 3600),
            'route_ttl' => (int) env('MAPS_CACHE_ROUTE_TTL', 600),
        ],
    ],

];
