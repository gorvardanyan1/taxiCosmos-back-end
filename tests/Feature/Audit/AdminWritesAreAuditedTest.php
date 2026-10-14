<?php

namespace Tests\Feature\Audit;

use App\Enums\AdminRole;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverBankAccount;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithTwoFactor;

/**
 * Guard for docs/audit-log.md: every admin route that changes data is listed here with the audit
 * action it must write, and is exercised. A new write route without an entry fails the first
 * test, so a task cannot add an admin action that leaves no trace.
 */
class AdminWritesAreAuditedTest extends AuditTestCase
{
    use InteractsWithTwoFactor;

    /**
     * route name => [expected audit action, performs the request as the admin].
     *
     * @return array<string, array{string, callable(User): TestResponse}>
     */
    private function registry(): array
    {
        $document = fn () => DriverDocument::factory()->create();

        return [
            'admin.drivers.update' => ['driver.updated', fn (User $admin) => $this->actingAs($admin)->patch('/admin/drivers/'.DriverProfile::factory()->create()->id, ['name' => 'Renamed '.uniqid(), 'reason' => 'Guard test'])],
            'admin.drivers.suspend' => ['driver.suspended', fn (User $admin) => $this->actingAs($admin)->post('/admin/drivers/'.DriverProfile::factory()->create()->id.'/suspend', ['reason' => 'Guard test'])],
            'admin.drivers.reactivate' => ['driver.reactivated', fn (User $admin) => $this->actingAs($admin)->post('/admin/drivers/'.DriverProfile::factory()->for(User::factory()->driver()->suspended()->create(), 'user')->create()->id.'/reactivate', ['reason' => 'Guard test'])],
            'admin.drivers.vehicles.store' => ['driver.vehicle.added', fn (User $admin) => $this->actingAs($admin)->post('/admin/drivers/'.DriverProfile::factory()->create()->id.'/vehicles', [
                'make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'color' => 'White', 'plate_number' => strtoupper('GD'.uniqid()), 'vehicle_class' => 'economy',
            ])],
            'admin.drivers.vehicles.update' => ['driver.vehicle.updated', function (User $admin) {
                $vehicle = Vehicle::factory()->active()->create();

                return $this->actingAs($admin)->patch("/admin/drivers/{$vehicle->driver_id}/vehicles/{$vehicle->id}", ['color' => 'Pink '.uniqid()]);
            }],
            'admin.drivers.vehicles.set-primary' => ['driver.vehicle.primary_set', function (User $admin) {
                $vehicle = Vehicle::factory()->active()->create();

                return $this->actingAs($admin)->post("/admin/drivers/{$vehicle->driver_id}/vehicles/{$vehicle->id}/set-primary");
            }],
            'admin.drivers.bank-accounts.reveal' => ['driver.bank_account.revealed', function (User $admin) {
                $account = DriverBankAccount::factory()->create();

                return $this->actingAs($admin)->postJson("/admin/drivers/{$account->driver_id}/bank-accounts/{$account->id}/reveal");
            }],
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
            'admin.commission-rules.store' => ['commission_rule.created', fn (User $admin) => $this->actingAs($admin)->post('/admin/commission-rules', [
                'zone_id' => Zone::factory()->create()->id, 'vehicle_class' => null, 'type' => 'percentage', 'rate_bps' => 1800, 'fixed_amount' => 0, 'min_commission' => 0, 'max_commission' => null, 'applies_to_surge' => true,
            ])],
            'admin.fare-rules.store' => ['fare_rule.created', fn (User $admin) => $this->actingAs($admin)->post('/admin/zones/'.Zone::factory()->create()->id.'/fare-rules', [
                'vehicle_class' => 'economy', 'base_fare' => 500, 'per_km' => 150, 'per_minute' => 20, 'min_fare' => 800, 'waiting_free_minutes' => 3, 'waiting_per_minute' => 30,
                'cancellation_fee' => 500, 'cancellation_free_seconds' => 120, 'booking_fee' => 0, 'tax_rate_bps' => 0, 'tax_inclusive' => false,
            ])],
            'admin.account.locale' => ['account.locale_changed', fn (User $admin) => $this->actingAs($admin)->patch('/admin/account/locale', ['locale' => $admin->locale === 'ru' ? 'hy' : 'ru'])],
            'admin.account.profile' => ['account.profile_updated', fn (User $admin) => $this->actingAs($admin)->patch('/admin/account/profile', ['name' => 'Renamed '.uniqid(), 'timezone' => 'Asia/Yerevan'])],
            'admin.account.password' => ['account.password_changed', fn (User $admin) => $this->actingAs($admin)->put('/admin/account/password', ['current_password' => 'password', 'password' => 'Brand-New-Pass!2026', 'password_confirmation' => 'Brand-New-Pass!2026'])],
            'admin.account.sessions.destroy' => ['account.other_sessions_ended', fn (User $admin) => $this->actingAs($admin)->delete('/admin/account/sessions', ['password' => 'password'])],
            'admin.account.two-factor.destroy' => ['admin.two_factor.disabled', function (User $admin) {
                $this->enableTwoFactor($admin);

                return $this->actingAs($admin)->delete('/admin/account/two-factor', ['password' => 'password']);
            }],
            'admin.account.two-factor.recovery-codes' => ['admin.two_factor.recovery_codes_regenerated', function (User $admin) {
                $this->enableTwoFactor($admin);

                return $this->actingAs($admin)->post('/admin/account/two-factor/recovery-codes', ['password' => 'password']);
            }],
            'admin.settings.users.two-factor.reset' => ['admin.two_factor.reset', function (User $admin) {
                $target = User::factory()->admin(AdminRole::Support)->create();
                $this->enableTwoFactor($target);

                return $this->actingAs($admin)->post("/admin/settings/users/{$target->id}/two-factor/reset", ['password' => 'password', 'reason' => 'Lost phone']);
            }],
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

            // Each write is a fresh browser: a password change in one must not leave its session hash behind for the next admin.
            $this->flushSession();

            // Every admin write redirects back to its page, except the bank-account reveal, which answers with the number as JSON.
            $response = $perform($admin);
            $route === 'admin.drivers.bank-accounts.reveal' ? $response->assertOk() : $response->assertRedirect();

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
