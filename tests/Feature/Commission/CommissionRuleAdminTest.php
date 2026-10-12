<?php

namespace Tests\Feature\Commission;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\CommissionRule;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

class CommissionRuleAdminTest extends AdminTestCase
{
    private User $admin;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(AdminRole::Admin);
        $this->zone = Zone::factory()->create(['name' => 'Downtown Core', 'code' => 'DT']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['zone_id' => $this->zone->id, 'vehicle_class' => 'economy', 'type' => 'percentage', 'rate_bps' => 1800, 'fixed_amount' => 0, 'min_commission' => 500, 'max_commission' => 8000, 'applies_to_surge' => true, ...$overrides];
    }

    private function save(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post('/admin/commission-rules', $this->payload($overrides));
    }

    // ---- POST: saving always adds a version ----

    public function test_saving_creates_a_rule_and_a_second_save_with_new_terms_adds_a_version(): void
    {
        $this->save()->assertRedirect()->assertSessionHas('success', 'Commission rule saved. The new terms apply from now.');
        $this->assertSame(1, CommissionRule::count());

        $this->travel(5)->minutes();
        $this->save(['rate_bps' => 2200, 'applies_to_surge' => false])->assertSessionHas('success', 'Commission rule saved. The new terms apply from now.');

        $this->assertSame(2, CommissionRule::count(), 'never an UPDATE of the old row');
        $old = CommissionRule::query()->orderBy('id')->first();
        $new = CommissionRule::query()->orderByDesc('id')->first();
        $this->assertSame(1800, $old->rate_bps);
        $this->assertNotNull($old->effective_to);
        $this->assertSame([2200, false, 500, 8000], [$new->rate_bps, $new->applies_to_surge, $new->min_commission, $new->max_commission]);
        $this->assertNull($new->effective_to);
        $this->assertSame($this->admin->id, $new->created_by);
    }

    public function test_an_empty_class_creates_the_zone_wide_rule(): void
    {
        $this->save(['vehicle_class' => null])->assertSessionHasNoErrors();

        $this->assertNull(CommissionRule::query()->sole()->vehicle_class);
    }

    public function test_saving_the_same_terms_twice_says_nothing_changed(): void
    {
        $this->save();
        $this->save()->assertSessionHas('success', 'Nothing changed: these terms are already in force.');

        $this->assertSame(1, CommissionRule::count());
        $this->assertSame(1, ActivityLogEntry::query()->where('description', 'like', 'commission_rule.%')->count());
    }

    public function test_the_currency_is_the_zones_and_cannot_be_chosen_by_the_client(): void
    {
        $this->save(['currency' => 'USD', 'created_by' => 1, 'effective_from' => '2000-01-01']);

        $rule = CommissionRule::query()->sole();
        $this->assertSame('AMD', $rule->currency);
        $this->assertSame($this->admin->id, $rule->created_by);
        $this->assertGreaterThan(now()->subMinute(), $rule->effective_from);
    }

    public function test_a_fixed_rule_stores_no_rate(): void
    {
        $this->save(['type' => 'fixed', 'rate_bps' => 1800, 'fixed_amount' => 2500, 'max_commission' => null, 'min_commission' => 0]);

        $rule = CommissionRule::query()->sole();
        $this->assertSame([0, 2500], [$rule->rate_bps, $rule->fixed_amount]);
    }

