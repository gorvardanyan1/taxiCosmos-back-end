<?php

namespace Tests\Feature\Riders;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

class RiderListTest extends AdminTestCase
{
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = $this->admin(AdminRole::Support, ['timezone' => 'UTC']);
    }

    private function rider(array $attributes = []): User
    {
        return User::factory()->rider()->create($attributes);
    }

    private function page(string $query = '')
    {
        return $this->actingAs($this->viewer)->get('/admin/riders'.$query);
    }

    public function test_the_list_shows_riders_newest_first_in_the_shape_the_page_reads(): void
    {
        $old = $this->rider(['name' => 'Priya Mehta', 'email' => 'priya@example.com', 'created_at' => '2026-01-12 10:00:00']);
        $old->forceFill(['phone' => '091 210 443'])->save();
        $new = $this->rider(['name' => 'James Okafor', 'created_at' => '2026-03-01 10:00:00']);

        $this->page()->assertInertia(fn (Assert $p) => $p
            ->component('Riders/Index')
            ->where('riders.total', 2)
            ->where('riders.data.0.id', $new->id)
            ->where('riders.data.1', [
                'id' => $old->id, 'code' => sprintf('R-%05d', $old->id), 'name' => 'Priya Mehta', 'phone' => '+37491210443',
                'email' => 'priya@example.com', 'status' => 'active', 'trips_count' => 0, 'registered_at' => '2026-01-12T10:00:00+00:00',
            ])
            ->where('statuses', ['active', 'suspended', 'deactivated', 'pending_deletion'])
            ->where('totalRegistered', 2)
            ->where('actions', ['export' => null, 'suspend' => '/admin/riders/{id}/suspend', 'reactivate' => '/admin/riders/{id}/reactivate']));
    }

    public function test_a_rider_without_name_email_or_phone_still_renders(): void
    {
        $this->rider(['name' => null, 'email' => null]);

        $this->page()->assertInertia(fn (Assert $p) => $p
            ->where('riders.data.0.name', 'Unnamed rider')->where('riders.data.0.email', '—')->where('riders.data.0.phone', '—'));
    }

    public function test_only_riders_are_listed_and_a_rider_who_also_drives_is(): void
    {
        $rider = $this->rider();
        $both = User::factory()->rider()->driver()->create();
        User::factory()->driver()->create();
        User::factory()->create();
        $this->admin(AdminRole::Admin);
        $gone = $this->rider();
        $gone->delete();

        $this->page()->assertInertia(fn (Assert $p) => $p
            ->where('riders.total', 2)->where('totalRegistered', 2)
            ->where('riders.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$rider->id, $both->id])->sort()->values()->all()));
    }

    public function test_the_list_never_carries_passwords_tokens_or_encrypted_columns(): void
    {
        $this->rider(['name' => 'Priya']);

        $json = $this->page()->viewData('page')['props']['riders']['data'][0];

        $this->assertSame(['id', 'code', 'name', 'phone', 'email', 'status', 'trips_count', 'registered_at'], array_keys($json));
    }

    // ---- search ----

    public function test_search_finds_name_and_email_parts_case_insensitively(): void
    {
        $a = $this->rider(['name' => 'Priya Mehta', 'email' => 'priya@example.com']);
        $this->rider(['name' => 'James Okafor', 'email' => 'james@elsewhere.org']);

        foreach (['priya', 'MEHTA', 'ya me', 'PRIYA@EXAMPLE', 'example.com'] as $term) {
            $this->page('?filter[search]='.urlencode($term))->assertInertia(fn (Assert $p) => $p->where('riders.total', 1)->where('riders.data.0.id', $a->id));
        }
        $this->page('?filter[search]=nobody')->assertInertia(fn (Assert $p) => $p->where('riders.total', 0));
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->rider(['name' => 'Plain Name']);
        $special = $this->rider(['name' => '100% rider_x']);

        $this->page('?filter[search]='.urlencode('%'))->assertInertia(fn (Assert $p) => $p->where('riders.total', 1)->where('riders.data.0.id', $special->id));
        $this->page('?filter[search]='.urlencode('_'))->assertInertia(fn (Assert $p) => $p->where('riders.total', 1));
    }

    public function test_search_finds_an_exact_phone_written_any_way_but_not_a_part_of_one(): void
    {
        $rider = $this->rider(['name' => 'Phone Owner']);
        $rider->forceFill(['phone' => '+37491440221'])->save();
        $other = $this->rider(['name' => 'Someone Else']);
        $other->forceFill(['phone' => '+37499112233'])->save();

        foreach (['091 440 221', '+374 91-440-221', '0037491440221', '+37491440221'] as $typed) {
            $this->page('?filter[search]='.urlencode($typed))->assertInertia(fn (Assert $p) => $p->where('riders.total', 1)->where('riders.data.0.id', $rider->id));
        }
        $this->page('?filter[search]='.urlencode('91440'))->assertInertia(fn (Assert $p) => $p->where('riders.total', 0), 'A fragment is not an exact phone.');
    }

    public function test_search_finds_a_rider_by_code(): void
    {
        $rider = $this->rider();
        $this->rider();

        foreach ([sprintf('R-%05d', $rider->id), sprintf('r%d', $rider->id), (string) $rider->id] as $term) {
            $this->page('?filter[search]='.$term)->assertInertia(fn (Assert $p) => $p->where('riders.data.0.id', $rider->id));
        }
    }

    public function test_the_search_runs_on_bound_parameters(): void
    {
        $this->rider(['name' => "O'Brien"]);

        $this->page('?filter[search]='.urlencode("'; DROP TABLE users; --"))->assertInertia(fn (Assert $p) => $p->where('riders.total', 0));
        $this->page('?filter[search]='.urlencode("O'Brien"))->assertInertia(fn (Assert $p) => $p->where('riders.total', 1));
    }

    // ---- filters ----

    public function test_filter_by_status(): void
    {
        $this->rider();
        $suspended = User::factory()->rider()->suspended()->create();
        User::factory()->rider()->create(['status' => UserStatus::Deactivated]);

        $this->page('?filter[status]=suspended')->assertInertia(fn (Assert $p) => $p->where('riders.total', 1)->where('riders.data.0.id', $suspended->id)->where('filters', ['status' => 'suspended']));
        $this->page('?filter[status]=active')->assertInertia(fn (Assert $p) => $p->where('riders.total', 1));
    }

    public function test_the_registration_range_is_inclusive_and_read_in_the_admins_timezone(): void
    {
        $this->viewer->forceFill(['timezone' => 'Asia/Yerevan'])->save(); // UTC+4
        $before = $this->rider(['created_at' => '2026-10-04 19:59:59']);
        $first = $this->rider(['created_at' => '2026-10-04 20:00:00']);
        $last = $this->rider(['created_at' => '2026-10-05 19:59:59']);
        $after = $this->rider(['created_at' => '2026-10-05 20:00:00']);

        $this->page('?filter[registered_from]=2026-10-05&filter[registered_to]=2026-10-05')->assertInertia(fn (Assert $p) => $p
            ->where('riders.total', 2)->where('riders.data.0.id', $last->id)->where('riders.data.1.id', $first->id));
        $this->page('?filter[registered_to]=2026-10-04')->assertInertia(fn (Assert $p) => $p->where('riders.total', 1)->where('riders.data.0.id', $before->id));
        $this->page('?filter[registered_from]=2026-10-06')->assertInertia(fn (Assert $p) => $p->where('riders.total', 1)->where('riders.data.0.id', $after->id));
        $this->page('?filter[registered_from]=2026-10-07')->assertInertia(fn (Assert $p) => $p->where('riders.total', 0));
    }

    public function test_filters_combine(): void
    {
        $hit = User::factory()->rider()->suspended()->create(['name' => 'Sun Li', 'created_at' => '2026-02-02 10:00:00']);
        User::factory()->rider()->suspended()->create(['name' => 'Sun Wu', 'created_at' => '2025-02-02 10:00:00']);
        User::factory()->rider()->create(['name' => 'Sun Yi', 'created_at' => '2026-02-02 10:00:00']);

        $this->page('?filter[search]=sun&filter[status]=suspended&filter[registered_from]=2026-01-01')
            ->assertInertia(fn (Assert $p) => $p->where('riders.total', 1)->where('riders.data.0.id', $hit->id));
    }

    #[DataProvider('badQueries')]
    public function test_bad_filters_and_sorts_are_400_not_500(string $query): void
    {
        $this->rider();

        $this->page('?'.$query)->assertStatus(400);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badQueries(): array
    {
        return [
            'unknown filter' => ['filter[bogus]=1'],
            'unknown status' => ['filter[status]=banned'],
            'several statuses' => ['filter[status]=active,suspended'],
            'status array' => ['filter[status][]=active'],
            'bad date' => ['filter[registered_from]=yesterday'],
            'year 0000' => ['filter[registered_from]=0000-01-01'],
            'year 2101' => ['filter[registered_to]=2101-01-01'],
            'impossible day' => ['filter[registered_to]=2026-02-30'],
            'several dates' => ['filter[registered_from]=2026-01-01,2026-01-02'],
            'unknown sort' => ['sort=password'],
        ];
    }

    // ---- sort, pages ----

    public function test_sorting_by_registration_date_and_name_both_ways(): void
    {
        $b = $this->rider(['name' => 'Bea', 'created_at' => '2026-01-02 00:00:00']);
        $c = $this->rider(['name' => 'Cal', 'created_at' => '2026-01-03 00:00:00']);
        $a = $this->rider(['name' => 'Abe', 'created_at' => '2026-01-01 00:00:00']);
        $ids = fn (string $query) => collect($this->page($query)->viewData('page')['props']['riders']['data'])->pluck('id')->all();

        $this->assertSame([$c->id, $b->id, $a->id], $ids(''));
        $this->assertSame([$a->id, $b->id, $c->id], $ids('?sort=registered_at'));
        $this->assertSame([$c->id, $b->id, $a->id], $ids('?sort=-registered_at'));
        $this->assertSame([$a->id, $b->id, $c->id], $ids('?sort=name'));
        $this->assertSame([$c->id, $b->id, $a->id], $ids('?sort=-name'));
    }

    public function test_sorting_by_trip_count_is_accepted_and_stable_until_trips_exist(): void
    {
        $older = $this->rider(['created_at' => '2026-01-01 00:00:00']);
        $newer = $this->rider(['created_at' => '2026-02-01 00:00:00']);

        $this->page('?sort=trips_count')->assertInertia(fn (Assert $p) => $p->where('sort', 'trips_count')->where('riders.total', 2));
        $this->page('?sort=-trips_count')->assertInertia(fn (Assert $p) => $p->where('riders.data.0.id', $newer->id)->where('riders.data.1.id', $older->id));
    }

    public function test_pages_hold_the_configured_sizes_and_keep_the_filter_in_their_links(): void
    {
        User::factory()->rider()->count(12)->create();

        $this->page('?per_page=10&page=2&filter[status]=active')->assertInertia(fn (Assert $p) => $p
            ->where('riders.total', 12)->where('riders.current_page', 2)->has('riders.data', 2)
            ->where('riders.links.1.url', fn ($url) => str_contains($url, 'filter%5Bstatus%5D=active')));
        $this->page('?per_page=7')->assertInertia(fn (Assert $p) => $p->where('riders.per_page', 10));
        $this->page('?per_page=50')->assertInertia(fn (Assert $p) => $p->where('riders.per_page', 50)->has('riders.data', 12));
    }

    public function test_the_list_issues_the_same_queries_however_many_riders_exist(): void
    {
        $this->rider();
        $this->page()->assertOk();
        $this->page()->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page()->assertOk();
        $few = count(DB::getQueryLog());

        User::factory()->rider()->count(9)->create();
        DB::flushQueryLog();
        $this->page()->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()));
    }

    // ---- access ----

    public function test_every_admin_role_can_view_the_list_and_guests_cannot(): void
    {
        foreach (AdminRole::cases() as $role) {
            $this->actingAs($this->admin($role))->get('/admin/riders')->assertOk();
        }

        auth()->logout();
        $this->get('/admin/riders')->assertRedirect('/login');
    }
}
