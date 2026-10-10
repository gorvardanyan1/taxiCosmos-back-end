<?php

// Sidebar badges and the TopBar notification dropdown (real counts: P13-T2; notifications: P13-T6).
return [
    'badges' => ['drivers' => 63, 'support-tickets' => 7, 'chargebacks' => 3],
    'notifications' => [
        ['id' => 1, 'kind' => 'safety', 'title' => 'Safety ticket · TKT-2941', 'body' => 'Rider reported an unsafe driving incident', 'created_at' => now()->subMinutes(2)->toIso8601String(), 'read' => false],
        ['id' => 2, 'kind' => 'document', 'title' => 'Driver document expiring', 'body' => 'Arman Petrosyan’s insurance expires in 12 days', 'created_at' => now()->subMinutes(18)->toIso8601String(), 'read' => false],
        ['id' => 3, 'kind' => 'payment', 'title' => 'Payment needs confirmation', 'body' => 'Manual payment AMD 18,500 is waiting', 'created_at' => now()->subHour()->toIso8601String(), 'read' => false],
    ],
];
