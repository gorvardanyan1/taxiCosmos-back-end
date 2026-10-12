<?php

namespace Tests\Feature\Drivers;

use App\Enums\AdminRole;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

/**
 * GET /admin/drivers: a real paginated list with the URL contract every admin list follows.
 */
class DriverListTest extends AdminTestCase
{
    private function driver(array $user = [], array $profile = []): DriverProfile
    {
        return DriverProfile::factory()->for(User::factory()->driver()->withPhone()->create($user), 'user')->create($profile);
    }

    private function list(string $query = '', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin(AdminRole::Support))->get('/admin/drivers'.$query);
    }

    public function test_the_list_shows_each_driver_with_vehicle_status_rating_trips_and_earnings(): void
    {
        $zone = Zone::factory()->create(['currency' => 'AMD']);
        $driver = $this->driver(['name' => 'Gor Hakobyan', 'phone' => '+37491440221'], ['rating_avg' => 4.5, 'home_zone_id' => $zone->id]);
        $driver->forceFill(['verification_status' => 'approved', 'availability' => 'online'])->save();
        Vehicle::factory()->primary()->active()->for($driver, 'driver')->create(['make' => 'Toyota', 'model' => 'Camry', 'year' => 2020]);
        Vehicle::factory()->active()->for($driver, 'driver')->create(['make' => 'Kia', 'model' => 'Forte']);
        Trip::factory()->completed()->create(['driver_id' => $driver->id, 'zone_id' => $zone->id, 'driver_earnings_amount' => 120000]);
        Trip::factory()->completed()->create(['driver_id' => $driver->id, 'zone_id' => $zone->id, 'driver_earnings_amount' => 80000, 'commission_amount' => 20000, 'fare_amount' => 100000]);

        $this->list()->assertInertia(fn (Assert $page) => $page
            ->component('Drivers/Index')
            ->where('drivers.total', 1)
            ->where('drivers.data.0', [
                'id' => $driver->id, 'code' => sprintf('D-%04d', $driver->id), 'name' => 'Gor Hakobyan', 'phone' => '+37491440221',
                'vehicle' => ['label' => 'Toyota Camry', 'year' => 2020], 'verification_status' => 'approved', 'availability' => 'online', 'status' => 'active',
                'rating' => '4.50', 'trips_count' => 2, 'earnings' => ['amount' => 200000, 'currency' => 'AMD'],
            ])
            ->where('totalRegistered', 1));
    }

    public function test_trips_and_earnings_count_only_the_drivers_own_completed_trips_in_their_currency(): void
    {
        $zone = Zone::factory()->create(['currency' => 'AMD']);
        $driver = $this->driver([], ['home_zone_id' => $zone->id]);
        $other = $this->driver();
        Trip::factory()->completed()->create(['driver_id' => $driver->id, 'zone_id' => $zone->id, 'driver_earnings_amount' => 100000]);
        Trip::factory()->completed()->create(['driver_id' => $driver->id, 'zone_id' => $zone->id, 'fare_currency' => 'USD', 'driver_earnings_amount' => 5000]);
        Trip::factory()->inProgress()->create(['driver_id' => $driver->id, 'zone_id' => $zone->id]);
        Trip::factory()->cancelled()->create(['driver_id' => $driver->id, 'zone_id' => $zone->id]);
        Trip::factory()->completed()->create(['driver_id' => $other->id, 'zone_id' => $zone->id, 'driver_earnings_amount' => 999]);

        $this->list('?filter[search]='.sprintf('D-%04d', $driver->id))->assertInertia(fn (Assert $page) => $page
            ->where('drivers.data.0.trips_count', 2)
            ->where('drivers.data.0.earnings', ['amount' => 100000, 'currency' => 'AMD']));
    }

    public function test_a_driver_without_a_home_zone_earns_in_the_base_currency(): void
    {
        $driver = $this->driver();

        $this->list()->assertInertia(fn (Assert $page) => $page->where('drivers.data.0.earnings', ['amount' => 0, 'currency' => 'AMD'])->where('drivers.data.0.vehicle', null));
    }

    public function test_a_deleted_account_shows_a_placeholder_and_no_personal_data(): void
    {
        $driver = $this->driver(['name' => 'Gone Person']);
        $driver->user->delete();

        $this->list()->assertInertia(fn (Assert $page) => $page->where('drivers.data.0.name', 'Deleted account')->where('drivers.data.0.phone', '—'));
    }

    // ---- paging, filters, sorts ----

    public function test_the_list_is_paginated_newest_first_and_keeps_filters_in_the_links(): void
    {
        foreach (range(1, 12) as $i) {
            $this->driver();
        }
        $last = DriverProfile::query()->max('id');

        $this->list()->assertInertia(fn (Assert $page) => $page->where('drivers.total', 12)->has('drivers.data', 10)->where('drivers.data.0.id', $last));
        $this->list('?page=2')->assertInertia(fn (Assert $page) => $page->has('drivers.data', 2));
        $this->list('?per_page=25')->assertInertia(fn (Assert $page) => $page->has('drivers.data', 12));
    }

    public function test_search_matches_name_email_code_exact_phone_and_exact_license_ignoring_case_and_spaces(): void
    {
        $gor = $this->driver(['name' => 'Gor Hakobyan', 'email' => 'gor@example.com', 'phone' => '+37491440221'], ['license_number' => 'AM-DL 4242']);
        $this->driver(['name' => 'Ana Petrosyan', 'email' => 'ana@example.com', 'phone' => '+37499000111'], ['license_number' => 'AM-DL 9999']);
        $ids = fn (string $search) => $this->list('?filter[search]='.urlencode($search))->viewData('page')['props']['drivers']['data'];

        $this->assertSame([$gor->id], array_column($ids('hakob'), 'id'), 'name, case-insensitive');
        $this->assertSame([$gor->id], array_column($ids('GOR@EXAMPLE'), 'id'), 'email');
        $this->assertSame([$gor->id], array_column($ids(sprintf('D-%04d', $gor->id)), 'id'), 'code');
        $this->assertSame([$gor->id], array_column($ids((string) $gor->id), 'id'), 'bare id');
        $this->assertSame([$gor->id], array_column($ids('091 440 221'), 'id'), 'phone written another way (exact, through the blind index)');
        $this->assertSame([$gor->id], array_column($ids(' am-dl4242 '), 'id'), 'exact licence, case and spaces ignored');
        $this->assertSame([], $ids('am-dl42'), 'a licence is matched exactly, never partially');
        $this->assertSame([], $ids('+37491440'), 'a partial phone matches nothing');
        $this->assertSame([], $ids('%'), 'wildcards are literal');
    }

    public function test_filters_by_verification_status_availability_zone_and_vehicle_class(): void
    {
        $zone = Zone::factory()->create();
        $a = $this->driver([], ['home_zone_id' => $zone->id]);
        $a->forceFill(['verification_status' => 'approved', 'availability' => 'online'])->save();
        Vehicle::factory()->for($a, 'driver')->create(['vehicle_class' => 'comfort']);
        $b = $this->driver();
        $b->forceFill(['verification_status' => 'rejected'])->save();
        Vehicle::factory()->for($b, 'driver')->create(['vehicle_class' => 'economy']);
        $c = $this->driver();
        $ids = fn (string $q) => array_column($this->list($q)->viewData('page')['props']['drivers']['data'], 'id');

        $this->assertSame([$a->id], $ids('?filter[verification_status]=approved'));
        $this->assertSame([$b->id], $ids('?filter[verification_status]=rejected'));
        $this->assertSame([$a->id], $ids('?filter[availability]=online'));
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $ids('?filter[availability]=offline'));
        $this->assertSame([$a->id], $ids('?filter[zone]='.$zone->id));
        $this->assertSame([$a->id], $ids('?filter[vehicle_class]=comfort'));
        $this->assertSame([$b->id], $ids('?filter[vehicle_class]=economy'));
        $this->assertSame([$a->id], $ids('?filter[vehicle_class]=comfort&filter[availability]=online&filter[zone]='.$zone->id), 'filters combine');
        $this->assertSame([], $ids('?filter[vehicle_class]=business'));
    }

    public function test_a_removed_vehicle_does_not_count_for_the_vehicle_class_filter(): void
    {
        $driver = $this->driver();
        Vehicle::factory()->for($driver, 'driver')->create(['vehicle_class' => 'business'])->delete();

        $this->list('?filter[vehicle_class]=business')->assertInertia(fn (Assert $page) => $page->where('drivers.total', 0));
    }

    public function test_the_exact_license_filter_uses_the_blind_index(): void
    {
        $gor = $this->driver([], ['license_number' => 'AM-DL 4242']);
        $this->driver([], ['license_number' => 'AM-DL 42420']);

        $this->list('?filter[license_number]='.urlencode('am-dl4242'))->assertInertia(fn (Assert $page) => $page->where('drivers.total', 1)->where('drivers.data.0.id', $gor->id));
        $this->list('?filter[license_number]=am-dl')->assertInertia(fn (Assert $page) => $page->where('drivers.total', 0));
    }

    public function test_documents_expiring_within_n_days_includes_the_boundary_and_excludes_past_and_later_dates(): void
    {
        $this->travelTo('2026-10-12 09:00:00');
        $soon = $this->driver();
        DriverDocument::factory()->for($soon, 'driver')->expiring('2026-10-19')->create();
        $edge = $this->driver();
        DriverDocument::factory()->for($edge, 'driver')->expiring('2026-10-26')->create();
        $later = $this->driver();
        DriverDocument::factory()->for($later, 'driver')->expiring('2026-10-27')->create();
        $expired = $this->driver();
        DriverDocument::factory()->for($expired, 'driver')->expiring('2026-10-11')->create();
        $none = $this->driver();
        $ids = fn (int $days) => array_column($this->list("?filter[documents_expiring]={$days}")->viewData('page')['props']['drivers']['data'], 'id');

        $this->assertEqualsCanonicalizing([$soon->id], $ids(7));
        $this->assertEqualsCanonicalizing([$soon->id, $edge->id], $ids(14), 'a document expiring exactly on the last day counts');
        $this->assertEqualsCanonicalizing([$soon->id, $edge->id, $later->id], $ids(15));
        $this->assertNotContains($expired->id, $ids(365), 'an already expired document is not "expiring"');
        $this->assertNotContains($none->id, $ids(365));
    }

    public function test_sorting_by_name_trips_and_rating_in_both_directions(): void
    {
        $zone = Zone::factory()->create();
        $ana = $this->driver(['name' => 'Ana'], ['rating_avg' => 3.0]);
        $bob = $this->driver(['name' => 'Bob'], ['rating_avg' => 5.0]);
        $cat = $this->driver(['name' => 'Cat']);
        Trip::factory()->completed()->count(2)->create(['driver_id' => $bob->id, 'zone_id' => $zone->id]);
        Trip::factory()->completed()->create(['driver_id' => $ana->id, 'zone_id' => $zone->id]);
        $order = fn (string $sort) => array_column($this->list("?sort={$sort}")->viewData('page')['props']['drivers']['data'], 'name');

        $this->assertSame(['Ana', 'Bob', 'Cat'], $order('name'));
        $this->assertSame(['Cat', 'Bob', 'Ana'], $order('-name'));
        $this->assertSame(['Cat', 'Ana', 'Bob'], $order('trips_count'));
        $this->assertSame(['Bob', 'Ana', 'Cat'], $order('-trips_count'));
        $this->assertSame(['Ana', 'Bob', 'Cat'], $order('rating'), 'unrated drivers go last either way');
        $this->assertSame(['Bob', 'Ana', 'Cat'], $order('-rating'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badUrls(): array
    {
        return [
            'unknown filter' => ['?filter[secret]=1'],
            'unknown sort' => ['?sort=license_number'],
            'bad verification status' => ['?filter[verification_status]=nope'],
            'bad availability' => ['?filter[availability]=asleep'],
            'bad vehicle class' => ['?filter[vehicle_class]=limousine'],
            'zone not a number' => ['?filter[zone]=abc'],
            'zone array' => ['?filter[zone][]=1&filter[zone][]=2'],
            'expiry not a number' => ['?filter[documents_expiring]=soon'],
            'expiry zero' => ['?filter[documents_expiring]=0'],
            'expiry too large' => ['?filter[documents_expiring]=366'],
            'license array' => ['?filter[license_number][]=a&filter[license_number][]=b'],
        ];
    }

    #[DataProvider('badUrls')]
    public function test_malformed_filters_and_sorts_are_a_400(string $query): void
    {
        $this->list($query)->assertStatus(400);
    }

    public function test_the_boundary_expiry_values_are_accepted(): void
    {
        $this->list('?filter[documents_expiring]=1')->assertOk();
        $this->list('?filter[documents_expiring]=365')->assertOk();
    }

    public function test_the_page_sends_the_filter_choices_and_echoes_the_active_filters(): void
    {
        Zone::factory()->create(['name' => 'Yerevan']);

        $this->list('?filter[availability]=online&filter[zone]=9')->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['availability' => 'online', 'zone' => '9'])
            ->where('verificationStatuses', ['pending', 'approved', 'rejected', 'expired'])
            ->where('availabilities', ['offline', 'online', 'on_trip'])
            ->where('vehicleClasses', ['economy', 'comfort', 'business'])
            ->where('zones.0.name', 'Yerevan'));
    }

    public function test_the_list_issues_the_same_queries_however_many_drivers_it_shows(): void
    {
        $this->driver();
        $admin = $this->admin(AdminRole::Support);
        $this->actingAs($admin)->get('/admin/drivers')->assertOk(); // warms the permission cache
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/admin/drivers')->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 8) as $i) {
            $driver = $this->driver();
            Vehicle::factory()->primary()->active()->for($driver, 'driver')->create();
        }
        DB::flushQueryLog();
        $this->actingAs($admin)->get('/admin/drivers')->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()), 'no query per driver');
    }

    // ---- who may ----

    public function test_every_admin_role_may_view_the_list_but_actions_follow_their_permissions(): void
    {
        $this->driver();

        $this->list('', $this->admin(AdminRole::Support))->assertInertia(fn (Assert $page) => $page->where('actions.suspend', '/admin/drivers/{id}/suspend')->where('actions.reactivate', '/admin/drivers/{id}/reactivate'));
        $this->list('', $this->admin(AdminRole::Finance))->assertInertia(fn (Assert $page) => $page->where('actions.suspend', null)->where('actions.reactivate', null));
        $this->list('', $this->admin(AdminRole::Dispatcher))->assertOk();
    }

    public function test_guests_and_non_admin_accounts_are_sent_to_login(): void
    {
        auth()->logout();
        $this->call('GET', '/admin/drivers')->assertRedirect('/login');

        $this->actingAs(User::factory()->rider()->create())->get('/admin/drivers')->assertRedirect('/login');
    }
}
