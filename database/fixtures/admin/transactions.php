<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];
$txn = fn (int $id, string $trip, string $gateway, string $type, int $amount, string $status, string $at, ?string $failure = null) => [
    'id' => $id, 'code' => 'TXN-'.(104870 + $id), 'trip_code' => $trip, 'gateway' => $gateway, 'type' => $type,
    'amount' => $amd($amount), 'status' => $status, 'created_at' => $at,
    'gateway_reference' => $gateway === 'stripe' ? 'pi_3Qx9…a81F' : null, 'rider' => 'Ani Sargsyan',
    'payment_method' => 'Visa •••• 4242', 'parent_code' => $type === 'capture' ? 'AUTH-'.(104870 + $id - 1) : null,
    'failure_reason' => $failure, 'metadata' => ['source' => 'trip', 'zone' => 'downtown-core'],
];

// Payments & transactions (real data: P6-T1 … P6-T7).
return [
    'summary' => ['volume_today' => $amd(18400000), 'transactions_count' => 7, 'pending_payouts' => $amd(3800000), 'refunds_today' => $amd(84500)],
    'rows' => [
        $txn(12, 'TK-8F3K2', 'stripe', 'capture', 12500, 'completed', '2026-10-05T14:16:00+00:00'),
        $txn(11, 'TK-7A91M', 'stripe', 'charge', 6800, 'completed', '2026-10-05T13:59:00+00:00'),
        $txn(10, 'TK-5D7N4', 'authorize_net', 'charge', 5900, 'completed', '2026-10-05T12:51:00+00:00'),
        $txn(9, 'TK-3M8R6', 'wallet', 'debit', 9400, 'completed', '2026-10-05T11:02:00+00:00'),
        $txn(8, 'TK-2B5L1', 'stripe', 'refund', 3500, 'completed', '2026-10-04T16:40:00+00:00'),
        $txn(7, 'TK-1V9C3', 'manual', 'charge', 18500, 'pending', '2026-10-04T15:10:00+00:00'),
        $txn(6, 'TK-9K4D7', 'stripe', 'charge', 7200, 'failed', '2026-10-04T09:30:00+00:00', 'do_not_honor'),
    ],
];
