<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/** An admin adjusted a trip's fare (P7-T2). The internal reason is deliberately not published. */
class TripFareAdjusted extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $tripId,
        public readonly string $tripCode,
        public readonly int $riderUserId,
        public readonly ?int $driverUserId,
        public readonly int $previousFareAmount,
        public readonly int $newFareAmount,
        public readonly string $currency,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::TripFareAdjusted;
    }

    public function payload(): array
    {
        return [
            'trip_id' => $this->tripId,
            'trip_code' => $this->tripCode,
            'rider_user_id' => $this->riderUserId,
            'driver_user_id' => $this->driverUserId,
            'previous_fare' => self::money($this->previousFareAmount, $this->currency),
            'new_fare' => self::money($this->newFareAmount, $this->currency),
        ];
    }
}
