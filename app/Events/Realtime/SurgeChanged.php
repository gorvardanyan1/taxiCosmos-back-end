<?php

namespace App\Events\Realtime;

use App\Realtime\RealtimeEventType;
use Carbon\CarbonInterface;

/** A surge rule was created, started, ended or changed (P5-T4). */
class SurgeChanged extends RealtimeDomainEvent
{
    public function __construct(
        public readonly int $surgeId,
        public readonly int $zoneId,
        public readonly ?string $vehicleClass,
        /** Decimal string, e.g. "1.50". */
        public readonly string $multiplier,
        public readonly bool $active,
        public readonly ?CarbonInterface $startsAt,
        public readonly ?CarbonInterface $endsAt,
    ) {
        parent::__construct();
    }

    public function type(): RealtimeEventType
    {
        return RealtimeEventType::SurgeChanged;
    }

    public function payload(): array
    {
        return [
            'surge_id' => $this->surgeId,
            'zone_id' => $this->zoneId,
            'vehicle_class' => $this->vehicleClass,
            'multiplier' => $this->multiplier,
            'active' => $this->active,
            'starts_at' => $this->startsAt?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'ends_at' => $this->endsAt?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
