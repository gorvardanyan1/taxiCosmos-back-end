<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\DriverVerificationStatus;
use App\Models\DriverProfile;
use App\Services\Admin\NavigationBadges;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * P13-T2: the sidebar counts are real, cached for 60 seconds, and only sent to admins whose permissions open the page.
 */
class NavigationBadgesTest extends AdminTestCase
{
    private function drivers(int $pending, int $approved = 0): void
    {
        DriverProfile::factory()->count($pending)->create(['verification_status' => DriverVerificationStatus::Pending]);
        DriverProfile::factory()->count($approved)->create(['verification_status' => DriverVerificationStatus::Approved]);
    }

    private function badgesSeenBy(AdminRole $role): array
    {
        $badges = null;
        $this->actingAs($this->admin($role))->get('/admin')->assertInertia(function (Assert $page) use (&$badges) {
            $badges = $page->toArray()['props']['navigation']['badges'];
        });

        return $badges;
    }

    public function test_the_drivers_badge_is_the_number_of_pending_verifications(): void
    {
        $this->drivers(pending: 3, approved: 2);
        DriverProfile::factory()->create(['verification_status' => DriverVerificationStatus::Rejected]);

        $this->assertSame(['drivers' => 3], $this->badgesSeenBy(AdminRole::Support));
    }

    public function test_a_zero_count_sends_no_badge_at_all(): void
    {
        $this->drivers(pending: 0, approved: 4);

        $this->assertSame([], $this->badgesSeenBy(AdminRole::Support));
    }

    public function test_a_badge_goes_only_to_admins_who_may_open_its_page(): void
    {
        $this->drivers(pending: 5);
        Role::findByName(AdminRole::Dispatcher->value)->revokePermissionTo('drivers.view');

        $this->assertSame(['drivers' => 5], $this->badgesSeenBy(AdminRole::Support));
        $this->assertSame(['drivers' => 5], $this->badgesSeenBy(AdminRole::SuperAdmin));
        $this->assertSame([], $this->badgesSeenBy(AdminRole::Dispatcher), 'a role without drivers.view must not learn how many drivers wait');
    }

    public function test_the_counts_are_cached_for_sixty_seconds_then_recomputed(): void
    {
        $this->travelTo('2026-10-13 10:00:00');
        $this->drivers(pending: 2);
        $badges = app(NavigationBadges::class);
        $admin = $this->admin(AdminRole::Support);
        $this->assertSame(['drivers' => 2], $badges->forAdmin($admin));

        $this->drivers(pending: 4); // six now
        $this->travelTo('2026-10-13 10:00:59');
        $this->assertSame(['drivers' => 2], $badges->forAdmin($admin), 'within the minute the cached count is served');

        $this->travelTo('2026-10-13 10:01:01');
        $this->assertSame(['drivers' => 6], $badges->forAdmin($admin), 'after the minute it is counted again');
    }

    public function test_a_cached_count_costs_no_query_and_is_shared_by_every_admin(): void
    {
        $this->drivers(pending: 1);
        $badges = app(NavigationBadges::class);
        $badges->forAdmin($this->admin(AdminRole::Support));
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'driver_profiles')) {
                $queries++;
            }
        });

        $badges->forAdmin($this->admin(AdminRole::Finance));
        $badges->forAdmin($this->admin(AdminRole::Dispatcher));

        $this->assertSame(0, $queries);
        $this->assertSame(['drivers' => 1, 'support-tickets' => 0, 'transactions' => 0, 'payouts' => 0], Cache::get(NavigationBadges::CACHE_KEY));
    }

    public function test_the_ticket_payment_and_payout_badges_stay_empty_until_their_tables_exist(): void
    {
        $this->assertSame(['drivers' => 0, 'support-tickets' => 0, 'transactions' => 0, 'payouts' => 0], app(NavigationBadges::class)->counts());
    }

    public function test_the_queue_monitor_link_follows_the_horizon_gate(): void
    {
        foreach ([AdminRole::SuperAdmin->value => true, AdminRole::Admin->value => true, AdminRole::Support->value => false, AdminRole::Finance->value => false, AdminRole::Dispatcher->value => false] as $role => $expected) {
            $this->actingAs($this->admin(AdminRole::from($role)))->get('/admin')
                ->assertInertia(fn (Assert $page) => $page->where('navigation.queueMonitor', $expected));
        }
    }

    public function test_guests_get_no_navigation_and_so_no_counts(): void
    {
        $this->drivers(pending: 9);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('navigation', null));
    }
}
