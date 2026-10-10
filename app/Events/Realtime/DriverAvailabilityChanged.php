<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/** Raised by DriverProfileObserver when driver_profiles.availability changes. */
class DriverAvailabilityChanged extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $driverUserId,
        public readonly int $driverProfileId,
        public readonly ?string $fromAvailability,
        public readonly string $toAvailability,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::DriverAvailabilityChanged;
    }

    public function payload(): array
    {
        return [
            'driver_user_id' => $this->driverUserId,
            'driver_profile_id' => $this->driverProfileId,
            'from_availability' => $this->fromAvailability,
            'to_availability' => $this->toAvailability,
        ];
    }
}