    // ---- validation: nothing is saved ----

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidFields(): array
    {
        return [
            'zone missing' => ['zone_id', null],
            'zone unknown' => ['zone_id', 999999],
            'zone not a number' => ['zone_id', 'abc'],
            'class unknown' => ['vehicle_class', 'limousine'],
            'type missing' => ['type', null],
            'type unknown' => ['type', 'barter'],
            'rate missing' => ['rate_bps', null],
            'rate over 100%' => ['rate_bps', 10_001],
            'rate negative' => ['rate_bps', -1],
            'rate with decimals' => ['rate_bps', 12.5],
            'fixed amount negative' => ['fixed_amount', -1],
            'fixed amount too large' => ['fixed_amount', 1_000_000_001],
            'minimum negative' => ['min_commission', -1],
            'minimum missing' => ['min_commission', null],
            'maximum negative' => ['max_commission', -1],
            'maximum below the minimum' => ['max_commission', 499],
            'maximum too large' => ['max_commission', 1_000_000_001],
            'surge flag missing' => ['applies_to_surge', null],
            'surge flag not a boolean' => ['applies_to_surge', 'maybe'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_input_shows_an_error_on_the_field_and_saves_nothing(string $field, mixed $value): void
    {
        $payload = $this->payload();
        $payload[$field] = $value;

        $this->actingAs($this->admin)->post('/admin/commission-rules', $payload)->assertSessionHasErrors($field);

        $this->assertSame(0, CommissionRule::count());
        $this->assertSame(0, ActivityLogEntry::query()->where('description', 'like', 'commission_rule.%')->count());
    }

    public function test_boundary_values_are_accepted(): void
    {
        $this->save(['rate_bps' => 0, 'fixed_amount' => 0, 'min_commission' => 0, 'max_commission' => 0])->assertSessionHasNoErrors();
        $this->save(['vehicle_class' => 'business', 'type' => 'percentage_plus_fixed', 'rate_bps' => 10_000, 'fixed_amount' => 1_000_000_000, 'min_commission' => 1_000_000_000, 'max_commission' => 1_000_000_000])->assertSessionHasNoErrors();
        $this->save(['vehicle_class' => 'comfort', 'min_commission' => 500, 'max_commission' => 500])->assertSessionHasNoErrors();

        $this->assertSame(3, CommissionRule::count());
    }

    // ---- GET: the Commission page ----

    public function test_the_page_lists_only_the_rules_in_force_with_their_terms(): void
    {
        $this->save(['rate_bps' => 1800]);
        $this->travel(5)->minutes();
        $this->save(['rate_bps' => 2200]);
        $this->save(['vehicle_class' => null, 'type' => 'percentage_plus_fixed', 'rate_bps' => 1600, 'fixed_amount' => 300, 'min_commission' => 0, 'max_commission' => null, 'applies_to_surge' => false]);

        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertInertia(fn (Assert $page) => $page
            ->component('CommissionRules/Index')
            ->where('rules.total', 2)
            // newest first: the zone-wide rule was saved last
            ->where('rules.data.0.vehicle_class', null)->where('rules.data.0.rate_bp', 1600)
            ->where('rules.data.0.zone', 'Downtown Core')
            ->where('rules.data.0.type', 'percentage_plus_fixed')
            ->where('rules.data.0.fixed_fee', ['amount' => 300, 'currency' => 'AMD'])
            ->where('rules.data.0.minimum', null)
            ->where('rules.data.0.maximum', null)
            ->where('rules.data.0.applies_to_surge', false)
            ->where('rules.data.1.vehicle_class', 'economy')->where('rules.data.1.rate_bp', 2200)
            ->where('rules.data.1.minimum', ['amount' => 500, 'currency' => 'AMD'])
            ->where('rules.data.1.maximum', ['amount' => 8000, 'currency' => 'AMD'])
            ->where('rules.data.1.fixed_fee', null)
            ->where('rules.data.1.effective_to', null)
            ->has('rules.data.1.effective_from')
            ->where('history', null)
            ->where('zones', [['id' => $this->zone->id, 'name' => 'Downtown Core', 'currency' => 'AMD']])
            ->where('vehicleClasses', ['economy', 'comfort', 'business'])
            ->where('types', ['percentage', 'fixed', 'percentage_plus_fixed'])
            ->where('actions.createVersion', '/admin/commission-rules'));
    }

    public function test_a_fixed_rule_has_no_rate_on_the_page(): void
    {
        $this->save(['type' => 'fixed', 'fixed_amount' => 2500, 'min_commission' => 0, 'max_commission' => null]);

        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertInertia(fn (Assert $page) => $page
            ->where('rules.data.0.rate_bp', null)->where('rules.data.0.fixed_fee.amount', 2500));
    }

    public function test_the_preview_uses_the_newest_rule_unless_another_is_chosen(): void
    {
        $this->save(['vehicle_class' => 'economy', 'rate_bps' => 1800]);
        $this->travel(5)->minutes();
        $this->save(['vehicle_class' => 'comfort', 'rate_bps' => 1600]);
        $economy = CommissionRule::query()->where('vehicle_class', 'economy')->sole();

        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertInertia(fn (Assert $page) => $page
            ->where('previewRule.vehicle_class', 'comfort')->has('previewRules', 2)
            ->where('previewFare', ['amount' => 12500, 'currency' => 'AMD']));
        $this->actingAs($this->admin)->get('/admin/commission-rules?preview='.$economy->id)->assertInertia(fn (Assert $page) => $page->where('previewRule.id', $economy->id));
        $this->actingAs($this->admin)->get('/admin/commission-rules?preview=999999')->assertInertia(fn (Assert $page) => $page->where('previewRule.vehicle_class', 'comfort'));
    }

    public function test_the_preview_fare_follows_the_currency_decimals(): void
    {
        $usd = Zone::factory()->create(['currency' => 'USD']);
        CommissionRule::factory()->forZone($usd)->create();

        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertInertia(fn (Assert $page) => $page->where('previewFare', ['amount' => 1250000, 'currency' => 'USD']));
    }

    public function test_the_history_lists_every_version_of_the_rule_newest_first_with_who_saved_it(): void
    {
        $this->save(['rate_bps' => 1800]);
        $this->travel(5)->minutes();
        $this->save(['rate_bps' => 2200]);
        $this->save(['vehicle_class' => null, 'rate_bps' => 1000]);
        $current = CommissionRule::query()->where('vehicle_class', 'economy')->whereNull('effective_to')->sole();

        $this->actingAs($this->admin)->get('/admin/commission-rules?history='.$current->id)->assertInertia(fn (Assert $page) => $page
            ->where('history.zone', 'Downtown Core')->where('history.vehicle_class', 'economy')
            ->has('history.versions', 2)
            ->where('history.versions.0.rate_bp', 2200)->where('history.versions.0.effective_to', null)->where('history.versions.0.created_by', $this->admin->name)
            ->where('history.versions.1.rate_bp', 1800)->whereNot('history.versions.1.effective_to', null));

        foreach (['0', 'abc', '999999'] as $bad) {
            $this->actingAs($this->admin)->get('/admin/commission-rules?history='.$bad)->assertInertia(fn (Assert $page) => $page->where('history', null));
        }
    }

    public function test_the_list_filters_by_class_zone_and_search(): void
    {
        $other = Zone::factory()->create(['name' => 'Airport Zone', 'code' => 'AIR']);
        $this->save(['vehicle_class' => 'economy']);
        $this->save(['vehicle_class' => 'comfort']);
        $this->save(['vehicle_class' => null]);
        $this->actingAs($this->admin)->post('/admin/commission-rules', $this->payload(['zone_id' => $other->id, 'vehicle_class' => 'economy']));
        $classes = fn (string $query) => $this->actingAs($this->admin)->get('/admin/commission-rules?'.$query);

        $classes('filter[vehicle_class]=economy')->assertInertia(fn (Assert $p) => $p->where('rules.total', 2));
        $classes('filter[vehicle_class]=all')->assertInertia(fn (Assert $p) => $p->where('rules.total', 1)->where('rules.data.0.vehicle_class', null));
        $classes('filter[zone]='.$other->id)->assertInertia(fn (Assert $p) => $p->where('rules.total', 1)->where('rules.data.0.zone', 'Airport Zone'));
        $classes('filter[search]=airport')->assertInertia(fn (Assert $p) => $p->where('rules.total', 1));
        $classes('filter[search]=DT')->assertInertia(fn (Assert $p) => $p->where('rules.total', 3));
        $classes('filter[search]=%25')->assertInertia(fn (Assert $p) => $p->where('rules.total', 0));
    }

    public function test_the_list_sorts_by_zone_and_start_and_rejects_unknown_filters(): void
    {
        $other = Zone::factory()->create(['name' => 'Airport Zone']);
        $this->save();
        $this->actingAs($this->admin)->post('/admin/commission-rules', $this->payload(['zone_id' => $other->id]));

        $this->actingAs($this->admin)->get('/admin/commission-rules?sort=zone')->assertInertia(fn (Assert $p) => $p->where('rules.data.0.zone', 'Airport Zone'));
        $this->actingAs($this->admin)->get('/admin/commission-rules?sort=-zone')->assertInertia(fn (Assert $p) => $p->where('rules.data.0.zone', 'Downtown Core'));
        $this->actingAs($this->admin)->get('/admin/commission-rules?sort=effective_from')->assertOk();
        $this->actingAs($this->admin)->get('/admin/commission-rules?sort=rate_bps')->assertStatus(400);
        $this->actingAs($this->admin)->get('/admin/commission-rules?filter[secret]=1')->assertStatus(400);
        $this->actingAs($this->admin)->get('/admin/commission-rules?filter[vehicle_class]=limousine')->assertStatus(400);
        $this->actingAs($this->admin)->get('/admin/commission-rules?filter[zone]=abc')->assertStatus(400);
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 12) as $i) {
            CommissionRule::factory()->forZone(Zone::factory()->create())->create();
        }

        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertInertia(fn (Assert $p) => $p->where('rules.total', 12)->has('rules.data', 10));
        $this->actingAs($this->admin)->get('/admin/commission-rules?page=2')->assertInertia(fn (Assert $p) => $p->has('rules.data', 2));
    }

    public function test_an_empty_system_renders_with_nothing_to_preview(): void
    {
        $this->actingAs($this->admin(AdminRole::SuperAdmin))->get('/admin/commission-rules')->assertInertia(fn (Assert $p) => $p
            ->where('rules.total', 0)->where('previewRule', null)->where('previewRules', []));
    }

    public function test_the_page_does_not_issue_more_queries_as_rules_are_added(): void
    {
        CommissionRule::factory()->forZone(Zone::factory()->create())->create();
        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertOk(); // warms the permission cache
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 6) as $i) {
            CommissionRule::factory()->forZone(Zone::factory()->create())->create();
        }
        DB::flushQueryLog();
        $this->actingAs($this->admin)->get('/admin/commission-rules')->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()), 'no query per rule');
    }

    // ---- who may ----

    #[DataProvider('rolesWithoutFares')]
    public function test_roles_without_fares_manage_get_403_on_the_page_and_the_save(AdminRole $role): void
    {
        $user = $this->admin($role);

        $this->actingAs($user)->get('/admin/commission-rules')->assertForbidden();
        $this->save([], $user)->assertForbidden();

        $this->assertSame(0, CommissionRule::count());
        $this->assertSame(0, ActivityLogEntry::query()->where('description', 'like', 'commission_rule.%')->count());
    }

    /**
     * @return array<string, array{AdminRole}>
     */
    public static function rolesWithoutFares(): array
    {
        return ['support' => [AdminRole::Support], 'finance' => [AdminRole::Finance], 'dispatcher' => [AdminRole::Dispatcher]];
    }

    public function test_super_admin_may_save_and_guests_are_sent_to_login(): void
    {
        $this->save([], $this->admin(AdminRole::SuperAdmin))->assertSessionHasNoErrors();
        $this->assertSame(1, CommissionRule::count());

        auth()->logout();
        $this->get('/admin/commission-rules')->assertRedirect('/login');
        $this->post('/admin/commission-rules', $this->payload(['rate_bps' => 999]))->assertRedirect('/login');
        $this->assertSame(1, CommissionRule::count());
    }

    public function test_a_save_is_audited_with_the_actor(): void
    {
        $this->save();

        $entry = ActivityLogEntry::query()->where('description', 'commission_rule.created')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame('economy', $entry->properties['context']['vehicle_class']);
        $this->assertSame('DT', $entry->properties['context']['zone']);
    }
}
