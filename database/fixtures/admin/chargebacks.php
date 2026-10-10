<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];

// Gateway chargebacks / disputes (real data: P6-T7).
return [
    'rows' => [
        ['id' => 3, 'gateway_dispute_id' => 'dp_1QZ81x', 'transaction_code' => 'TXN-104882', 'rider' => 'Ani Sargsyan', 'amount' => $amd(42600), 'reason' => 'fraudulent', 'status' => 'review', 'evidence_due_at' => now()->addDays(3)->toIso8601String()],
        ['id' => 2, 'gateway_dispute_id' => 'dp_1QX77b', 'transaction_code' => 'TXN-104109', 'rider' => 'Gor Hakobyan', 'amount' => $amd(18400), 'reason' => 'duplicate', 'status' => 'processing', 'evidence_due_at' => now()->addDay()->toIso8601String()],
        ['id' => 1, 'gateway_dispute_id' => 'dp_1QV20a', 'transaction_code' => 'TXN-102992', 'rider' => 'Mariam Avetisyan', 'amount' => $amd(12500), 'reason' => 'product_not_received', 'status' => 'failed', 'evidence_due_at' => now()->subDays(2)->toIso8601String()],
    ],
];
