<?php

namespace Tests\Feature\Riders;

use App\Enums\AdminRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

class RiderDetailTest extends AdminTestCase
{
    private function viewer(AdminRole $role = AdminRole::Support): User
    {
        return $this->admin($role);
    }

    public function test_the_detail_page_carries_everything_it_shows(): void
    {
        $rider = User::factory()->rider()->create(['name' => 'Priya Mehta', 'email' => 'priya@example.com', 'locale' => 'hy', 'created_at' => '2026-01-12 10:00:00']);
        $rider->forceFill(['phone' => '091 210 443', 'last_login_at' => '2026-10-05 09:30:00'])->save();

        $this->actingAs($this->viewer())->get("/admin/riders/{$rider->id}")->assertInertia(fn (Assert $p) => $p
            ->component('Riders/Show')
            ->where('rider.id', $rider->id)->where('rider.code', sprintf('R-%05d', $rider->id))->where('rider.name', 'Priya Mehta')
            ->where('rider.phone', '+37491210443')->where('rider.email', 'priya@example.com')->where('rider.status', 'active')
            ->where('rider.locale', 'hy')->where('rider.registered_at', '2026-01-12T10:00:00+00:00')->where('rider.last_login_at', '2026-10-05T09:30:00+00:00')
            ->where('rider.raw', ['name' => 'Priya Mehta', 'email' => 'priya@example.com', 'phone' => '+37491210443', 'locale' => 'hy'])
            ->where('rider.suspension_reason', null)
            ->where('rider.trips_count', 0)
            ->where('rider.stats', ['total_spent' => ['amount' => 0, 'currency' => 'AMD'], 'cancellation_rate_bp' => 0, 'open_tickets' => 0])
            ->where('rider.payment_methods', [])->where('rider.deletion_scheduled_for', null)
            ->where('rider.records.trips', [])->where('rider.records.payments', [])->where('rider.records.tickets', [])->where('rider.records.ratings', [])
            ->where('tab', 'trips')->where('tabs', ['trips', 'payments', 'payment-methods', 'tickets', 'ratings', 'activity'])
            ->where('locales', ['en', 'hy', 'ru'])
            ->where('actions', ['edit' => "/admin/riders/{$rider->id}", 'suspend' => "/admin/riders/{$rider->id}/suspend", 'reactivate' => "/admin/riders/{$rider->id}/reactivate"]));
    }

    public function test_nothing_from_the_fixture_riders_leaks_in_and_no_secrets_are_sent(): void
    {
        $rider = User::factory()->rider()->create(['password' => 'Secret-Password-1!']);

        $props = $this->actingAs($this->viewer())->get("/admin/riders/{$rider->id}")->viewData('page')['props']['rider'];

        $this->assertStringNotContainsString('Secret-Password', json_encode($props));
        $this->assertArrayNotHasKey('password', $props);
        $this->assertArrayNotHasKey('phone_hash', $props);
        $this->assertArrayNotHasKey('remember_token', $props);
        $this->assertSame([], $props['records']['trips'], 'No trips table yet: empty, not fixture trips.');
    }

    public function test_a_suspended_rider_shows_the_reason_and_other_statuses_hide_it(): void
    {
        $suspended = User::factory()->rider()->suspended('Chargeback fraud')->create();
        $active = User::factory()->rider()->create(['suspension_reason' => 'Left over from before']);

        $this->actingAs($this->viewer())->get("/admin/riders/{$suspended->id}")->assertInertia(fn (Assert $p) => $p->where('rider.status', 'suspended')->where('rider.suspension_reason', 'Chargeback fraud'));
        $this->actingAs($this->viewer())->get("/admin/riders/{$active->id}")->assertInertia(fn (Assert $p) => $p->where('rider.suspension_reason', null));
    }

    public function test_the_tab_comes_from_the_url_and_unknown_tabs_fall_back(): void
    {
        $rider = User::factory()->rider()->create();

        foreach (['trips', 'payments', 'payment-methods', 'tickets', 'ratings', 'activity'] as $tab) {
            $this->actingAs($this->viewer())->get("/admin/riders/{$rider->id}?tab={$tab}")->assertInertia(fn (Assert $p) => $p->where('tab', $tab));
        }
        $this->actingAs($this->viewer())->get("/admin/riders/{$rider->id}?tab=secrets")->assertInertia(fn (Assert $p) => $p->where('tab', 'trips'));
        $this->actingAs($this->viewer())->get("/admin/riders/{$rider->id}?tab[]=x")->assertOk();
    }

    public function test_the_activity_tab_lists_what_admins_did_to_this_rider_only(): void
    {
        $rider = User::factory()->rider()->create();
        $other = User::factory()->rider()->create();
        $actor = $this->admin(AdminRole::Support, ['name' => 'Riley Chen']);
        $audit = app(AuditLogger::class);
        $audit->record($actor, 'rider.suspended', $rider, 'Chargeback fraud', ['status' => 'active'], ['status' => 'suspended']);
        $audit->record($actor, 'rider.reactivated', $rider, 'Appeal accepted');
        $audit->record($actor, 'rider.suspended', $other, 'Someone else');

        $this->actingAs($this->viewer())->get("/admin/riders/{$rider->id}?tab=activity")->assertInertia(fn (Assert $p) => $p
            ->has('rider.records.activity', 2)
            ->where('rider.records.activity.0.description', 'rider.reactivated by Riley Chen: Appeal accepted')
            ->where('rider.records.activity.1.description', 'rider.suspended by Riley Chen: Chargeback fraud')
            ->where('rider.records.activity.0.reference', fn ($ref) => str_starts_with($ref, 'ACT-')));
    }

    public function test_only_riders_have_a_detail_page(): void
    {
        $viewer = $this->viewer();
        $driverOnly = User::factory()->driver()->create();
        $adminOnly = $this->admin();
        $deleted = User::factory()->rider()->create();
        $deleted->delete();
        $both = User::factory()->rider()->driver()->create();

        foreach ([$driverOnly->id, $adminOnly->id, $deleted->id, 987654, 99999999999999999999] as $id) {
            $this->actingAs($viewer)->get("/admin/riders/{$id}")->assertNotFound();
        }
        $this->actingAs($viewer)->get("/admin/riders/{$both->id}")->assertOk();
    }

    public function test_every_admin_role_can_open_a_rider_and_guests_cannot(): void
    {
        $rider = User::factory()->rider()->create();

        foreach (AdminRole::cases() as $role) {
            $this->actingAs($this->admin($role))->get("/admin/riders/{$rider->id}")->assertOk();
        }
        auth()->logout();
        $this->get("/admin/riders/{$rider->id}")->assertRedirect('/login');
    }
}
