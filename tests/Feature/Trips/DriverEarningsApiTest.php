<?php

namespace Tests\Feature\Trips;

use App\Enums\TripStatus;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * GET /api/v1/driver/earnings: today, the last 7 days and the last 30 days, added up from completed trips in the driver's home zone's
 * calendar (Asia/Yerevan is UTC+4: a local day runs from 20:00 UTC to 20:00 UTC).
 */
class DriverEarningsApiTest extends TripApiTestCase
{
    private DriverProfile $profile;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 20 October 2026, 14:00 in Yerevan.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'));
        [, $this->profile, $this->token] = $this->driver(profile: ['home_zone_id' => $this->zone->id]);
    }

    /**
     * A completed trip: the fare, the commission and the driver's share (fare - commission).
     */
    private function trip(string $completedAtUtc, int $fare = 1000, int $commission = 200, array $extra = []): Trip
    {
        return Trip::factory()->completed()->create([
            'rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $this->profile->id,
            'completed_at' => CarbonImmutable::parse($completedAtUtc, 'UTC'), 'fare_amount' => $fare, 'commission_amount' => $commission, 'driver_earnings_amount' => $fare - $commission,
            ...$extra,
        ]);
    }

    private function summary(?string $token = null): array
    {
        return $this->fetch('/api/v1/driver/earnings', $token ?? $this->token)->assertOk()->json('data');
    }

    public function test_a_driver_with_no_trips_has_zero_everywhere(): void
    {
        $zero = ['trips' => 0, 'fare' => 0, 'commission' => 0, 'earnings' => 0];

        $this->assertSame(['currency' => 'AMD', 'today' => $zero, 'week' => $zero, 'month' => $zero], $this->summary());
    }

    public function test_the_totals_add_up_fare_commission_and_earnings(): void
    {
        $this->trip('2026-10-20 08:00:00', 1500, 300);
        $this->trip('2026-10-20 09:00:00', 900, 180);

        $this->assertSame(['trips' => 2, 'fare' => 2400, 'commission' => 480, 'earnings' => 1920], $this->summary()['today']);
    }

    public function test_today_starts_at_the_drivers_local_midnight_and_ends_at_the_next_one(): void
    {
        $this->trip('2026-10-19 19:59:59', 1000, 200);   // 23:59:59 the day before, local
        $this->trip('2026-10-19 20:00:00', 2000, 400);   // 00:00:00 today, local
        $this->trip('2026-10-20 19:59:59', 4000, 800);   // 23:59:59 today, local
        $this->trip('2026-10-20 20:00:00', 8000, 1600);  // 00:00:00 tomorrow, local

        $summary = $this->summary();

        $this->assertSame([2, 6000, 4800], [$summary['today']['trips'], $summary['today']['fare'], $summary['today']['earnings']]);
    }

    public function test_the_week_is_the_last_seven_local_days_including_today(): void
    {
        $this->trip('2026-10-13 19:59:59'); // 23:59:59 on 13 October: eight days ago
        $this->trip('2026-10-13 20:00:00'); // 00:00:00 on 14 October: six days ago, still in
        $this->trip('2026-10-20 08:00:00');

        $this->assertSame(2, $this->summary()['week']['trips']);
    }

    public function test_the_month_is_the_last_thirty_local_days_including_today(): void
    {
        $this->trip('2026-09-20 19:59:59'); // 23:59:59 on 20 September: thirty days ago... (31 with today) out
        $this->trip('2026-09-20 20:00:00'); // 00:00:00 on 21 September: twenty-nine days ago, in
        $this->trip('2026-10-01 10:00:00');

        $summary = $this->summary();

        $this->assertSame(2, $summary['month']['trips']);
        $this->assertSame(0, $summary['week']['trips']);
    }

    public function test_only_this_drivers_completed_trips_in_their_currency_count(): void
    {
        $this->trip('2026-10-20 08:00:00', 1000, 200);
        $this->trip('2026-10-20 08:30:00', 5000, 1000, ['fare_currency' => 'USD']);
        $this->trip('2026-10-20 08:40:00', 5000, 1000, ['driver_id' => DriverProfile::factory()->approved()]);
        Trip::factory()->cancelled()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $this->profile->id, 'cancelled_at' => now()]);
        Trip::factory()->inProgress()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $this->profile->id]);

        $summary = $this->summary();

        $this->assertSame(['trips' => 1, 'fare' => 1000, 'commission' => 200, 'earnings' => 800], $summary['today']);
        $this->assertSame('AMD', $summary['currency']);
    }

    public function test_without_a_home_zone_the_base_currency_and_the_utc_day_are_used(): void
    {
        [, $profile, $token] = $this->driver();
        Trip::factory()->completed()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $profile->id, 'completed_at' => CarbonImmutable::parse('2026-10-20 00:00:00', 'UTC'), 'fare_amount' => 700, 'commission_amount' => 100, 'driver_earnings_amount' => 600]);
        Trip::factory()->completed()->create(['rider_id' => User::factory()->rider(), 'zone_id' => $this->zone->id, 'driver_id' => $profile->id, 'completed_at' => CarbonImmutable::parse('2026-10-19 23:59:59', 'UTC'), 'fare_amount' => 700, 'commission_amount' => 100, 'driver_earnings_amount' => 600]);

        $summary = $this->summary($token);

        $this->assertSame('AMD', $summary['currency']);
        $this->assertSame([1, 2], [$summary['today']['trips'], $summary['week']['trips']]);
    }

    public function test_a_completed_trip_shows_up_in_todays_earnings(): void
    {
        $this->travelBack(); // the fare rule of this test class took effect yesterday, in real time
        [$trip, $profile, $token] = $this->matched();
        $profile->forceFill(['home_zone_id' => $this->zone->id])->save();
        $this->act("/api/v1/driver/trips/{$trip->id}/arrived", $token)->assertOk();
        $this->act("/api/v1/driver/trips/{$trip->id}/start", $token)->assertOk();
        $this->travel(10)->minutes();
        $this->act("/api/v1/driver/trips/{$trip->id}/complete", $token)->assertOk();

        $this->assertSame(['trips' => 1, 'fare' => 917, 'commission' => 183, 'earnings' => 734], $this->summary($token)['today']);
        $this->assertSame(TripStatus::Completed, $trip->refresh()->status);
        $this->assertSame($profile->id, $trip->driver_id);
    }

    public function test_riders_and_guests_cannot_read_earnings(): void
    {
        $this->fetch('/api/v1/driver/earnings', $this->riderToken)->assertForbidden();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/driver/earnings')->assertUnauthorized();
    }

    public function test_a_driver_account_without_a_profile_is_refused(): void
    {
        $token = User::factory()->driver()->create()->createToken('device', ['driver'])->plainTextToken;

        $this->fetch('/api/v1/driver/earnings', $token)->assertForbidden();
    }
}
