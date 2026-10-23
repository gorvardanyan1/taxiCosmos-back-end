<?php

namespace Tests\Feature\Trips;

use App\Data\GeoPoint;
use App\Models\DriverLocationPing;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Services\Fares\RouteEstimator;
use App\Services\Trips\TripMetrics;
use Carbon\CarbonImmutable;

/**
 * How the final distance, duration and waiting of a ride are measured (P7-T6).
 */
class TripMetricsTest extends TripApiTestCase
{
    /** The route estimate of the factory trip (Republic Square to Cascade: 1491 m straight, x 1.30). */
    private const FACTORY_ROUTE_METERS = 1939;

    private CarbonImmutable $start;

    private DriverProfile $profile;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = CarbonImmutable::now()->startOfMinute()->subMinutes(30);
        $this->profile = $this->driver()[1];
        $this->trip = Trip::factory()->inProgress()->create([
            'rider_id' => $this->rider->id, 'zone_id' => $this->zone->id, 'driver_id' => $this->profile->id,
            'arrived_at' => $this->start->subMinutes(5), 'started_at' => $this->start,
        ]);
    }

    private function pingAt(float $lat, float $lng, CarbonImmutable $at, ?DriverProfile $driver = null): void
    {
        $driver ??= $this->profile;
        DriverLocationPing::query()->create(['driver_user_id' => $driver->user_id, 'driver_profile_id' => $driver->id, 'location' => ['type' => 'Point', 'coordinates' => [$lng, $lat]], 'recorded_at' => $at]);
    }

    private function metres(float $fromLat, float $toLat): float
    {
        return app(RouteEstimator::class)->straightLineMeters(new GeoPoint($fromLat, 44.51), new GeoPoint($toLat, 44.51));
    }

    private function measure(?CarbonImmutable $until = null): array
    {
        return app(TripMetrics::class)->measure($this->trip->refresh(), $until ?? $this->start->addMinutes(20));
    }

    public function test_the_distance_is_the_sum_of_the_legs_between_pings(): void
    {
        $this->pingAt(40.18, 44.51, $this->start->addMinute());
        $this->pingAt(40.189, 44.51, $this->start->addMinutes(2));
        $this->pingAt(40.20, 44.51, $this->start->addMinutes(3));

        $ride = $this->measure();

        $this->assertSame('pings', $ride['source']);
        $this->assertSame((int) round($this->metres(40.18, 40.189) + $this->metres(40.189, 40.20)), $ride['distance_meters']);
    }

    public function test_duration_and_waiting_come_from_the_trips_own_times(): void
    {
        $ride = $this->measure($this->start->addMinutes(12)->addSeconds(30));

        $this->assertSame(750, $ride['duration_seconds']);
        $this->assertSame(300, $ride['waiting_seconds']);
    }

    public function test_a_trip_that_never_logged_its_arrival_has_no_waiting_time(): void
    {
        $this->trip->forceFill(['arrived_at' => null])->save();

        $this->assertSame(0, $this->measure()['waiting_seconds']);
    }

    public function test_a_gap_between_pings_longer_than_a_drive_is_not_counted(): void
    {
        $this->pingAt(40.18, 44.51, $this->start->addMinute());
        $this->pingAt(40.189, 44.51, $this->start->addMinutes(2));
        // The app was closed for ten minutes: 1 km in ten minutes is not a leg of the ride.
        $this->pingAt(40.198, 44.51, $this->start->addMinutes(12));

        $this->assertSame((int) round($this->metres(40.18, 40.189)), $this->measure()['distance_meters']);
    }

    public function test_a_gps_jump_is_not_counted(): void
    {
        $this->pingAt(40.18, 44.51, $this->start->addMinute());
        $this->pingAt(40.189, 44.51, $this->start->addMinute()->addSeconds(60));
        // About 100 km north in ten seconds.
        $this->pingAt(41.1, 44.51, $this->start->addMinute()->addSeconds(70));
        $this->pingAt(40.198, 44.51, $this->start->addMinute()->addSeconds(130));

        // The leg to the glitch and the leg back are both impossible and left out; the first leg stands.
        $this->assertSame((int) round($this->metres(40.18, 40.189)), $this->measure()['distance_meters']);
    }

    public function test_only_pings_during_the_ride_and_from_this_driver_count(): void
    {
        $other = $this->driver()[1];
        $this->pingAt(40.10, 44.51, $this->start->subMinute());                    // before the ride started
        $this->pingAt(40.18, 44.51, $this->start->addMinute());
        $this->pingAt(40.189, 44.51, $this->start->addMinutes(2));
        $this->pingAt(40.1895, 44.51, $this->start->addSeconds(150), $other);    // someone else's, a believable leg if it were counted
        $this->pingAt(40.30, 44.51, $this->start->addMinutes(25));                 // after the ride ended

        $this->assertSame((int) round($this->metres(40.18, 40.189)), $this->measure()['distance_meters']);
    }

    public function test_a_stationary_ride_has_no_distance_but_still_comes_from_the_pings(): void
    {
        $this->pingAt(40.18, 44.51, $this->start->addMinute());
        $this->pingAt(40.18, 44.51, $this->start->addMinutes(2));

        $ride = $this->measure();

        $this->assertSame(['pings', 0], [$ride['source'], $ride['distance_meters']]);
    }

    public function test_with_fewer_than_two_pings_the_quoted_route_stands_in(): void
    {
        $this->assertSame(['route', self::FACTORY_ROUTE_METERS], [$this->measure()['source'], $this->measure()['distance_meters']]);

        $this->pingAt(40.18, 44.51, $this->start->addMinute());

        $this->assertSame(['route', self::FACTORY_ROUTE_METERS], [$this->measure()['source'], $this->measure()['distance_meters']]);
    }

    public function test_when_the_pings_cannot_be_read_the_quoted_route_stands_in(): void
    {
        $this->pingAt(40.18, 44.51, $this->start->addMinute());
        $this->pingAt(40.189, 44.51, $this->start->addMinutes(2));
        $unreadable = true;
        // MongoDB failing on the read (down, timing out).
        DriverLocationPing::addGlobalScope('unreadable', function () use (&$unreadable) {
            if ($unreadable) {
                throw new \RuntimeException('MongoDB is down');
            }
        });

        try {
            $ride = $this->measure();
        } finally {
            $unreadable = false;
        }

        $this->assertSame(['route', self::FACTORY_ROUTE_METERS], [$ride['source'], $ride['distance_meters']]);
    }
}
