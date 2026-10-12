<?php

namespace Tests\Feature\Fares;

use App\Enums\AdminRole;
use App\Enums\VehicleClass;
use App\Models\Audit\ActivityLogEntry;
use App\Models\FareRule;
use App\Models\Zone;
use App\Services\Fares\FareRuleLookup;
use App\Services\Fares\FareRuleService;
use Carbon\CarbonImmutable;
use Tests\Feature\Admin\AdminTestCase;

class FareRuleServiceTest extends AdminTestCase
{
    private Zone $zone;

    private FareRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['code' => 'YER', 'currency' => 'AMD']);
        $this->service = app(FareRuleService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function terms(array $overrides = []): array
    {
        return [
            'base_fare' => 500, 'per_km' => 150, 'per_minute' => 20, 'min_fare' => 800,
            'waiting_free_minutes' => 3, 'waiting_per_minute' => 30,
            'cancellation_fee' => 500, 'cancellation_free_seconds' => 120,
            'booking_fee' => 0, 'tax_rate_bps' => 0, 'tax_inclusive' => false,
            ...$overrides,
        ];
    }

    public function test_the_first_save_creates_an_open_rule_in_the_zones_currency(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');

        $saved = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms());

        $this->assertTrue($saved['created']);
        $rule = $saved['rule']->fresh();
        $this->assertSame($this->zone->id, $rule->zone_id);
        $this->assertSame('economy', $rule->vehicle_class->value);
        $this->assertSame(500, $rule->base_fare);
        $this->assertSame('AMD', $rule->currency);
        $this->assertSame($admin->id, $rule->created_by);
        $this->assertTrue($rule->effective_from->equalTo(CarbonImmutable::parse('2026-10-12 09:00:00')), 'in force from the moment it was saved');
        $this->assertNull($rule->effective_to);
    }

    public function test_changing_a_price_closes_the_old_row_and_adds_a_new_one_without_touching_the_old_values(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');
        $first = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 500]))['rule'];

        $this->travelTo('2026-10-20 15:30:00');
        $second = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 650]))['rule'];

        $this->assertSame(2, FareRule::query()->count(), 'a new row, not an update');
        $this->assertNotSame($first->id, $second->id);

        $old = $first->fresh();
        $this->assertSame(500, $old->base_fare, 'the old row keeps its price');
        $this->assertTrue($old->effective_to->equalTo(CarbonImmutable::parse('2026-10-20 15:30:00')));
        $this->assertSame(650, $second->fresh()->base_fare);
        $this->assertTrue($second->effective_from->equalTo($old->effective_to), 'no gap and no overlap between the two');
        $this->assertNull($second->fresh()->effective_to);
    }

    public function test_the_history_answers_what_was_charged_at_any_past_moment(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');
        $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 500]));
        $this->travelTo('2026-10-20 15:30:00');
        $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 650]));
        $this->travelTo('2026-11-01 00:00:00');
        $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 700]));
        $lookup = new FareRuleLookup;

        $this->assertSame(500, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 15))->base_fare);
        $this->assertSame(650, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 25))->base_fare);
        $this->assertSame(700, $lookup->at($this->zone, VehicleClass::Economy)->base_fare);
        $this->assertSame(500, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 20)->setTime(15, 29, 59))->base_fare);
        $this->assertSame(650, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 20)->setTime(15, 30, 0))->base_fare);
    }

    public function test_saving_the_prices_already_in_force_adds_no_version_and_no_audit_entry(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $first = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms())['rule'];

        $again = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms());

        $this->assertFalse($again['created']);
        $this->assertSame($first->id, $again['rule']->id);
        $this->assertSame(1, FareRule::query()->count());
        $this->assertNull($first->fresh()->effective_to);
        $this->assertSame(1, ActivityLogEntry::query()->where('log_name', 'admin')->count());
    }

    public function test_classes_and_zones_have_their_own_history(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $other = Zone::factory()->create(['currency' => 'USD']);

        $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 500]));
        $this->service->saveVersion($admin, $this->zone, VehicleClass::Comfort, $this->terms(['base_fare' => 800]));
        $this->service->saveVersion($admin, $other, VehicleClass::Economy, $this->terms(['base_fare' => 300]));
        $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 550]));

        $this->assertSame(4, FareRule::query()->count());
        $this->assertSame(800, (new FareRuleLookup)->at($this->zone, VehicleClass::Comfort)->base_fare, 'the other class was not closed');
        $this->assertSame(300, (new FareRuleLookup)->at($other, VehicleClass::Economy)->base_fare, 'the other zone was not closed');
        $this->assertSame('USD', (new FareRuleLookup)->at($other, VehicleClass::Economy)->currency);
        $this->assertSame(2, FareRule::query()->where('zone_id', $this->zone->id)->where('vehicle_class', 'economy')->count());
    }

    public function test_two_saves_in_the_same_instant_still_leave_one_open_rule(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');

        $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 500]));
        $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 600]));

        $this->assertSame(2, FareRule::query()->count());
        $this->assertSame(1, FareRule::query()->whereNull('effective_to')->count());
        $this->assertSame(500, (new FareRuleLookup)->at($this->zone, VehicleClass::Economy)->base_fare, 'the first price stays in force until the second starts');

        $this->travelTo('2026-10-12 09:00:01');
        $this->assertSame(600, (new FareRuleLookup)->at($this->zone, VehicleClass::Economy)->base_fare);
    }

    public function test_several_saves_in_one_second_each_add_a_version_and_leave_one_open_rule(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');

        foreach ([500, 600, 700, 800] as $base) {
            $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => $base]));
        }

        $versions = FareRule::query()->orderBy('id')->get();
        $this->assertSame([500, 600, 700, 800], $versions->pluck('base_fare')->all());
        $this->assertSame(1, $versions->whereNull('effective_to')->count());
        foreach ($versions->slice(1) as $i => $version) {
            $this->assertTrue($versions[$i - 1]->effective_to->equalTo($version->effective_from), 'each version starts when the previous one ends');
        }
        $this->assertSame(500, (new FareRuleLookup)->at($this->zone, VehicleClass::Economy)->base_fare);

        $this->travelTo('2026-10-12 09:00:10');
        $this->assertSame(800, (new FareRuleLookup)->at($this->zone, VehicleClass::Economy)->base_fare);
        $this->assertFalse($this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 800]))['created']);
    }

    public function test_every_save_is_audited_with_the_actor_the_changed_terms_and_the_previous_version(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $first = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 500]))['rule'];
        $second = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['base_fare' => 650, 'tax_rate_bps' => 1800, 'tax_inclusive' => true]))['rule'];

        $created = ActivityLogEntry::query()->where('description', 'fare_rule.created')->sole();
        $this->assertSame($admin->id, $created->causer_id);
        $this->assertSame($first->id, $created->subject_id);
        $this->assertSame(500, $created->attribute_changes['attributes']['base_fare']);

        $versioned = ActivityLogEntry::query()->where('description', 'fare_rule.versioned')->sole();
        $this->assertSame($admin->id, $versioned->causer_id);
        $this->assertSame($second->id, $versioned->subject_id);
        $this->assertSame(['base_fare' => 500, 'tax_rate_bps' => 0, 'tax_inclusive' => false], $versioned->attribute_changes['old']);
        $this->assertSame(['base_fare' => 650, 'tax_rate_bps' => 1800, 'tax_inclusive' => true], $versioned->attribute_changes['attributes']);
        $this->assertSame(['zone' => 'YER', 'vehicle_class' => 'economy', 'previous_rule_id' => $first->id], $versioned->properties['context']);
        $this->assertSame('fare_rule', $versioned->properties['target_type'] ?? 'fare_rule');
    }

    public function test_unknown_fields_never_reach_the_rule(): void
    {
        $admin = $this->admin(AdminRole::Admin);

        $rule = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms() + ['currency' => 'USD', 'zone_id' => 999, 'effective_from' => '2000-01-01', 'created_by' => 12345])['rule'];

        $this->assertSame('AMD', $rule->currency, 'the currency is the zone\'s');
        $this->assertSame($this->zone->id, $rule->zone_id);
        $this->assertSame($admin->id, $rule->created_by);
        $this->assertGreaterThan(now()->subMinute(), $rule->effective_from);
    }
}
