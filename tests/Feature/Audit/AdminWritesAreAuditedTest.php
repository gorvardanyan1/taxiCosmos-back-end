<?php

namespace Tests\Feature\Audit;

use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverDocument;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Guard for docs/audit-log.md: every admin route that changes data is listed here with the audit
 * action it must write, and is exercised. A new write route without an entry fails the first
 * test, so a task cannot add an admin action that leaves no trace.
 */
class AdminWritesAreAuditedTest extends AuditTestCase
{
    /**
     * route name => [expected audit action, performs the request as the admin].
     *
     * @return array<string, array{string, callable(User): TestResponse}>
     */
    private function registry(): array
    {
        $document = fn () => DriverDocument::factory()->create();

        return [
            'admin.drivers.documents.approve' => ['driver.document.approved', function (User $admin) use ($document) {
                $doc = $document();

                return $this->actingAs($admin)->post("/admin/drivers/{$doc->driver_id}/documents/{$doc->id}/approve");
            }],
            'admin.drivers.documents.reject' => ['driver.document.rejected', function (User $admin) use ($document) {
                $doc = $document();

                return $this->actingAs($admin)->post("/admin/drivers/{$doc->driver_id}/documents/{$doc->id}/reject", ['reason' => 'Unreadable']);
            }],
            'admin.riders.update' => ['rider.updated', fn (User $admin) => $this->actingAs($admin)->patch('/admin/riders/'.User::factory()->rider()->create()->id, ['name' => 'Renamed '.uniqid(), 'reason' => 'Guard test'])],
            'admin.riders.suspend' => ['rider.suspended', fn (User $admin) => $this->actingAs($admin)->post('/admin/riders/'.User::factory()->rider()->create()->id.'/suspend', ['reason' => 'Guard test'])],
            'admin.riders.reactivate' => ['rider.reactivated', fn (User $admin) => $this->actingAs($admin)->post('/admin/riders/'.User::factory()->rider()->suspended()->create()->id.'/reactivate', ['reason' => 'Guard test'])],
            'admin.zones.store' => ['zone.created', fn (User $admin) => $this->actingAs($admin)->post('/admin/zones', [
                'name' => 'Guard zone', 'code' => strtoupper('GUARD-'.uniqid()), 'timezone' => 'Asia/Yerevan', 'currency' => 'AMD', 'polygon' => ZoneFactory::squareGeoJson(40.18, 44.51),
            ])],
            'admin.zones.update' => ['zone.updated', fn (User $admin) => $this->actingAs($admin)->patch('/admin/zones/'.Zone::factory()->create()->id, ['name' => 'Renamed '.uniqid()])],
            'admin.zones.deactivate' => ['zone.deactivated', fn (User $admin) => $this->actingAs($admin)->post('/admin/zones/'.Zone::factory()->create()->id.'/deactivate')],
            'admin.zones.activate' => ['zone.activated', fn (User $admin) => $this->actingAs($admin)->post('/admin/zones/'.Zone::factory()->inactive()->create()->id.'/activate')],
        ];
    }

    /**
     * @return list<LaravelRoute>
     */
    private function adminWriteRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), 'admin') && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->values()->all();
    }

    public function test_every_admin_write_route_is_registered_in_this_guard(): void
    {
        $names = array_map(fn (LaravelRoute $route) => $route->getName() ?? $route->uri(), $this->adminWriteRoutes());

        $this->assertEqualsCanonicalizing(
            array_keys($this->registry()),
            $names,
            'Add the new admin write route to AdminWritesAreAuditedTest::registry() and log it through AuditLogger (docs/audit-log.md).',
        );
    }

    public function test_each_admin_write_leaves_exactly_one_audit_entry_for_its_action_by_the_actor(): void
    {
        foreach ($this->registry() as $route => [$action, $perform]) {
            $admin = $this->admin();
            $before = ActivityLogEntry::where('description', $action)->count();

            $perform($admin)->assertRedirect();

            $entries = ActivityLogEntry::where('description', $action)->orderByDesc('id')->get();
            $this->assertCount($before + 1, $entries, "{$route} must write exactly one {$action} entry.");
            $this->assertSame($admin->id, $entries->first()->causer_id, "{$route}: the actor is the signed-in admin.");
            $this->assertNotNull($entries->first()->subject_id, "{$route}: the entry names its target.");
            $this->assertNotEmpty($entries->first()->properties['ip'], "{$route}: the entry records the IP.");
            $this->assertSame($admin->name, $entries->first()->properties['actor_name']);
        }
    }

    public function test_a_refused_write_leaves_no_entry(): void
    {
        $doc = DriverDocument::factory()->approved()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/admin/drivers/{$doc->driver_id}/documents/{$doc->id}/approve")->assertStatus(409);
        $this->actingAs($admin)->postJson("/admin/drivers/{$doc->driver_id}/documents/{$doc->id}/reject", ['reason' => ''])->assertStatus(422);

        $this->assertSame(0, ActivityLogEntry::count());
    }
}
