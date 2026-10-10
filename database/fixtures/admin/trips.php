<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];
$trip = fn (int $id, string $code, string $rider, ?string $driver, string $pickup, string $dropoff, string $status, ?int $fare, string $at) => [
    'id' => $id, 'code' => $code, 'rider' => $rider, 'driver' => $driver, 'pickup_address' => $pickup,
    'dropoff_address' => $dropoff, 'status' => $status, 'fare' => $fare === null ? null : $amd($fare), 'requested_at' => $at,
];

// Trips list and detail (real data: P7-T1).
return [
    'rows' => [
        $trip(8, 'TK-8F3K2', 'Ani Sargsyan', 'Arman Petrosyan', 'Republic Square', 'EVN Airport', 'completed', 12500, '2026-10-05T14:14:00+00:00'),
        $trip(7, 'TK-7A91M', 'Gor Hakobyan', 'Lilit Grigoryan', 'Cascade Complex', 'Komitas Avenue', 'completed', 6800, '2026-10-05T13:57:00+00:00'),
        $trip(6, 'TK-6C2P8', 'Mariam Avetisyan', 'Mariam Avetisyan', 'Northern Avenue', 'Dalma Mall', 'cancelled', 0, '2026-10-05T13:33:00+00:00'),
        $trip(5, 'TK-5D7N4', 'Narek Sahakyan', 'Ani Sargsyan', 'Arabkir', 'Yerevan State University', 'completed', 5900, '2026-10-05T12:49:00+00:00'),
        $trip(4, 'TK-58200', 'Oliver Wang', 'Marco Rossi', 'West End Blvd', 'Downtown Hotel', 'in_progress', null, '2026-10-05T05:18:00+00:00'),
        $trip(3, 'TK-58199', 'Chloe Martin', 'Dmitri Volkov', 'Pine St & 2nd', 'Tech Campus A', 'in_progress', null, '2026-10-05T05:12:00+00:00'),
        $trip(2, 'TK-58198', 'Ethan Brooks', null, 'River Rd', 'North Station', 'requested', null, '2026-10-05T05:20:00+00:00'),
        $trip(1, 'TK-4H1Q9', 'Lusine Martirosyan', 'Samvel Gevorgyan', 'Davtashen', 'Republic Square', 'cancelled', 0, '2026-10-04T18:04:00+00:00'),
    ],
];
