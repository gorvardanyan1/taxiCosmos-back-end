<?php

namespace App\Services\Fares;

use App\Data\GeoPoint;
use App\Models\FareFee;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The fixed surcharges that apply to a trip's places at a moment. A fee is charged once per trip,
 * when at least one end it applies to (pickup, drop-off or either) is inside the zone or drawn area
 * it is set for. Only fees in the rule's currency count. Points reach SQL as bound parameters.
 */
final class FareFeeLookup
{
    /**
     * @return Collection<int, FareFee>
     */
    public function forTrip(GeoPoint $pickup, GeoPoint $dropoff, string $currency, ?CarbonInterface $at = null): Collection
    {
        $covers = fn (string $point) => "(
            (fare_fees.polygon IS NOT NULL AND ST_Covers(fare_fees.polygon, {$point}))
            OR (fare_fees.zone_id IS NOT NULL AND EXISTS (SELECT 1 FROM zones z WHERE z.id = fare_fees.zone_id AND ST_Covers(z.polygon, {$point})))
        )";
        $pickupPoint = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';
        $dropoffPoint = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';

        return FareFee::query()
            ->where('currency', $currency)
            ->effectiveAt($at ?? now())
            ->whereRaw(
                "((fare_fees.applies_to IN ('pickup', 'both') AND {$covers($pickupPoint)}) OR (fare_fees.applies_to IN ('dropoff', 'both') AND {$covers($dropoffPoint)}))",
                [
                    $pickup->longitude, $pickup->latitude, $pickup->longitude, $pickup->latitude, // the pickup point is used twice (drawn area, then zone)
                    $dropoff->longitude, $dropoff->latitude, $dropoff->longitude, $dropoff->latitude,
                ],
            )
            ->orderBy('id')
            ->get();
    }
}
