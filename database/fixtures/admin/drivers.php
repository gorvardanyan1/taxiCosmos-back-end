<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];
$driver = fn (int $id, string $name, string $phone, string $vehicle, int $year, string $status, ?string $rating, int $trips, int $earnings) => [
    'id' => $id, 'code' => 'D-'.(3033 + $id), 'name' => $name, 'phone' => $phone,
    'vehicle' => ['label' => $vehicle, 'year' => $year], 'verification_status' => $status,
    'rating' => $rating, 'trips_count' => $trips, 'earnings' => $amd($earnings),
];

// Drivers list and detail (real data: P4-T2, documents P2-T3, earnings P11-T2).
return [
    'total_registered' => 5847,
    'rows' => [
        $driver(8, 'Arman Petrosyan', '+374 91 812 334', 'Toyota Camry', 2022, 'approved', '4.91', 1842, 18410000),
        $driver(7, 'Lilit Grigoryan', '+374 93 223 887', 'Honda Civic', 2023, 'approved', '4.87', 1124, 12320000),
        $driver(6, 'Gor Hakobyan', '+374 99 661 009', 'Hyundai Elantra', 2021, 'pending', '4.72', 309, 4880000),
        $driver(5, 'Narek Sahakyan', '+374 44 440 551', 'Kia Forte', 2022, 'pending', null, 0, 0),
        $driver(4, 'Mariam Avetisyan', '+374 91 991 332', 'VW Jetta', 2020, 'approved', '4.96', 2201, 23760000),
        $driver(3, 'Samvel Gevorgyan', '+374 77 778 221', 'Ford Fusion', 2019, 'rejected', '3.90', 44, 580000),
        $driver(2, 'Ani Sargsyan', '+374 33 332 665', 'Nissan Altima', 2021, 'approved', '4.82', 976, 14910000),
        $driver(1, 'Vahan Mkrtchyan', '+374 11 110 774', 'Chevrolet Malibu', 2022, 'pending', null, 0, 0),
    ],
    'detail' => [
        'documents' => [
            ['id' => 1, 'type' => 'license', 'status' => 'approved', 'expires_at' => '2028-04-30', 'rejection_reason' => null],
            ['id' => 2, 'type' => 'vehicle_registration', 'status' => 'approved', 'expires_at' => null, 'rejection_reason' => null],
            ['id' => 3, 'type' => 'insurance', 'status' => 'pending', 'expires_at' => now()->addDays(12)->toDateString(), 'rejection_reason' => null],
            ['id' => 4, 'type' => 'background_check', 'status' => 'pending', 'expires_at' => null, 'rejection_reason' => null],
        ],
        'vehicles' => [
            ['id' => 1, 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2022, 'color' => 'Black', 'plate_number' => '35 XX 482', 'vehicle_class' => 'comfort', 'status' => 'active', 'is_primary' => true],
            ['id' => 2, 'make' => 'Hyundai', 'model' => 'Elantra', 'year' => 2021, 'color' => 'Silver', 'plate_number' => '36 GG 901', 'vehicle_class' => 'economy', 'status' => 'active', 'is_primary' => false],
        ],
        'earnings_detail' => [
            'balance' => $amd(-18000),
            'debt_limit_exceeded' => true,
            'summary' => ['week' => $amd(284000), 'month' => $amd(1200000), 'all_time' => $amd(18400000)],
            'ledger' => [
                ['id' => 1, 'type' => 'trip_earning', 'reference' => 'TK-8F3K2', 'amount' => $amd(8920), 'occurred_at' => '2026-10-05T14:40:00+00:00'],
                ['id' => 2, 'type' => 'cash_commission', 'reference' => 'TK-7A91M', 'amount' => $amd(-2240), 'occurred_at' => '2026-10-05T13:22:00+00:00'],
                ['id' => 3, 'type' => 'payout', 'reference' => 'PAY-4401', 'amount' => $amd(-184500), 'occurred_at' => '2026-10-01T05:10:00+00:00'],
                ['id' => 4, 'type' => 'bonus', 'reference' => null, 'amount' => $amd(10000), 'occurred_at' => '2026-09-30T08:00:00+00:00'],
            ],
        ],
        'trip_history' => [
            ['id' => 1, 'code' => 'TK-8F3K2', 'route' => 'Republic Square → EVN Airport', 'fare' => $amd(12500), 'status' => 'completed', 'rating' => '5.0', 'occurred_at' => '2026-10-05T14:12:00+00:00'],
            ['id' => 2, 'code' => 'TK-7A91M', 'route' => 'Cascade → Komitas Ave', 'fare' => $amd(6800), 'status' => 'completed', 'rating' => '4.0', 'occurred_at' => '2026-10-05T12:44:00+00:00'],
            ['id' => 3, 'code' => 'TK-6C2P8', 'route' => 'Northern Ave → Arabkir', 'fare' => $amd(5400), 'status' => 'cancelled', 'rating' => null, 'occurred_at' => '2026-10-04T18:08:00+00:00'],
        ],
        'bank_accounts' => [
            ['id' => 1, 'bank_name' => 'Ameriabank', 'account_last4' => '4821', 'is_default' => true],
        ],
    ],
];
