<?php

namespace Tests\Feature\Fares;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\FareRule;
use App\Models\User;
use App\Models\Zone;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Admin\AdminTestCase;

class FareRuleAdminTest extends AdminTestCase
{
    private User $admin;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(AdminRole::Admin);
        $this->zone = Zone::factory()->create(['code' => 'YER']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'vehicle_class' => 'economy', 'base_fare' => 500, 'per_km' => 150, 'per_minute' => 20, 'min_fare' => 800,
            'waiting_free_minutes' => 3, 'waiting_per_minute' => 30, 'cancellation_fee' => 500, 'cancellation_free_seconds' => 120,
            'booking_fee' => 0, 'tax_rate_bps' => 0, 'tax_inclusive' => false,
            ...$overrides,
        ];
    }

    private function save(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post("/admin/zones/{$this->zone->id}/fare-rules", $this->payload($overrides));
    }

    // ---- saving always adds a version ----

    public function test_saving_creates_a_rule_and_a_second_save_with_new_prices_adds_a_version(): void
    {
        $this->save()->assertRedirect()->assertSessionHas('success', 'Fare rule saved. The new prices apply from now.');
        $this->assertSame(1, FareRule::count());

        $this->travel(5)->minutes();
        $this->save(['base_fare' => 650, 'tax_rate_bps' => 1800])->assertSessionHas('success', 'Fare rule saved. The new prices apply from now.');

        $this->assertSame(2, FareRule::count(), 'never an UPDATE of the old row');
        $old = FareRule::query()->orderBy('id')->first();
        $new = FareRule::query()->orderByDesc('id')->first();
        $this->assertSame(500, $old->base_fare);
        $this->assertNotNull($old->effective_to);
        $this->assertSame(650, $new->base_fare);
        $this->assertSame(1800, $new->tax_rate_bps);
        $this->assertNull($new->effective_to);
        $this->assertSame($this->admin->id, $new->created_by);
    }

    public function test_saving_the_same_prices_twice_says_nothing_changed(): void
    {
        $this->save();
        $this->save()->assertSessionHas('success', 'Nothing changed: these prices are already in force.');

        $this->assertSame(1, FareRule::count());
        $this->assertSame(1, ActivityLogEntry::query()->where('description', 'like', 'fare_rule.%')->count());
    }

    public function test_the_currency_is_the_zones_and_cannot_be_chosen_by_the_client(): void
    {
        $this->save(['currency' => 'USD', 'zone_id' => 99999, 'created_by' => 1]);

        $rule = FareRule::query()->sole();
        $this->assertSame('AMD', $rule->currency);
        $this->assertSame($this->zone->id, $rule->zone_id);
        $this->assertSame($this->admin->id, $rule->created_by);
    }

    public function test_saving_for_an_unknown_zone_is_a_404_and_saves_nothing(): void
    {
        $this->actingAs($this->admin)->post('/admin/zones/999999/fare-rules', $this->payload())->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/zones/abc/fare-rules', $this->payload())->assertNotFound();

        $this->assertSame(0, FareRule::count());
    }

    // ---- validation: nothing is saved ----

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidFields(): array
    {
        return [
            'vehicle class missing' => ['vehicle_class', null],
            'vehicle class unknown' => ['vehicle_class', 'limousine'],
            'base fare missing' => ['base_fare', null],
            'base fare negative' => ['base_fare', -1],
            'base fare with decimals' => ['base_fare', 12.5],
            'base fare text' => ['base_fare', 'abc'],
            'base fare too large' => ['base_fare', 1_000_000_000_001],
            'per km negative' => ['per_km', -1],
            'per minute negative' => ['per_minute', -1],
            'minimum negative' => ['min_fare', -1],
            'waiting rate negative' => ['waiting_per_minute', -1],
            'free waiting minutes too long' => ['waiting_free_minutes', 241],
            'free waiting minutes negative' => ['waiting_free_minutes', -1],
            'cancellation fee negative' => ['cancellation_fee', -1],
            'cancellation window too long' => ['cancellation_free_seconds', 86_401],
            'booking fee negative' => ['booking_fee', -1],
            'tax over 100 percent' => ['tax_rate_bps', 10_001],
            'tax negative' => ['tax_rate_bps', -1],
            'tax inclusive missing' => ['tax_inclusive', null],
            'tax inclusive not a boolean' => ['tax_inclusive', 'maybe'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_input_shows_an_error_on_the_field_and_saves_nothing(string $field, mixed $value): void
    {
        $payload = $this->payload();
        $value === null ? $payload[$field] = null : $payload[$field] = $value;

        $this->actingAs($this->admin)->post("/admin/zones/{$this->zone->id}/fare-rules", $payload)->assertSessionHasErrors($field);

        $this->assertSame(0, FareRule::count());
        $this->assertSame(0, ActivityLogEntry::query()->where('description', 'like', 'fare_rule.%')->count());
    }

    public function test_boundary_values_are_accepted(): void
    {
        $this->save(['base_fare' => 0, 'per_km' => 0, 'per_minute' => 0, 'min_fare' => 0, 'waiting_free_minutes' => 0, 'waiting_per_minute' => 0, 'cancellation_fee' => 0, 'cancellation_free_seconds' => 0, 'booking_fee' => 0, 'tax_rate_bps' => 0])->assertSessionHasNoErrors();
        $this->travel(5)->minutes();
        $this->save(['vehicle_class' => 'business', 'base_fare' => 1_000_000_000_000, 'waiting_free_minutes' => 240, 'cancellation_free_seconds' => 86_400, 'tax_rate_bps' => 10_000, 'tax_inclusive' => true])->assertSessionHasNoErrors();

        $this->assertSame(2, FareRule::count());
    }

    // ---- the Zones & Fares page ----

    public function test_the_page_lists_the_prices_in_force_with_their_period_and_the_save_action(): void
    {
        $this->save(['base_fare' => 500]);
        $this->travel(5)->minutes();
        $this->save(['base_fare' => 650, 'vehicle_class' => 'economy']);
        $this->save(['vehicle_class' => 'comfort', 'base_fare' => 900]);

        $this->actingAs($this->admin)->get('/admin/zones')->assertInertia(fn (Assert $page) => $page
            ->component('Zones/Index')
            ->has('fareRules', 2)
            ->where('fareRules', fn ($rules) => collect($rules)->pluck('base_fare.amount', 'vehicle_class')->all() === ['economy' => 650, 'comfort' => 900])
            ->where('fareRules.0.base_fare', ['amount' => 650, 'currency' => 'AMD'])
            ->where('fareRules.0.effective_to', null)
            ->has('fareRules.0.effective_from')
            ->where('fareRules.0.created_by', null, 'the creator is only loaded with the history')
            ->where('fareHistory', null)
            ->where('vehicleClasses', ['economy', 'comfort', 'business'])
            ->where('actions.saveFareRule', '/admin/zones/{id}/fare-rules'));
    }

    public function test_the_history_view_lists_every_version_of_a_class_newest_first_with_who_saved_it(): void
    {
        $this->save(['base_fare' => 500]);
        $this->travel(5)->minutes();
        $this->save(['base_fare' => 650]);
        $this->save(['vehicle_class' => 'comfort', 'base_fare' => 900]);

        $this->actingAs($this->admin)->get('/admin/zones?history=economy')->assertInertia(fn (Assert $page) => $page
            ->where('fareHistory.vehicle_class', 'economy')
            ->has('fareHistory.versions', 2)
            ->where('fareHistory.versions.0.base_fare.amount', 650)
            ->where('fareHistory.versions.0.effective_to', null)
            ->where('fareHistory.versions.0.created_by', $this->admin->name)
            ->where('fareHistory.versions.1.base_fare.amount', 500)
            ->whereNot('fareHistory.versions.1.effective_to', null));

        $this->actingAs($this->admin)->get('/admin/zones?history=limousine')->assertInertia(fn (Assert $page) => $page->where('fareHistory', null));
    }

    public function test_the_prices_of_another_zone_are_not_shown(): void
    {
        $other = Zone::factory()->priority(1)->create();
        FareRule::factory()->forZone($other)->create(['base_fare' => 123]);
        $this->save(['base_fare' => 500]);

        $this->actingAs($this->admin)->get("/admin/zones?zone={$this->zone->id}")->assertInertia(fn (Assert $page) => $page
            ->has('fareRules', 1)->where('fareRules.0.base_fare.amount', 500));
        $this->actingAs($this->admin)->get("/admin/zones?zone={$other->id}&history=economy")->assertInertia(fn (Assert $page) => $page
            ->has('fareRules', 1)->where('fareRules.0.base_fare.amount', 123)->has('fareHistory.versions', 1));
    }

    // ---- who may ----

    public function test_a_zone_manager_without_fares_manage_sees_the_prices_but_has_no_save_action_and_gets_403(): void
    {
        $manager = User::factory()->admin(AdminRole::Support)->create();
        $manager->givePermissionTo(Permission::findByName('zones.manage'));
        FareRule::factory()->forZone($this->zone)->create();

        $this->actingAs($manager)->get('/admin/zones')->assertInertia(fn (Assert $page) => $page->has('fareRules', 1)->where('actions.saveFareRule', null));
        $this->save([], $manager)->assertForbidden();

        $this->assertSame(1, FareRule::count());
    }

    #[DataProvider('rolesWithoutFares')]
    public function test_roles_without_fares_manage_get_403_and_nothing_is_saved(AdminRole $role): void
    {
        $this->save([], $this->admin($role))->assertForbidden();

        $this->assertSame(0, FareRule::count());
        $this->assertSame(0, ActivityLogEntry::query()->where('description', 'like', 'fare_rule.%')->count());
    }

    /**
     * @return array<string, array{AdminRole}>
     */
    public static function rolesWithoutFares(): array
    {
        return [
            'support' => [AdminRole::Support],
            'finance' => [AdminRole::Finance],
            'dispatcher' => [AdminRole::Dispatcher],
        ];
    }

    public function test_super_admin_may_save_and_guests_are_sent_to_login(): void
    {
        $this->save([], $this->admin(AdminRole::SuperAdmin))->assertSessionHasNoErrors();
        $this->assertSame(1, FareRule::count());

        auth()->logout();
        $this->post("/admin/zones/{$this->zone->id}/fare-rules", $this->payload(['base_fare' => 999]))->assertRedirect('/login');
        $this->assertSame(1, FareRule::count());
    }

    public function test_a_save_is_audited_with_the_actor(): void
    {
        $this->save(['base_fare' => 500]);

        $entry = ActivityLogEntry::query()->where('description', 'fare_rule.created')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame('economy', $entry->properties['context']['vehicle_class']);
        $this->assertSame('YER', $entry->properties['context']['zone']);
    }
}
