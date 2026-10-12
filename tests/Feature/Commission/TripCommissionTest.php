<?php

namespace Tests\Feature\Commission;

use App\Data\FareBreakdown;
use App\Enums\AdminRole;
use App\Enums\TripStatus;
use App\Enums\VehicleClass;
use App\Exceptions\TripStateException;
use App\Models\CommissionRule;
use App\Models\FareRule;
use App\Models\Trip;
use App\Models\User;
use App\Models\Zone;
use App\Services\Commission\CommissionRuleService;
use App\Services\Commission\TripCommission;
use App\Services\Fares\FareCalculator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Criterion: a rule change never alters an already-completed trip's commission. The trip stores the
 * rule id, the commission and the driver's share when it is completed, and its pricing is then frozen.
 */
class TripCommissionTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create();
    }

    /**
     * 5 km, 12 minutes on an AMD rule: 500 + 750 + 240 = 1,490 (see FareCalculatorTest).
     */
    private function fare(int $surge = 100): FareBreakdown
    {
        $rule = FareRule::factory()->forZone($this->zone)->make(['base_fare' => 500, 'per_km' => 150, 'per_minute' => 20, 'min_fare' => 0]);

        return (new FareCalculator)->calculate($rule, 5000, 720, 0, $surge);
    }

    private function completeTrip(FareBreakdown $fare): Trip
    {
        $trip = Trip::factory()->inProgress()->create(['zone_id' => $this->zone->id]);
        $snapshot = app(TripCommission::class)->snapshot($this->zone, VehicleClass::Economy, $fare);

        return $trip->transitionTo(TripStatus::Completed, attributes: [
            ...$snapshot, 'fare_amount' => $fare->total, 'fare_breakdown' => $fare->toArray(), 'surge_multiplier' => $fare->surgeMultiplier,
        ]);
    }

    public function test_the_trip_stores_the_rule_the_commission_and_the_drivers_share(): void
    {
        $rule = CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 2000, 'min_commission' => 0]);

        $trip = $this->completeTrip($this->fare());

        $fresh = Trip::findOrFail($trip->id);
        // 20 % of 1,490 = 298; the driver keeps 1,192
        $this->assertSame($rule->id, $fresh->commission_rule_id);
        $this->assertSame(298, $fresh->commission_amount);
        $this->assertSame(1192, $fresh->driver_earnings_amount);
        $this->assertSame(1490, $fresh->fare_amount);
        $this->assertSame($fresh->fare_amount, $fresh->commission_amount + $fresh->driver_earnings_amount);
    }

    public function test_a_rule_change_after_completion_does_not_alter_the_trips_commission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin(AdminRole::Admin)->create();
        $old = CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 2000]);
        $trip = $this->completeTrip($this->fare());
        $before = DB::table('trips')->where('id', $trip->id)->first();

        $this->travel(2)->minutes();
        $new = app(CommissionRuleService::class)->saveVersion($admin, $this->zone, null, [
            'type' => 'percentage', 'rate_bps' => 5000, 'fixed_amount' => 0, 'min_commission' => 0, 'max_commission' => null, 'applies_to_surge' => true,
        ])['rule'];

        $this->assertNotSame($old->id, $new->id);
        $this->assertEquals($before, DB::table('trips')->where('id', $trip->id)->first(), 'the completed trip row is byte for byte the same');
        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame($old->id, $fresh->commission_rule_id, 'still points at the rule it was charged with');
        $this->assertSame(298, $fresh->commission_amount);
        $this->assertSame(1192, $fresh->driver_earnings_amount);
        $this->assertSame(2000, $old->fresh()->rate_bps, 'and that rule still says 20 %');
    }

    public function test_the_next_trip_after_the_change_uses_the_new_rule(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin(AdminRole::Admin)->create();
        CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 2000]);
        $first = $this->completeTrip($this->fare());

        $this->travel(2)->minutes();
        $new = app(CommissionRuleService::class)->saveVersion($admin, $this->zone, null, ['type' => 'percentage', 'rate_bps' => 5000, 'fixed_amount' => 0, 'min_commission' => 0, 'max_commission' => null, 'applies_to_surge' => true])['rule'];
        $second = $this->completeTrip($this->fare());

        // 50 % of 1,490 = 745
        $this->assertSame([298, 745], [Trip::find($first->id)->commission_amount, Trip::find($second->id)->commission_amount]);
        $this->assertSame($new->id, Trip::find($second->id)->commission_rule_id);
    }

    public function test_a_completed_trips_commission_cannot_be_edited(): void
    {
        CommissionRule::factory()->forZone($this->zone)->create();
        $trip = $this->completeTrip($this->fare());

        foreach (['commission_amount' => 1, 'driver_earnings_amount' => 1, 'commission_rule_id' => 999] as $column => $value) {
            try {
                $trip->forceFill([$column => $value])->save();
                $this->fail("{$column} of a completed trip was changed.");
            } catch (TripStateException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }
        }
    }

    public function test_the_rule_a_trip_was_charged_with_cannot_be_deleted_or_rewritten(): void
    {
        $rule = CommissionRule::factory()->forZone($this->zone)->create();
        $this->completeTrip($this->fare());

        try {
            DB::transaction(fn () => DB::delete('DELETE FROM commission_rules WHERE id = ?', [$rule->id]));
            $this->fail('A rule a trip points at must stay.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::update('UPDATE commission_rules SET rate_bps = 1 WHERE id = ?', [$rule->id]));
    }

    public function test_the_rule_in_force_at_completion_is_used_not_the_one_at_the_time_of_booking(): void
    {
        CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 1000, 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);
        $newer = CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 3000, 'effective_from' => '2026-06-01 00:00:00']);
        $snapshots = app(TripCommission::class);

        $past = $snapshots->snapshot($this->zone, VehicleClass::Economy, $this->fare(), now()->setDate(2026, 3, 1));
        $now = $snapshots->snapshot($this->zone, VehicleClass::Economy, $this->fare());

        $this->assertSame(149, $past['commission_amount'], '10 % of 1,490');
        $this->assertSame(447, $now['commission_amount'], '30 % of 1,490');
        $this->assertSame($newer->id, $now['commission_rule_id']);
    }

    public function test_a_class_rule_is_used_for_its_class_and_the_zone_wide_rule_for_the_rest(): void
    {
        CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 2000]);
        CommissionRule::factory()->forZone($this->zone)->vehicleClass(VehicleClass::Economy)->create(['type' => 'fixed', 'rate_bps' => 0, 'fixed_amount' => 100]);
        $snapshots = app(TripCommission::class);

        $this->assertSame(100, $snapshots->snapshot($this->zone, VehicleClass::Economy, $this->fare())['commission_amount']);
        $this->assertSame(298, $snapshots->snapshot($this->zone, VehicleClass::Comfort, $this->fare())['commission_amount']);
    }

    public function test_surge_and_tax_are_handled_in_the_stored_split(): void
    {
        CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 2000, 'applies_to_surge' => false]);
        // 1.50x surge on 1,490: surge 745, total 2,235 (no tax); base = 2,235 - 745 = 1,490; 20 % = 298
        $fare = $this->fare(150);

        $trip = $this->completeTrip($fare);

        $this->assertSame(2235, $fare->total);
        $this->assertSame(298, Trip::find($trip->id)->commission_amount);
        $this->assertSame(1937, Trip::find($trip->id)->driver_earnings_amount, 'the driver keeps all of the surge');
        $this->assertSame('1.50', Trip::find($trip->id)->surge_multiplier);
    }

    public function test_a_zone_without_a_rule_takes_no_commission_and_records_no_rule(): void
    {
        $trip = $this->completeTrip($this->fare());

        $fresh = Trip::findOrFail($trip->id);
        $this->assertNull($fresh->commission_rule_id);
        $this->assertSame(0, $fresh->commission_amount);
        $this->assertSame(1490, $fresh->driver_earnings_amount);
    }

    public function test_the_snapshot_matches_the_trips_consistency_rule(): void
    {
        CommissionRule::factory()->forZone($this->zone)->create(['rate_bps' => 10000]);

        $trip = $this->completeTrip($this->fare());

        $this->assertSame(1490, Trip::find($trip->id)->commission_amount, 'a 100 % rule takes everything');
        $this->assertSame(0, Trip::find($trip->id)->driver_earnings_amount);
    }
}
