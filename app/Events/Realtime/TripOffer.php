<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;
use Carbon\CarbonInterface;

/** A trip is offered to one driver (P7-T7). */
class TripOffer extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $tripId,
        public readonly int $driverUserId,
        public readonly CarbonInterface $expiresAt,
        public readonly string $pickupAddress,
        public readonly string $dropoffAddress,
        public readonly int $estimatedFareAmount,
        public readonly string $currency,
        public readonly string $vehicleClass,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::TripOffer;
    }

    public function payload(): array
    {
        return [
            'offer_id' => $this->offerId,
            'trip_id' => $this->tripId,
            'driver_user_id' => $this->driverUserId,
            'expires_at' => $this->expiresAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'pickup_address' => $this->pickupAddress,
            'dropoff_address' => $this->dropoffAddress,
            'estimated_fare' => self::money($this->estimatedFareAmount, $this->currency),
            'vehicle_class' => $this->vehicleClass,
        ];
    }
}
