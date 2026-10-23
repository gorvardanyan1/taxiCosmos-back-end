<?php

namespace Tests\Feature\Trips;

use App\Enums\TripStatus;
use App\Models\ApiIdempotencyKey;
use App\Models\Trip;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The `idempotent` middleware (P7-T6), through the trip endpoints that use it.
 */
class IdempotencyKeyTest extends TripApiTestCase
{
    private function cancel(Trip $trip, string $key, ?string $token = null)
    {
        return $this->act("/api/v1/trips/{$trip->id}/cancel", $token ?? $this->riderToken, [], $key);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function keys(): array
    {
        return [
            '7 characters' => ['abcdefg', false],
            '8 characters' => ['abcdefgh', true],
            '64 characters' => [str_repeat('k', 64), true],
            '65 characters' => [str_repeat('k', 65), false],
            'letters digits dash underscore' => ['A1-b2_c3-D4', true],
            'a space' => ['abcd efgh', false],
            'a dot' => ['abcd.efgh', false],
            'a slash' => ['abcd/efgh', false],
        ];
    }

    #[DataProvider('keys')]
    public function test_which_keys_are_accepted(string $key, bool $accepted): void
    {
        $trip = $this->requested();

        $response = $this->cancel($trip, $key);

        $accepted ? $response->assertOk() : $response->assertStatus(422)->assertJsonPath('code', 'idempotency_key_required');
        $this->assertSame($accepted ? TripStatus::Cancelled : TripStatus::Requested, $trip->refresh()->status);
    }

    public function test_a_successful_answer_is_stored_under_the_key_and_the_user(): void
    {
        $key = $this->key();
        $response = $this->requestTrip(key: $key)->assertCreated();

        $row = ApiIdempotencyKey::query()->sole();
        $this->assertSame([$this->rider->id, $key, 201], [$row->user_id, $row->key, $row->response_status]);
        $this->assertSame($response->json(), $row->response_body);
    }

    public function test_two_people_can_use_the_same_key(): void
    {
        $key = $this->key();
        $mine = $this->requested();
        $other = User::factory()->rider()->create();
        $otherToken = $other->createToken('device', ['rider'])->plainTextToken;
        $theirs = $this->requested($other, $otherToken);

        $this->cancel($mine, $key)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->cancel($theirs, $key, $otherToken)->assertOk()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame([TripStatus::Cancelled, TripStatus::Cancelled], [$mine->refresh()->status, $theirs->refresh()->status]);
    }

    public function test_the_same_key_on_another_trip_is_a_mistake_not_a_retry(): void
    {
        $first = $this->requested();
        $key = $this->key();
        $this->cancel($first, $key)->assertOk();
        $second = $this->requested();

        $this->cancel($second, $key)->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
        $this->assertSame(TripStatus::Requested, $second->refresh()->status);
    }

    public function test_a_call_still_running_makes_a_repeat_wait(): void
    {
        $trip = $this->requested();
        $key = $this->key();
        ApiIdempotencyKey::query()->create(['user_id' => $this->rider->id, 'key' => $key, 'fingerprint' => hash('sha256', "POST\napi/v1/trips/{$trip->id}/cancel\n[]")]);

        $this->cancel($trip, $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_in_progress')->assertHeader('Retry-After', '1');
        $this->assertSame(TripStatus::Requested, $trip->refresh()->status, 'the repeat did not run');
    }

    public function test_a_claim_left_by_a_call_that_died_is_taken_over_after_two_minutes(): void
    {
        $trip = $this->requested();
        $key = $this->key();
        $claim = ApiIdempotencyKey::query()->create(['user_id' => $this->rider->id, 'key' => $key, 'fingerprint' => hash('sha256', "POST\napi/v1/trips/{$trip->id}/cancel\n[]")]);
        $claim->forceFill(['created_at' => now()->subSeconds(121)])->save();

        $this->cancel($trip, $key)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(TripStatus::Cancelled, $trip->refresh()->status);
    }

    public function test_a_refusal_is_not_stored_so_the_retry_meets_the_real_state(): void
    {
        $trip = $this->requested();
        $key = $this->key();
        Trip::query()->whereKey($trip->id)->update(['status' => TripStatus::InProgress->value, 'driver_id' => $this->driver()[1]->id]);

        $this->cancel($trip, $key)->assertStatus(409);

        $this->assertSame(0, ApiIdempotencyKey::query()->where('key', $key)->count(), 'a 409 leaves nothing behind');
        // The state changes; the same key now runs and succeeds.
        Trip::query()->whereKey($trip->id)->update(['status' => TripStatus::Requested->value, 'driver_id' => null]);
        $this->cancel($trip, $key)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_a_failure_is_not_stored_and_the_same_key_works_after_it(): void
    {
        $trip = $this->requested();
        $key = $this->key();
        $failing = true;
        Trip::updating(function () use (&$failing) {
            if ($failing) {
                throw new RuntimeException('boom');
            }
        });

        $this->cancel($trip, $key)->assertStatus(500);
        $this->assertSame(0, ApiIdempotencyKey::query()->where('key', $key)->count());
        $this->assertSame(TripStatus::Requested, $trip->refresh()->status);

        $failing = false;
        $this->cancel($trip, $key)->assertOk();
        $this->assertSame(TripStatus::Cancelled, $trip->refresh()->status);
    }

    public function test_old_keys_are_pruned_and_recent_ones_kept(): void
    {
        $old = ApiIdempotencyKey::query()->create(['user_id' => $this->rider->id, 'key' => 'old-key-0001', 'fingerprint' => 'x']);
        $old->forceFill(['created_at' => now()->subDay()->subMinute()])->save();
        ApiIdempotencyKey::query()->create(['user_id' => $this->rider->id, 'key' => 'new-key-0001', 'fingerprint' => 'x']);

        (new ApiIdempotencyKey)->pruneAll();

        $this->assertSame(['new-key-0001'], ApiIdempotencyKey::query()->pluck('key')->all());
    }
}
