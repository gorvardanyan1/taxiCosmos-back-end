<?php

namespace App\Services\Commission;

use App\Enums\VehicleClass;
use App\Models\CommissionRule;
use App\Models\Zone;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Finds the commission rule in force at a moment: effective_from <= at < effective_to (or still
 * open). A rule written for the vehicle class beats the zone's "every class" rule.
 */
final class CommissionRuleLookup
{
    public function at(Zone|int $zone, VehicleClass $class, ?CarbonInterface $at = null): ?CommissionRule
    {
        return CommissionRule::query()
            ->where('zone_id', $zone instanceof Zone ? $zone->id : $zone)
            ->where(fn ($query) => $query->where('vehicle_class', $class->value)->orWhereNull('vehicle_class'))
            ->effectiveAt($at ?? now())
            ->orderByRaw('vehicle_class IS NULL')
            ->first();
    }

    /**
     * Every rule in force at the moment, newest first.
     *
     * @return Collection<int, CommissionRule>
     */
    public function allAt(?CarbonInterface $at = null): Collection
    {
        return CommissionRule::query()->with('zone:id,name,code')->effectiveAt($at ?? now())->orderByDesc('effective_from')->orderByDesc('id')->get();
    }

    /**
     * Every version of one rule (same zone, same class or "all classes"), newest first.
     *
     * @return Collection<int, CommissionRule>
     */
    public function history(Zone|int $zone, ?VehicleClass $class): Collection
    {
        return CommissionRule::query()
            ->with('creator:id,name')
            ->where('zone_id', $zone instanceof Zone ? $zone->id : $zone)
            ->when($class === null, fn ($query) => $query->whereNull('vehicle_class'), fn ($query) => $query->where('vehicle_class', $class->value))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
