<?php

namespace Tests\Feature\Trips;

use App\Enums\TripCancelledBy;
use App\Enums\TripStatus;
use App\Exceptions\TripStateException;
use App\Models\FareRule;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Money rule: a trip keeps the price it was made with. Fare rules are effective-dated and never edited
 * (P5-T2), and the trip holds copies of its numbers; it refuses to be rewritten once it is finished.
 */
class TripPricingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function frozenColumns(): array
    {
        return [
            'fare_amount' => ['fare_amount', 1],
            'estimated_fare_amount' => ['estimated_fare_amount', 1],
            'fare_currency' => ['fare_currency', 'USD'],
            'fare_rule_id' => ['fare_rule_id', 99],
            'commission_rule_id' => ['commission_rule_id', 99],
            'surge_multiplier' => ['surge_multiplier', '3.00'],
            'fare_breakdown' => ['fare_breakdown', ['base' => 1]],
            'commission_amount' => ['commission_amount', 1],
            'driver_earnings_amount' => ['driver_earnings_amount', 1],
        ];
    }

    #[DataProvider('frozenColumns')]
    public function test_a_completed_trips_pricing_can_not_be_edited(string $column, mixed $value): void
    {
        $trip = Trip::factory()->completed()->create(['fare_rule_id' => FareRule::factory()->create()->id, 'commission_rule_id' => 3, 'surge_multiplier' => '1.50']);
        $before = DB::table('trips')->where('id', $trip->id)->first();

        try {
            $trip->forceFill([$column => $value])->save();
            $this->fail("{$column} of a completed trip was changed.");
        } catch (TripStateException $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }

        $this->assertEquals($before, DB::table('trips')->where('id', $trip->id)->first());
    }

    public function test_the_stored_fare_and_breakdown_do_not_move_when_a_rule_changes_later(): void
    {
        $rule = FareRule::factory()->create(['base_fare' => 500]);
        $trip = Trip::factory()->completed()->create(['fare_rule_id' => $rule->id, 'commission_rule_id' => 3, 'surge_multiplier' => '1.50']);
        $breakdown = Trip::findOrFail($trip->id)->fare_breakdown;

        // A "rule edit" never reaches trips: a rule is never edited (a change adds a version, and the old
        // row keeps its prices), and the trip holds copies of its numbers. Other changes to a finished
        // trip are still fine (settlement carries on).
        $later = Trip::findOrFail($trip->id);
        $later->forceFill(['payment_status' => 'paid'])->save();

        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame(150000, $fresh->fare_amount);
        $this->assertSame(30000, $fresh->commission_amount);
        $this->assertSame(120000, $fresh->driver_earnings_amount);
        $this->assertSame('1.50', $fresh->surge_multiplier);
        $this->assertSame($breakdown, $fresh->fare_breakdown);
        $this->assertSame($rule->id, $fresh->fare_rule_id);
        $this->assertSame(500, $rule->fresh()->base_fare);
        $this->assertSame('paid', $fresh->payment_status->value);
    }

    public function test_the_snapshot_can_be_written_by_the_save_that_completes_the_trip(): void
    {
        $trip = Trip::factory()->inProgress()->create();
        $rule = FareRule::factory()->create();

        $trip->transitionTo(TripStatus::Completed, attributes: [
            'fare_amount' => 175000, 'commission_amount' => 35000, 'driver_earnings_amount' => 140000,
            'fare_rule_id' => $rule->id, 'commission_rule_id' => 2, 'surge_multiplier' => '1.25',
            'fare_breakdown' => ['base' => 50000, 'surge' => 25000],
        ]);

        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame(175000, $fresh->fare_amount);
        $this->assertSame('1.25', $fresh->surge_multiplier);
        $this->assertSame($rule->id, $fresh->fare_rule_id);
        $this->assertSame(25000, $fresh->fare_breakdown['surge']);
    }

    public function test_pricing_stays_editable_while_the_trip_is_open(): void
    {
        $trip = Trip::factory()->matched()->create();

        $trip->forceFill(['estimated_fare_amount' => 210000, 'surge_multiplier' => '2.00'])->save();

        $this->assertSame(210000, Trip::findOrFail($trip->id)->estimated_fare_amount);
    }

    public function test_a_cancelled_trip_keeps_its_cancellation_fee_snapshot(): void
    {
        $trip = Trip::factory()->arrived()->create();
        $trip->transitionTo(TripStatus::Cancelled, TripCancelledBy::Rider, 'Too slow', [
            'fare_amount' => 50000, 'fare_breakdown' => ['cancellation_fee' => 50000],
        ]);

        $fresh = Trip::findOrFail($trip->id);
        $this->assertSame(50000, $fresh->fare_amount);

        $this->expectException(TripStateException::class);
        $fresh->forceFill(['fare_amount' => 0])->save();
    }

    public function test_amounts_are_never_floats(): void
    {
        $trip = Trip::factory()->completed()->create();
        $fresh = Trip::findOrFail($trip->id);

        foreach (['estimated_fare_amount', 'fare_amount', 'commission_amount', 'driver_earnings_amount'] as $column) {
            $this->assertIsInt($fresh->{$column}, $column);
        }
    }
}
