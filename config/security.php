<?php

/**
 * Abuse protection and hardening for the admin web surface (P9-T3): response security headers,
 * the optional IP allowlist for /admin and /horizon, and the tunable ceilings of the general
 * rate limiters (docs/rate-limits.md, docs/security.md).
 */
return [

    'headers' => [
        // Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off.
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

        // Report-only CSP: the browser logs violations but blocks nothing. Use it to trial a stricter policy.
        'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        // The fonts the admin loads (resources/css/app.css). Everything else must come from the app itself.
        'style_sources' => ['https://fonts.googleapis.com'],
        'font_sources' => ['https://fonts.gstatic.com'],
    ],

    // Optional: when set, /admin, /horizon and the admin sign-in screens answer 403 to every other address.
    // Comma separated IPv4/IPv6 addresses or CIDR ranges, e.g. "203.0.113.7,10.0.0.0/8". Empty = off.
    'admin_ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),

    // Node/Socket.io service (P8-T3): it calls POST /api/v1/internal/socket-auth with this shared secret (header X-Service-Token or
    // Authorization: Bearer) from one of these addresses. Both must match; an empty/short token or an empty list refuses everything.
    'socket' => [
        'service_token' => (string) env('SOCKET_SERVICE_TOKEN', ''),
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('SOCKET_SERVICE_ALLOWED_IPS', '127.0.0.1,::1'))))),
        // How long an admin socket ticket (GET /admin/socket-ticket) can be exchanged, in seconds. It works once.
        'ticket_ttl_seconds' => (int) env('SOCKET_TICKET_TTL', 60),
    ],

    'two_factor' => [
        // Admin roles that must have two-factor authentication: until they set it up they can reach nothing but the
        // setup screen (P3-T2), and they cannot switch it off. Comma separated role names. Unset or blank means the default:
        // super_admin and finance (none in the test and e2e environments, whose fixtures sign in with a password alone).
        // The rule is switched off only on purpose, with the word "none".
        'required_roles' => (function (): array {
            $configured = trim((string) env('ADMIN_2FA_REQUIRED_ROLES', ''));
            $configured = $configured === '' ? (in_array(env('APP_ENV'), ['testing', 'e2e'], true) ? 'none' : 'super_admin,finance') : $configured;

            return strtolower($configured) === 'none' ? [] : array_values(array_filter(array_map('trim', explode(',', $configured))));
        })(),
    ],

    // Requests per minute. Named limiters are registered in AppServiceProvider.
    'rate_limits' => [
        'admin' => (int) env('RATE_LIMIT_ADMIN', 300),             // per signed-in admin, every /admin page and action
        'admin_auth' => (int) env('RATE_LIMIT_ADMIN_AUTH', 30),     // per IP and path, the admin auth POSTs (reset, 2FA, ...)
        'login_ip' => (int) env('RATE_LIMIT_LOGIN_IP', 30),         // per IP, POST /login across all emails
        'api' => (int) env('RATE_LIMIT_API', 120),                 // per signed-in rider/driver, every authenticated /api/v1 call
        'geo' => (int) env('RATE_LIMIT_GEO', 60),                   // per signed-in rider/driver, the four /api/v1/geo endpoints together
        'app_config' => (int) env('RATE_LIMIT_APP_CONFIG', 60),     // per address, GET /api/v1/app-config (public, asked at every app start)
        'payment' => (int) env('RATE_LIMIT_PAYMENT', 10),           // per signed-in user, payment endpoints (P6)
        'driver_location' => (int) env('RATE_LIMIT_DRIVER_LOCATION', 30),         // per driver, POST /api/v1/driver/location (the REST fallback for pings)
        'devices' => (int) env('RATE_LIMIT_DEVICES', 30),                          // per signed-in user, POST/DELETE /api/v1/devices (push tokens)
        'driver_availability' => (int) env('RATE_LIMIT_DRIVER_AVAILABILITY', 10), // per driver, POST /api/v1/driver/availability (going online/offline)
        'driver_onboarding' => (int) env('RATE_LIMIT_DRIVER_ONBOARDING', 30),     // per driver, the /api/v1/driver application, profile, vehicle and bank-account calls
        'driver_documents' => (int) env('RATE_LIMIT_DRIVER_DOCUMENTS', 20),       // per driver, POST /api/v1/driver/documents (uploads)
        'support_tickets' => (int) env('RATE_LIMIT_SUPPORT_TICKETS', 20),         // per signed-in rider/driver, /api/v1/support-tickets (open, list, write)
        'socket_auth' => (int) env('RATE_LIMIT_SOCKET_AUTH', 1200),               // per calling address, POST /api/v1/internal/socket-auth (every socket connect)
        'socket_ticket' => (int) env('RATE_LIMIT_SOCKET_TICKET', 20),             // per signed-in admin, GET /admin/socket-ticket
    ],

];
