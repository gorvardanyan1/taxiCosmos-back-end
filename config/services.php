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

    // Maps (P5-T1): address search, geocoding and routes behind App\Contracts\GeoProvider. MAPS_PROVIDER is osm (free, the default), google or 2gis;
    // `fake` (no key, no network, made-up answers) is the default in the testing and e2e environments and is refused anywhere
    // but local, testing and e2e (AppServiceProvider).
    // Push notifications (P8-T4). `expo` sends through the Expo Push Service; `log` writes the push to the log and sends nothing
    // (the default outside production, so local and staging never push unless it is switched on). Production must choose.
    'push' => [
        'driver' => env('PUSH_DRIVER', env('APP_ENV') === 'production' ? null : 'log'),
        'timeout' => (int) env('PUSH_TIMEOUT', 10),
        'expo' => [
            // Only needed when "enhanced push security" is on for the Expo project.
            'access_token' => env('EXPO_ACCESS_TOKEN'),
        ],
    ],

    'maps' => [
        'provider' => env('MAPS_PROVIDER', in_array(env('APP_ENV'), ['testing', 'e2e'], true) ? 'fake' : 'osm'),
        'google' => ['key' => env('GOOGLE_MAPS_API_KEY')],
        '2gis' => ['key' => env('TWOGIS_API_KEY')],
        // OpenStreetMap (MVP-1): Nominatim for search, OSRM for routes. The public servers are for development only; at launch point the
        // URLs at servers of our own and set the interval to 0. The User-Agent must identify us (Nominatim's usage policy).
        'osm' => [
            'nominatim_url' => env('OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
            'osrm_url' => env('OSM_OSRM_URL', 'https://router.project-osrm.org'),
            'user_agent' => env('OSM_USER_AGENT', 'TaxiKosmos/1.0 (set OSM_USER_AGENT to a contact address)'),
            // Milliseconds between two calls to Nominatim (the public server allows one a second); 0 = no limit (self-hosted).
            'nominatim_interval_ms' => (int) env('OSM_NOMINATIM_INTERVAL_MS', 1000),
            // Comma-separated ISO country codes the search is limited to (am = Armenia); empty = worldwide.
            'country_codes' => (string) env('OSM_COUNTRY_CODES', 'am'),
            // Seconds a search, place or geocode answer is remembered, 0 = off.
            'search_cache_ttl' => (int) env('OSM_SEARCH_CACHE_TTL', 86400),
        ],
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
