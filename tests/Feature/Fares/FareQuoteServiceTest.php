<?php

namespace Tests\Feature\Fares;

use App\Data\GeoPoint;
use App\Enums\AdminRole;
use App\Enums\VehicleClass;
use App\Exceptions\FareException;
use App\Models\FareQuote;
use App\Models\FareRule;
use App\Models\User;
use App\Models\Zone;
use App\Services\Fares\FareEstimator;
use App\Services\Fares\FareQuoteService;
use App\Services\Fares\FareRuleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Trip creation (P7-T6) prices a trip from a quote: the same rule and surge the rider saw, for the
 * rider who asked, for ten minutes.
 */
class FareQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->around(40.18, 44.51, 0.05)->create();
        $this->rider = User::factory()->rider()->create();
        FareRule::factory()->forZone($this->zone)->create(['base_fare' => 500, 'min_fare' => 0]);
    }

    private function quote(?User $rider = null): FareQuote
    {
        return app(FareEstimator::class)->quote($rider ?? $this->rider, new GeoPoint(40.18, 44.51), new GeoPoint(40.19, 44.51));
    }

    public function test_the_rider_can_use_their_quote_until_it_expires(): void
    {
        $this->travelTo('2026-10-12 09:00:00');
        $quote = $this->quote();
        $service = app(FareQuoteService::class);

        $this->assertSame($quote->id, $service->usable($quote->id, $this->rider)->id);

        $this->travelTo('2026-10-12 09:09:59');
        $this->assertSame($quote->id, $service->usable($quote->id, $this->rider)->id, 'still valid one second before ten minutes');

        $this->travelTo('2026-10-12 09:10:00');
        $this->expectException(FareException::class);
        $this->expectExceptionMessage('This price quote has expired.');
        $service->usable($quote->id, $this->rider);
    }

    public function test_an_expired_quote_reports_its_code_and_a_410(): void
    {
        $this->travelTo('2026-10-12 09:00:00');
        $quote = $this->quote();
        $this->travelTo('2026-10-12 09:30:00');

        try {
            app(FareQuoteService::class)->usable($quote->id, $this->rider);
            $this->fail('An expired quote must be refused.');
        } catch (FareException $e) {
            $this->assertSame('quote_expired', $e->errorCode);
            $this->assertSame(410, $e->status);
        }
    }

    public function test_another_riders_quote_looks_like_no_quote_at_all(): void
    {
        $quote = $this->quote();
        $stranger = User::factory()->rider()->create();

        try {
            app(FareQuoteService::class)->usable($quote->id, $stranger);
            $this->fail('A quote belongs to the rider who asked.');
        } catch (FareException $e) {
            $this->assertSame('quote_not_found', $e->errorCode);
            $this->assertSame(404, $e->status);
        }
    }

    public function test_unknown_and_malformed_ids_are_not_found_without_a_database_error(): void
    {
        $service = app(FareQuoteService::class);

        foreach ([Str::uuid()->toString(), 'not-a-uuid', '', "' OR 1=1 --", '12345'] as $id) {
            try {
                $service->usable($id, $this->rider);
                $this->fail("'{$id}' must be refused.");
            } catch (FareException $e) {
                $this->assertSame('quote_not_found', $e->errorCode);
            }
        }
    }

    public function test_the_snapshot_is_the_class_rule_surge_and_breakdown_the_rider_saw(): void
    {
        $quote = $this->quote();
        $rule = FareRule::query()->sole();

        $snapshot = app(FareQuoteService::class)->snapshot($quote, VehicleClass::Economy);

        $this->assertSame($rule->id, $snapshot['fare_rule_id']);
        $this->assertSame('AMD', $snapshot['currency']);
        $this->assertSame('1.00', $snapshot['surge_multiplier']);
        $this->assertSame(500 + 217 + 58, $snapshot['amount']);
        $this->assertSame($snapshot['amount'], $snapshot['breakdown']->total);
        $this->assertSame(217, $snapshot['breakdown']->distance);
    }

    public function test_a_class_that_was_not_quoted_cannot_be_used(): void
    {
        $quote = $this->quote();

        try {
            app(FareQuoteService::class)->snapshot($quote, VehicleClass::Business);
            $this->fail('Only quoted classes can be priced from the quote.');
        } catch (FareException $e) {
            $this->assertSame('vehicle_class_not_quoted', $e->errorCode);
        }
    }

    public function test_a_price_change_after_the_quote_does_not_change_what_the_quote_says(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $quote = $this->quote();
        $before = FareRule::query()->sole();

        $this->travel(2)->minutes();
        app(FareRuleService::class)->saveVersion(User::factory()->admin(AdminRole::Admin)->create(), $this->zone, VehicleClass::Economy, [
            ...$before->only(FareRule::TERMS), 'base_fare' => 9000,
        ]);

        $snapshot = app(FareQuoteService::class)->snapshot(app(FareQuoteService::class)->usable($quote->id, $this->rider), VehicleClass::Economy);

        $this->assertSame($before->id, $snapshot['fare_rule_id'], 'priced with the rule the rider saw');
        $this->assertSame(775, $snapshot['amount']);
        $this->assertSame(2, FareRule::count());
    }

    public function test_old_quotes_are_pruned_and_recent_ones_kept(): void
    {
        $this->travelTo('2026-10-12 09:00:00');
        $old = $this->quote();
        $this->travelTo('2026-10-13 08:59:00');
        $recent = $this->quote();
        $this->travelTo('2026-10-13 09:11:00'); // the first one expired more than a day ago

        Artisan::call('model:prune', ['--model' => [FareQuote::class]]);

        $this->assertNull(FareQuote::find($old->id));
        $this->assertNotNull(FareQuote::find($recent->id), 'expired but less than a day ago');
    }
}
