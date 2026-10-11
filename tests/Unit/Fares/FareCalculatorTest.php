<?php

namespace Tests\Unit\Fares;

use App\Data\FareBreakdown;
use App\Enums\TripCancelledBy;
use App\Models\FareRule;
use App\Services\Fares\FareCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Every number below is worked out by hand in the comment next to it, not by calling the calculator.
 */
class FareCalculatorTest extends TestCase
{
    private FareCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new FareCalculator;
        config(['taxikosmos.fares.surge_cap_hundredths' => 300]);
    }

    /**
     * An AMD rule (no minor decimals): 500 base, 150/km, 20/min, minimum 800, 3 free waiting minutes then 30/min.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function rule(array $overrides = []): FareRule
    {
        return new FareRule([
            'base_fare' => 500, 'per_km' => 150, 'per_minute' => 20, 'min_fare' => 800,
            'waiting_free_minutes' => 3, 'waiting_per_minute' => 30,
            'cancellation_fee' => 500, 'cancellation_free_seconds' => 120,
            'booking_fee' => 0, 'tax_rate_bps' => 0, 'tax_inclusive' => false, 'currency' => 'AMD',
            ...$overrides,
        ]);
    }

    // ---- base, distance, time ----

    public function test_a_trip_is_base_plus_distance_plus_time(): void
    {
        // 5 km x 150 = 750; 12 min x 20 = 240; base 500 -> 1490
        $fare = $this->calculator->calculate($this->rule(), 5_000, 720);

        $this->assertSame(500, $fare->base);
        $this->assertSame(750, $fare->distance);
        $this->assertSame(240, $fare->time);
        $this->assertSame(0, $fare->waiting);
        $this->assertSame(0, $fare->surge);
        $this->assertSame(0, $fare->minimumAdjustment);
        $this->assertSame(1490, $fare->subtotal);
        $this->assertSame(1490, $fare->total);
        $this->assertSame('AMD', $fare->currency);
    }

    public function test_a_component_is_rounded_half_up_to_a_whole_minor_unit(): void
    {
        // 1.25 km x 150 = 187.5 -> 188 (half rounds up); 1.234 km x 150 = 185.1 -> 185
        $this->assertSame(188, $this->calculator->calculate($this->rule(), 1_250, 0)->distance);
        $this->assertSame(185, $this->calculator->calculate($this->rule(), 1_234, 0)->distance);
        // 90 s x 20/min = 30; 91 s -> 30.33 -> 30; 93 s -> 31
        $this->assertSame(30, $this->calculator->calculate($this->rule(), 0, 91)->time);
        $this->assertSame(31, $this->calculator->calculate($this->rule(), 0, 93)->time);
    }

    public function test_rounding_follows_the_currency_decimals_because_amounts_are_minor_units(): void
    {
        // USD in cents: 2.5 km x 133 cents = 332.5 -> 333 cents ($3.33); base 250 -> 583
        $usd = $this->rule(['currency' => 'USD', 'base_fare' => 250, 'per_km' => 133, 'per_minute' => 0, 'min_fare' => 0]);
        $fare = $this->calculator->calculate($usd, 2_500, 0);

        $this->assertSame(333, $fare->distance);
        $this->assertSame(583, $fare->total);
        $this->assertSame('USD', $fare->currency);
    }

    public function test_a_trip_with_no_distance_or_time_costs_the_base_fare_or_the_minimum(): void
    {
        // base 500 < minimum 800 -> lifted by 300
        $fare = $this->calculator->calculate($this->rule(), 0, 0);

        $this->assertSame(300, $fare->minimumAdjustment);
        $this->assertSame(800, $fare->total);
    }

    // ---- minimum fare ----

    public function test_the_minimum_fare_lifts_a_short_trip_and_shows_the_difference(): void
    {
        // 500 + 1 km 150 + 2 min 40 = 690 -> minimum 800 adds 110
        $fare = $this->calculator->calculate($this->rule(), 1_000, 120);

        $this->assertSame(110, $fare->minimumAdjustment);
        $this->assertSame(800, $fare->subtotal);
        $this->assertSame(800, $fare->total);
    }

    public function test_the_minimum_fare_does_not_apply_at_or_above_the_minimum(): void
    {
        // 500 + 2 km 300 = 800 exactly (boundary) and 801
        $this->assertSame(0, $this->calculator->calculate($this->rule(), 2_000, 0)->minimumAdjustment);
        $this->assertSame(800, $this->calculator->calculate($this->rule(), 2_000, 0)->total);
        $this->assertSame(0, $this->calculator->calculate($this->rule(), 2_000, 2)->minimumAdjustment);
        $this->assertSame(801, $this->calculator->calculate($this->rule(), 2_000, 3)->total);
    }

    public function test_the_minimum_fare_is_measured_after_surge_and_before_fees(): void
    {
        // metered 500 + 1 km 150 = 650; surge 1.20 -> +130 = 780; minimum 800 adds 20; booking fee 100 on top
        $fare = $this->calculator->calculate($this->rule(['booking_fee' => 100]), 1_000, 0, 0, 120);

        $this->assertSame(130, $fare->surge);
        $this->assertSame(20, $fare->minimumAdjustment);
        $this->assertSame(900, $fare->total);
    }

    // ---- surge ----

    public function test_surge_multiplies_the_metered_fare_only(): void
    {
        // metered 500 + 10 km 1500 + 20 min 400 = 2400; 1.50x -> +1200; booking fee 100 and an airport fee 1000 are not surged
        $fee = (object) ['name' => 'Airport pickup', 'amount' => 1000];
        $fare = $this->calculator->calculate($this->rule(['booking_fee' => 100]), 10_000, 1_200, 0, 150, [$fee]);

        $this->assertSame('1.50', $fare->surgeMultiplier);
        $this->assertSame(1200, $fare->surge);
        $this->assertSame(2400 + 1200 + 100 + 1000, $fare->total);
    }

    public function test_surge_is_rounded_half_up(): void
    {
        // metered 500 + 1 km 150 = 650 x 0.25 = 162.5 -> 163
        $this->assertSame(163, $this->calculator->calculate($this->rule(), 1_000, 0, 0, 125)->surge);
    }

    public function test_surge_is_capped_by_config_and_never_below_one(): void
    {
        // cap 3.00: asking for 5.00 prices at 3.00; metered 650 -> +1300
        $capped = $this->calculator->calculate($this->rule(), 1_000, 0, 0, 500);
        $this->assertSame('3.00', $capped->surgeMultiplier);
        $this->assertSame(1300, $capped->surge);

        config(['taxikosmos.fares.surge_cap_hundredths' => 200]);
        $this->assertSame('2.00', $this->calculator->calculate($this->rule(), 1_000, 0, 0, 500)->surgeMultiplier);

        // 0.50 would be a discount: not allowed, priced at 1.00
        $low = $this->calculator->calculate($this->rule(), 1_000, 0, 0, 50);
        $this->assertSame('1.00', $low->surgeMultiplier);
        $this->assertSame(0, $low->surge);
    }

    // ---- waiting ----

    public function test_waiting_is_free_for_the_rules_free_minutes(): void
    {
        $this->assertSame(0, $this->calculator->calculate($this->rule(), 0, 0, 0)->waiting);
        $this->assertSame(0, $this->calculator->calculate($this->rule(), 0, 0, 180)->waiting); // exactly 3 min
    }

    public function test_waiting_beyond_the_free_minutes_is_charged_per_minute(): void
    {
        // 5 min waited, 3 free -> 2 min x 30 = 60
        $this->assertSame(60, $this->calculator->calculate($this->rule(), 0, 0, 300)->waiting);
        // 3 min 30 s waited -> 30 s billable x 30/min = 15
        $this->assertSame(15, $this->calculator->calculate($this->rule(), 0, 0, 210)->waiting);
        // 20 min waited -> 17 x 30 = 510, and it counts toward the metered fare: 500 + 510 = 1010
        $fare = $this->calculator->calculate($this->rule(), 0, 0, 1_200);
        $this->assertSame(510, $fare->waiting);
        $this->assertSame(1010, $fare->total);
    }

    public function test_a_rule_with_no_free_waiting_charges_from_the_first_second(): void
    {
        $this->assertSame(30, $this->calculator->calculate($this->rule(['waiting_free_minutes' => 0]), 0, 0, 60)->waiting);
    }

    // ---- place fees ----

    public function test_airport_and_toll_fees_are_listed_and_added(): void
    {
        $fees = [(object) ['name' => 'Airport pickup', 'amount' => 1000], (object) ['name' => 'Toll', 'amount' => 250]];
        // 500 + 5 km 750 = 1250 + 1250 fees = 2500
        $fare = $this->calculator->calculate($this->rule(), 5_000, 0, 0, 100, $fees);

        $this->assertSame([['name' => 'Airport pickup', 'amount' => 1000], ['name' => 'Toll', 'amount' => 250]], $fare->fees);
        $this->assertSame(2500, $fare->total);
    }

    public function test_fees_count_toward_the_total_even_when_the_minimum_fare_applies(): void
    {
        // metered 500 -> lifted to 800 (+300); fee 1000 on top -> 1800
        $fare = $this->calculator->calculate($this->rule(), 0, 0, 0, 100, [(object) ['name' => 'Airport', 'amount' => 1000]]);

        $this->assertSame(300, $fare->minimumAdjustment);
        $this->assertSame(1800, $fare->total);
    }

    // ---- tax ----

    public function test_exclusive_tax_is_added_on_top_of_the_subtotal(): void
    {
        // subtotal 1490 (5 km, 12 min); 18% = 268.2 -> 268; total 1758
        $fare = $this->calculator->calculate($this->rule(['tax_rate_bps' => 1800]), 5_000, 720);

        $this->assertSame(1490, $fare->subtotal);
        $this->assertSame(268, $fare->tax);
        $this->assertSame(1758, $fare->total);
        $this->assertFalse($fare->taxInclusive);
        $this->assertSame(1800, $fare->taxRateBps);
    }

    public function test_inclusive_tax_is_the_part_of_the_subtotal_that_is_tax(): void
    {
        // the 1490 already contains 18%: 1490 x 1800 / 11800 = 227.29 -> 227; the total stays 1490
        $fare = $this->calculator->calculate($this->rule(['tax_rate_bps' => 1800, 'tax_inclusive' => true]), 5_000, 720);

        $this->assertSame(1490, $fare->subtotal);
        $this->assertSame(227, $fare->tax);
        $this->assertSame(1490, $fare->total);
        $this->assertTrue($fare->taxInclusive);
    }

    public function test_no_tax_when_the_rate_is_zero_either_way(): void
    {
        foreach ([false, true] as $inclusive) {
            $fare = $this->calculator->calculate($this->rule(['tax_inclusive' => $inclusive]), 5_000, 720);
            $this->assertSame(0, $fare->tax);
            $this->assertSame(1490, $fare->total);
        }
    }

    public function test_tax_covers_fees_and_surge_and_is_rounded_half_up(): void
    {
        // metered 650 x 1.40 -> +260 = 910; fee 90 -> subtotal 1000; 5% = 50 exactly; 5.5% of 1000 = 55
        $fee = (object) ['name' => 'Toll', 'amount' => 90];
        $this->assertSame(1050, $this->calculator->calculate($this->rule(['tax_rate_bps' => 500]), 1_000, 0, 0, 140, [$fee])->total);
        // 1000 x 0.0555 = 55.5 -> 56 (half up)
        $this->assertSame(56, $this->calculator->calculate($this->rule(['tax_rate_bps' => 555]), 1_000, 0, 0, 140, [$fee])->tax);
    }

    // ---- the parts always add up ----

    public function test_the_components_always_add_up_to_the_subtotal_and_total(): void
    {
        $fees = [(object) ['name' => 'Toll', 'amount' => 130]];

        foreach ([0, 1, 777, 1_250, 9_999, 42_000] as $meters) {
            foreach ([0, 59, 600, 4_321] as $seconds) {
                foreach ([0, 61, 500] as $waiting) {
                    foreach ([100, 133, 275, 999] as $surge) {
                        foreach ([[0, false], [1800, false], [1800, true], [555, true]] as [$bps, $inclusive]) {
                            $fare = $this->calculator->calculate($this->rule(['booking_fee' => 90, 'tax_rate_bps' => $bps, 'tax_inclusive' => $inclusive]), $meters, $seconds, $waiting, $surge, $fees);

                            $parts = $fare->base + $fare->distance + $fare->time + $fare->waiting + $fare->surge + $fare->minimumAdjustment + $fare->bookingFee + 130;
                            $this->assertSame($parts, $fare->subtotal, "subtotal for {$meters} m {$seconds} s");
                            $this->assertSame($inclusive ? $fare->subtotal : $fare->subtotal + $fare->tax, $fare->total);
                            $this->assertGreaterThanOrEqual(800 + 90 + 130, $fare->subtotal, 'never below minimum + fees');
                        }
                    }
                }
            }
        }
    }

    public function test_negative_inputs_are_refused(): void
    {
        foreach ([[-1, 0, 0], [0, -1, 0], [0, 0, -1]] as [$meters, $seconds, $waiting]) {
            try {
                $this->calculator->calculate($this->rule(), $meters, $seconds, $waiting);
                $this->fail('A negative input must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_large_trip_does_not_overflow(): void
    {
        // 2000 km at 150/km = 300,000; 40 h at 20/min = 48,000; base 500
        $fare = $this->calculator->calculate($this->rule(), 2_000_000, 144_000);

        $this->assertSame(348_500, $fare->total);
    }

    // ---- cancellation ----

    public function test_a_rider_cancelling_after_the_free_window_pays_the_cancellation_fee(): void
    {
        $matched = CarbonImmutable::parse('2026-10-12 10:00:00');
        $rule = $this->rule();

        $this->assertSame(0, $this->calculator->cancellationFee($rule, TripCancelledBy::Rider, $matched, $matched->addSeconds(119)));
        $this->assertSame(0, $this->calculator->cancellationFee($rule, TripCancelledBy::Rider, $matched, $matched->addSeconds(120)), 'exactly at the limit is still free');
        $this->assertSame(500, $this->calculator->cancellationFee($rule, TripCancelledBy::Rider, $matched, $matched->addSeconds(121)));
        $this->assertSame(500, $this->calculator->cancellationFee($rule, TripCancelledBy::Rider, $matched, $matched->addHour()));
    }

    public function test_nobody_but_a_rider_with_a_matched_driver_pays_a_cancellation_fee(): void
    {
        $matched = CarbonImmutable::parse('2026-10-12 10:00:00');
        $late = $matched->addHour();

        foreach ([TripCancelledBy::Driver, TripCancelledBy::Admin, TripCancelledBy::System] as $by) {
            $this->assertSame(0, $this->calculator->cancellationFee($this->rule(), $by, $matched, $late), $by->value.' cancellations never charge the rider');
        }

        $this->assertSame(0, $this->calculator->cancellationFee($this->rule(), TripCancelledBy::Rider, null, $late), 'no driver was matched yet');
    }

    public function test_a_driver_no_show_charges_the_fee_after_the_free_waiting_minutes(): void
    {
        $arrived = CarbonImmutable::parse('2026-10-12 10:00:00');

        $this->assertSame(0, $this->calculator->noShowFee($this->rule(), $arrived, $arrived->addSeconds(179)));
        $this->assertSame(500, $this->calculator->noShowFee($this->rule(), $arrived, $arrived->addSeconds(180)), 'after the full 3 free minutes the driver may mark a no-show');
        $this->assertSame(500, $this->calculator->noShowFee($this->rule(), $arrived, $arrived->addMinutes(10)));
        $this->assertSame(0, $this->calculator->noShowFee($this->rule(['cancellation_fee' => 0]), $arrived, $arrived->addMinutes(10)));
    }

    public function test_the_cancellation_breakdown_holds_only_the_fee(): void
    {
        $fare = $this->calculator->cancellationBreakdown($this->rule(), 500);

        $this->assertSame(500, $fare->cancellationFee);
        $this->assertSame(500, $fare->total);
        $this->assertSame(500, $fare->subtotal);
        $this->assertSame(0, $fare->distance + $fare->time + $fare->base + $fare->tax);
    }

    // ---- storage shape ----

    public function test_the_breakdown_round_trips_through_the_trips_json_column(): void
    {
        $fee = (object) ['name' => 'Toll', 'amount' => 250];
        $fare = $this->calculator->calculate($this->rule(['tax_rate_bps' => 1800]), 5_000, 720, 300, 150, [$fee]);

        $stored = json_decode(json_encode($fare->toArray()), true);

        $this->assertEquals($fare, FareBreakdown::fromArray($stored));
        $this->assertSame('1.50', $stored['surge_multiplier'], 'the multiplier is a string, never a float');
        $this->assertSame(['currency', 'base', 'distance', 'time', 'waiting', 'minimum_adjustment', 'booking_fee', 'fees', 'surge_multiplier', 'surge', 'subtotal', 'tax_rate_bps', 'tax_inclusive', 'tax', 'total', 'cancellation_fee'], array_keys($stored));
    }

    public function test_a_stored_breakdown_missing_a_part_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FareBreakdown::fromArray(['currency' => 'AMD', 'total' => 1]);
    }
}
