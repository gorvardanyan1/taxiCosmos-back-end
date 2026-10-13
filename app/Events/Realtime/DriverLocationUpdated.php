<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;
use Carbon\CarbonInterface;

/**
 * A position reported through POST /api/v1/driver/location (P8-T2), published after it is stored in Redis. Pings the Node
 * service receives itself are written straight to Redis; this event lets the fallback pings reach the same riders and admin map.
 * A missed ping is harmless: the next one replaces it.
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
