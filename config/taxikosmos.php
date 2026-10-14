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

    'phone' => [
        // Region used to read numbers typed without a country code (fallback for the open
        // "launch country" decision; the template is Armenian).
        'default_region' => env('PHONE_DEFAULT_REGION', 'AM'),
    ],

    'documents' => [
        // Private disk for driver and vehicle papers. Switch to an S3-compatible disk (staging,
        // production) with DRIVER_DOCUMENTS_DISK=s3; no code change is needed.
        'disk' => env('DRIVER_DOCUMENTS_DISK', 'driver_documents'),

        // Document types a driver needs approved before the profile becomes verified
        // (comma separated DriverDocumentType values; background_check is optional by default).
        'required' => array_values(array_filter(array_map('trim', explode(',', (string) env('DRIVER_REQUIRED_DOCUMENTS', 'license,id_card,vehicle_registration,insurance'))))),

        // How long a signed file link stays valid. Short on purpose: the page issues a fresh one
        // on every load.
        'url_ttl_minutes' => (int) env('DRIVER_DOCUMENT_URL_TTL', 5),

        // Upload limits, checked against the file's real content (not the client-sent name).
        'max_kilobytes' => (int) env('DRIVER_DOCUMENT_MAX_KB', 8192),
        'mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
    ],

    // Languages a rider can use in the apps (their `locale`).
    'locales' => ['en', 'hy', 'ru'],

    'zones' => [
        // A zone polygon may have at most this many points (all rings together).
        'max_vertices' => (int) env('ZONE_MAX_VERTICES', 5000),
    ],

    'fares' => [
        // How long a rider's estimate (quote) can be used to create a trip.
        'quote_ttl_minutes' => (int) env('FARE_QUOTE_TTL_MINUTES', 10),

        // The estimate is shown as a range around the quoted price: ± this many basis points (1000 = 10 %).
        'range_bps' => (int) env('FARE_ESTIMATE_RANGE_BPS', 1000),

        // Highest surge multiplier ever applied, in hundredths (300 = 3.00x). Surge rules (P5-T4) may not exceed it.
        'surge_cap_hundredths' => (int) env('FARE_SURGE_CAP_HUNDREDTHS', 300),

        // Until the maps provider supplies routes (P5-T1) the route is estimated from the straight line:
        // the distance is multiplied by this detour factor (130 = 1.30x) and driven at the average speed.
        'route_detour_percent' => (int) env('FARE_ROUTE_DETOUR_PERCENT', 130),
        'route_average_speed_kmh' => (int) env('FARE_ROUTE_AVERAGE_SPEED_KMH', 30),
    ],

    'mobile_auth' => [
        // Access token lifetime (Sanctum personal access token) and refresh token lifetime.
        'access_ttl_minutes' => (int) env('MOBILE_ACCESS_TTL_MINUTES', 60),
        'refresh_ttl_days' => (int) env('MOBILE_REFRESH_TTL_DAYS', 30),

        'otp' => [
            'length' => 6,
            'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
            // Wrong guesses allowed before the code is thrown away.
            'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

            // Fixed code for listed phone numbers so mobile E2E tests can sign in. Honoured only
            // when the app runs in the local, testing or e2e environment, whatever is configured.
            'test_code' => env('OTP_TEST_CODE'),
            'test_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('OTP_TEST_PHONES', ''))))),
        ],

        // Audiences accepted from the native sign-in SDKs.
        'social' => [
            'google_client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
            'apple_client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_CLIENT_IDS', ''))))),
            'facebook_app_id' => env('FACEBOOK_APP_ID'),
            'facebook_app_secret' => env('FACEBOOK_APP_SECRET'),
        ],
    ],

    'push' => [
        // How long Expo may keep trying to deliver a trip offer (an offer lasts about 15 s, so a late one is worthless).
        'offer_ttl_seconds' => (int) env('PUSH_OFFER_TTL', 30),

        // Expo receipts (where a stale token is reported) are ready after a while; tickets older than this are read, and
        // tickets older than the retention are dropped (Expo keeps receipts for about a day).
        'receipt_delay_minutes' => (int) env('PUSH_RECEIPT_DELAY_MINUTES', 15),
        'ticket_retention_hours' => (int) env('PUSH_TICKET_RETENTION_HOURS', 24),
    ],

    'driver_location' => [
        // An online driver with no ping for this long is marked offline by drivers:mark-stale-offline (P8-T2).
        'stale_after_minutes' => (int) env('DRIVER_STALE_AFTER_MINUTES', 5),

        // Location pings kept in MongoDB for history; older ones are removed by the collection's TTL index.
        'ping_retention_days' => (int) env('DRIVER_PING_RETENTION_DAYS', 90),
    ],

    'audit' => [
        // CSV export of the Activity Log stops after this many rows.
        'export_max_rows' => (int) env('AUDIT_EXPORT_MAX_ROWS', 50000),
    ],

    'admin' => [
        // Admins are signed out after this many minutes without a request (P3-T1).
        // Keep SESSION_LIFETIME at least as long so the session survives until then.
        'idle_timeout_minutes' => (int) env('ADMIN_IDLE_TIMEOUT', 60),

        // Rows per page on admin lists; a request may ask for any of these.
        'per_page_options' => [10, 25, 50],
    ],

];
