<?php

namespace App\Services\Fares;

use App\Enums\VehicleClass;
use App\Models\FareRule;
use App\Models\Zone;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Finds the fare rule that was in force at a moment: effective_from <= at < effective_to (or still
 * open). Answers both "what do we charge now" and "what did we charge then".
 */
final class FareRuleLookup
{
    public function at(Zone|int $zone, VehicleClass $class, ?CarbonInterface $at = null): ?FareRule
    {
        return FareRule::query()
            ->where('zone_id', $zone instanceof Zone ? $zone->id : $zone)
            ->where('vehicle_class', $class->value)
            ->effectiveAt($at ?? now())
            ->first();
    }

    /**
     * Every class that has a rule in force at the moment, keyed by class, in the order of VehicleClass.
     *
     * @return Collection<string, FareRule>
     */
    public function allAt(Zone|int $zone, ?CarbonInterface $at = null): Collection
    {
        return FareRule::query()
            ->where('zone_id', $zone instanceof Zone ? $zone->id : $zone)
            ->effectiveAt($at ?? now())
            ->get()
            ->sortBy(fn (FareRule $rule) => array_search($rule->vehicle_class, VehicleClass::cases(), true))
            ->keyBy(fn (FareRule $rule) => $rule->vehicle_class->value);
    }

    /**
     * Every version of one class in a zone, newest first.
     *
     * @return Collection<int, FareRule>
     */
    public function history(Zone|int $zone, VehicleClass $class): Collection
    {
        return FareRule::query()
            ->with('creator:id,name')
            ->where('zone_id', $zone instanceof Zone ? $zone->id : $zone)
            ->where('vehicle_class', $class->value)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
