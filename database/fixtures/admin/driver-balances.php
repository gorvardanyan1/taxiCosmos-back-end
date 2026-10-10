<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];

// Driver balances and cash-commission debt ageing (real data: P11-T2 / P11-T3).
return [
    'summary' => ['owed_to_drivers' => $amd(42800000), 'owed_by_drivers' => $amd(3200000), 'over_limit_count' => 18],
    'rows' => [
        ['id' => 8, 'driver' => 'Arman Petrosyan', 'balance' => $amd(84200), 'ageing' => ['days_0_7' => $amd(0), 'days_8_30' => $amd(0), 'days_30_plus' => $amd(0)], 'debt_limit_used_percent' => 32],
        ['id' => 3, 'driver' => 'Samvel Gevorgyan', 'balance' => $amd(-48000), 'ageing' => ['days_0_7' => $amd(18000), 'days_8_30' => $amd(20000), 'days_30_plus' => $amd(10000)], 'debt_limit_used_percent' => 96],
        ['id' => 5, 'driver' => 'Narek Sahakyan', 'balance' => $amd(-21500), 'ageing' => ['days_0_7' => $amd(4500), 'days_8_30' => $amd(17000), 'days_30_plus' => $amd(0)], 'debt_limit_used_percent' => 43],
    ],
];
