<?php

namespace Tests\Feature\Drivers;

use App\Enums\AdminRole;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Zone;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The Earnings and Trip History tabs read the driver's real trips. Until the earnings ledger exists (P11-T2)
 * the earnings are the driver's share of completed trips, and the page says so.
 */
class DriverEarningsAndTripsTabTest extends AdminTestCase
{
    private DriverProfile $driver;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['currency' => 'AMD']);
        $this->driver = DriverProfile::factory()->create(['home_zone_id' => $this->zone->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function completed(int $earnings, string $when, array $attributes = []): Trip
    {
        return Trip::factory()->completed()->create([
            'driver_id' => $this->driver->id, 'zone_id' => $this->zone->id, 'driver_earnings_amount' => $earnings, 'commission_amount' => 100, 'fare_amount' => $earnings + 100,
            'completed_at' => $when, 'requested_at' => $when,
        ] + $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(?DriverProfile $driver = null): array
    {
        return $this->actingAs($this->admin(AdminRole::Finance))->get('/admin/drivers/'.($driver ?? $this->driver)->id.'?tab=earnings')->assertOk()->viewData('page')['props']['driver'];
    }

    public function test_the_summary_adds_up_completed_trips_for_the_week_the_month_and_all_time(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $this->completed(1000, '2026-10-11 10:00:00');
        $this->completed(2000, '2026-10-06 12:00:01'); // just inside 7 days
        $this->completed(4000, '2026-10-05 11:59:59'); // just outside 7 days, inside 30
        $this->completed(8000, '2026-09-12 12:00:01'); // just inside 30 days
        $this->completed(16000, '2026-09-12 11:59:59'); // outside 30 days

        $detail = $this->props()['earnings_detail'];

        $this->assertSame(3000, $detail['summary']['week']['amount']);
        $this->assertSame(15000, $detail['summary']['month']['amount']);
        $this->assertSame(31000, $detail['summary']['all_time']['amount']);
        $this->assertSame(['amount' => 31000, 'currency' => 'AMD'], $detail['balance'], 'no payouts exist yet: the balance is the total earned');
        $this->assertSame(['amount' => 31000, 'currency' => 'AMD'], $this->props()['earnings']);
        $this->assertTrue($detail['ledger_pending']);
        $this->assertFalse($detail['debt_limit_exceeded']);
    }

    public function test_only_this_drivers_completed_trips_in_their_currency_count(): void
    {
        $other = DriverProfile::factory()->create(['home_zone_id' => $this->zone->id]);
        $this->completed(1000, '2026-10-11 10:00:00');
        Trip::factory()->completed()->create(['driver_id' => $other->id, 'zone_id' => $this->zone->id, 'driver_earnings_amount' => 777]);
        Trip::factory()->completed()->create(['driver_id' => $this->driver->id, 'zone_id' => $this->zone->id, 'fare_currency' => 'USD', 'driver_earnings_amount' => 555]);
        Trip::factory()->inProgress()->create(['driver_id' => $this->driver->id, 'zone_id' => $this->zone->id]);
        Trip::factory()->cancelled()->create(['driver_id' => $this->driver->id, 'zone_id' => $this->zone->id]);

        $detail = $this->props()['earnings_detail'];

        $this->assertSame(1000, $detail['summary']['all_time']['amount']);
        $this->assertCount(1, $detail['ledger']);
    }

    public function test_the_ledger_lists_trip_earnings_newest_first_with_the_trip_code(): void
    {
        $old = $this->completed(1000, '2026-10-01 10:00:00');
        $new = $this->completed(2500, '2026-10-10 10:00:00');

        $ledger = $this->props()['earnings_detail']['ledger'];

        $this->assertSame([$new->id, $old->id], array_column($ledger, 'id'));
        $this->assertSame(['id' => $new->id, 'type' => 'trip_earning', 'reference' => $new->public_code, 'amount' => ['amount' => 2500, 'currency' => 'AMD'], 'occurred_at' => $new->completed_at->toIso8601String()], $ledger[0]);
    }

    public function test_the_ledger_is_limited_to_the_latest_fifty_entries_but_the_totals_count_everything(): void
    {
        foreach (range(1, 55) as $i) {
            $this->completed(10, now()->subMinutes($i)->toDateTimeString());
        }

        $detail = $this->props()['earnings_detail'];

        $this->assertCount(50, $detail['ledger']);
        $this->assertSame(550, $detail['summary']['all_time']['amount']);
    }

    public function test_a_driver_without_trips_has_zero_earnings_in_the_zone_currency(): void
    {
        $detail = $this->props()['earnings_detail'];

        $this->assertSame([], $detail['ledger']);
        $this->assertSame(['amount' => 0, 'currency' => 'AMD'], $detail['balance']);
    }

    public function test_a_driver_in_a_dollar_zone_earns_in_dollars(): void
    {
        $usd = Zone::factory()->create(['currency' => 'USD']);
        $driver = DriverProfile::factory()->create(['home_zone_id' => $usd->id]);
        Trip::factory()->completed()->create(['driver_id' => $driver->id, 'zone_id' => $usd->id, 'fare_currency' => 'USD', 'driver_earnings_amount' => 4250]);
        Trip::factory()->completed()->create(['driver_id' => $driver->id, 'zone_id' => $usd->id, 'fare_currency' => 'AMD', 'driver_earnings_amount' => 999]);

        $detail = $this->props($driver)['earnings_detail'];

        $this->assertSame(['amount' => 4250, 'currency' => 'USD'], $detail['summary']['all_time']);
    }

    // ---- trip history ----

    public function test_the_trip_history_lists_the_drivers_trips_newest_first_with_route_fare_and_status(): void
    {
        $older = $this->completed(1000, '2026-10-01 10:00:00', ['pickup_address' => 'Republic Square', 'dropoff_address' => 'Cascade']);
        $newer = Trip::factory()->cancelled()->create(['driver_id' => $this->driver->id, 'zone_id' => $this->zone->id, 'requested_at' => '2026-10-10 10:00:00', 'estimated_fare_amount' => 3300]);
        Trip::factory()->completed()->create(['zone_id' => $this->zone->id, 'driver_id' => DriverProfile::factory()->create()->id]);

        $history = $this->props()['trip_history'];

        $this->assertSame([$newer->id, $older->id], array_column($history, 'id'));
        $this->assertSame($newer->public_code, $history[0]['code']);
        $this->assertSame(['amount' => 3300, 'currency' => 'AMD'], $history[0]['fare'], 'an unfinished trip shows its estimate');
        $this->assertSame('cancelled', $history[0]['status']);
        $this->assertSame('Republic Square → Cascade', $history[1]['route']);
        $this->assertSame(['amount' => 1100, 'currency' => 'AMD'], $history[1]['fare'], 'a finished trip shows its final fare');
        $this->assertNull($history[1]['rating']);
        $this->assertSame($older->requested_at->toIso8601String(), $history[1]['occurred_at']);
    }

    public function test_the_trip_history_is_limited_to_the_latest_fifty(): void
    {
        Trip::factory()->count(55)->create(['driver_id' => $this->driver->id, 'zone_id' => $this->zone->id]);

        $this->assertCount(50, $this->props()['trip_history']);
    }

    public function test_the_completed_trip_count_on_the_profile_is_real(): void
    {
        $this->completed(1000, '2026-10-01 10:00:00');
        $this->completed(1000, '2026-10-02 10:00:00');
        Trip::factory()->cancelled()->create(['driver_id' => $this->driver->id, 'zone_id' => $this->zone->id]);

        $this->assertSame(2, $this->props()['trips_count']);
    }

    public function test_every_role_that_may_view_drivers_sees_the_tabs_and_the_profile_shows_licence_and_zone(): void
    {
        $driver = DriverProfile::factory()->for(User::factory()->driver()->create(), 'user')->create(['license_number' => 'AM-DL 4242', 'license_expiry' => '2028-05-01', 'home_zone_id' => $this->zone->id]);

        foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->actingAs($this->admin($role))->get('/admin/drivers/'.$driver->id)->assertInertia(fn ($page) => $page
                ->where('driver.license_last4', '4242')->where('driver.home_zone', $this->zone->name)->where('driver.raw.home_zone_id', $this->zone->id)->where('driver.raw.license_expiry', '2028-05-01'));
        }
    }
}
