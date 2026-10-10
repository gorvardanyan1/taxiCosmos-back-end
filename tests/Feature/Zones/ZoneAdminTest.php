<?php

namespace Tests\Feature\Zones;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

class ZoneAdminTest extends AdminTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(AdminRole::Admin);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Yerevan Centre', 'code' => 'YEREVAN', 'timezone' => 'Asia/Yerevan', 'currency' => 'AMD', 'priority' => 5,
            'polygon' => ZoneFactory::squareGeoJson(40.18, 44.51, 0.05),
            ...$overrides,
        ];
    }

    private function bowtie(): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[44.4, 40.1], [44.6, 40.3], [44.6, 40.1], [44.4, 40.3], [44.4, 40.1]]]];
    }

    private function geoJsonOf(Zone $zone): array
    {
        return json_decode(DB::selectOne('SELECT ST_AsGeoJSON(polygon, 6) AS g FROM zones WHERE id = ?', [$zone->id])->g, true);
    }

    // ---- index ----

    public function test_the_page_lists_zones_by_priority_with_the_selected_shape_only(): void
    {
        $low = Zone::factory()->priority(1)->create(['name' => 'Suburbs', 'code' => 'SUB']);
        $high = Zone::factory()->priority(9)->around(40.0, 44.0, 0.2)->create(['name' => 'Airport', 'code' => 'AIR']);
        $off = Zone::factory()->inactive()->priority(5)->create(['name' => 'Harbor', 'code' => 'HAR']);

        $this->actingAs($this->admin)->get('/admin/zones?zone='.$low->id)->assertInertia(fn (Assert $p) => $p
            ->component('Zones/Index')
            ->where('zones.0.id', $high->id)->where('zones.0.code', 'AIR')->where('zones.0.priority', 9)->where('zones.0.status', 'active')
            ->where('zones.1.id', $off->id)->where('zones.1.status', 'inactive')
            ->where('zones.2.id', $low->id)->where('zones.2.timezone', 'Asia/Yerevan')->where('zones.2.currency', 'AMD')->where('zones.2.points', 5)
            ->where('zones.2.polygon_valid', true)
            ->missing('zones.0.polygon')->missing('zones.0.polygon_geojson')
            ->where('selectedZoneId', $low->id)
            ->where('selectedPolygon.type', 'MultiPolygon')
            ->where('selectedPolygon.coordinates.0.0.0', fn ($point) => abs($point[0] - 44.46) < 1e-6 && abs($point[1] - 40.13) < 1e-6)
            ->where('fareRules', [])
            ->has('timezones')->where('currencies', ['AMD', 'USD', 'EUR']));
    }

    public function test_the_first_zone_is_selected_by_default_and_an_unknown_one_falls_back(): void
    {
        $first = Zone::factory()->priority(7)->create();
        Zone::factory()->priority(1)->create();

        $this->actingAs($this->admin)->get('/admin/zones')->assertInertia(fn (Assert $p) => $p->where('selectedZoneId', $first->id));
        $this->actingAs($this->admin)->get('/admin/zones?zone=999999')->assertInertia(fn (Assert $p) => $p->where('selectedZoneId', $first->id));
        $this->actingAs($this->admin)->get('/admin/zones?zone=abc')->assertInertia(fn (Assert $p) => $p->where('selectedZoneId', $first->id));
    }

    public function test_an_empty_system_renders_with_no_selection(): void
    {
        $this->actingAs($this->admin)->get('/admin/zones')->assertInertia(fn (Assert $p) => $p
            ->where('zones', [])->where('selectedZoneId', null)->where('selectedPolygon', null));
    }

    public function test_the_page_exposes_the_write_endpoints_as_url_templates(): void
    {
        $this->actingAs($this->admin)->get('/admin/zones')->assertInertia(fn (Assert $p) => $p
            ->where('actions.createZone', '/admin/zones')
            ->where('actions.updateZone', '/admin/zones/{id}')
            ->where('actions.deactivateZone', '/admin/zones/{id}/deactivate')
            ->where('actions.activateZone', '/admin/zones/{id}/activate')
            ->where('actions.updateFareRule', null));
    }

    public function test_the_page_issues_the_same_queries_however_many_zones_exist(): void
    {
        Zone::factory()->create();
        $this->actingAs($this->admin)->get('/admin/zones')->assertOk();
        $this->actingAs($this->admin)->get('/admin/zones')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/admin/zones')->assertOk();
        $few = count(DB::getQueryLog());

        Zone::factory()->count(8)->create();
        DB::flushQueryLog();
        $this->actingAs($this->admin)->get('/admin/zones')->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()));
    }

    // ---- create ----

    public function test_a_zone_is_created_with_its_polygon_and_audited(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/zones', $this->payload());

        $zone = Zone::where('code', 'YEREVAN')->sole();
        $response->assertRedirect('/admin/zones?zone='.$zone->id)->assertSessionHas('success', 'Zone created.');
        $this->assertSame(['Yerevan Centre', 'Asia/Yerevan', 'AMD', 5, true], [$zone->name, $zone->timezone, $zone->currency, $zone->priority, $zone->is_active]);
        $stored = $this->geoJsonOf($zone);
        $this->assertSame('MultiPolygon', $stored['type'], 'A Polygon is stored as a MultiPolygon.');
        $this->assertEqualsWithDelta(44.46, $stored['coordinates'][0][0][0][0], 1e-6);

        $entry = ActivityLogEntry::where('description', 'zone.created')->sole();
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertSame($zone->id, $entry->subject_id);
        $this->assertSame('Zone YEREVAN', $entry->properties['target_label']);
        $this->assertSame(['name' => 'Yerevan Centre', 'code' => 'YEREVAN', 'timezone' => 'Asia/Yerevan', 'currency' => 'AMD', 'priority' => 5, 'polygon_points' => 5], $entry->attribute_changes['attributes']);
        $this->assertStringNotContainsString('coordinates', $entry->toJson(), 'The polygon itself is not copied into the log.');
    }

    public function test_the_polygon_may_be_a_multipolygon_a_feature_or_a_json_string_and_priority_defaults_to_zero(): void
    {
        $square = ZoneFactory::squareGeoJson(40.18, 44.51, 0.05);
        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['code' => 'MULTI', 'polygon' => ['type' => 'MultiPolygon', 'coordinates' => [$square['coordinates']]]]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['code' => 'FEATURE', 'polygon' => ['type' => 'Feature', 'properties' => [], 'geometry' => $square]]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['code' => 'STRING', 'polygon' => json_encode($square), 'priority' => null]))->assertSessionHasNoErrors();

        $this->assertSame(3, Zone::count());
        $this->assertSame(0, Zone::where('code', 'STRING')->value('priority'));
    }

    #[DataProvider('invalidCreates')]
    public function test_a_bad_request_is_refused_with_the_error_on_its_field_and_nothing_is_saved(array $overrides, string $field): void
    {
        Zone::factory()->create(['code' => 'TAKEN']);
        $overrides = array_map(fn ($v) => $v === '__bowtie__' ? $this->bowtie() : $v, $overrides);

        $this->actingAs($this->admin)->post('/admin/zones', $this->payload($overrides))->assertSessionHasErrors($field);

        $this->assertSame(1, Zone::count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCreates(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'name over 100' => [['name' => str_repeat('x', 101)], 'name'],
            'no code' => [['code' => ''], 'code'],
            'lower-case code' => [['code' => 'yerevan'], 'code'],
            'code with a space' => [['code' => 'NEW YORK'], 'code'],
            'code of one character' => [['code' => 'A'], 'code'],
            'code over 32' => [['code' => str_repeat('A', 33)], 'code'],
            'duplicate code' => [['code' => 'TAKEN'], 'code'],
            'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
            'no timezone' => [['timezone' => ''], 'timezone'],
            'unknown currency' => [['currency' => 'XXX'], 'currency'],
            'lower-case currency' => [['currency' => 'amd'], 'currency'],
            'priority below 0' => [['priority' => -1], 'priority'],
            'priority above 1000' => [['priority' => 1001], 'priority'],
            'priority not a number' => [['priority' => 'high'], 'priority'],
            'no polygon' => [['polygon' => null], 'polygon'],
            'a self-intersecting polygon' => [['polygon' => '__bowtie__'], 'polygon'],
            'fewer than 3 points' => [['polygon' => ['type' => 'Polygon', 'coordinates' => [[[44.4, 40.1], [44.6, 40.1], [44.4, 40.1]]]]], 'polygon'],
            'a point instead of an area' => [['polygon' => ['type' => 'Point', 'coordinates' => [44.5, 40.2]]], 'polygon'],
            'a polygon outside the world' => [['polygon' => ['type' => 'Polygon', 'coordinates' => [[[200, 40.1], [44.6, 40.1], [44.6, 40.3], [200, 40.1]]]]], 'polygon'],
        ];
    }

    public function test_the_self_intersection_error_names_the_problem(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['polygon' => $this->bowtie()]));

        $response->assertSessionHasErrors(['polygon' => 'Polygon intersects itself.']);
        $this->assertSame(0, ActivityLogEntry::where('description', 'zone.created')->count());
    }

    public function test_boundary_values_are_accepted(): void
    {
        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['code' => 'AB', 'priority' => 0, 'name' => str_repeat('n', 100)]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['code' => str_repeat('A', 32), 'priority' => 1000]))->assertSessionHasNoErrors();

        $this->assertSame(2, Zone::count());
    }

    public function test_a_polygon_exported_with_altitude_is_stored_in_two_dimensions(): void
    {
        $withAltitude = ['type' => 'Polygon', 'coordinates' => [[[44.4, 40.1, 1200], [44.6, 40.1, 1200], [44.6, 40.3, 1200], [44.4, 40.3, 1200], [44.4, 40.1, 1200]]]];

        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['polygon' => $withAltitude]))->assertSessionHasNoErrors();

        $zone = Zone::where('code', 'YEREVAN')->sole();
        $this->assertFalse(DB::selectOne('SELECT ST_HasZ(polygon) AS z FROM zones WHERE id = ?', [$zone->id])->z);
        $this->assertSame(5, (int) Zone::query()->withGeometry()->find($zone->id)->polygon_points);

        $this->actingAs($this->admin)->patch("/admin/zones/{$zone->id}", ['polygon' => ['type' => 'Polygon', 'coordinates' => [[[45.0, 41.0, 5], [45.2, 41.0, 5], [45.2, 41.2, 5], [45.0, 41.0, 5]]]]])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(45.0, $this->geoJsonOf($zone)['coordinates'][0][0][0][0], 1e-6);
    }

    public function test_a_zone_id_too_large_for_the_database_is_a_404_not_a_server_error(): void
    {
        $tooBig = '99999999999999999999';

        $this->actingAs($this->admin)->patch("/admin/zones/{$tooBig}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/zones/{$tooBig}/deactivate")->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/zones/{$tooBig}/activate")->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/zones?zone='.$tooBig)->assertOk();
    }

    // ---- update ----

    public function test_a_zone_is_updated_field_by_field_and_audited_with_a_diff(): void
    {
        $zone = Zone::factory()->create(['name' => 'Old name', 'priority' => 1, 'currency' => 'AMD', 'code' => 'KEEP']);

        $this->actingAs($this->admin)->from('/admin/zones')->patch("/admin/zones/{$zone->id}", ['name' => 'New name', 'priority' => 8])
            ->assertRedirect('/admin/zones')->assertSessionHas('success', 'Zone updated.');

        $fresh = $zone->fresh();
        $this->assertSame(['New name', 8, 'AMD', 'KEEP'], [$fresh->name, $fresh->priority, $fresh->currency, $fresh->code]);
        $entry = ActivityLogEntry::where('description', 'zone.updated')->sole();
        $this->assertSame(['name' => 'Old name', 'priority' => 1], $entry->attribute_changes['old']);
        $this->assertSame(['name' => 'New name', 'priority' => 8], $entry->attribute_changes['attributes']);
    }

    public function test_changing_the_polygon_replaces_it_and_the_log_only_counts_points(): void
    {
        $zone = Zone::factory()->around(40.18, 44.51, 0.05)->create();
        $new = ['type' => 'Polygon', 'coordinates' => [[[45.0, 41.0], [45.4, 41.0], [45.4, 41.4], [45.0, 41.4], [45.2, 41.2], [45.0, 41.0]]]];

        $this->actingAs($this->admin)->patch("/admin/zones/{$zone->id}", ['polygon' => $new])->assertSessionHasNoErrors();

        $stored = $this->geoJsonOf($zone);
        $this->assertEqualsWithDelta(45.0, $stored['coordinates'][0][0][0][0], 1e-6);
        $entry = ActivityLogEntry::where('description', 'zone.updated')->sole();
        $this->assertSame(['polygon_points' => 5], $entry->attribute_changes['old']);
        $this->assertSame(['polygon_points' => 6], $entry->attribute_changes['attributes']);
        $this->assertStringNotContainsString('45.4', $entry->toJson());
    }

    public function test_sending_the_same_values_changes_nothing_and_logs_nothing(): void
    {
        $zone = Zone::factory()->around(40.18, 44.51, 0.05)->create(['name' => 'Same']);
        $before = $zone->fresh()->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($this->admin)->patch("/admin/zones/{$zone->id}", ['name' => 'Same', 'priority' => 0, 'polygon' => ZoneFactory::squareGeoJson(40.18, 44.51, 0.05)])->assertSessionHasNoErrors();

        $this->assertEquals($before, $zone->fresh()->updated_at);
        $this->assertSame(0, ActivityLogEntry::where('description', 'zone.updated')->count());
    }

    public function test_the_code_cannot_be_changed(): void
    {
        $zone = Zone::factory()->create(['code' => 'FIXED']);

        $this->actingAs($this->admin)->patch("/admin/zones/{$zone->id}", ['code' => 'OTHER'])->assertSessionHasErrors(['code' => 'The zone code cannot be changed.']);

        $this->assertSame('FIXED', $zone->fresh()->code);
    }

    #[DataProvider('invalidUpdates')]
    public function test_an_invalid_update_changes_nothing(array $payload, string $field): void
    {
        $zone = Zone::factory()->around(40.18, 44.51, 0.05)->create(['name' => 'Stays', 'priority' => 2]);
        $payload = array_map(fn ($v) => $v === '__bowtie__' ? $this->bowtie() : $v, $payload);

        $this->actingAs($this->admin)->patch("/admin/zones/{$zone->id}", $payload)->assertSessionHasErrors($field);

        $this->assertSame(['Stays', 2], [$zone->fresh()->name, $zone->fresh()->priority]);
        $this->assertEqualsWithDelta(44.46, $this->geoJsonOf($zone)['coordinates'][0][0][0][0], 1e-6);
        $this->assertSame(0, ActivityLogEntry::where('description', 'zone.updated')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidUpdates(): array
    {
        return [
            'an empty name' => [['name' => ''], 'name'],
            'a bad timezone' => [['timezone' => 'Nowhere/City'], 'timezone'],
            'a bad currency' => [['currency' => 'ZZZ'], 'currency'],
            'priority out of range' => [['priority' => 5000], 'priority'],
            'a self-intersecting polygon' => [['name' => 'Valid name too', 'polygon' => '__bowtie__'], 'polygon'],
            'an empty polygon value' => [['polygon' => ''], 'polygon'],
        ];
    }

    public function test_an_unknown_zone_is_a_404(): void
    {
        $this->actingAs($this->admin)->patch('/admin/zones/999999', ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/zones/999999/deactivate')->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/zones/999999/activate')->assertNotFound();
    }

    // ---- deactivate / activate ----

    public function test_a_zone_is_deactivated_and_reactivated_with_an_audit_trail(): void
    {
        $zone = Zone::factory()->create();

        $this->actingAs($this->admin)->post("/admin/zones/{$zone->id}/deactivate", ['reason' => 'Road works'])->assertSessionHas('success', 'Zone deactivated.');
        $this->assertFalse($zone->fresh()->is_active);
        $entry = ActivityLogEntry::where('description', 'zone.deactivated')->sole();
        $this->assertSame('Road works', $entry->properties['reason']);
        $this->assertSame(['is_active' => true], $entry->attribute_changes['old']);
        $this->assertSame(['is_active' => false], $entry->attribute_changes['attributes']);

        $this->actingAs($this->admin)->post("/admin/zones/{$zone->id}/activate")->assertSessionHas('success', 'Zone activated.');
        $this->assertTrue($zone->fresh()->is_active);
        $this->assertSame($this->admin->id, ActivityLogEntry::where('description', 'zone.activated')->sole()->causer_id);
    }

    public function test_deactivating_twice_is_a_conflict_with_one_effect(): void
    {
        $zone = Zone::factory()->create();
        $this->actingAs($this->admin)->post("/admin/zones/{$zone->id}/deactivate");

        $this->actingAs($this->admin)->postJson("/admin/zones/{$zone->id}/deactivate")->assertStatus(409)->assertJson(['message' => 'This zone is already inactive.']);
        $this->actingAs($this->admin)->postJson('/admin/zones/'.Zone::factory()->create()->id.'/activate')->assertStatus(409)->assertJson(['message' => 'This zone is already active.']);

        $this->assertSame(1, ActivityLogEntry::where('description', 'zone.deactivated')->count());
    }

    public function test_the_admin_ui_sees_a_conflict_as_a_flash_message(): void
    {
        $zone = Zone::factory()->inactive()->create();

        $this->actingAs($this->admin)->from('/admin/zones')->withHeaders(['X-Inertia' => 'true'])->post("/admin/zones/{$zone->id}/deactivate")
            ->assertRedirect('/admin/zones')->assertSessionHas('error', 'This zone is already inactive.');
    }

    public function test_the_reason_is_optional_but_limited(): void
    {
        $zone = Zone::factory()->create();

        $this->actingAs($this->admin)->post("/admin/zones/{$zone->id}/deactivate", ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');
        $this->assertTrue($zone->fresh()->is_active);

        $this->actingAs($this->admin)->post("/admin/zones/{$zone->id}/deactivate", ['reason' => str_repeat('x', 500)])->assertSessionHasNoErrors();
        $this->assertFalse($zone->fresh()->is_active);
    }

    public function test_there_is_no_delete_route_and_zones_survive_deactivation(): void
    {
        $zone = Zone::factory()->create();

        $this->actingAs($this->admin)->delete("/admin/zones/{$zone->id}")->assertStatus(405);
        $this->actingAs($this->admin)->post("/admin/zones/{$zone->id}/deactivate");

        $this->assertNotNull(Zone::find($zone->id));
    }

    // ---- permissions ----

    #[DataProvider('writeRequests')]
    public function test_only_roles_with_zones_manage_can_write(string $method, string $uri): void
    {
        $zone = Zone::factory()->create(['name' => 'Untouched']);
        $uri = str_replace('{id}', (string) $zone->id, $uri);

        foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->actingAs($this->admin($role))->call($method, $uri, ['name' => 'Hacked', 'code' => 'HACK', 'timezone' => 'UTC', 'currency' => 'AMD', 'polygon' => ZoneFactory::squareGeoJson(1, 1)])->assertForbidden();
        }

        $this->assertSame('Untouched', $zone->fresh()->name);
        $this->assertSame(1, Zone::count());
        $this->assertSame(0, ActivityLogEntry::where('description', 'like', 'zone.%')->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writeRequests(): array
    {
        return [
            'create' => ['POST', '/admin/zones'],
            'update' => ['PATCH', '/admin/zones/{id}'],
            'deactivate' => ['POST', '/admin/zones/{id}/deactivate'],
            'activate' => ['POST', '/admin/zones/{id}/activate'],
        ];
    }

    public function test_the_page_is_for_zones_manage_only_and_guests_go_to_login(): void
    {
        foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->actingAs($this->admin($role))->get('/admin/zones')->assertForbidden();
        }
        $this->actingAs($this->admin(AdminRole::SuperAdmin))->get('/admin/zones')->assertOk();

        auth()->logout();
        $this->get('/admin/zones')->assertRedirect('/login');
        $this->post('/admin/zones', $this->payload())->assertRedirect('/login');
        $this->assertSame(0, Zone::count());
    }

    public function test_the_polygon_never_reaches_sql_unbound_a_hostile_value_is_just_refused(): void
    {
        $polygon = ['type' => "Polygon'); DROP TABLE zones; --", 'coordinates' => [[[1, 1], [2, 1], [2, 2], [1, 1]]]];

        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['polygon' => $polygon]))->assertSessionHasErrors('polygon');
        $this->actingAs($this->admin)->post('/admin/zones', $this->payload(['name' => "Robert'); DROP TABLE zones;--", 'code' => 'ROBERT']))->assertSessionHasNoErrors();

        $this->assertSame("Robert'); DROP TABLE zones;--", Zone::where('code', 'ROBERT')->value('name'));
        $this->assertSame(1, Zone::count());
    }
}
