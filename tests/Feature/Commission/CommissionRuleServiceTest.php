<?php

namespace Tests\Feature\Commission;

use App\Enums\AdminRole;
use App\Enums\VehicleClass;
use App\Models\Audit\ActivityLogEntry;
use App\Models\CommissionRule;
use App\Models\Zone;
use App\Services\Commission\CommissionRuleLookup;
use App\Services\Commission\CommissionRuleService;
use Carbon\CarbonImmutable;
use Tests\Feature\Admin\AdminTestCase;

class CommissionRuleServiceTest extends AdminTestCase
{
    private Zone $zone;

    private CommissionRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['code' => 'YER', 'currency' => 'AMD']);
        $this->service = app(CommissionRuleService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function terms(array $overrides = []): array
    {
        return ['type' => 'percentage', 'rate_bps' => 1800, 'fixed_amount' => 0, 'min_commission' => 0, 'max_commission' => null, 'applies_to_surge' => true, ...$overrides];
    }

    public function test_the_first_save_creates_an_open_rule_in_the_zones_currency(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');

        $saved = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['max_commission' => 8000, 'min_commission' => 500]));

        $this->assertTrue($saved['created']);
        $rule = $saved['rule']->fresh();
        $this->assertSame($this->zone->id, $rule->zone_id);
        $this->assertSame('economy', $rule->vehicle_class->value);
        $this->assertSame(1800, $rule->rate_bps);
        $this->assertSame(500, $rule->min_commission);
        $this->assertSame(8000, $rule->max_commission);
        $this->assertSame('AMD', $rule->currency);
        $this->assertSame($admin->id, $rule->created_by);
        $this->assertTrue($rule->effective_from->equalTo(CarbonImmutable::parse('2026-10-12 09:00:00')));
        $this->assertNull($rule->effective_to);
    }

    public function test_a_null_class_creates_the_zone_wide_rule(): void
    {
        $rule = $this->service->saveVersion($this->admin(AdminRole::Admin), $this->zone, null, $this->terms())['rule'];

        $this->assertNull($rule->fresh()->vehicle_class);
    }

    public function test_changing_the_terms_closes_the_old_row_and_adds_a_new_one_without_touching_the_old_values(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');
        $first = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['rate_bps' => 1800]))['rule'];

        $this->travelTo('2026-10-20 15:30:00');
        $second = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['rate_bps' => 2200]))['rule'];

        $this->assertSame(2, CommissionRule::query()->count(), 'a new row, not an update');
        $old = $first->fresh();
        $this->assertSame(1800, $old->rate_bps, 'the old row keeps its terms');
        $this->assertTrue($old->effective_to->equalTo(CarbonImmutable::parse('2026-10-20 15:30:00')));
        $this->assertSame(2200, $second->fresh()->rate_bps);
        $this->assertTrue($second->effective_from->equalTo($old->effective_to), 'no gap and no overlap');
        $this->assertNull($second->fresh()->effective_to);
    }

    public function test_the_history_answers_which_rule_applied_at_any_past_moment(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');
        $this->service->saveVersion($admin, $this->zone, null, $this->terms(['rate_bps' => 1800]));
        $this->travelTo('2026-10-20 15:30:00');
        $this->service->saveVersion($admin, $this->zone, null, $this->terms(['rate_bps' => 2200]));
        $this->travelTo('2026-11-01 00:00:00');
        $this->service->saveVersion($admin, $this->zone, null, $this->terms(['rate_bps' => 2500]));
        $lookup = new CommissionRuleLookup;

        $this->assertSame(1800, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 15))->rate_bps);
        $this->assertSame(2200, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 25))->rate_bps);
        $this->assertSame(2500, $lookup->at($this->zone, VehicleClass::Economy)->rate_bps);
        $this->assertSame(1800, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 20)->setTime(15, 29, 59))->rate_bps);
        $this->assertSame(2200, $lookup->at($this->zone, VehicleClass::Economy, now()->setDate(2026, 10, 20)->setTime(15, 30, 0))->rate_bps);
    }

    public function test_saving_the_terms_already_in_force_adds_no_version_and_no_audit_entry(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $first = $this->service->saveVersion($admin, $this->zone, null, $this->terms())['rule'];

        $again = $this->service->saveVersion($admin, $this->zone, null, $this->terms());

        $this->assertFalse($again['created']);
        $this->assertSame($first->id, $again['rule']->id);
        $this->assertSame(1, CommissionRule::query()->count());
        $this->assertNull($first->fresh()->effective_to);
        $this->assertSame(1, ActivityLogEntry::query()->where('log_name', 'admin')->count());
    }

    public function test_a_type_stores_only_the_terms_it_uses(): void
    {
        $admin = $this->admin(AdminRole::Admin);

        $fixed = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['type' => 'fixed', 'rate_bps' => 1800, 'fixed_amount' => 2500]))['rule'];
        $percentage = $this->service->saveVersion($admin, $this->zone, VehicleClass::Comfort, $this->terms(['type' => 'percentage', 'rate_bps' => 1500, 'fixed_amount' => 999]))['rule'];

        $this->assertSame(0, $fixed->fresh()->rate_bps);
        $this->assertSame(2500, $fixed->fresh()->fixed_amount);
        $this->assertSame(0, $percentage->fresh()->fixed_amount);
        $this->assertSame(1500, $percentage->fresh()->rate_bps);
    }

    public function test_saving_a_type_with_terms_the_stored_rule_already_has_in_other_fields_is_still_no_change(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->service->saveVersion($admin, $this->zone, null, $this->terms(['type' => 'fixed', 'fixed_amount' => 2500]));

        $again = $this->service->saveVersion($admin, $this->zone, null, $this->terms(['type' => 'fixed', 'rate_bps' => 777, 'fixed_amount' => 2500]));

        $this->assertFalse($again['created'], 'the unused rate does not make it a different rule');
    }

    public function test_classes_and_the_zone_wide_rule_have_their_own_history(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $other = Zone::factory()->create(['currency' => 'USD']);

        $this->service->saveVersion($admin, $this->zone, null, $this->terms(['rate_bps' => 2000]));
        $this->service->saveVersion($admin, $this->zone, VehicleClass::Comfort, $this->terms(['rate_bps' => 1500]));
        $this->service->saveVersion($admin, $other, null, $this->terms(['rate_bps' => 900]));
        $this->service->saveVersion($admin, $this->zone, null, $this->terms(['rate_bps' => 2100]));

        $this->assertSame(4, CommissionRule::query()->count());
        $this->assertSame(1500, (new CommissionRuleLookup)->at($this->zone, VehicleClass::Comfort)->rate_bps, 'the class rule was not closed');
        $this->assertSame(900, (new CommissionRuleLookup)->at($other, VehicleClass::Economy)->rate_bps, 'the other zone was not closed');
        $this->assertSame('USD', (new CommissionRuleLookup)->at($other, VehicleClass::Economy)->currency);
        $this->assertSame(2, CommissionRule::query()->where('zone_id', $this->zone->id)->whereNull('vehicle_class')->count());
    }

    public function test_several_saves_in_one_second_each_add_a_version_and_leave_one_open_rule(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $this->travelTo('2026-10-12 09:00:00');

        foreach ([1000, 1100, 1200, 1300] as $rate) {
            $this->service->saveVersion($admin, $this->zone, null, $this->terms(['rate_bps' => $rate]));
        }

        $versions = CommissionRule::query()->orderBy('id')->get();
        $this->assertSame([1000, 1100, 1200, 1300], $versions->pluck('rate_bps')->all());
        $this->assertSame(1, $versions->whereNull('effective_to')->count());
        foreach ($versions->slice(1) as $i => $version) {
            $this->assertTrue($versions[$i - 1]->effective_to->equalTo($version->effective_from));
        }

        $this->travelTo('2026-10-12 09:00:10');
        $this->assertSame(1300, (new CommissionRuleLookup)->at($this->zone, VehicleClass::Economy)->rate_bps);
    }

    public function test_every_save_is_audited_with_the_actor_the_changed_terms_and_the_previous_version(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $first = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['rate_bps' => 1800]))['rule'];
        $second = $this->service->saveVersion($admin, $this->zone, VehicleClass::Economy, $this->terms(['rate_bps' => 2200, 'applies_to_surge' => false]))['rule'];

        $created = ActivityLogEntry::query()->where('description', 'commission_rule.created')->sole();
        $this->assertSame($admin->id, $created->causer_id);
        $this->assertSame($first->id, $created->subject_id);
        $this->assertSame(1800, $created->attribute_changes['attributes']['rate_bps']);

        $versioned = ActivityLogEntry::query()->where('description', 'commission_rule.versioned')->sole();
        $this->assertSame($admin->id, $versioned->causer_id);
        $this->assertSame($second->id, $versioned->subject_id);
        $this->assertSame(['rate_bps' => 1800, 'applies_to_surge' => true], $versioned->attribute_changes['old']);
        $this->assertSame(['rate_bps' => 2200, 'applies_to_surge' => false], $versioned->attribute_changes['attributes']);
        $this->assertSame(['zone' => 'YER', 'vehicle_class' => 'economy', 'previous_rule_id' => $first->id], $versioned->properties['context']);
    }

    public function test_the_zone_wide_rule_is_audited_as_all_classes(): void
    {
        $this->service->saveVersion($this->admin(AdminRole::Admin), $this->zone, null, $this->terms());

        $this->assertSame('all', ActivityLogEntry::query()->where('description', 'commission_rule.created')->sole()->properties['context']['vehicle_class']);
    }

    public function test_unknown_fields_never_reach_the_rule(): void
    {
        $admin = $this->admin(AdminRole::Admin);

        $rule = $this->service->saveVersion($admin, $this->zone, null, $this->terms() + ['currency' => 'USD', 'zone_id' => 999, 'effective_from' => '2000-01-01', 'created_by' => 12345])['rule'];

        $this->assertSame('AMD', $rule->currency, 'the currency is the zone\'s');
        $this->assertSame($this->zone->id, $rule->zone_id);
        $this->assertSame($admin->id, $rule->created_by);
        $this->assertGreaterThan(now()->subMinute(), $rule->effective_from);
    }
}
