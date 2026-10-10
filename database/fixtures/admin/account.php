<?php

// My Account security and notification data (real data: P3-T2 / P13-T3 / P13-T6).
return [
    'two_factor_enabled' => true,
    'sessions' => [
        ['id' => 'current', 'device' => 'Chrome · macOS', 'ip' => '10.10.4.21', 'last_active_at' => now()->toIso8601String(), 'is_current' => true],
        ['id' => 'other-1', 'device' => 'Safari · iPhone', 'ip' => '10.10.6.14', 'last_active_at' => now()->subHours(2)->toIso8601String(), 'is_current' => false],
    ],
    'notification_preferences' => [
        ['event' => 'new_driver_application', 'in_app' => true, 'email' => true],
        ['event' => 'document_expiring', 'in_app' => true, 'email' => true],
        ['event' => 'stuck_trip', 'in_app' => true, 'email' => true],
        ['event' => 'urgent_ticket', 'in_app' => true, 'email' => true],
        ['event' => 'chargeback_opened', 'in_app' => true, 'email' => true],
        ['event' => 'payout_batch_ready', 'in_app' => true, 'email' => true],
        ['event' => 'manual_payment_pending', 'in_app' => false, 'email' => false],
        ['event' => 'system_health', 'in_app' => false, 'email' => false],
    ],
];
