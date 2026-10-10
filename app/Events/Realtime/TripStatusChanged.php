<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

class TripStatusChanged extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $tripId,
        public readonly string $tripCode,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly int $riderUserId,
        public readonly ?int $driverUserId,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::TripStatusChanged;
    }

    public function payload(): array
    {
        return [
            'trip_id' => $this->tripId,
            'trip_code' => $this->tripCode,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'rider_user_id' => $this->riderUserId,
            'driver_user_id' => $this->driverUserId,
        ];
    }
}
