<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];

// Driver payouts (real data: P6-T8).
return [
    'periods' => [['start' => '2026-09-29', 'end' => '2026-10-05'], ['start' => '2026-09-22', 'end' => '2026-09-28']],
    'summary' => ['drivers_in_batch' => 284, 'total_amount' => $amd(38400000), 'skipped_count' => 12],
    'rows' => [
        ['id' => 3, 'driver' => 'Arman Petrosyan', 'bank_account' => ['bank_name' => 'Ameriabank', 'account_last4' => '4821'], 'period_start' => '2026-09-29', 'period_end' => '2026-10-05', 'amount' => $amd(184500), 'status' => 'pending'],
        ['id' => 2, 'driver' => 'Lilit Grigoryan', 'bank_account' => ['bank_name' => 'ACBA', 'account_last4' => '0193'], 'period_start' => '2026-09-29', 'period_end' => '2026-10-05', 'amount' => $amd(142800), 'status' => 'approved'],
        ['id' => 1, 'driver' => 'Gor Hakobyan', 'bank_account' => ['bank_name' => 'IDBank', 'account_last4' => '7712'], 'period_start' => '2026-09-29', 'period_end' => '2026-10-05', 'amount' => $amd(98200), 'status' => 'paid'],
    ],
    'skipped' => [
        ['driver' => 'Narek Sahakyan', 'reason' => 'no_verified_bank_account'],
        ['driver' => 'Samvel Gevorgyan', 'reason' => 'negative_balance'],
    ],
];
