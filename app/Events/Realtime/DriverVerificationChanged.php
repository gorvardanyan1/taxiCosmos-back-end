<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;

/** Raised by DriverProfileObserver when driver_profiles.verification_status changes. */
class DriverVerificationChanged extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $driverUserId,
        public readonly int $driverProfileId,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::DriverVerificationChanged;
    }

    public function payload(): array
    {
        return [
            'driver_user_id' => $this->driverUserId,
            'driver_profile_id' => $this->driverProfileId,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
        ];
    }
}
