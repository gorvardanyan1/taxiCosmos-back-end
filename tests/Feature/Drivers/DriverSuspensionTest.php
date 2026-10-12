<?php

namespace Tests\Feature\Drivers;

use App\Enums\AdminRole;
use App\Enums\TripCancelledBy;
use App\Enums\TripStatus;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

/**
 * POST /admin/drivers/{id}/suspend and /reactivate: a reason is required, the account loses its sessions,
 * and a driver on a trip cannot be suspended until the trip is cancelled or reassigned.
 */
class DriverSuspensionTest extends AdminTestCase
{
    private User $support;

    private DriverProfile $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = $this->admin(AdminRole::Support, ['name' => 'Riley Chen']);
        $this->driver = DriverProfile::factory()->approved()->online()->for(User::factory()->driver()->create(['name' => 'Gor Hakobyan']), 'user')->create();
    }

    private function suspend(?DriverProfile $driver = null, array $body = ['reason' => 'Repeated complaints'], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->support)->post('/admin/drivers/'.($driver ?? $this->driver)->id.'/suspend', $body);
    }

    private function reactivate(?DriverProfile $driver = null, array $body = ['reason' => 'Appeal accepted'], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->support)->post('/admin/drivers/'.($driver ?? $this->driver)->id.'/reactivate', $body);
    }

    public function test_suspending_sets_the_status_keeps_the_reason_takes_the_driver_offline_and_signs_them_out(): void
    {
        $token = $this->driver->user->createToken('device', ['driver'])->plainTextToken;

        $this->suspend()->assertSessionHasNoErrors()->assertSessionHas('success', 'Driver suspended.');

        $user = $this->driver->user->fresh();
        $this->assertSame('suspended', $user->status->value);
        $this->assertSame('Repeated complaints', $user->suspension_reason);
        $this->assertSame('offline', $this->driver->fresh()->availability->value);
        $this->assertSame(0, $user->tokens()->count(), 'every mobile session is gone');
        $this->assertNotEmpty($token);
    }

    public function test_the_suspension_is_audited_with_the_actor_and_the_reason(): void
    {
        $this->suspend();

        $entry = ActivityLogEntry::where('description', 'driver.suspended')->sole();
        $this->assertSame($this->support->id, $entry->causer_id);
        $this->assertSame($this->driver->id, $entry->subject_id);
        $this->assertSame('Repeated complaints', $entry->properties['reason']);
        $this->assertSame(['status' => 'active'], $entry->attribute_changes['old']);
        $this->assertSame(['status' => 'suspended'], $entry->attribute_changes['attributes']);
    }

    public function test_reactivating_restores_the_account_but_leaves_the_driver_offline(): void
    {
        $this->suspend();

        $this->reactivate()->assertSessionHasNoErrors()->assertSessionHas('success', 'Driver reactivated.');

        $user = $this->driver->user->fresh();
        $this->assertSame('active', $user->status->value);
        $this->assertNull($user->suspension_reason);
        $this->assertSame('offline', $this->driver->fresh()->availability->value, 'the driver goes online again themselves');
        $entry = ActivityLogEntry::where('description', 'driver.reactivated')->sole();
        $this->assertSame('Appeal accepted', $entry->properties['reason']);
        $this->assertSame($this->support->id, $entry->causer_id);
    }

    public function test_a_reason_is_required_for_both_actions(): void
    {
        $this->suspend(null, [])->assertSessionHasErrors('reason');
        $this->suspend(null, ['reason' => '   '])->assertSessionHasErrors('reason');
        $this->suspend(null, ['reason' => str_repeat('r', 501)])->assertSessionHasErrors('reason');
        $this->assertSame('active', $this->driver->user->fresh()->status->value);

        $this->suspend();
        $this->reactivate(null, [])->assertSessionHasErrors('reason');
        $this->reactivate(null, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('suspended', $this->driver->user->fresh()->status->value);
        $this->assertSame(1, ActivityLogEntry::whereIn('description', ['driver.suspended', 'driver.reactivated'])->count());
    }

    // ---- a driver on a trip ----

    /**
     * @return array<string, array{TripStatus}>
     */
    public static function liveStatuses(): array
    {
        return ['matched' => [TripStatus::Matched], 'arrived' => [TripStatus::Arrived], 'in progress' => [TripStatus::InProgress]];
    }

    #[DataProvider('liveStatuses')]
    public function test_a_driver_on_a_live_trip_cannot_be_suspended(TripStatus $status): void
    {
        $trip = Trip::factory()->create(['driver_id' => $this->driver->id]);
        $trip->forceFill(['status' => $status->value, 'matched_at' => now()])->saveQuietly();

        $this->actingAs($this->support)->postJson('/admin/drivers/'.$this->driver->id.'/suspend', ['reason' => 'Complaints'])
            ->assertStatus(409)->assertJson(['message' => 'This driver is on a trip. Force-cancel or reassign the trip first.']);

        $this->assertSame('active', $this->driver->user->fresh()->status->value);
        $this->assertSame('online', $this->driver->fresh()->availability->value, 'nothing was changed');
        $this->assertSame(0, ActivityLogEntry::where('description', 'driver.suspended')->count());
    }

    public function test_the_message_is_shown_on_the_page_the_admin_came_from(): void
    {
        Trip::factory()->inProgress()->create(['driver_id' => $this->driver->id]);

        $this->actingAs($this->support)->from('/admin/drivers/'.$this->driver->id)->withHeaders(['X-Inertia' => 'true'])
            ->post('/admin/drivers/'.$this->driver->id.'/suspend', ['reason' => 'Complaints'])
            ->assertRedirect('/admin/drivers/'.$this->driver->id)->assertSessionHas('error', 'This driver is on a trip. Force-cancel or reassign the trip first.');
    }

    public function test_finished_cancelled_and_other_drivers_trips_do_not_block_a_suspension(): void
    {
        $other = DriverProfile::factory()->create();
        Trip::factory()->completed()->create(['driver_id' => $this->driver->id]);
        Trip::factory()->cancelled()->create(['driver_id' => $this->driver->id]);
        Trip::factory()->inProgress()->create(['driver_id' => $other->id]);

        $this->suspend()->assertSessionHasNoErrors();

        $this->assertSame('suspended', $this->driver->user->fresh()->status->value);
    }

    public function test_once_the_trip_is_cancelled_the_driver_can_be_suspended(): void
    {
        $trip = Trip::factory()->inProgress()->create(['driver_id' => $this->driver->id]);
        $this->actingAs($this->support)->postJson('/admin/drivers/'.$this->driver->id.'/suspend', ['reason' => 'x'])->assertStatus(409);

        $trip->transitionTo(TripStatus::Cancelled, TripCancelledBy::Admin, 'Force-cancelled');
        $this->suspend()->assertSessionHasNoErrors();

        $this->assertSame('suspended', $this->driver->user->fresh()->status->value);
    }

    // ---- refusals ----

    public function test_only_active_drivers_can_be_suspended_and_only_suspended_ones_reactivated(): void
    {
        $this->suspend();
        $this->actingAs($this->support)->postJson('/admin/drivers/'.$this->driver->id.'/suspend', ['reason' => 'Again'])
            ->assertStatus(409)->assertJson(['message' => 'Only active drivers can be suspended.']);

        $active = DriverProfile::factory()->create();
        $this->actingAs($this->support)->postJson('/admin/drivers/'.$active->id.'/reactivate', ['reason' => 'Why'])
            ->assertStatus(409)->assertJson(['message' => 'Only suspended drivers can be reactivated.']);

        $deactivated = DriverProfile::factory()->for(User::factory()->driver()->create(['status' => 'deactivated']), 'user')->create();
        $this->actingAs($this->support)->postJson('/admin/drivers/'.$deactivated->id.'/suspend', ['reason' => 'x'])->assertStatus(409);
    }

    public function test_an_admin_cannot_suspend_their_own_driver_account_or_another_admin_through_the_driver_list(): void
    {
        $admin = $this->admin(AdminRole::Admin);
        $own = DriverProfile::factory()->for($admin, 'user')->create();
        $admin->forceFill(['is_driver' => true])->save();
        $other = $this->admin(AdminRole::Support);
        $theirs = DriverProfile::factory()->for($other, 'user')->create();

        $this->actingAs($admin)->postJson('/admin/drivers/'.$own->id.'/suspend', ['reason' => 'x'])->assertStatus(409)->assertJson(['message' => 'You cannot suspend your own account.']);
        $this->actingAs($admin)->postJson('/admin/drivers/'.$theirs->id.'/suspend', ['reason' => 'x'])->assertStatus(409)
            ->assertJson(['message' => 'This account has admin access. Admin accounts are managed under Settings → Users.']);

        $this->assertSame('active', $admin->fresh()->status->value);
        $this->assertSame('active', $other->fresh()->status->value);
    }

    public function test_an_unknown_driver_is_a_404(): void
    {
        $this->actingAs($this->support)->post('/admin/drivers/999999/suspend', ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->support)->post('/admin/drivers/999999/reactivate', ['reason' => 'x'])->assertNotFound();
    }

    public function test_a_person_who_is_also_a_rider_is_suspended_as_a_whole(): void
    {
        $this->driver->user->forceFill(['is_rider' => true])->save();

        $this->suspend();

        $this->assertSame('suspended', $this->driver->user->fresh()->status->value, 'suspension is a property of the account');
    }

    // ---- who may ----

    public function test_roles_without_drivers_suspend_get_403_and_nothing_changes(): void
    {
        foreach ([AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->suspend(null, ['reason' => 'x'], $this->admin($role))->assertForbidden();
        }
        $this->driver->user->forceFill(['status' => 'suspended'])->save();
        foreach ([AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->reactivate(null, ['reason' => 'x'], $this->admin($role))->assertForbidden();
        }

        $this->assertSame('suspended', $this->driver->user->fresh()->status->value);
        $this->assertSame(0, ActivityLogEntry::whereIn('description', ['driver.suspended', 'driver.reactivated'])->count());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->call('POST', '/admin/drivers/'.$this->driver->id.'/suspend', ['reason' => 'x'])->assertRedirect('/login');
        $this->assertSame('active', $this->driver->user->fresh()->status->value);
    }

    public function test_the_page_shows_the_suspension_reason_and_the_status_in_the_list(): void
    {
        $this->suspend();

        $this->actingAs($this->support)->get('/admin/drivers/'.$this->driver->id)->assertInertia(fn ($page) => $page
            ->where('driver.status', 'suspended')->where('driver.suspension_reason', 'Repeated complaints'));
        $this->actingAs($this->support)->get('/admin/drivers')->assertInertia(fn ($page) => $page->where('drivers.data.0.status', 'suspended'));
    }
}
