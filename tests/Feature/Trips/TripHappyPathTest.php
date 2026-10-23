<?php

namespace Tests\Feature\Trips;

use App\Enums\DriverAvailability;
use App\Enums\TripPaymentStatus;
use App\Enums\TripStatus;
use App\Models\DriverLocationPing;
use App\Models\Trip;
use App\Realtime\RealtimeEventType;
use App\Services\Trips\TripService;
use Carbon\CarbonImmutable;

/**
 * The whole ride as the two apps live it, through the real endpoints: estimate, request, a driver accepted (dispatch, P7-T7, calls
 * TripService::assignDriver), arrived, start, complete, paid in cash.
 */
class TripHappyPathTest extends TripApiTestCase
{
    public function test_a_cash_trip_from_estimate_to_paid(): void
    {
        $this->travelTo(CarbonImmutable::now()->startOfMinute());
        [, $profile, $driverToken] = $this->driver(DriverAvailability::Online);

        // Rider: estimate and request.
        $quote = $this->quote();
        $requested = $this->requestTrip($this->booking($quote))->assertCreated()->assertJsonPath('data.status', 'requested');
        $trip = Trip::query()->findOrFail($requested->json('data.id'));
        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertOk()->assertJsonPath('data.id', $trip->id);

        // Dispatch: the driver accepts.
        app(TripService::class)->assignDriver($trip, $profile);
        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertJsonPath('data.status', 'matched')->assertJsonPath('data.driver.name', $profile->user->name);
        $this->fetch('/api/v1/driver/trips/current', $driverToken)->assertOk()->assertJsonPath('data.id', $trip->id);

        // Driver: on the way, at the pickup, the ride, the end.
        $this->travel(6)->minutes();
        $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $driverToken)->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertJsonPath('data.status', 'arrived');
        $this->travel(1)->minutes();
        $this->act("/api/v1/driver/trips/{$trip->id}/start", $driverToken)->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->travel(12)->minutes();
        DriverLocationPing::query()->create(['driver_user_id' => $profile->user_id, 'driver_profile_id' => $profile->id, 'location' => ['type' => 'Point', 'coordinates' => [44.51, 40.18]], 'recorded_at' => now()->subMinutes(12)->addSeconds(5)]);
        DriverLocationPing::query()->create(['driver_user_id' => $profile->user_id, 'driver_profile_id' => $profile->id, 'location' => ['type' => 'Point', 'coordinates' => [44.51, 40.189]], 'recorded_at' => now()->subMinutes(11)]);
        DriverLocationPing::query()->create(['driver_user_id' => $profile->user_id, 'driver_profile_id' => $profile->id, 'location' => ['type' => 'Point', 'coordinates' => [44.51, 40.198]], 'recorded_at' => now()->subMinutes(10)]);
        $completed = $this->act("/api/v1/driver/trips/{$trip->id}/complete", $driverToken)->assertOk();

        // Money: cash, the driver holds the fare; the split is stored.
        $trip->refresh();
        $this->assertSame(TripStatus::Completed, $trip->status);
        $this->assertSame(TripPaymentStatus::Paid, $trip->payment_status);
        $this->assertSame($trip->fare_amount, $trip->commission_amount + $trip->driver_earnings_amount);
        $this->assertGreaterThanOrEqual(800, $trip->fare_amount);
        $completed->assertJsonPath('data.fare.final', $trip->fare_amount)->assertJsonPath('data.earnings.amount', $trip->driver_earnings_amount);
        $this->assertSame(DriverAvailability::Online, $profile->refresh()->availability);

        // Rider: nothing active, the trip is in the history, with a receipt.
        $this->fetch('/api/v1/trips/current', $this->riderToken)->assertNoContent();
        $this->fetch('/api/v1/trips', $this->riderToken)->assertJsonPath('data.0.id', $trip->id)->assertJsonPath('data.0.fare.final', $trip->fare_amount)->assertJsonPath('data.0.payment.status', 'paid');
        $this->fetch("/api/v1/trips/{$trip->id}/receipt", $this->riderToken)->assertOk()->assertJsonPath('data.total', $trip->fare_amount);
        // Driver: the trip is in the history and in today's earnings.
        $this->fetch('/api/v1/driver/trips', $driverToken)->assertJsonPath('data.0.id', $trip->id);
        $this->fetch('/api/v1/driver/earnings', $driverToken)->assertJsonPath('data.today.trips', 1)->assertJsonPath('data.today.earnings', $trip->driver_earnings_amount);

        // Every change was announced, in order, with the driver's user id once there was a driver.
        $payloads = array_map(fn ($e) => $e['envelope']->payload, $this->entries(RealtimeEventType::TripStatusChanged));
        $this->assertSame([null, 'requested', 'matched', 'arrived', 'in_progress'], array_column($payloads, 'from_status'));
        $this->assertSame(['requested', 'matched', 'arrived', 'in_progress', 'completed'], array_column($payloads, 'to_status'));
        $this->assertSame([null, $profile->user_id, $profile->user_id, $profile->user_id, $profile->user_id], array_column($payloads, 'driver_user_id'));
        $this->assertSame([$trip->id], array_values(array_unique(array_column($payloads, 'trip_id'))));
    }

    public function test_the_rider_can_book_again_after_the_trip_is_paid(): void
    {
        [$trip, , $driverToken] = $this->matched();
        $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $driverToken)->assertOk();
        $this->act("/api/v1/driver/trips/{$trip->id}/start", $driverToken)->assertOk();
        $this->act("/api/v1/driver/trips/{$trip->id}/complete", $driverToken)->assertOk();

        $this->requestTrip()->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->assertSame(2, Trip::query()->count());
    }
}
