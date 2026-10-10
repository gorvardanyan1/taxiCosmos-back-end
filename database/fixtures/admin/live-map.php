<?php

// Live operations map (real data: P7-T4 / P13-T7).
return [
    'zones' => [['id' => 1, 'name' => 'Downtown Core'], ['id' => 2, 'name' => 'Airport Zone']],
    'stats' => ['online' => 142, 'on_trip' => 38, 'waiting' => 11],
    'updated_at' => now()->subSeconds(5)->toIso8601String(),
    'markers' => [
        ['driver_id' => 1, 'x' => 22, 'y' => 32, 'availability' => 'online'], ['driver_id' => 2, 'x' => 38, 'y' => 58, 'availability' => 'on_trip'],
        ['driver_id' => 3, 'x' => 52, 'y' => 24, 'availability' => 'online'], ['driver_id' => 4, 'x' => 61, 'y' => 68, 'availability' => 'offline'],
        ['driver_id' => 5, 'x' => 76, 'y' => 42, 'availability' => 'on_trip'], ['driver_id' => 7, 'x' => 82, 'y' => 74, 'availability' => 'online'],
    ],
    'route' => 'M100 420 C260 330 320 250 540 180 S760 130 910 80',
    'focused_driver' => [
        'id' => 1, 'name' => 'Arman Petrosyan', 'vehicle' => 'Toyota Camry', 'plate' => '35 XX 482',
        'rating' => '4.92', 'trip_code' => 'TK-8F3K2',
    ],
];
