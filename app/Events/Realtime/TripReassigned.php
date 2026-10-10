<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/** An admin or dispatch moved a live trip to another driver (P7-T2). */
class TripReassigned extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $tripId,
        public readonly string $tripCode,
        public readonly int $riderUserId,
        public readonly ?int $previousDriverUserId,
        public readonly int $newDriverUserId,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::TripReassigned;
    }

    public function payload(): array
    {
        return [
            'trip_id' => $this->tripId,
            'trip_code' => $this->tripCode,
            'rider_user_id' => $this->riderUserId,
            'previous_driver_user_id' => $this->previousDriverUserId,
            'new_driver_user_id' => $this->newDriverUserId,
        ];
    }
}
