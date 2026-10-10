<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];
$rider = fn (int $id, string $name, string $phone, string $email, string $status, int $trips, string $registered) => [
    'id' => $id, 'code' => 'R-'.(10400 + $id), 'name' => $name, 'phone' => $phone, 'email' => $email,
    'status' => $status, 'trips_count' => $trips, 'registered_at' => $registered,
];

// Riders list and detail (real data: P4-T1).
return [
    'total_registered' => 48291,
    'rows' => [
        $rider(82, 'Priya Mehta', '+374 91 210 443', 'priya@example.com', 'active', 142, '2024-01-12'),
        $rider(81, 'James Okafor', '+374 93 887 220', 'james@example.com', 'active', 87, '2024-01-18'),
        $rider(80, 'Sun Li', '+374 94 334 778', 'sunli@example.com', 'suspended', 23, '2024-02-02'),
        $rider(79, 'Maria Torres', '+374 95 612 990', 'maria@example.com', 'active', 315, '2023-11-04'),
        $rider(78, 'Ethan Brooks', '+374 96 445 221', 'ethan@example.com', 'deactivated', 5, '2024-03-22'),
        $rider(77, 'Fatima Al-Rashid', '+374 97 990 112', 'fatima@example.com', 'active', 208, '2023-09-17'),
        $rider(76, 'Lucas Diaz', '+374 98 772 441', 'lucas@example.com', 'active', 64, '2024-04-05'),
        $rider(75, 'Amara Nwosu', '+374 99 330 889', 'amara@example.com', 'suspended', 18, '2023-12-29'),
        $rider(74, 'Oliver Wang', '+374 41 554 332', 'oliver@example.com', 'active', 502, '2023-07-08'),
        $rider(73, 'Chloe Martin', '+374 43 223 004', 'chloe@example.com', 'active', 129, '2024-02-14'),
        $rider(72, 'Ani Sargsyan', '+374 91 440 221', 'ani@example.com', 'pending_deletion', 61, '2024-05-02'),
        $rider(71, 'Gor Hakobyan', '+374 77 661 009', 'gor@example.com', 'active', 33, '2024-06-11'),
    ],
    // Shared detail used for every fixture rider until P4-T1 loads real data.
    'detail' => [
        'stats' => ['total_spent' => $amd(1284500), 'cancellation_rate_bp' => 380, 'open_tickets' => 1],
        'deletion_scheduled_for' => '2026-11-07',
        'payment_methods' => [['id' => 1, 'brand' => 'Visa', 'last4' => '4242', 'expires' => '08/29', 'is_default' => true]],
        'records' => [
            'trips' => [
                ['reference' => 'TK-8F3K2', 'description' => 'Republic Square → EVN Airport', 'status' => 'completed', 'value' => $amd(12500), 'occurred_at' => '2026-10-05T14:14:00+00:00'],
                ['reference' => 'TK-7A91M', 'description' => 'Cascade → Komitas Avenue', 'status' => 'completed', 'value' => $amd(6800), 'occurred_at' => '2026-10-04T12:22:00+00:00'],
            ],
            'payments' => [
                ['reference' => 'TXN-104882', 'description' => 'Trip TK-8F3K2 · Stripe', 'status' => 'completed', 'value' => $amd(12500), 'occurred_at' => '2026-10-05T14:16:00+00:00'],
                ['reference' => 'TXN-104881', 'description' => 'Trip TK-7A91M · Stripe', 'status' => 'completed', 'value' => $amd(6800), 'occurred_at' => '2026-10-04T12:25:00+00:00'],
            ],
            'tickets' => [
                ['reference' => 'TKT-2941', 'description' => 'Safety incident', 'status' => 'open', 'value' => 'Urgent', 'occurred_at' => '2026-10-03T17:09:00+00:00'],
            ],
            'ratings' => [
                ['reference' => 'TK-8F3K2', 'description' => 'Rated driver 5 ★ — “Smooth ride”', 'status' => 'completed', 'value' => '5.0', 'occurred_at' => '2026-10-05T14:22:00+00:00'],
            ],
            'activity' => [
                ['reference' => 'ACT-5521', 'description' => 'Requested account deletion', 'status' => 'pending', 'value' => '—', 'occurred_at' => '2026-10-08T09:00:00+00:00'],
            ],
        ],
    ],
];
