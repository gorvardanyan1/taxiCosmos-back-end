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
