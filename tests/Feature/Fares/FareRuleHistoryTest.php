<?php

namespace Tests\Feature\Fares;

use App\Enums\VehicleClass;
use App\Models\FareRule;
use App\Models\Trip;
use App\Models\Zone;
use App\Services\Fares\FareRuleLookup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Fare rules are history: the lookup answers "what was the price then", and the database itself
 * refuses overlapping periods, edits and deletes.
 */
class FareRuleHistoryTest extends TestCase
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
    private function rule(array $attributes = []): FareRule
    {
        return FareRule::factory()->forZone($this->zone)->create($attributes);
    }

    // ---- "what was the base fare for zone X, class Y at timestamp T" ----

    public function test_the_price_at_a_past_moment_comes_from_the_rule_that_was_in_force_then(): void
    {
        $this->rule(['base_fare' => 400, 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-03-01 00:00:00']);
        $this->rule(['base_fare' => 500, 'effective_from' => '2026-03-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);
        $this->rule(['base_fare' => 650, 'effective_from' => '2026-06-01 00:00:00']);
        $lookup = new FareRuleLookup;

        $this->assertSame(400, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 2, 15))->base_fare);
        $this->assertSame(500, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 4, 1))->base_fare);
        $this->assertSame(650, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 9, 1))->base_fare);
        $this->assertSame(650, $lookup->at($this->zone, VehicleClass::Economy)->base_fare, 'now is the open rule');
        $this->assertNull($lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2025, 12, 31)), 'before the first rule there was no price');
    }

    public function test_the_period_is_inclusive_at_the_start_and_exclusive_at_the_end(): void
    {
        $this->rule(['base_fare' => 400, 'effective_from' => '2026-03-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);
        $this->rule(['base_fare' => 500, 'effective_from' => '2026-06-01 00:00:00']);
        $lookup = new FareRuleLookup;

        $this->assertSame(400, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 3, 1)->startOfDay())->base_fare, 'starts at effective_from');
        $this->assertSame(400, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 5, 31)->setTime(23, 59, 59))->base_fare);
        $this->assertSame(500, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 6, 1)->startOfDay())->base_fare, 'the old rule ends exactly when the new one starts');
    }

    public function test_the_lookup_is_per_zone_and_per_vehicle_class(): void
    {
        $other = Zone::factory()->create();
        $this->rule(['base_fare' => 500]);
        $this->rule(['base_fare' => 900, 'vehicle_class' => 'business']);
        FareRule::factory()->forZone($other)->create(['base_fare' => 123]);
        $lookup = new FareRuleLookup;

        $this->assertSame(500, $lookup->at($this->zone, VehicleClass::Economy)->base_fare);
        $this->assertSame(900, $lookup->at($this->zone, VehicleClass::Business)->base_fare);
        $this->assertNull($lookup->at($this->zone, VehicleClass::Comfort));
        $this->assertSame(123, $lookup->at($other, VehicleClass::Economy)->base_fare);
        $this->assertSame(['business', 'economy'], $lookup->allAt($this->zone)->keys()->sort()->values()->all());
    }

    public function test_history_lists_every_version_newest_first(): void
    {
        $this->rule(['base_fare' => 400, 'effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-03-01 00:00:00']);
        $this->rule(['base_fare' => 500, 'effective_from' => '2026-03-01 00:00:00']);
        $this->rule(['base_fare' => 999, 'vehicle_class' => 'comfort']);

        $history = (new FareRuleLookup)->history($this->zone, VehicleClass::Economy);

        $this->assertSame([500, 400], $history->pluck('base_fare')->all());
    }

    // ---- the database refuses to rewrite history ----

    public function test_overlapping_periods_for_the_same_zone_and_class_are_refused(): void
    {
        $this->rule(['effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('fare_rules_no_overlap');
        $this->rule(['effective_from' => '2026-05-31 00:00:00']);
    }

    public function test_touching_periods_and_other_classes_or_zones_are_fine(): void
    {
        $this->rule(['effective_from' => '2026-01-01 00:00:00', 'effective_to' => '2026-06-01 00:00:00']);
        $this->rule(['effective_from' => '2026-06-01 00:00:00']);
        $this->rule(['effective_from' => '2026-01-01 00:00:00', 'vehicle_class' => 'comfort']);
        FareRule::factory()->forZone(Zone::factory()->create())->create(['effective_from' => '2026-01-01 00:00:00']);

        $this->assertSame(4, FareRule::query()->count());
    }

    public function test_a_price_cannot_be_updated_by_the_model_or_by_sql(): void
    {
        $rule = $this->rule(['base_fare' => 500]);

        try {
            $rule->update(['base_fare' => 600]);
            $this->fail('The model must refuse to edit a price.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('add a new version', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::update('UPDATE fare_rules SET base_fare = 600 WHERE id = ?', [$rule->id]));
            $this->fail('The database must refuse to edit a price.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never edited', $e->getMessage());
        }

        $this->assertSame(500, $rule->fresh()->base_fare);
    }

    public function test_every_term_is_protected_not_only_the_base_fare(): void
    {
        $rule = $this->rule();

        foreach (['per_km' => 1, 'min_fare' => 1, 'tax_rate_bps' => 5, 'cancellation_fee' => 1, 'booking_fee' => 1, 'tax_inclusive' => 'true', 'effective_from' => "'2020-01-01'", 'zone_id' => Zone::factory()->create()->id] as $column => $value) {
            try {
                DB::transaction(fn () => DB::update("UPDATE fare_rules SET {$column} = {$value} WHERE id = ?", [$rule->id]));
                $this->fail("{$column} must be frozen.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('never edited', $e->getMessage(), $column);
            }
        }
    }

    public function test_a_rule_can_be_closed_once_and_a_closed_rule_is_final(): void
    {
        $rule = $this->rule(['effective_from' => now()->subDay()]);

        $rule->forceFill(['effective_to' => now()])->save();
        $this->assertNotNull($rule->fresh()->effective_to);

        try {
            DB::transaction(fn () => DB::update("UPDATE fare_rules SET effective_to = now() + interval '1 day' WHERE id = ?", [$rule->id]));
            $this->fail('A closed rule must not be reopened or moved.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('closed fare rule is final', $e->getMessage());
        }
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
            DB::transaction(fn () => DB::delete('DELETE FROM fare_rules WHERE id = ?', [$rule->id]));
            $this->fail('The database must refuse to delete.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertDatabaseHas('fare_rules', ['id' => $rule->id]);
    }

    public function test_invalid_values_are_refused_by_the_database(): void
    {
        $invalid = [
            'negative base fare' => ['base_fare' => -1],
            'negative per km' => ['per_km' => -5],
            'negative minimum' => ['min_fare' => -1],
            'tax over 100%' => ['tax_rate_bps' => 10_001],
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

        $this->assertSame(0, FareRule::query()->count());
    }

    public function test_an_unknown_vehicle_class_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('fare_rules_vehicle_class_check');

        DB::insert("INSERT INTO fare_rules (zone_id, vehicle_class, base_fare, per_km, per_minute, min_fare, waiting_free_minutes, waiting_per_minute, cancellation_fee, cancellation_free_seconds, booking_fee, currency, effective_from)
            VALUES (?, 'limousine', 1, 1, 1, 1, 0, 0, 0, 0, 0, 'AMD', now())", [$this->zone->id]);
    }

    public function test_a_trip_priced_with_a_rule_keeps_it_from_being_removed_or_unlinked(): void
    {
        $rule = $this->rule();
        $trip = Trip::factory()->create(['zone_id' => $this->zone->id]);
        $trip->forceFill(['fare_rule_id' => $rule->id])->save();

        $this->assertSame($rule->id, $trip->fresh()->fare_rule_id);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::update('UPDATE trips SET fare_rule_id = 999999 WHERE id = ?', [$trip->id]));
    }
}
