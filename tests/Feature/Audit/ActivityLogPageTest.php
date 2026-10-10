<?php

namespace Tests\Feature\Audit;

use App\Enums\AdminRole;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

class ActivityLogPageTest extends AuditTestCase
{
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = $this->admin(AdminRole::SuperAdmin, ['timezone' => 'UTC']);
    }

    private function page(string $query = ''): TestResponse
    {
        return $this->actingAs($this->viewer)->get('/admin/activity-logs'.$query);
    }

    public function test_entries_are_listed_newest_first_with_everything_the_page_shows(): void
    {
        $actor = $this->admin(AdminRole::Finance, ['name' => 'Morgan Webb']);
        $document = DriverDocument::factory()->create();
        $this->travelTo('2026-10-05 14:20:00');
        $this->audit()->record($actor, 'driver.document.rejected', $document, 'Expired policy', ['status' => 'pending', 'document_number' => 'A1'], ['status' => 'rejected', 'document_number' => 'B2']);
        $this->travelTo('2026-10-05 15:00:00');
        $newer = $this->audit()->record($actor, 'driver.verification.changed', DriverProfile::factory()->create());
        $this->travelBack();

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->component('ActivityLog/Index')
            ->where('entries.total', 2)
            ->where('entries.data.0.id', $newer->id)
            ->where('entries.data.0.action', 'driver.verification.changed')
            ->where('entries.data.1.action', 'driver.document.rejected')
            ->where('entries.data.1.occurred_at', '2026-10-05T14:20:00+00:00')
            ->where('entries.data.1.actor', ['id' => $actor->id, 'name' => 'Morgan Webb', 'role' => 'finance'])
            ->where('entries.data.1.target_type', 'driver_document')
            ->where('entries.data.1.target_id', $document->id)
            ->where('entries.data.1.target', sprintf('D-%04d / Driver\'s license', $document->driver_id))
            ->where('entries.data.1.reason', 'Expired policy')
            ->where('entries.data.1.before', ['status' => 'pending', 'document_number' => 'changed'])
            ->where('entries.data.1.after', ['status' => 'rejected', 'document_number' => 'changed'])
            ->where('targetTypes', ['driver', 'driver_document', 'vehicle', 'user', 'zone'])
            ->where('actors', [['id' => $actor->id, 'name' => 'Morgan Webb']])
            ->where('actionNames', ['driver.document.rejected', 'driver.verification.changed']));
    }

    public function test_an_empty_log_renders_an_empty_page(): void
    {
        $this->page()->assertInertia(fn (Assert $page) => $page->where('entries.total', 0)->where('actors', [])->where('actionNames', []));
    }

    public function test_filter_by_actor(): void
    {
        $a = $this->admin(AdminRole::Admin);
        $b = $this->admin(AdminRole::Support);
        $this->audit()->record($a, 'driver.document.approved');
        $mine = $this->audit()->record($b, 'driver.document.rejected');

        $this->page("?filter[actor]={$b->id}")->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.id', $mine->id)->where('filters', ['actor' => (string) $b->id]));
    }

    public function test_filter_by_action_exact_or_prefix(): void
    {
        $admin = $this->admin();
        $this->audit()->record($admin, 'driver.document.approved');
        $this->audit()->record($admin, 'driver.document.rejected');
        $this->audit()->record($admin, 'driver.verification.changed');
        $this->audit()->record($admin, 'driverx.other');

        $this->page('?filter[action]=driver.document.rejected')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
        $this->page('?filter[action]=driver.document')->assertInertia(fn (Assert $p) => $p->where('entries.total', 2));
        $this->page('?filter[action]=driver')->assertInertia(fn (Assert $p) => $p->where('entries.total', 3)->etc());
        $this->page('?filter[action]=driver.doc')->assertInertia(fn (Assert $p) => $p->where('entries.total', 0));
        $this->page('?filter[action]=driver%25')->assertInertia(fn (Assert $p) => $p->where('entries.total', 0));
    }

    public function test_filter_by_target_type_and_target_id(): void
    {
        $admin = $this->admin();
        $document = DriverDocument::factory()->create();
        $profile = DriverProfile::factory()->create();
        $this->audit()->record($admin, 'driver.document.approved', $document);
        $this->audit()->record($admin, 'driver.verification.changed', $profile);
        $this->audit()->record($admin, 'activity_log.exported');

        $this->page('?filter[target_type]=driver_document')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1)->where('entries.data.0.target_id', $document->id));
        $this->page('?filter[target_type]=driver')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1)->where('entries.data.0.target_id', $profile->id));
        $this->page("?filter[target_type]=driver_document&filter[target_id]={$document->id}")->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
        $this->page("?filter[target_type]=driver&filter[target_id]={$document->id}")->assertInertia(fn (Assert $p) => $p->where('entries.total', 0));
        $this->page("?filter[target_id]={$profile->id}")->assertInertia(fn (Assert $p) => $p->where('entries.data.0.action', 'driver.verification.changed'));
    }

    public function test_the_date_range_is_inclusive_and_read_in_the_admins_timezone(): void
    {
        $admin = $this->admin();
        // Yerevan is UTC+4: 2026-10-05 00:00 local = 2026-10-04 20:00 UTC.
        $this->viewer->forceFill(['timezone' => 'Asia/Yerevan'])->save();
        $before = $this->entryAt('2026-10-04 19:59:59', $admin, 'a.before');
        $first = $this->entryAt('2026-10-04 20:00:00', $admin, 'a.first');
        $last = $this->entryAt('2026-10-05 19:59:59', $admin, 'a.last');
        $after = $this->entryAt('2026-10-05 20:00:00', $admin, 'a.after');

        $this->page('?filter[from]=2026-10-05&filter[to]=2026-10-05')->assertInertia(fn (Assert $p) => $p
            ->where('entries.total', 2)->where('entries.data.0.id', $last->id)->where('entries.data.1.id', $first->id));
        $this->page('?filter[from]=2026-10-05')->assertInertia(fn (Assert $p) => $p->where('entries.total', 3));
        $this->page('?filter[to]=2026-10-04')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1)->where('entries.data.0.id', $before->id));
        $this->assertNotNull($after);
    }

    public function test_invalid_dates_and_ids_and_unknown_filters_are_rejected(): void
    {
        foreach (['filter[from]=yesterday', 'filter[from]=2026-13-45', 'filter[to]=2026-02-30', 'filter[from]=05.10.2026', 'filter[actor]=abc', 'filter[actor]=0', 'filter[target_id]=-3', 'filter[target_type]=trip_secret', 'filter[bogus]=1'] as $query) {
            $this->page('?'.$query)->assertStatus(400);
        }
    }

    public function test_a_filter_given_several_values_is_refused_not_a_server_error(): void
    {
        foreach (['filter[action]=a,b', 'filter[action][]=x', 'filter[target_type]=driver,user', 'filter[target_type][]=driver', 'filter[from]=2026-10-01,2026-10-02', 'filter[to][]=2026-10-01', 'filter[actor][]=1', 'filter[target_id]=1,2'] as $query) {
            $this->page('?'.$query)->assertStatus(400);
            $this->actingAs($this->viewer)->get('/admin/activity-logs/export?'.$query)->assertStatus(400);
        }
    }

    public function test_dates_outside_the_supported_years_are_refused_at_the_boundaries(): void
    {
        foreach (['filter[from]=0000-01-01', 'filter[to]=0000-12-31', 'filter[from]=1969-12-31', 'filter[to]=2101-01-01', 'filter[from]=9999-12-31'] as $query) {
            $this->page('?'.$query)->assertStatus(400);
            $this->actingAs($this->viewer)->get('/admin/activity-logs/export?'.$query)->assertStatus(400);
        }

        $this->page('?filter[from]=1970-01-01&filter[to]=2100-12-31')->assertOk();
        $this->actingAs($this->viewer)->get('/admin/activity-logs/export?filter[from]=1970-01-01&filter[to]=2100-12-31')->assertOk();
    }

    public function test_a_search_containing_a_comma_is_matched_as_typed(): void
    {
        $this->audit()->record($this->admin(), 'driver.document.rejected', null, 'Expired policy, renew it');
        $this->audit()->record($this->admin(), 'driver.document.rejected', null, 'Expired policy renew it');

        $this->page('?filter[search]=policy,+renew')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
    }

    public function test_search_matches_action_actor_target_and_reason_case_insensitively_and_literally(): void
    {
        $actor = $this->admin(AdminRole::Support, ['name' => 'Riley Chen']);
        $this->audit()->record($actor, 'trip.driver.reassigned', null, 'Closer driver available', targetLabel: 'TK-8F3K2');
        $this->audit()->record($actor, 'payment.refund.created', null, '100% fare adjustment', targetLabel: 'TXN-104882');

        $this->page('?filter[search]=reassigned')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
        $this->page('?filter[search]=CLOSER')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
        $this->page('?filter[search]=riley')->assertInertia(fn (Assert $p) => $p->where('entries.total', 2));
        $this->page('?filter[search]=txn-1048')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
        $this->page('?filter[search]=100%25')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
        $this->page('?filter[search]=%25')->assertInertia(fn (Assert $p) => $p->where('entries.total', 1));
        $this->page('?filter[search]=nothing-like-this')->assertInertia(fn (Assert $p) => $p->where('entries.total', 0));
    }

    public function test_filters_combine(): void
    {
        $a = $this->admin(AdminRole::Admin);
        $b = $this->admin(AdminRole::Support);
        $this->entryAt('2026-10-01 10:00:00', $a, 'driver.document.approved');
        $hit = $this->entryAt('2026-10-02 10:00:00', $b, 'driver.document.approved');
        $this->entryAt('2026-10-02 11:00:00', $b, 'driver.document.rejected');

        $this->page("?filter[actor]={$b->id}&filter[action]=driver.document.approved&filter[from]=2026-10-02&filter[to]=2026-10-02")
            ->assertInertia(fn (Assert $p) => $p->where('entries.total', 1)->where('entries.data.0.id', $hit->id));
    }

    public function test_sorting_by_time_ascending_or_descending_and_unknown_sorts_are_rejected(): void
    {
        $admin = $this->admin();
        $old = $this->entryAt('2026-10-01 10:00:00', $admin);
        $new = $this->entryAt('2026-10-03 10:00:00', $admin);

        $this->page('?sort=occurred_at')->assertInertia(fn (Assert $p) => $p->where('entries.data.0.id', $old->id));
        $this->page('?sort=-occurred_at')->assertInertia(fn (Assert $p) => $p->where('entries.data.0.id', $new->id));
        $this->page('?sort=password')->assertStatus(400);
    }

    public function test_pagination_follows_the_page_size_options_and_keeps_filters_in_the_links(): void
    {
        $admin = $this->admin();
        foreach (range(1, 12) as $i) {
            $this->audit()->record($admin, 'driver.document.approved');
        }

        $this->page('?filter[action]=driver&per_page=10&page=2')->assertInertia(fn (Assert $p) => $p
            ->where('entries.total', 12)->where('entries.per_page', 10)->where('entries.current_page', 2)->has('entries.data', 2));
        $this->page('?per_page=7')->assertInertia(fn (Assert $p) => $p->where('entries.per_page', 10));
        $this->page('?filter[action]=driver&per_page=10')->assertInertia(fn (Assert $p) => $p->where('entries.next_page_url', fn ($url) => str_contains($url, 'filter%5Baction%5D=driver')));
    }

    public function test_an_entry_named_in_the_url_is_expanded_even_when_it_is_not_on_the_page(): void
    {
        $admin = $this->admin();
        $oldest = $this->entryAt('2026-01-01 10:00:00', $admin, 'driver.document.rejected', ['reason' => 'Old one', 'old' => ['status' => 'pending'], 'new' => ['status' => 'rejected']]);
        foreach (range(1, 12) as $i) {
            $this->audit()->record($admin, 'driver.document.approved');
        }

        $this->page('?entry='.$oldest->id)->assertInertia(fn (Assert $p) => $p
            ->where('expandedId', $oldest->id)->where('expanded.reason', 'Old one')->where('expanded.before', ['status' => 'pending'])->where('expanded.after', ['status' => 'rejected']));
        $this->page('?entry=abc')->assertInertia(fn (Assert $p) => $p->where('expandedId', null));
    }

    public function test_the_export_link_carries_the_current_filter_but_not_the_page(): void
    {
        $this->page('?filter[action]=driver&sort=-occurred_at&page=3&entry=1&per_page=25')->assertInertia(fn (Assert $p) => $p
            ->where('actions.export', '/admin/activity-logs/export?filter%5Baction%5D=driver&sort=-occurred_at'));
    }

    public function test_the_page_needs_activity_log_view(): void
    {
        $this->actingAs($this->admin(AdminRole::Finance))->get('/admin/activity-logs')->assertForbidden();
        $this->actingAs($this->admin(AdminRole::Support))->get('/admin/activity-logs')->assertForbidden();
        $this->actingAs($this->admin(AdminRole::Dispatcher))->get('/admin/activity-logs')->assertForbidden();
        $this->actingAs($this->admin(AdminRole::Admin))->get('/admin/activity-logs')->assertOk();
        auth()->logout();
        $this->get('/admin/activity-logs')->assertRedirect('/login');
    }

    public function test_the_page_issues_the_same_number_of_queries_however_many_entries_exist(): void
    {
        $admin = $this->admin();
        $this->audit()->record($admin, 'driver.document.approved', DriverDocument::factory()->create());
        $this->actingAs($this->viewer)->get('/admin/activity-logs')->assertOk();
        $this->actingAs($this->viewer)->get('/admin/activity-logs')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->viewer)->get('/admin/activity-logs')->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 9) as $i) {
            $this->audit()->record($this->admin(), 'driver.document.rejected', DriverDocument::factory()->create(), 'x');
        }
        DB::flushQueryLog();
        $this->actingAs($this->viewer)->get('/admin/activity-logs')->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()), 'Entries are shown from their stored copy: no query per row.');
    }
}
