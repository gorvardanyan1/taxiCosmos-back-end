<?php

// Surge pricing (real data: P5-T4).
return [
    'zones' => [['id' => 1, 'name' => 'Downtown Core'], ['id' => 2, 'name' => 'Airport Zone'], ['id' => 3, 'name' => 'Yerevan North']],
    'max_multiplier' => '3.00',
    'rows' => [
        ['id' => 3, 'zone' => 'Downtown Core', 'multiplier' => '1.50', 'vehicle_class' => 'comfort', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String(), 'recurrence' => null, 'reason' => 'Evening demand', 'created_by' => 'Jordan Avery'],
        ['id' => 2, 'zone' => 'Airport Zone', 'multiplier' => '1.80', 'vehicle_class' => null, 'starts_at' => '2026-10-08T03:00:00+00:00', 'ends_at' => '2026-10-08T06:00:00+00:00', 'recurrence' => null, 'reason' => 'Morning arrivals', 'created_by' => 'Riley Chen'],
        ['id' => 1, 'zone' => 'Yerevan North', 'multiplier' => '1.30', 'vehicle_class' => 'economy', 'starts_at' => null, 'ends_at' => null, 'recurrence' => 'Fri · 18:00–22:00', 'reason' => 'Recurring peak', 'created_by' => null],
    ],
];
