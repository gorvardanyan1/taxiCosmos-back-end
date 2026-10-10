<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/** An offer was withdrawn, taken by another driver or timed out (P7-T7). */
class TripOfferCancelled extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $tripId,
        public readonly int $driverUserId,
        /** One of: accepted_by_other, expired, trip_cancelled, reassigned. */
        public readonly string $reason,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::TripOfferCancelled;
    }

    public function payload(): array
    {
        return [
            'offer_id' => $this->offerId,
            'trip_id' => $this->tripId,
            'driver_user_id' => $this->driverUserId,
            'reason' => $this->reason,
        ];
    }
}
