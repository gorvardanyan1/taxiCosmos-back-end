<?php

$ticket = fn (int $id, string $code, string $subject, string $requester, string $type, string $priority, string $status, int $minutesAgo) => [
    'id' => $id, 'code' => $code, 'subject' => $subject, 'priority' => $priority, 'status' => $status,
    'requester' => ['name' => $requester, 'type' => $type, 'code' => ($type === 'rider' ? 'R-' : 'D-').(10590 + $id)],
    'created_at' => now()->subMinutes($minutesAgo)->toIso8601String(),
];

// Support tickets (real data: P7-T5).
return [
    'rows' => [
        $ticket(4, 'TKT-2941', 'Safety incident', 'Ani Sargsyan', 'rider', 'urgent', 'open', 12),
        $ticket(3, 'TKT-2940', 'Fare seems incorrect', 'Gor Hakobyan', 'rider', 'high', 'in_progress', 34),
        $ticket(2, 'TKT-2939', 'Phone left in vehicle', 'Mariam Avetisyan', 'rider', 'normal', 'waiting', 95),
        $ticket(1, 'TKT-2938', 'App crashes at checkout', 'Narek Melikyan', 'driver', 'low', 'resolved', 240),
    ],
    // Conversation shown for the selected ticket until P7-T5 loads real messages.
    'conversation' => [
        'messages' => [
            ['id' => 1, 'kind' => 'requester', 'body' => 'The driver was speeding near Mashtots Avenue and I felt unsafe. Please review the trip.'],
            ['id' => 2, 'kind' => 'staff', 'body' => 'Thank you for reporting this. Our safety team is reviewing the route and driver telemetry now.'],
            ['id' => 3, 'kind' => 'internal', 'body' => 'GPS shows two hard-braking events. Escalated to Safety.'],
        ],
        'linked_trip' => ['id' => 8, 'code' => 'TK-8F3K2'],
    ],
];
