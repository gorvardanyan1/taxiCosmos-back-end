<?php

// My Account notification preferences (real data: P13-T6). Two-factor state (P3-T2), the profile and the sessions (P13-T3) are real.
return [
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
