<?php

namespace Tests\Feature\Trips;

use App\Enums\DriverAvailability;
use App\Models\CommissionRule;
use App\Models\DriverProfile;
use App\Models\FareRule;
use App\Models\Trip;
use App\Models\User;
use App\Models\Zone;
use App\Services\Trips\TripService;
use Illuminate\Testing\TestResponse;
use Tests\Feature\DriverLocation\DriverLocationTestCase;

/**
 * The mobile trip API (P7-T6) against real PostgreSQL, Redis and MongoDB. A zone around Yerevan with an economy fare rule
 * (500 base, 150 per km, 20 per minute, 800 minimum, 3 free waiting minutes then 30 per minute) and a 20 % commission rule; a rider with a
 * token. The route of the estimate is 0.01 degrees of latitude: 1446 m, 174 s, so an economy trip is quoted 800 (the minimum).
 */
abstract class TripApiTestCase extends DriverLocationTestCase
{
    protected const PICKUP = ['lat' => 40.18, 'lng' => 44.51, 'address' => 'Republic Square, Yerevan'];

    protected const DROPOFF = ['lat' => 40.19, 'lng' => 44.51, 'address' => 'Cascade, Yerevan'];

    protected Zone $zone;

    protected User $rider;

    protected string $riderToken;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->around(40.18, 44.51, 0.05)->create(['name' => 'Yerevan']);
        FareRule::factory()->forZone($this->zone)->create();
        CommissionRule::factory()->forZone($this->zone)->create();
        $this->rider = User::factory()->rider()->withPhone('+37491234567')->create(['name' => 'Anna Rider']);
        $this->riderToken = $this->rider->createToken('device', ['rider'])->plainTextToken;
    }

    protected function key(): string
    {
        return 'test-key-'.++$this->keys.'-'.bin2hex(random_bytes(4));
    }

    /**
     * A fresh estimate for the standard route; the quote id.
     */
    protected function quote(?string $token = null, ?array $pickup = null, ?array $dropoff = null): string
    {
        app('auth')->forgetGuards();

        $pickup ??= self::PICKUP;
        $dropoff ??= self::DROPOFF;
        $response = $this->postJson('/api/v1/trips/fare-estimate', ['pickup' => ['lat' => $pickup['lat'], 'lng' => $pickup['lng']], 'dropoff' => ['lat' => $dropoff['lat'], 'lng' => $dropoff['lng']]], $this->bearer($token ?? $this->riderToken));
        $response->assertCreated();

        return $response->json('quote_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function booking(string $quoteId, array $overrides = []): array
    {
        return ['quote_id' => $quoteId, 'vehicle_class' => 'economy', 'payment_method' => 'cash', 'pickup' => self::PICKUP, 'dropoff' => self::DROPOFF, ...$overrides];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function requestTrip(?array $body = null, ?string $token = null, ?string $key = null): TestResponse
    {
        app('auth')->forgetGuards();
        $body ??= $this->booking($this->quote($token));

        return $this->postJson('/api/v1/trips', $body, [...$this->bearer($token ?? $this->riderToken), 'Idempotency-Key' => $key ?? $this->key()]);
    }

    /**
     * POST to a trip step with a fresh Idempotency-Key unless one is given.
     *
     * @param  array<string, mixed>  $body
     */
    protected function act(string $path, string $token, array $body = [], ?string $key = null): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->postJson($path, $body, [...$this->bearer($token), 'Idempotency-Key' => $key ?? $this->key()]);
    }

    protected function fetch(string $path, string $token): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->getJson($path, $this->bearer($token));
    }

    /**
     * A trip the rider requested through the API.
     */
    protected function requested(?User $rider = null, ?string $token = null): Trip
    {
        $response = $this->requestTrip(token: $token);
        $response->assertCreated();

        return Trip::query()->findOrFail($response->json('data.id'));
    }

    /**
     * A trip matched to a driver the way dispatch will do it (TripService::assignDriver).
     *
     * @return array{0: Trip, 1: DriverProfile, 2: string}
     */
    protected function matched(): array
    {
        [, $profile, $driverToken] = $this->driver(DriverAvailability::Online);
        $trip = app(TripService::class)->assignDriver($this->requested(), $profile);

        return [$trip, $profile, $driverToken];
    }
}
