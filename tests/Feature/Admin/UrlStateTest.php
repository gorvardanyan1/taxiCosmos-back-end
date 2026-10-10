<?php

namespace Tests\Feature\Admin;

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Filters, sorts, pages, tabs and selections are read from the URL, so a deep link (or
 * browser back/forward, which replays the URL) restores exactly the same view.
 */
class UrlStateTest extends AdminTestCase
{
    public function test_a_status_filter_deep_link_returns_only_matching_rows_and_echoes_the_filter(): void
    {
        $this->actingAs($this->admin())->get('/admin/riders?filter[status]=suspended')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['status' => 'suspended'])
                ->where('riders.total', 2)
                ->where('riders.data.0.status', 'suspended')
                ->where('riders.data.1.status', 'suspended'));
    }

    public function test_search_matches_name_phone_email_and_code_case_insensitively(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/riders?filter[search]=SUN')
            ->assertInertia(fn (Assert $page) => $page->where('riders.total', 1)->where('riders.data.0.name', 'Sun Li')->where('filters.search', 'SUN'));
        $this->actingAs($admin)->get('/admin/riders?filter[search]=R-10474')
            ->assertInertia(fn (Assert $page) => $page->where('riders.total', 1)->where('riders.data.0.name', 'Oliver Wang'));
        $this->actingAs($admin)->get('/admin/riders?filter[search]=no-such-rider')
            ->assertInertia(fn (Assert $page) => $page->where('riders.total', 0)->has('riders.data', 0));
    }

    public function test_search_and_filter_combine(): void
    {
        $this->actingAs($this->admin())->get('/admin/trips?filter[status]=cancelled&filter[search]=lusine')
            ->assertInertia(fn (Assert $page) => $page->where('trips.total', 1)->where('trips.data.0.code', 'TK-4H1Q9'));
    }

    public function test_page_two_deep_link_and_pagination_links_keep_the_query(): void
    {
        $this->actingAs($this->admin())->get('/admin/riders?page=2&sort=name')
            ->assertInertia(fn (Assert $page) => $page
                ->where('riders.current_page', 2)
                ->where('riders.from', 11)
                ->where('riders.to', 12)
                ->has('riders.data', 2)
                ->where('riders.data.0.name', 'Priya Mehta')
                ->where('riders.links.1.url', fn (string $url) => str_contains($url, 'sort=name') && str_contains($url, 'page=1')));
    }

    public function test_sort_ascending_and_descending(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/riders?sort=trips_count')
            ->assertInertia(fn (Assert $page) => $page->where('riders.data.0.trips_count', 5)->where('sort', 'trips_count'));
        $this->actingAs($admin)->get('/admin/riders?sort=-trips_count')
            ->assertInertia(fn (Assert $page) => $page->where('riders.data.0.trips_count', 502)->where('sort', '-trips_count'));
        // Numeric sort handles negative money amounts (not string order).
        $this->actingAs($admin)->get('/admin/driver-balances?sort=-balance.amount')
            ->assertInertia(fn (Assert $page) => $page->where('balances.data.0.balance.amount', 84200)->where('balances.data.2.balance.amount', -48000));
    }

    public function test_per_page_is_limited_to_the_configured_options(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/riders?per_page=25')->assertInertia(fn (Assert $page) => $page->where('riders.per_page', 25)->has('riders.data', 12));
        $this->actingAs($admin)->get('/admin/riders?per_page=100000')->assertInertia(fn (Assert $page) => $page->where('riders.per_page', 10));
    }

    public function test_unknown_filters_and_sorts_are_rejected_like_the_query_builder(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/riders?filter[password]=x')->assertStatus(400);
        $this->actingAs($admin)->get('/admin/riders?sort=email')->assertStatus(400);
        $this->actingAs($admin)->get('/admin/riders?filter[status][]=active')->assertStatus(400);
        $this->actingAs($admin)->get('/admin/riders?filter=oops')->assertStatus(400);
    }

    public function test_detail_tabs_are_deep_linkable_and_fall_back_to_the_first_tab(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/drivers/8?tab=documents')->assertInertia(fn (Assert $page) => $page->where('tab', 'documents'));
        $this->actingAs($admin)->get('/admin/drivers/8?tab=../../etc')->assertInertia(fn (Assert $page) => $page->where('tab', 'profile'));
        $this->actingAs($admin)->get('/admin/riders/80?tab=payment-methods')->assertInertia(fn (Assert $page) => $page->where('tab', 'payment-methods'));
        $this->actingAs($admin)->get('/admin/account?tab=security')->assertInertia(fn (Assert $page) => $page->where('tab', 'security'));
    }

    public function test_selections_in_the_url_open_the_matching_record(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/transactions?txn=6')
            ->assertInertia(fn (Assert $page) => $page->where('selected.code', 'TXN-104876')->where('selected.failure_reason', 'do_not_honor'));
        $this->actingAs($admin)->get('/admin/transactions?txn=999')->assertInertia(fn (Assert $page) => $page->where('selected', null));
        $this->actingAs($admin)->get('/admin/support-tickets?ticket=2')->assertInertia(fn (Assert $page) => $page->where('selected.code', 'TKT-2939'));
        $this->actingAs($admin)->get('/admin/activity-logs?entry=2')->assertInertia(fn (Assert $page) => $page->where('expandedId', 2));
        $this->actingAs($admin)->get('/admin/zones?zone=2')
            ->assertInertia(fn (Assert $page) => $page->where('selectedZoneId', 2)->has('fareRules', 2)->where('fareRules.0.zone_id', 2)->where('fareRules.1.zone_id', 2));
        $this->actingAs($admin)->get('/admin/zones?zone=99')->assertInertia(fn (Assert $page) => $page->where('selectedZoneId', 1));
    }

    public function test_report_builder_state_comes_from_the_url_and_invalid_values_fall_back(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/reports?metric=trips&from=2026-08-10&to=2026-08-20&group=day&zone=2&vehicle_class=comfort&currency=USD')
            ->assertInertia(fn (Assert $page) => $page->where('filters', [
                'metric' => 'trips', 'from' => '2026-08-10', 'to' => '2026-08-20', 'zone' => 2,
                'vehicle_class' => 'comfort', 'group' => 'day', 'currency' => 'USD',
            ]));

        $this->actingAs($admin)->get('/admin/reports?metric=drop_tables&from=2026-13-45&group=decade&currency=XXX&vehicle_class=rocket')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.metric', 'revenue')->where('filters.from', '2026-08-01')->where('filters.group', 'week')
                ->where('filters.currency', 'AMD')->where('filters.vehicle_class', null));
    }

    public function test_map_and_dashboard_filters(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/live-map?availability=online')
            ->assertInertia(fn (Assert $page) => $page->where('filters.availability', 'online')->has('markers', 3)
                ->where('markers', fn ($markers) => collect($markers)->every(fn ($m) => $m['availability'] === 'online')));
        $this->actingAs($admin)->get('/admin?zone=2')->assertInertia(fn (Assert $page) => $page->where('filters.zone', 2));
        $this->actingAs($admin)->get('/admin?zone=77')->assertInertia(fn (Assert $page) => $page->where('filters.zone', null));
    }

    public function test_unknown_records_are_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/riders/999')->assertNotFound();
        $this->actingAs($admin)->get('/admin/drivers/999')->assertNotFound();
        $this->actingAs($admin)->get('/admin/trips/999')->assertNotFound();
        $this->actingAs($admin)->get('/admin/trips/abc')->assertNotFound();
    }
}
