<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];
$rule = fn (int $zone, string $class, int $base, int $km, int $min, int $minimum) => [
    'zone_id' => $zone, 'vehicle_class' => $class, 'base_fare' => $amd($base),
    'per_km' => $amd($km), 'per_minute' => $amd($min), 'minimum_fare' => $amd($minimum),
];

// Zones & fare rules (real data: P5-T3 / P5-T2; map drawing: P13-T7).
return [
    'zones' => [
        ['id' => 1, 'name' => 'Downtown Core', 'status' => 'active', 'polygon_valid' => true],
        ['id' => 2, 'name' => 'Airport Zone', 'status' => 'active', 'polygon_valid' => true],
        ['id' => 3, 'name' => 'Suburbs North', 'status' => 'active', 'polygon_valid' => true],
        ['id' => 4, 'name' => 'Harbor District', 'status' => 'inactive', 'polygon_valid' => false],
    ],
    'fare_rules' => [
        $rule(1, 'economy', 600, 250, 40, 1000), $rule(1, 'comfort', 900, 350, 60, 1600), $rule(1, 'business', 1300, 450, 80, 2500),
        $rule(2, 'economy', 800, 300, 45, 2000), $rule(2, 'comfort', 1100, 420, 70, 3000),
        $rule(3, 'economy', 500, 220, 35, 900),
    ],
];
