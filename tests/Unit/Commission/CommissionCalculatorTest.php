<?php

namespace Tests\Unit\Commission;

use App\Data\FareBreakdown;
use App\Enums\CommissionType;
use App\Models\CommissionRule;
use App\Services\Commission\CommissionCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every expected number is worked out by hand in its comment (and the shared vectors in
 * tests/fixtures/commission-vectors.json are the same ones the admin preview's Vitest test uses).
 */
class CommissionCalculatorTest extends TestCase
{
    private CommissionCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new CommissionCalculator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function rule(array $overrides = []): CommissionRule
    {
        return new CommissionRule([
            'type' => CommissionType::Percentage, 'rate_bps' => 2000, 'fixed_amount' => 0, 'min_commission' => 0, 'max_commission' => null,
            'applies_to_surge' => true, 'currency' => 'AMD',
            ...$overrides,
        ]);
    }

    /**
     * A fare breakdown with only the parts commission looks at: total, tax and surge.
     */
    private function fare(int $total, int $tax = 0, int $surge = 0, string $currency = 'AMD'): FareBreakdown
    {
        return new FareBreakdown($currency, $total - $tax - $surge, 0, 0, 0, 0, 0, [], '1.00', $surge, $total - $tax, 0, false, $tax, $total);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function vectors(): array
    {
        $rows = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/commission-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_combine(array_column($rows, 'name'), array_map(fn (array $row) => [$row], $rows));
    }

    /**
     * @param  array<string, mixed>  $v
     */
    #[DataProvider('vectors')]
    public function test_the_shared_vectors(array $v): void
    {
        $result = $this->calculator->calculate($this->fare($v['fare']), $this->rule([
            'type' => CommissionType::from($v['type']), 'rate_bps' => $v['rate_bps'], 'fixed_amount' => $v['fixed'], 'min_commission' => $v['min'], 'max_commission' => $v['max'],
        ]));

        $this->assertSame($v['commission'], $result->commission);
        $this->assertSame($v['fare'] - $v['commission'], $result->driverEarnings);
    }

    // ---- the three types ----

    public function test_percentage_takes_a_share_of_the_fare(): void
    {
        // 12,500 x 18 % = 2,250; the driver keeps 10,250
        $result = $this->calculator->calculate($this->fare(12500), $this->rule(['rate_bps' => 1800]));

        $this->assertSame(2250, $result->commission);
        $this->assertSame(10250, $result->driverEarnings);
        $this->assertSame(12500, $result->taxFree);
        $this->assertSame(12500, $result->base);
        $this->assertSame('AMD', $result->currency);
    }

    public function test_fixed_takes_the_same_amount_whatever_the_fare(): void
    {
        $rule = $this->rule(['type' => CommissionType::Fixed, 'rate_bps' => 0, 'fixed_amount' => 500]);

        $this->assertSame(500, $this->calculator->calculate($this->fare(3000), $rule)->commission);
        $this->assertSame(500, $this->calculator->calculate($this->fare(90000), $rule)->commission);
    }

    public function test_percentage_plus_fixed_adds_both(): void
    {
        // 10,000 x 16 % = 1,600 + 300
        $this->assertSame(1900, $this->calculator->calculate($this->fare(10000), $this->rule(['type' => CommissionType::PercentagePlusFixed, 'rate_bps' => 1600, 'fixed_amount' => 300]))->commission);
    }

    public function test_a_type_ignores_the_terms_it_does_not_use(): void
    {
        $percentage = $this->rule(['type' => CommissionType::Percentage, 'rate_bps' => 1000, 'fixed_amount' => 999]);
        $fixed = $this->rule(['type' => CommissionType::Fixed, 'rate_bps' => 9999, 'fixed_amount' => 400]);

        $this->assertSame(1000, $this->calculator->calculate($this->fare(10000), $percentage)->commission);
        $this->assertSame(400, $this->calculator->calculate($this->fare(10000), $fixed)->commission);
    }

    // ---- min / max ----

    public function test_the_minimum_lifts_and_the_maximum_caps(): void
    {
        // 18 % of 1,000 = 180 -> minimum 500; 18 % of 100,000 = 18,000 -> maximum 8,000
        $this->assertSame(500, $this->calculator->calculate($this->fare(1000), $this->rule(['rate_bps' => 1800, 'min_commission' => 500]))->commission);
        $this->assertSame(8000, $this->calculator->calculate($this->fare(100000), $this->rule(['rate_bps' => 1800, 'max_commission' => 8000]))->commission);
    }

    public function test_the_boundaries_of_min_and_max_are_inclusive(): void
    {
        // 18 % of 2,500 = 450 exactly the minimum; 18 % of 5,000 = 900 exactly the maximum
        $this->assertSame(450, $this->calculator->calculate($this->fare(2500), $this->rule(['rate_bps' => 1800, 'min_commission' => 450]))->commission);
        $this->assertSame(900, $this->calculator->calculate($this->fare(5000), $this->rule(['rate_bps' => 1800, 'max_commission' => 900]))->commission);
        $this->assertSame(451, $this->calculator->calculate($this->fare(2506), $this->rule(['rate_bps' => 1800, 'min_commission' => 450]))->commission, '18 % of 2,506 = 451.08');
    }

    public function test_the_commission_never_exceeds_what_there_is_to_share(): void
    {
        // minimum 500 on a 300 fare takes the 300; the driver gets nothing but is never negative
        $result = $this->calculator->calculate($this->fare(300), $this->rule(['rate_bps' => 1800, 'min_commission' => 500]));

        $this->assertSame(300, $result->commission);
        $this->assertSame(0, $result->driverEarnings);
    }

    // ---- surge ----

    public function test_surge_is_in_the_commission_base_when_the_rule_applies_to_surge(): void
    {
        // total 15,000 of which 5,000 is surge; 20 % of all 15,000 = 3,000
        $result = $this->calculator->calculate($this->fare(15000, surge: 5000), $this->rule(['rate_bps' => 2000, 'applies_to_surge' => true]));

        $this->assertSame(15000, $result->base);
        $this->assertSame(3000, $result->commission);
        $this->assertSame(12000, $result->driverEarnings);
    }

    public function test_surge_is_left_out_of_the_base_and_stays_with_the_driver_when_the_rule_says_so(): void
    {
        // 20 % of 15,000 - 5,000 = 2,000; the driver keeps 13,000 (all of the surge)
        $result = $this->calculator->calculate($this->fare(15000, surge: 5000), $this->rule(['rate_bps' => 2000, 'applies_to_surge' => false]));

        $this->assertSame(10000, $result->base);
        $this->assertSame(2000, $result->commission);
        $this->assertSame(13000, $result->driverEarnings);
    }

    public function test_a_fixed_commission_is_not_changed_by_surge(): void
    {
        $rule = $this->rule(['type' => CommissionType::Fixed, 'rate_bps' => 0, 'fixed_amount' => 700, 'applies_to_surge' => false]);

        $this->assertSame(700, $this->calculator->calculate($this->fare(15000, surge: 5000), $rule)->commission);
    }

    // ---- tax ----

    public function test_tax_is_kept_out_of_the_commission_base_and_out_of_the_drivers_share(): void
    {
        // exclusive tax: the rider pays 11,800 = 10,000 + 1,800 tax; commission 20 % of 10,000 = 2,000; driver 8,000
        $result = $this->calculator->calculate($this->fare(11800, tax: 1800), $this->rule(['rate_bps' => 2000]));

        $this->assertSame(10000, $result->taxFree);
        $this->assertSame(2000, $result->commission);
        $this->assertSame(8000, $result->driverEarnings);
        $this->assertSame(11800, $result->commission + $result->driverEarnings + 1800, 'commission + driver + tax = what the rider paid');
    }

    public function test_tax_inside_the_price_is_taken_out_before_commission(): void
    {
        $inclusive = new FareBreakdown('AMD', 0, 0, 0, 0, 0, 0, [], '1.00', 0, 11800, 1800, true, 1800, 11800);

        // the 11,800 contains 1,800 tax: the same 10,000 base
        $this->assertSame(2000, $this->calculator->calculate($inclusive, $this->rule(['rate_bps' => 2000]))->commission);
        $this->assertSame(8000, $this->calculator->calculate($inclusive, $this->rule(['rate_bps' => 2000]))->driverEarnings);
    }

    public function test_surge_exclusion_and_tax_work_together(): void
    {
        // total 13,570 = 11,500 + 2,070 tax (18 %), where 1,500 is surge; base 10,000; 20 % = 2,000; driver 9,500
        $result = $this->calculator->calculate($this->fare(13570, tax: 2070, surge: 1500), $this->rule(['rate_bps' => 2000, 'applies_to_surge' => false]));

        $this->assertSame(11500, $result->taxFree);
        $this->assertSame(10000, $result->base);
        $this->assertSame(2000, $result->commission);
        $this->assertSame(9500, $result->driverEarnings);
    }

    // ---- invariants and errors ----

    public function test_commission_plus_driver_earnings_is_always_the_fare_without_tax(): void
    {
        foreach ([0, 1, 99, 1003, 12500, 999_999] as $total) {
            foreach ([0, 150] as $surge) {
                foreach ([[CommissionType::Percentage, 1800, 0], [CommissionType::Fixed, 0, 2500], [CommissionType::PercentagePlusFixed, 1333, 77]] as [$type, $bps, $fixed]) {
                    foreach ([[0, null], [500, 8000], [0, 1]] as [$min, $max]) {
                        $rule = $this->rule(['type' => $type, 'rate_bps' => $bps, 'fixed_amount' => $fixed, 'min_commission' => $min, 'max_commission' => $max, 'applies_to_surge' => false]);
                        $result = $this->calculator->calculate($this->fare($total + $surge, surge: $surge), $rule);

                        $this->assertSame($total + $surge, $result->commission + $result->driverEarnings);
                        $this->assertGreaterThanOrEqual(0, $result->commission);
                        $this->assertGreaterThanOrEqual(0, $result->driverEarnings);
                        $max === null || $this->assertLessThanOrEqual(max($max, 0), $result->commission);
                    }
                }
            }
        }
    }

    public function test_a_rule_in_another_currency_cannot_price_a_fare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('USD commission rule cannot price a AMD fare');

        $this->calculator->calculate($this->fare(1000), $this->rule(['currency' => 'USD']));
    }

    public function test_an_amount_too_large_to_calculate_is_refused_instead_of_overflowing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate($this->fare(PHP_INT_MAX - 5), $this->rule(['rate_bps' => 2000]));
    }

    public function test_the_result_carries_the_rule_id(): void
    {
        $rule = $this->rule();
        $rule->id = 42;

        $this->assertSame(42, $this->calculator->calculate($this->fare(1000), $rule)->ruleId);
    }
}
