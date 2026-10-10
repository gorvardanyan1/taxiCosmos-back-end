<?php

$amd = fn (int $amount) => ['amount' => $amount, 'currency' => 'AMD'];

// Report builder preview and exports (real data: P13-T9).
return [
    'zones' => [['id' => 1, 'name' => 'Downtown Core'], ['id' => 2, 'name' => 'Airport Zone']],
    'series' => [
        ['label' => 'Aug 1–7', 'revenue' => $amd(42100), 'trips' => 2840, 'driver_earnings' => $amd(30312), 'commission' => $amd(11788), 'cancellations' => 142, 'refunds' => $amd(4200), 'payouts' => $amd(29800), 'driver_debt' => $amd(3100)],
        ['label' => 'Aug 8–14', 'revenue' => $amd(51800), 'trips' => 3310, 'driver_earnings' => $amd(37296), 'commission' => $amd(14504), 'cancellations' => 188, 'refunds' => $amd(5100), 'payouts' => $amd(36100), 'driver_debt' => $amd(3600)],
        ['label' => 'Aug 15–21', 'revenue' => $amd(49200), 'trips' => 3190, 'driver_earnings' => $amd(35424), 'commission' => $amd(13776), 'cancellations' => 164, 'refunds' => $amd(3900), 'payouts' => $amd(34900), 'driver_debt' => $amd(3300)],
        ['label' => 'Aug 22–28', 'revenue' => $amd(58400), 'trips' => 3820, 'driver_earnings' => $amd(42048), 'commission' => $amd(16352), 'cancellations' => 201, 'refunds' => $amd(6200), 'payouts' => $amd(41200), 'driver_debt' => $amd(4100)],
        ['label' => 'Aug 29–Sep 3', 'revenue' => $amd(31500), 'trips' => 2040, 'driver_earnings' => $amd(22680), 'commission' => $amd(8820), 'cancellations' => 96, 'refunds' => $amd(2400), 'payouts' => $amd(21900), 'driver_debt' => $amd(2700)],
    ],
    'exports' => [
        ['id' => 2, 'name' => 'Revenue · Sep 2026', 'status' => 'ready', 'file_name' => 'report-sep-2026.csv'],
        ['id' => 1, 'name' => 'Trips · Q3 2026', 'status' => 'processing', 'file_name' => null],
    ],
];
