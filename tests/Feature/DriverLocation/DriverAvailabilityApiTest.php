<?php

namespace Tests\Feature\DriverLocation;

use App\Enums\DriverAvailability;
use App\Enums\DriverDocumentType;
use App\Enums\DriverVerificationStatus;
use App\Enums\UserStatus;
use App\Enums\VehicleStatus;
use App\Exceptions\DriverStatusException;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Realtime\RealtimeEventType;
use App\Services\Drivers\DriverAvailabilityService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P8-T2: POST /api/v1/driver/availability, the online / offline switch.
 */
class DriverAvailabilityApiTest extends DriverLocationTestCase
{
    /** @return list<array{from: ?string, to: string}> */
    private function availabilityEvents(): array
    {
        return array_map(fn ($e) => ['from' => $e['envelope']->payload['from_availability'], 'to' => $e['envelope']->payload['to_availability']], $this->entries(RealtimeEventType::DriverAvailabilityChanged));
    }

    private function refused(string $token, int $status, string $code): void
    {
        $this->setAvailability($token, 'online')->assertStatus($status)->assertJsonPath('code', $code);
    }

    public function test_going_online_changes_the_profile_publishes_the_event_and_drops_a_leftover_position(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        [$user, $profile, $token] = $this->driver(DriverAvailability::Offline);
        $this->seedPosition($user->id, now()->subHours(3)->getTimestamp());

        $this->setAvailability($token, 'online')->assertOk()->assertExactJson(['availability' => 'online']);

        $profile->refresh();
        $this->assertSame(DriverAvailability::Online, $profile->availability);
        $this->assertSame('2026-10-14 10:00:00', $profile->last_online_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame([['from' => 'offline', 'to' => 'online']], $this->availabilityEvents());
        $payload = $this->entries(RealtimeEventType::DriverAvailabilityChanged)[0]['envelope']->payload;
        $this->assertSame([$user->id, $profile->id], [$payload['driver_user_id'], $payload['driver_profile_id']]);
        $this->assertNull($this->position($user->id), 'a position from an earlier session is not where the driver is now');
        $this->assertNull($this->lastSeen($user->id));
    }

    public function test_going_online_twice_changes_and_publishes_once(): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $this->setAvailability($token, 'online')->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:05:00', 'UTC'));

        $this->setAvailability($token, 'online')->assertOk()->assertExactJson(['availability' => 'online']);

        $this->assertSame([['from' => 'offline', 'to' => 'online']], $this->availabilityEvents());
        $this->assertSame('2026-10-14 10:00:00', $profile->fresh()->last_online_at->utc()->format('Y-m-d H:i:s'), 'the time of the first tap stays');
    }

    public function test_going_offline_changes_the_profile_publishes_the_event_and_removes_the_position(): void
    {
        [$user, $profile, $token] = $this->driver();
        $this->seedPosition($user->id, now()->getTimestamp());

        $this->setAvailability($token, 'offline')->assertOk()->assertExactJson(['availability' => 'offline']);

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame([['from' => 'online', 'to' => 'offline']], $this->availabilityEvents());
        $this->assertSame(0, $this->positionCount(), 'neither the position nor the ping time stays');
    }

    public function test_going_offline_when_already_offline_does_nothing(): void
    {
        [, , $token] = $this->driver(DriverAvailability::Offline);

        $this->setAvailability($token, 'offline')->assertOk()->assertExactJson(['availability' => 'offline']);

        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_a_driver_on_a_trip_cannot_go_offline_and_going_online_changes_nothing(): void
    {
        [$user, $profile, $token] = $this->driver(DriverAvailability::OnTrip);
        $this->seedPosition($user->id, now()->getTimestamp());

        $this->setAvailability($token, 'offline')->assertStatus(409)->assertExactJson(['message' => 'You cannot go offline during a trip.', 'code' => 'driver_on_trip']);
        $this->setAvailability($token, 'online')->assertOk()->assertExactJson(['availability' => 'on_trip']);

        $this->assertSame(DriverAvailability::OnTrip, $profile->fresh()->availability);
        $this->assertSame([], $this->availabilityEvents());
        $this->assertNotNull($this->position($user->id), 'the position stays during a trip');
    }

    // ---- refusals while going online ----

    /**
     * @return array<string, array{DriverVerificationStatus, int, string}>
     */
    public static function notApproved(): array
    {
        return [
            'pending' => [DriverVerificationStatus::Pending, 403, 'driver_not_verified'],
            'rejected' => [DriverVerificationStatus::Rejected, 403, 'driver_not_verified'],
            'expired' => [DriverVerificationStatus::Expired, 403, 'documents_expired'],
        ];
    }

    #[DataProvider('notApproved')]
    public function test_an_unverified_driver_cannot_go_online(DriverVerificationStatus $status, int $http, string $code): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline, ['verification_status' => $status]);

