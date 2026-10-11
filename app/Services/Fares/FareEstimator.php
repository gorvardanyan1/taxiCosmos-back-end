<?php

namespace App\Services\Fares;

use App\Data\GeoPoint;
use App\Exceptions\FareException;
use App\Models\FareQuote;
use App\Models\FareRule;
use App\Models\User;
use App\Services\Zones\ZoneLocator;
use Illuminate\Support\Facades\DB;

/**
 * The rider's estimate: for a pickup and a drop-off, one price per vehicle class that has a fare rule
 * in the pickup zone, stored as a quote the trip can later be created from. The quote keeps the rule
 * id, surge and full breakdown of each class, so the trip is priced exactly as the rider saw it
 * even if a rule changes within the quote's life.
 */
final class FareEstimator
{
    public function __construct(
        private readonly ZoneLocator $zones,
        private readonly RouteEstimator $routes,
        private readonly FareRuleLookup $rules,
        private readonly FareFeeLookup $fees,
        private readonly SurgeResolver $surge,
        private readonly FareCalculator $calculator,
    ) {}

    /**
     * @throws FareException outside_service_area when no active zone contains the pickup; no_fare_rules when the zone has no price yet
     */
    public function quote(User $rider, GeoPoint $pickup, GeoPoint $dropoff): FareQuote
    {
        $zone = $this->zones->find($pickup) ?? throw FareException::outsideServiceArea();
        $now = now();
        $rules = $this->rules->allAt($zone, $now);

        if ($rules->isEmpty()) {
            throw FareException::noFareRules();
        }

        $route = $this->routes->estimate($pickup, $dropoff);
        $estimates = [];

        /** @var FareRule $rule */
        foreach ($rules as $rule) {
            $surge = $this->surge->hundredths($zone, $rule->vehicle_class, $now);
            $breakdown = $this->calculator->calculate(
                $rule, $route['distance_meters'], $route['duration_seconds'], 0, $surge,
                $this->fees->forTrip($pickup, $dropoff, $rule->currency, $now),
            );
            $range = (int) config('taxikosmos.fares.range_bps');

            $estimates[] = [
                'vehicle_class' => $rule->vehicle_class->value,
                'fare_rule_id' => $rule->id,
                'currency' => $rule->currency,
                'amount' => $breakdown->total,
                'amount_min' => intdiv($breakdown->total * (10_000 - $range) * 2 + 10_000, 20_000),
                'amount_max' => intdiv($breakdown->total * (10_000 + $range) * 2 + 10_000, 20_000),
                'surge_multiplier' => $breakdown->surgeMultiplier,
                'breakdown' => $breakdown->toArray(),
            ];
        }

        return DB::transaction(fn () => FareQuote::query()->create([
            'rider_id' => $rider->id,
            'zone_id' => $zone->id,
            'pickup_location' => $pickup,
            'dropoff_location' => $dropoff,
            'distance_meters' => $route['distance_meters'],
            'duration_seconds' => $route['duration_seconds'],
            'currency' => $zone->currency,
            'estimates' => $estimates,
            'expires_at' => $now->copy()->addMinutes((int) config('taxikosmos.fares.quote_ttl_minutes')),
        ]));
    }
}
