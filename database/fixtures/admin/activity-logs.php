<?php

// Admin audit trail (real data: P9-T1). Encrypted fields only ever appear as "changed".
return [
    'target_types' => ['driver_document', 'transaction', 'trip'],
    'rows' => [
        ['id' => 3, 'occurred_at' => '2026-10-05T14:42:00+00:00', 'actor' => ['name' => 'Jordan Avery', 'role' => 'super_admin'], 'action' => 'driver.document.rejected', 'target_type' => 'driver_document', 'target' => 'D-3041 / Insurance', 'reason' => 'Expired policy', 'ip' => '10.10.4.21', 'before' => ['status' => 'pending', 'reviewer' => null, 'document_number' => 'changed'], 'after' => ['status' => 'rejected', 'reviewer' => 'Jordan Avery', 'document_number' => 'changed']],
        ['id' => 2, 'occurred_at' => '2026-10-05T14:20:00+00:00', 'actor' => ['name' => 'Morgan Webb', 'role' => 'finance'], 'action' => 'payment.refund.created', 'target_type' => 'transaction', 'target' => 'TXN-104882', 'reason' => 'Fare adjustment', 'ip' => '10.10.4.18', 'before' => ['refunded' => 0], 'after' => ['refunded' => 3500]],
        ['id' => 1, 'occurred_at' => '2026-10-05T13:58:00+00:00', 'actor' => ['name' => 'Riley Chen', 'role' => 'dispatcher'], 'action' => 'trip.driver.reassigned', 'target_type' => 'trip', 'target' => 'TK-8F3K2', 'reason' => 'Closer driver available', 'ip' => '10.10.4.09', 'before' => ['driver' => 'Lilit Grigoryan'], 'after' => ['driver' => 'Arman Petrosyan']],
    ],
];
