<?php

namespace Tests\Feature\DriverLocation;

use App\Data\GeoPoint;
use App\Enums\DriverAvailability;
use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Enums\UserStatus;
use App\Models\DriverLocationPing;
use App\Models\DriverProfile;
use App\Models\User;
use App\Realtime\RealtimeEventType;
use App\Realtime\StreamClient;
use App\Services\Drivers\DriverLocationStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * P8-T2: POST /api/v1/driver/location, the REST fallback for the driver's position.
 */
class DriverLocationApiTest extends DriverLocationTestCase
{
    private function nothingWritten(): void
    {
        $this->assertSame(0, $this->positionCount(), 'nothing in Redis');
        $this->assertSame(0, DriverLocationPing::query()->count(), 'nothing in MongoDB');
        $this->assertSame(0, $this->streamLength(RealtimeEventType::DriverLocationUpdated), 'no event');
    }

    public function test_a_ping_goes_to_redis_with_its_time_to_mongodb_and_to_the_stream(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00.250', 'UTC'));
        [$user, $profile, $token] = $this->driver();

        $this->ping($token, ['lat' => 40.1792, 'lng' => 44.4991, 'heading' => 270.4, 'speed' => 12.5, 'accuracy' => 8])
            ->assertOk()->assertExactJson(['recorded_at' => '2026-10-14T10:00:00.250Z']);

        // Redis: the position (GEO precision is about a metre) and the time of the ping, one entry for this driver.
        [$lng, $lat] = $this->position($user->id);
        $this->assertEqualsWithDelta(44.4991, $lng, 0.0001);
        $this->assertEqualsWithDelta(40.1792, $lat, 0.0001);
        $this->assertSame(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC')->getTimestamp(), $this->lastSeen($user->id));
        $this->assertSame(2, $this->positionCount());

        // MongoDB: the lightweight ping, GeoJSON with longitude first.
        $ping = DriverLocationPing::query()->sole();
        $this->assertSame([$user->id, $profile->id], [$ping->driver_user_id, $profile->id]);
        $this->assertSame(['type' => 'Point', 'coordinates' => [44.4991, 40.1792]], ['type' => $ping->location['type'], 'coordinates' => (array) $ping->location['coordinates']]);
        $this->assertSame([270, 12.5, 8.0, 'rest'], [$ping->heading, $ping->speed, $ping->accuracy, $ping->source]);
        $this->assertSame('2026-10-14T10:00:00.250Z', $ping->recorded_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        // The stream the Node service reads.
        $payload = $this->entries(RealtimeEventType::DriverLocationUpdated)[0]['envelope']->payload;
        $this->assertSame(['driver_user_id' => $user->id, 'latitude' => 40.1792, 'longitude' => 44.4991, 'heading' => 270, 'recorded_at' => '2026-10-14T10:00:00.250Z'], $payload);
    }

    public function test_the_next_ping_moves_the_same_entry_and_history_keeps_both(): void
    {
        [$user, , $token] = $this->driver();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $this->ping($token, ['lat' => 40.10, 'lng' => 44.50])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:04', 'UTC'));

        $this->ping($token, ['lat' => 40.20, 'lng' => 44.60])->assertOk();

        $this->assertSame(2, $this->positionCount(), 'one member in each set, not two');
        $this->assertEqualsWithDelta(40.20, $this->position($user->id)[1], 0.0001);
        $this->assertSame(CarbonImmutable::parse('2026-10-14 10:00:04', 'UTC')->getTimestamp(), $this->lastSeen($user->id));
        $this->assertSame(2, DriverLocationPing::query()->count());
        $this->assertSame(2, $this->streamLength(RealtimeEventType::DriverLocationUpdated));
    }

    public function test_a_driver_on_a_trip_keeps_reporting(): void
    {
        [$user, , $token] = $this->driver(DriverAvailability::OnTrip);

        $this->ping($token)->assertOk();

        $this->assertNotNull($this->position($user->id));
    }

    public function test_heading_speed_and_accuracy_are_optional_and_360_is_north(): void
    {
        [, , $token] = $this->driver();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $this->ping($token, ['lat' => 40.1, 'lng' => 44.5])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:05', 'UTC'));
        $this->ping($token, ['lat' => 40.1, 'lng' => 44.5, 'heading' => 360])->assertOk();

        $pings = DriverLocationPing::query()->orderBy('recorded_at')->get();
        $this->assertSame([null, 0], [$pings[0]->heading, $pings[1]->heading]);
        $this->assertNull($pings[0]->speed);
        $this->assertNull($pings[0]->accuracy);
    }

    public function test_the_driver_is_always_the_signed_in_one_whatever_the_request_says(): void
    {
        [$me, , $token] = $this->driver();
        [$other] = $this->driver();

        $this->ping($token, ['lat' => 40.1, 'lng' => 44.5, 'driver_id' => $other->id, 'driver_user_id' => $other->id, 'user_id' => $other->id])->assertOk();

        $this->assertNotNull($this->position($me->id));
        $this->assertNull($this->position($other->id));
        $this->assertSame($me->id, DriverLocationPing::query()->sole()->driver_user_id);
    }

    // ---- who may ----

    public function test_a_guest_a_junk_token_a_rider_and_an_unfinished_sign_in_are_refused(): void
    {
        $rider = User::factory()->rider()->create();
        $pending = User::factory()->driver()->create()->createToken('device', ['pending-phone:driver'])->plainTextToken;

        $this->postJson('/api/v1/driver/location', ['lat' => 40.1, 'lng' => 44.5])->assertUnauthorized();
        $this->ping('junk')->assertUnauthorized();
        $this->ping($rider->createToken('device', ['rider'])->plainTextToken)->assertForbidden();
        $this->ping($pending)->assertForbidden();
        $this->nothingWritten();
    }

    public function test_a_rider_app_token_of_a_user_who_is_also_an_approved_driver_is_refused(): void
    {
        [$user] = $this->driver();
        $riderAppToken = $user->createToken('rider-app', ['rider'])->plainTextToken;

        $this->ping($riderAppToken)->assertForbidden();

        $this->nothingWritten();
    }

    public function test_suspending_a_driver_ends_their_session_and_a_suspended_account_cannot_sign_in_a_new_one(): void
    {
        [$user, , $token] = $this->driver();
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->ping($token)->assertUnauthorized(); // the token was revoked by the suspension
        $this->ping($user->createToken('late', ['driver'])->plainTextToken)->assertForbidden(); // and a token that still exists is refused

        $this->nothingWritten();
    }

    public function test_a_user_with_no_driver_profile_is_not_verified(): void
    {
        $token = User::factory()->driver()->create()->createToken('device', ['driver'])->plainTextToken;

        $this->ping($token)->assertForbidden()->assertJsonPath('code', 'driver_not_verified');
        $this->nothingWritten();
    }

    // ---- online and verified ----

    public function test_an_offline_driver_is_refused_with_a_code(): void
    {
        [, , $token] = $this->driver(DriverAvailability::Offline);

        $this->ping($token)->assertStatus(409)->assertExactJson(['message' => 'Go online before sending your location.', 'code' => 'driver_offline']);

        $this->nothingWritten();
    }

    /**
     * @return array<string, array{DriverVerificationStatus, string}>
     */
    public static function unverified(): array
    {
        return [
            'pending' => [DriverVerificationStatus::Pending, 'driver_not_verified'],
            'rejected' => [DriverVerificationStatus::Rejected, 'driver_not_verified'],
            'expired' => [DriverVerificationStatus::Expired, 'documents_expired'],
        ];
    }

    #[DataProvider('unverified')]
    public function test_a_driver_who_is_not_approved_is_refused(DriverVerificationStatus $status, string $code): void
    {
        [, , $token] = $this->driver(DriverAvailability::Online, ['verification_status' => $status]);

        $this->ping($token)->assertForbidden()->assertJsonPath('code', $code);

        $this->nothingWritten();
    }

    public function test_an_approved_driver_whose_document_ran_out_by_date_is_refused_before_the_expiry_job_runs(): void
    {
        [, $profile, $token] = $this->driver();
        $this->allDocuments($profile, expiring: DriverDocumentType::Insurance, expiryDate: '2020-01-01');

        $this->ping($token)->assertForbidden()->assertJsonPath('code', 'documents_expired');

        $this->nothingWritten();
    }

    public function test_a_driver_with_every_document_valid_is_accepted(): void
    {
        [$user, $profile, $token] = $this->driver();
        $this->allDocuments($profile);

        $this->ping($token)->assertOk();

        $this->assertNotNull($this->position($user->id));
    }

    // ---- validation ----

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'no body' => [[], 'lat'],
            'no lat' => [['lng' => 44.5], 'lat'],
            'no lng' => [['lat' => 40.1], 'lng'],
            'lat not a number' => [['lat' => 'north', 'lng' => 44.5], 'lat'],
            'lat as a list' => [['lat' => [40.1], 'lng' => 44.5], 'lat'],
            'lat above 90' => [['lat' => 90.5, 'lng' => 44.5], 'lat'],
            'lat below -90' => [['lat' => -90.5, 'lng' => 44.5], 'lat'],
            'lat past what Redis can store' => [['lat' => 85.1, 'lng' => 44.5], 'lat'],
            'lat past what Redis can store, south' => [['lat' => -85.1, 'lng' => 44.5], 'lat'],
            'lng above 180' => [['lat' => 40.1, 'lng' => 180.0001], 'lng'],
            'lng below -180' => [['lat' => 40.1, 'lng' => -181], 'lng'],
            'lng not a number' => [['lat' => 40.1, 'lng' => 'east'], 'lng'],
            'heading above 360' => [['lat' => 40.1, 'lng' => 44.5, 'heading' => 361], 'heading'],
            'heading negative' => [['lat' => 40.1, 'lng' => 44.5, 'heading' => -1], 'heading'],
            'heading not a number' => [['lat' => 40.1, 'lng' => 44.5, 'heading' => 'up'], 'heading'],
            'speed negative' => [['lat' => 40.1, 'lng' => 44.5, 'speed' => -0.1], 'speed'],
            'speed absurd' => [['lat' => 40.1, 'lng' => 44.5, 'speed' => 101], 'speed'],
            'accuracy negative' => [['lat' => 40.1, 'lng' => 44.5, 'accuracy' => -1], 'accuracy'],
            'accuracy absurd' => [['lat' => 40.1, 'lng' => 44.5, 'accuracy' => 10001], 'accuracy'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalid')]
    public function test_coordinates_and_numbers_out_of_range_are_rejected_and_nothing_is_stored(array $body, string $field): void
    {
        [, , $token] = $this->driver();

        $this->ping($token, $body)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->nothingWritten();
    }

    public function test_the_edges_of_the_valid_ranges_are_accepted(): void
    {
        [, , $token] = $this->driver();

        foreach ([[85.05112878, 180], [-85.05112878, -180], [0, 0]] as [$lat, $lng]) {
            $this->ping($token, ['lat' => $lat, 'lng' => $lng, 'heading' => 0, 'speed' => 0, 'accuracy' => 0])->assertOk();
        }

        $this->assertSame(3, DriverLocationPing::query()->count());
    }

    // ---- rate limit ----

    public function test_a_driver_is_limited_per_minute_and_another_driver_is_not(): void
    {
        config(['security.rate_limits.driver_location' => 3]);
        [, , $token] = $this->driver();
        [$other, , $otherToken] = $this->driver();

        foreach (range(1, 3) as $_) {
            $this->ping($token)->assertOk();
        }

        $blocked = $this->ping($token)->assertStatus(429);
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->assertSame(3, DriverLocationPing::query()->count(), 'the refused ping is not stored');

        $this->ping($otherToken)->assertOk();
        $this->assertNotNull($this->position($other->id));
    }

    // ---- failures ----

    public function test_redis_being_down_is_a_503_that_stores_and_announces_nothing(): void
    {
        [, , $token] = $this->driver();
        $this->app->instance(DriverLocationStore::class, new class(app(StreamClient::class)) extends DriverLocationStore
        {
            public function record(int $driverUserId, GeoPoint $point, CarbonInterface $at): void
            {
                throw new RuntimeException('connection refused');
            }
        });
        Log::spy();

        $this->ping($token)->assertStatus(503)->assertExactJson(['message' => 'Your location could not be saved. Please try again.', 'code' => 'location_unavailable']);

        $this->assertSame(0, DriverLocationPing::query()->count());
        $this->assertSame(0, $this->streamLength(RealtimeEventType::DriverLocationUpdated));
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => ! str_contains(json_encode($context), '40.1792') && $context['exception'] === RuntimeException::class);
    }

    public function test_mongodb_being_down_loses_only_the_history_not_the_ping(): void
    {
        [$user, , $token] = $this->driver();
        DriverLocationPing::creating(fn () => throw new RuntimeException('mongo down'));
        Log::spy();

        $this->ping($token)->assertOk();

        $this->assertNotNull($this->position($user->id), 'the live position is there');
        $this->assertSame(1, $this->streamLength(RealtimeEventType::DriverLocationUpdated));
        $this->assertSame(0, DriverLocationPing::query()->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context === ['driver_user_id' => $user->id, 'exception' => RuntimeException::class]);
    }

    public function test_the_refusal_is_in_the_drivers_language(): void
    {
        [, , $token] = $this->driver(DriverAvailability::Offline);

        $message = $this->withHeader('Accept-Language', 'ru')->postJson('/api/v1/driver/location', ['lat' => 40.1, 'lng' => 44.5], $this->bearer($token))->assertStatus(409)->json('message');

        $this->assertSame('Выйдите на линию, прежде чем отправлять местоположение.', $message);
    }

    public function test_a_profile_row_exists_per_driver_in_these_tests(): void
    {
        // Guards the helper: the driver() helper must give each call its own driver and profile.
        $this->driver();
        $this->driver();

        $this->assertSame(2, DriverProfile::query()->count());
    }
}
