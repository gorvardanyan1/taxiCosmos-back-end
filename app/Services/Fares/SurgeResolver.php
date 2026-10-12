<?php

namespace App\Services\Fares;

use App\Enums\VehicleClass;
use App\Models\Zone;
use Carbon\CarbonInterface;

/**
 * The surge multiplier for a zone and vehicle class at a moment, in hundredths (100 = no surge).
 * Surge rules arrive with P5-T4, which fills in this one method; the calculator, the estimate and
 * the quote already read the multiplier from here and snapshot it, so nothing else changes then.
 */
final class SurgeResolver
{
    public function hundredths(Zone $zone, VehicleClass $class, CarbonInterface $at): int
    {
        return 100;
    }
}
