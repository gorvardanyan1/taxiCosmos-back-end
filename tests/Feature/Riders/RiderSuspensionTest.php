<?php

namespace Tests\Feature\Riders;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\Audit\ActivityLogEntry;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Riders\RiderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;

class RiderSuspensionTest extends AdminTestCase
{
    private User $support;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = $this->admin(AdminRole::Support, ['name' => 'Riley Chen']);
        $this->rider = User::factory()->rider()->create(['name' => 'Sun Li']);
    }

    private function suspend(?User $rider = null, array $body = ['reason' => 'Repeated chargebacks'], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->support)->post('/admin/riders/'.($rider ?? $this->rider)->id.'/suspend', $body);
    }

    private function reactivate(?User $rider = null, array $body = ['reason' => 'Appeal accepted'], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->support)->post('/admin/riders/'.($rider ?? $this->rider)->id.'/reactivate', $body);
    }

    public function test_suspending_sets_the_status_and_keeps_the_reason_on_the_account(): void
    {
        $this->suspend()->assertSessionHas('success', 'Rider suspended.');

        $fresh = $this->rider->fresh();
        $this->assertSame(UserStatus::Suspended, $fresh->status);
        $this->assertSame('Repeated chargebacks', $fresh->suspension_reason);
        $this->assertFalse($fresh->isActive());
    }

    public function test_suspending_revokes_every_sanctum_token_of_that_rider_and_only_theirs(): void
    {
        $this->rider->createToken('phone', ['rider']);
        $this->rider->createToken('tablet', ['rider']);
        $other = User::factory()->rider()->create();
        $other->createToken('phone', ['rider']);

        $this->suspend();

        $this->assertSame(0, $this->rider->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_suspending_is_audited_with_actor_target_reason_ip_and_the_status_change(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.4.9']);
        $this->suspend();

        $entry = ActivityLogEntry::where('description', 'rider.suspended')->sole();
        $this->assertSame($this->support->id, $entry->causer_id);
        $this->assertSame($this->rider->id, $entry->subject_id);
        $this->assertSame('Repeated chargebacks', $entry->properties['reason']);
        $this->assertSame('Riley Chen', $entry->properties['actor_name']);
        $this->assertSame('support', $entry->properties['actor_role']);
        $this->assertSame('10.10.4.9', $entry->properties['ip']);
        $this->assertSame(['status' => 'active'], $entry->attribute_changes['old']);
        $this->assertSame(['status' => 'suspended'], $entry->attribute_changes['attributes']);
    }

    #[DataProvider('badReasons')]
    public function test_a_suspension_without_a_proper_reason_is_refused_and_nothing_changes(mixed $reason): void
    {
        $this->rider->createToken('phone');

        $this->suspend(body: ['reason' => $reason])->assertSessionHasErrors('reason');

        $this->assertSame(UserStatus::Active, $this->rider->fresh()->status);
        $this->assertSame(1, $this->rider->tokens()->count(), 'Tokens stay when the suspension is refused.');
        $this->assertSame(0, ActivityLogEntry::where('description', 'rider.suspended')->count());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badReasons(): array
    {
        return ['missing' => [null], 'empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('x', 501)], 'an array' => [['x']]];
    }

    public function test_the_longest_allowed_reason_is_accepted(): void
    {
        $this->suspend(body: ['reason' => str_repeat('x', 500)])->assertSessionHasNoErrors();

        $this->assertSame(500, mb_strlen($this->rider->fresh()->suspension_reason));
    }

    public function test_only_an_active_rider_can_be_suspended(): void
    {
        foreach ([UserStatus::Suspended, UserStatus::Deactivated, UserStatus::PendingDeletion] as $status) {
            $rider = User::factory()->rider()->create(['status' => $status]);

            $this->actingAs($this->support)->postJson("/admin/riders/{$rider->id}/suspend", ['reason' => 'x'])
                ->assertStatus(409)->assertJson(['message' => 'Only active riders can be suspended.']);

            $this->assertSame($status, $rider->fresh()->status);
        }
        $this->assertSame(0, ActivityLogEntry::where('description', 'rider.suspended')->count());
    }

    public function test_suspending_twice_has_exactly_one_effect(): void
    {
        $this->suspend();
        $this->actingAs($this->support)->postJson("/admin/riders/{$this->rider->id}/suspend", ['reason' => 'Again'])->assertStatus(409);

        $this->assertSame('Repeated chargebacks', $this->rider->fresh()->suspension_reason, 'The second request changed nothing.');
        $this->assertSame(1, ActivityLogEntry::where('description', 'rider.suspended')->count());
    }

    public function test_the_admin_ui_sees_a_conflict_as_a_flash_message_on_the_same_page(): void
    {
        $suspended = User::factory()->rider()->suspended()->create();

        $this->actingAs($this->support)->from('/admin/riders')->withHeaders(['X-Inertia' => 'true'])
            ->post("/admin/riders/{$suspended->id}/suspend", ['reason' => 'x'])
            ->assertRedirect('/admin/riders')->assertSessionHas('error', 'Only active riders can be suspended.');
    }

    public function test_an_account_with_admin_access_cannot_be_suspended_from_the_rider_list(): void
    {
        $rider = User::factory()->rider()->admin(AdminRole::Admin)->create();

        $this->actingAs($this->support)->postJson("/admin/riders/{$rider->id}/suspend", ['reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'admin access'));

        $this->assertSame(UserStatus::Active, $rider->fresh()->status);
        $this->assertTrue($rider->fresh()->hasAdminAccess());
    }

    public function test_an_admin_cannot_suspend_their_own_account(): void
    {
        $self = $this->admin(AdminRole::SuperAdmin);
        $self->forceFill(['is_rider' => true])->save();

        $this->actingAs($self)->postJson("/admin/riders/{$self->id}/suspend", ['reason' => 'x'])
            ->assertStatus(409)->assertJson(['message' => 'You cannot suspend your own account.']);

        $this->assertSame(UserStatus::Active, $self->fresh()->status);
    }

    public function test_a_rider_who_also_drives_is_suspended_as_one_account_and_the_driver_profile_is_kept(): void
    {
        $both = User::factory()->rider()->driver()->create();
        $profile = DriverProfile::factory()->for($both, 'user')->create();

        $this->suspend($both)->assertSessionHasNoErrors();

        $this->assertSame(UserStatus::Suspended, $both->fresh()->status);
        $this->assertNotNull($profile->fresh());
    }

    // ---- reactivate ----

    public function test_reactivating_restores_the_account_and_clears_the_suspension_reason(): void
    {
        $this->suspend();

        $this->reactivate()->assertSessionHas('success', 'Rider reactivated.');

        $fresh = $this->rider->fresh();
        $this->assertSame(UserStatus::Active, $fresh->status);
        $this->assertNull($fresh->suspension_reason);
        $entry = ActivityLogEntry::where('description', 'rider.reactivated')->sole();
        $this->assertSame('Appeal accepted', $entry->properties['reason']);
        $this->assertSame($this->support->id, $entry->causer_id);
        $this->assertSame(['status' => 'suspended'], $entry->attribute_changes['old']);
        $this->assertSame(['status' => 'active'], $entry->attribute_changes['attributes']);
    }

    #[DataProvider('badReasons')]
    public function test_a_reactivation_needs_a_reason_too(mixed $reason): void
    {
        $rider = User::factory()->rider()->suspended()->create();

        $this->reactivate($rider, ['reason' => $reason])->assertSessionHasErrors('reason');

        $this->assertSame(UserStatus::Suspended, $rider->fresh()->status);
        $this->assertSame(0, ActivityLogEntry::where('description', 'rider.reactivated')->count());
    }

    public function test_only_a_suspended_rider_can_be_reactivated(): void
    {
        foreach ([UserStatus::Active, UserStatus::Deactivated, UserStatus::PendingDeletion] as $status) {
            $rider = User::factory()->rider()->create(['status' => $status]);

            $this->actingAs($this->support)->postJson("/admin/riders/{$rider->id}/reactivate", ['reason' => 'x'])
                ->assertStatus(409)->assertJson(['message' => 'Only suspended riders can be reactivated.']);

            $this->assertSame($status, $rider->fresh()->status);
        }
    }

    public function test_reactivating_twice_has_exactly_one_effect(): void
    {
        $this->suspend();
        $this->reactivate();
        $this->reactivate();

        $this->assertSame(1, ActivityLogEntry::where('description', 'rider.reactivated')->count());
    }

    public function test_a_reactivated_rider_can_be_suspended_again_with_a_new_reason(): void
    {
        $this->suspend();
        $this->reactivate();
        $this->suspend(body: ['reason' => 'Second offence']);

        $this->assertSame('Second offence', $this->rider->fresh()->suspension_reason);
        $this->assertSame(2, ActivityLogEntry::where('description', 'rider.suspended')->count());
    }

    // ---- access ----

    public function test_only_roles_with_riders_suspend_can_suspend_or_reactivate(): void
    {
        foreach ([AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $by = $this->admin($role);
            $this->suspend(by: $by)->assertForbidden();
            $this->reactivate(by: $by)->assertForbidden();
        }
        $this->assertSame(UserStatus::Active, $this->rider->fresh()->status);

        foreach ([AdminRole::Admin, AdminRole::SuperAdmin] as $role) {
            $rider = User::factory()->rider()->create();
            $this->suspend($rider, by: $this->admin($role))->assertSessionHasNoErrors();
            $this->assertSame(UserStatus::Suspended, $rider->fresh()->status);
        }

        auth()->logout();
        $this->post("/admin/riders/{$this->rider->id}/suspend", ['reason' => 'x'])->assertRedirect('/login');
    }

    public function test_only_riders_can_be_suspended_here_and_ids_are_checked(): void
    {
        $driverOnly = User::factory()->driver()->create();

        $this->suspend($driverOnly)->assertNotFound();
        $this->reactivate($driverOnly)->assertNotFound();
        $this->actingAs($this->support)->post('/admin/riders/987654/suspend', ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->support)->post('/admin/riders/99999999999999999999/reactivate', ['reason' => 'x'])->assertNotFound();

        $this->assertSame(UserStatus::Active, $driverOnly->fresh()->status);
    }

    public function test_the_service_itself_refuses_users_who_are_not_riders(): void
    {
        $service = app(RiderService::class);
        $driverOnly = User::factory()->driver()->create(['name' => 'Driver']);

        foreach ([
            fn () => $service->suspend($this->support, $driverOnly, 'x'),
            fn () => $service->reactivate($this->support, User::factory()->driver()->suspended()->create(), 'x'),
            fn () => $service->update($this->support, $driverOnly, ['name' => 'Hacked'], 'x'),
        ] as $call) {
            try {
                $call();
                $this->fail('A user without rider capability must be refused.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Driver', $driverOnly->fresh()->name);
        $this->assertSame(UserStatus::Active, $driverOnly->fresh()->status);
    }

    public function test_the_row_is_locked_while_the_status_is_decided(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->suspend();

        $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_ends_with($sql, 'for update'))->implode("\n");
        $this->assertStringContainsString('from "users"', $locking);
    }

    public function test_a_failure_after_the_status_change_rolls_back_everything(): void
    {
        $this->rider->createToken('phone');
        DB::statement('ALTER TABLE activity_log ADD CONSTRAINT force_audit_failure CHECK (false)');

        try {
            $this->withoutExceptionHandling();
            $this->suspend();
            $this->fail('The audit insert should have failed.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(UserStatus::Active, $this->rider->fresh()->status);
        $this->assertSame(1, $this->rider->tokens()->count(), 'Tokens are only revoked together with the suspension.');
    }
}