        $this->refused($token, $http, $code);

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_a_driver_whose_document_expired_by_date_cannot_go_online_even_while_the_status_still_says_approved(): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline);
        $this->allDocuments($profile, DriverDocumentType::License, '2020-01-01');
        $this->assertSame(DriverVerificationStatus::Approved, $profile->fresh()->verification_status);

        $this->refused($token, 403, 'documents_expired');

        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_a_replaced_document_makes_the_driver_eligible_again(): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline);
        $this->allDocuments($profile, DriverDocumentType::License, '2020-01-01');
        DriverDocument::factory()->ofType(DriverDocumentType::License)->approved()->expiring('2099-01-01')->create(['driver_id' => $profile->id]);

        $this->setAvailability($token, 'online')->assertOk();
    }

    public function test_the_suspended_cannot_go_online_and_nothing_changes(): void
    {
        [$user, $profile] = $this->driver(DriverAvailability::Offline);
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        $lateToken = $user->createToken('late', ['driver'])->plainTextToken;

        $this->setAvailability($lateToken, 'online')->assertForbidden();

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_the_service_itself_refuses_a_suspended_account_whoever_calls_it(): void
    {
        [$user, $profile] = $this->driver(DriverAvailability::Offline);
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        try {
            app(DriverAvailabilityService::class)->goOnline($user->fresh());
            $this->fail('a suspended driver must not go online');
        } catch (DriverStatusException $e) {
            $this->assertSame(['driver_suspended', 403], [$e->errorCode, $e->status]);
        }

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_a_user_without_a_driver_profile_cannot_go_online(): void
    {
        $token = User::factory()->driver()->create()->createToken('device', ['driver'])->plainTextToken;

        $this->refused($token, 403, 'driver_not_verified');
    }

    public function test_a_driver_with_no_vehicle_cannot_go_online(): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline, withVehicle: false);

        $this->refused($token, 409, 'no_active_vehicle');

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
    }

    /**
     * @return array<string, array{callable(Vehicle): void}>
     */
    public static function unusableVehicles(): array
    {
        return [
            'not primary' => [fn (Vehicle $v) => $v->forceFill(['is_primary' => false])->save()],
            'primary but inactive' => [fn (Vehicle $v) => $v->forceFill(['status' => VehicleStatus::Inactive])->save()],
            'primary but waiting for review' => [fn (Vehicle $v) => $v->forceFill(['status' => VehicleStatus::PendingReview])->save()],
            'primary but removed' => [fn (Vehicle $v) => $v->delete()],
        ];
    }

    /**
     * @param  callable(Vehicle): void  $spoil
     */
    #[DataProvider('unusableVehicles')]
    public function test_only_an_active_primary_vehicle_counts(callable $spoil): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline);
        $spoil($profile->vehicles()->sole());

        $this->refused($token, 409, 'no_active_vehicle');
    }

    public function test_an_active_primary_vehicle_among_others_is_enough(): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline);
        Vehicle::factory()->for($profile, 'driver')->create(['status' => VehicleStatus::Inactive]);

        $this->setAvailability($token, 'online')->assertOk();
    }

    // ---- who may, what is valid, how often ----

    public function test_a_rider_app_token_of_a_user_who_is_also_an_approved_driver_cannot_switch(): void
    {
        [$user, $profile] = $this->driver(DriverAvailability::Offline);
        $riderAppToken = $user->createToken('rider-app', ['rider'])->plainTextToken;

        $this->setAvailability($riderAppToken, 'online')->assertForbidden();

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_a_guest_a_rider_and_an_unfinished_sign_in_are_refused(): void
    {
        $rider = User::factory()->rider()->create()->createToken('device', ['rider'])->plainTextToken;
        $pending = User::factory()->driver()->create()->createToken('device', ['pending-phone:driver'])->plainTextToken;

        $this->postJson('/api/v1/driver/availability', ['availability' => 'online'])->assertUnauthorized();
        $this->setAvailability('junk', 'online')->assertUnauthorized();
        $this->setAvailability($rider, 'online')->assertForbidden();
        $this->setAvailability($pending, 'online')->assertForbidden();
        $this->assertSame([], $this->availabilityEvents());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badValues(): array
    {
        return ['missing' => [null], 'on_trip is not the drivers to set' => ['on_trip'], 'wrong case' => ['ONLINE'], 'unknown' => ['busy'], 'a list' => [['online']], 'empty' => ['']];
    }

    #[DataProvider('badValues')]
    public function test_only_online_and_offline_are_accepted(mixed $value): void
    {
        [, $profile, $token] = $this->driver(DriverAvailability::Offline);

        $this->setAvailability($token, $value)->assertUnprocessable()->assertJsonValidationErrors('availability');

        $this->assertSame(DriverAvailability::Offline, $profile->fresh()->availability);
        $this->assertSame([], $this->availabilityEvents());
    }

    public function test_the_switch_is_limited_per_driver(): void
    {
        config(['security.rate_limits.driver_availability' => 2]);
        [, , $token] = $this->driver(DriverAvailability::Offline);
        [, , $otherToken] = $this->driver(DriverAvailability::Offline);

        $this->setAvailability($token, 'online')->assertOk();
        $this->setAvailability($token, 'offline')->assertOk();
        $blocked = $this->setAvailability($token, 'online')->assertStatus(429);
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));

        $this->setAvailability($otherToken, 'online')->assertOk();
    }

    public function test_refusals_are_in_the_drivers_language(): void
    {
        [, , $token] = $this->driver(DriverAvailability::Offline, withVehicle: false);

        $message = $this->withHeader('Accept-Language', 'hy')->postJson('/api/v1/driver/availability', ['availability' => 'online'], $this->bearer($token))->assertStatus(409)->json('message');

        $this->assertSame('Ավելացրեք հաստատված հիմնական ավտոմեքենա՝ առցանց լինելու համար։', $message);
    }

    public function test_it_is_not_a_page_for_admins_or_sessions(): void
    {
        $profile = DriverProfile::factory()->approved()->create();

        $this->actingAs($profile->user)->postJson('/api/v1/driver/availability', ['availability' => 'online'])->assertUnauthorized();
    }
}
