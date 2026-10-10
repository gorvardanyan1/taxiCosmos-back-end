<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];

// Versioned commission rules (real data: P11-T1).
return [
    'rows' => [
        ['id' => 3, 'zone' => 'Downtown Core', 'vehicle_class' => 'economy', 'type' => 'percentage', 'rate_bp' => 1800, 'fixed_fee' => null, 'minimum' => $amd(500), 'maximum' => $amd(8000), 'applies_to_surge' => true, 'effective_from' => '2026-10-01'],
        ['id' => 2, 'zone' => 'Airport Zone', 'vehicle_class' => 'comfort', 'type' => 'percentage_plus_fixed', 'rate_bp' => 1600, 'fixed_fee' => $amd(300), 'minimum' => $amd(800), 'maximum' => null, 'applies_to_surge' => true, 'effective_from' => '2026-10-01'],
        ['id' => 1, 'zone' => 'Yerevan North', 'vehicle_class' => 'business', 'type' => 'fixed', 'rate_bp' => null, 'fixed_fee' => $amd(2500), 'minimum' => null, 'maximum' => null, 'applies_to_surge' => false, 'effective_from' => '2026-09-01'],
    ],
    // Rule used by the commission preview calculator.
    'preview_rule_id' => 3,
    'preview_fare' => $amd(12500),
];
