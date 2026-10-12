<?php

namespace Tests\Feature\Commission;

use App\Enums\VehicleClass;
use App\Models\CommissionRule;
use App\Models\Trip;
use App\Models\Zone;
use App\Services\Commission\CommissionRuleLookup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Commission rules are history: the lookup answers "which rule applied then", and the database
 * itself refuses overlapping periods, edits and deletes.
 */
class CommissionRuleHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(array $attributes = []): CommissionRule
    {
        return CommissionRule::factory()->forZone($this->zone)->create($attributes);
    }

    // ---- which rule applied at timestamp T ----

    public function test_the_rule_at_a_past_moment_is_the_one_that_was_in_force_then(): void
    {
        $this->rule(['rate_bps' => 1500, 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-03-01 00:00:00']);
        $this->rule(['rate_bps' => 1800, 'effective_from' => '2026-03-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);
        $this->rule(['rate_bps' => 2000, 'effective_from' => '2026-06-01 00:00:00']);
        $lookup = new CommissionRuleLookup;

        $this->assertSame(1500, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 2, 15))->rate_bps);
        $this->assertSame(1800, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 4, 1))->rate_bps);
        $this->assertSame(2000, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 9, 1))->rate_bps);
        $this->assertSame(2000, $lookup->at($this->zone, VehicleClass::Economy)->rate_bps, 'now is the open rule');
        $this->assertNull($lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2025, 12, 31)), 'before the first rule there was none');
    }

    public function test_the_period_is_inclusive_at_the_start_and_exclusive_at_the_end(): void
    {
        $this->rule(['rate_bps' => 1500, 'effective_from' => '2026-03-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);
        $this->rule(['rate_bps' => 1800, 'effective_from' => '2026-06-01 00:00:00']);
        $lookup = new CommissionRuleLookup;

        $this->assertSame(1500, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 3, 1)->startOfDay())->rate_bps);
        $this->assertSame(1500, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 5, 31)->setTime(23, 59, 59))->rate_bps);
        $this->assertSame(1800, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 6, 1)->startOfDay())->rate_bps);
    }

    public function test_a_rule_for_the_vehicle_class_beats_the_zones_every_class_rule(): void
    {
        $this->rule(['rate_bps' => 2000]);
        $this->rule(['rate_bps' => 1200, 'vehicle_class' => 'comfort']);
        $lookup = new CommissionRuleLookup;

        $this->assertSame(1200, $lookup->at($this->zone, VehicleClass::Comfort)->rate_bps);
        $this->assertSame(2000, $lookup->at($this->zone, VehicleClass::Economy)->rate_bps, 'every other class falls back to the zone-wide rule');
        $this->assertSame(2000, $lookup->at($this->zone, VehicleClass::Business)->rate_bps);
    }

    public function test_a_class_rule_that_has_ended_does_not_hide_the_zone_wide_rule(): void
    {
        $this->rule(['rate_bps' => 2000]);
        $this->rule(['rate_bps' => 1200, 'vehicle_class' => 'comfort', 'effective_from' => now()->subDays(10), 'effective_to' => now()->subDay()]);

        $this->assertSame(2000, (new CommissionRuleLookup)->at($this->zone, VehicleClass::Comfort)->rate_bps);
    }

    public function test_the_lookup_is_per_zone(): void
    {
        $other = Zone::factory()->create();
        $this->rule(['rate_bps' => 2000]);
        CommissionRule::factory()->forZone($other)->create(['rate_bps' => 900]);
        $lookup = new CommissionRuleLookup;

        $this->assertSame(900, $lookup->at($other, VehicleClass::Economy)->rate_bps);
        $this->assertNull($lookup->at(Zone::factory()->create(), VehicleClass::Economy));
    }

    public function test_history_lists_every_version_of_one_rule_newest_first(): void
    {
        $this->rule(['rate_bps' => 1500, 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-03-01 00:00:00']);
        $this->rule(['rate_bps' => 1800, 'effective_from' => '2026-03-01 00:00:00']);
        $this->rule(['rate_bps' => 999, 'vehicle_class' => 'comfort']);

        $lookup = new CommissionRuleLookup;

        $this->assertSame([1800, 1500], $lookup->history($this->zone, null)->pluck('rate_bps')->all(), 'only the zone-wide rule');
        $this->assertSame([999], $lookup->history($this->zone, VehicleClass::Comfort)->pluck('rate_bps')->all());
    }

    // ---- the database refuses to rewrite history ----

    public function test_overlapping_periods_for_the_same_zone_and_class_are_refused(): void
    {
        $this->rule(['vehicle_class' => 'economy', 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('commission_rules_no_overlap');
        $this->rule(['vehicle_class' => 'economy', 'effective_from' => '2026-05-31 00:00:00']);
    }

    public function test_two_zone_wide_rules_cannot_overlap_either(): void
    {
        $this->rule(['effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('commission_rules_no_overlap');
        $this->rule(['effective_from' => '2026-05-31 00:00:00']);
    }

    public function test_a_class_rule_may_overlap_the_zone_wide_rule_and_touching_periods_are_fine(): void
    {
        $this->rule(['effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);
        $this->rule(['effective_from' => '2026-06-01 00:00:00']);
        $this->rule(['vehicle_class' => 'economy', 'effective_from' => '2026-01-01 00:00:00']);
        CommissionRule::factory()->forZone(Zone::factory()->create())->create(['effective_from' => '2026-01-01 00:00:00']);

        $this->assertSame(4, CommissionRule::query()->count());
    }

    public function test_a_term_cannot_be_updated_by_the_model_or_by_sql(): void
    {
        $rule = $this->rule(['rate_bps' => 1800]);

        try {
            $rule->update(['rate_bps' => 1900]);
            $this->fail('The model must refuse to edit a rule.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('add a new version', $e->getMessage());
        }

        foreach (['rate_bps = 1', 'fixed_amount = 5', 'min_commission = 1', 'max_commission = 99999', "type = 'fixed'", 'applies_to_surge = false', "vehicle_class = 'economy'", "effective_from = '2020-01-01'", 'zone_id = '.Zone::factory()->create()->id] as $set) {
            try {
                DB::transaction(fn () => DB::update("UPDATE commission_rules SET {$set} WHERE id = ?", [$rule->id]));
                $this->fail("{$set} must be refused.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('never edited', $e->getMessage(), $set);
            }
        }

        $this->assertSame(1800, $rule->fresh()->rate_bps);
    }

    public function test_a_rule_can_be_closed_once_and_a_closed_rule_is_final(): void
    {
        $rule = $this->rule(['effective_from' => now()->subDay()]);

        $rule->forceFill(['effective_to' => now()])->save();
        $this->assertNotNull($rule->fresh()->effective_to);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('closed commission rule is final');
        DB::transaction(fn () => DB::update("UPDATE commission_rules SET effective_to = now() + interval '1 day' WHERE id = ?", [$rule->id]));
    }

    public function test_a_rule_cannot_be_deleted(): void
    {
        $rule = $this->rule();

        try {
            $rule->delete();
            $this->fail('The model must refuse to delete.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::transaction(fn () => DB::delete('DELETE FROM commission_rules WHERE id = ?', [$rule->id]));
            $this->fail('The database must refuse to delete.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertDatabaseHas('commission_rules', ['id' => $rule->id]);
    }

    public function test_invalid_rules_are_refused_by_the_database(): void
    {
        $invalid = [
            'rate over 100%' => ['rate_bps' => 10_001],
            'negative fixed amount' => ['type' => 'fixed', 'rate_bps' => 0, 'fixed_amount' => -1],
            'negative minimum' => ['min_commission' => -1],
            'maximum below minimum' => ['min_commission' => 500, 'max_commission' => 499],
            'a percentage rule with a fixed amount' => ['type' => 'percentage', 'fixed_amount' => 300],
            'a fixed rule with a rate' => ['type' => 'fixed', 'rate_bps' => 1000, 'fixed_amount' => 300],
            'period ends before it starts' => ['effective_from' => '2026-06-01 00:00:00', 'effective_to' => '2026-05-01 00:00:00'],
            'period of zero length' => ['effective_from' => '2026-06-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00'],
        ];

        foreach ($invalid as $case => $attributes) {
            try {
                DB::transaction(fn () => $this->rule($attributes));
                $this->fail("{$case} must be refused.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, CommissionRule::query()->count());
    }

    public function test_an_unknown_type_or_vehicle_class_is_refused_by_the_database(): void
    {
        foreach (["'barter', NULL", "'percentage', 'limousine'"] as $values) {
            try {
                DB::transaction(fn () => DB::insert("INSERT INTO commission_rules (zone_id, type, vehicle_class, rate_bps, fixed_amount, currency, min_commission, applies_to_surge, effective_from) VALUES (?, {$values}, 1000, 0, 'AMD', 0, true, now())", [$this->zone->id]));
                $this->fail("{$values} must be refused.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_trip_priced_with_a_rule_keeps_it_from_being_unlinked_to_nothing(): void
    {
        $rule = $this->rule();
        $trip = Trip::factory()->create(['zone_id' => $this->zone->id]);
        $trip->forceFill(['commission_rule_id' => $rule->id])->save();

        $this->assertSame($rule->id, $trip->fresh()->commission_rule_id);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::update('UPDATE trips SET commission_rule_id = 999999 WHERE id = ?', [$trip->id]));
    }
}
