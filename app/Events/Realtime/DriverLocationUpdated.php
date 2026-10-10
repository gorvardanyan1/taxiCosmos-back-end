<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;
use Carbon\CarbonInterface;

/**
 * Placeholder for P8-T2. Live positions normally go through Redis GEOADD / pub-sub (a missed
 * ping is harmless); this stream exists so the contract is complete and a consumer can subscribe
 * to coarse position changes if that task decides to publish them.
 */
class DriverLocationUpdated extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $driverUserId,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?int $heading,
        public readonly CarbonInterface $recordedAt,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::DriverLocationUpdated;
    }

    public function payload(): array
    {
        return [
            'driver_user_id' => $this->driverUserId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'heading' => $this->heading,
            'recorded_at' => $this->recordedAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
