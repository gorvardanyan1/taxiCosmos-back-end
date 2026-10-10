<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];

// Dashboard KPIs (real data: P13-T4).
return [
    'as_of' => '2026-10-05T14:42:00+00:00',
    'zones' => [['id' => 1, 'name' => 'Downtown Core'], ['id' => 2, 'name' => 'Airport Zone']],
    'metrics' => [
        ['key' => 'trips_today', 'value' => 2184, 'change' => '+8.1% vs yesterday', 'trend' => 'up'],
        ['key' => 'active_trips', 'value' => 134, 'change' => '+12 vs 1h ago', 'trend' => 'up'],
        ['key' => 'online_drivers', 'value' => 1429, 'change' => '72% of active fleet', 'trend' => 'up'],
        ['key' => 'revenue_today', 'value' => $amd(18400000), 'change' => '+8.4% vs yesterday', 'trend' => 'up'],
        ['key' => 'cancellation_rate', 'value' => 480, 'change' => '−0.4% vs yesterday', 'trend' => 'down'],
        ['key' => 'pending_verifications', 'value' => 63, 'change' => '12 expiring soon', 'trend' => 'down'],
    ],
    'alerts' => ['stuck_trips' => 3, 'failed_payments' => 5, 'urgent_tickets' => 1],
    'revenue_trend' => [
        'change_bp' => 1840,
        'points' => [
            ['date' => '2026-08-05', 'revenue' => $amd(12400)], ['date' => '2026-08-09', 'revenue' => $amd(18200)],
            ['date' => '2026-08-13', 'revenue' => $amd(15800)], ['date' => '2026-08-17', 'revenue' => $amd(22100)],
            ['date' => '2026-08-21', 'revenue' => $amd(19600)], ['date' => '2026-08-25', 'revenue' => $amd(25400)],
            ['date' => '2026-08-29', 'revenue' => $amd(28900)], ['date' => '2026-09-02', 'revenue' => $amd(31500)],
            ['date' => '2026-09-03', 'revenue' => $amd(29800)],
        ],
    ],
    'trip_status' => [
        ['status' => 'completed', 'count' => 6842], ['status' => 'in_progress', 'count' => 134],
        ['status' => 'cancelled', 'count' => 891], ['status' => 'requested', 'count' => 203],
    ],
    'map' => [
        'active_count' => 134,
        'pins' => [
            ['x' => 22, 'y' => 28, 'state' => 'en_route'], ['x' => 58, 'y' => 52, 'state' => 'en_route'],
            ['x' => 72, 'y' => 18, 'state' => 'in_trip'], ['x' => 32, 'y' => 68, 'state' => 'en_route'],
            ['x' => 80, 'y' => 42, 'state' => 'in_trip'], ['x' => 14, 'y' => 62, 'state' => 'en_route'],
            ['x' => 48, 'y' => 33, 'state' => 'en_route'], ['x' => 62, 'y' => 78, 'state' => 'in_trip'],
            ['x' => 38, 'y' => 14, 'state' => 'en_route'], ['x' => 88, 'y' => 55, 'state' => 'in_trip'],
        ],
    ],
    'activity' => [
        ['id' => 1, 'type' => 'signup', 'title' => 'Priya Mehta', 'detail' => 'New rider registered', 'occurred_at' => '2026-10-05T14:40:00+00:00'],
        ['id' => 2, 'type' => 'trip', 'title' => 'Trip TK-58204', 'detail' => 'Completed — AMD 14,800', 'occurred_at' => '2026-10-05T14:38:00+00:00'],
        ['id' => 3, 'type' => 'flag', 'title' => 'Marco Rossi', 'detail' => 'Document rejected — resubmit', 'occurred_at' => '2026-10-05T14:31:00+00:00'],
        ['id' => 4, 'type' => 'signup', 'title' => 'James Okafor', 'detail' => 'New driver application', 'occurred_at' => '2026-10-05T14:19:00+00:00'],
        ['id' => 5, 'type' => 'cancel', 'title' => 'Trip TK-58196', 'detail' => 'Force-cancelled by admin', 'occurred_at' => '2026-10-05T14:08:00+00:00'],
        ['id' => 6, 'type' => 'signup', 'title' => 'Sun Li', 'detail' => 'New rider registered', 'occurred_at' => '2026-10-05T13:51:00+00:00'],
        ['id' => 7, 'type' => 'trip', 'title' => 'Trip TK-58188', 'detail' => 'Completed — AMD 32,400', 'occurred_at' => '2026-10-05T13:42:00+00:00'],
    ],
];
