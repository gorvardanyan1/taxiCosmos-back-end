<?php

namespace Tests\Feature\Rbac;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Probe routes guarded exactly like real /admin routes will be (named permission middleware).
        Route::middleware(['web', 'auth', 'permission:payments.refund'])
            ->get('/_test/rbac/refund', fn () => 'refund-ok');
        Route::middleware(['web', 'auth', 'permission:admins.manage'])
            ->get('/_test/rbac/admins', fn () => 'admins-ok');
    }

    public function test_assigning_a_role_grants_its_permissions_and_removing_it_revokes_them(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->assertFalse($user->can('payments.refund'));

        $user->assignRole(AdminRole::Finance->value);

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $user->id,
            'role_id' => DB::table('roles')->where('name', 'finance')->value('id'),
        ]);
        $fresh = $user->fresh();
        $this->assertSame(['finance'], $fresh->getRoleNames()->all());
        $this->assertTrue($fresh->can('payments.refund'));
        $this->assertTrue($fresh->can('payouts.approve'));
        $this->assertFalse($fresh->can('live_map.view'));
        $this->assertTrue($fresh->hasAdminAccess());

        $fresh->removeRole(AdminRole::Finance->value);

        $this->assertDatabaseMissing('model_has_roles', ['model_type' => User::class, 'model_id' => $user->id]);
        $afterRemoval = $user->fresh();
        $this->assertSame([], $afterRemoval->getRoleNames()->all());
        $this->assertFalse($afterRemoval->can('payments.refund'));
        $this->assertFalse($afterRemoval->hasAdminAccess());
    }

    public function test_role_changes_are_enforced_by_permission_middleware(): void
    {
        $user = User::factory()->admin(AdminRole::Dispatcher)->create();

        $this->actingAs($user)->get('/_test/rbac/refund')->assertForbidden();

        $user->syncRoles([AdminRole::Finance->value]);

        $this->actingAs($user->fresh())->get('/_test/rbac/refund')->assertOk()->assertSee('refund-ok');

        $user->removeRole(AdminRole::Finance->value);

        $this->actingAs($user->fresh())->get('/_test/rbac/refund')->assertForbidden();
    }

    public function test_guests_are_not_let_through_permission_middleware(): void
    {
        // Admin login (P3-T1) does not exist yet, so probe the permission middleware without `auth`.
        Route::middleware(['web', 'permission:payments.refund'])->get('/_test/rbac/guest', fn () => 'guest-ok');

        $this->get('/_test/rbac/guest')->assertForbidden()->assertDontSee('guest-ok');
    }

    public function test_a_user_can_hold_several_roles_and_gets_the_union_of_permissions(): void
    {
        $user = User::factory()->admin(AdminRole::Support)->create();
        $user->assignRole(AdminRole::Dispatcher->value);

        $fresh = $user->fresh();
        $this->assertEqualsCanonicalizing(['support', 'dispatcher'], $fresh->getRoleNames()->all());
        $this->assertTrue($fresh->can('support.manage'));
        $this->assertTrue($fresh->can('live_map.view'));
        $this->assertFalse($fresh->can('payments.refund'));
    }

    public function test_assigning_an_unknown_role_fails_and_changes_nothing(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        try {
            $user->assignRole('root');
            $this->fail('Unknown roles must not be assignable.');
        } catch (RoleDoesNotExist) {
        }

        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id]);
    }

    public function test_the_is_admin_flag_alone_grants_nothing(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->assertFalse($user->hasAdminAccess());
        $this->assertFalse($user->isSuperAdmin());
        $this->assertFalse($user->can('riders.view'));
        $this->actingAs($user)->get('/_test/rbac/refund')->assertForbidden();
    }

    public function test_a_role_without_the_admin_flag_is_not_admin_access(): void
    {
        $user = User::factory()->rider()->create();
        $user->assignRole(AdminRole::Admin->value);

        $this->assertFalse($user->fresh()->hasAdminAccess());
    }

    public function test_a_suspended_admin_has_no_admin_access(): void
    {
        $user = User::factory()->admin(AdminRole::Admin)->create();
        $this->assertTrue($user->hasAdminAccess());

        $user->forceFill(['status' => UserStatus::Suspended, 'suspension_reason' => 'Left the company'])->save();

        $this->assertFalse($user->fresh()->hasAdminAccess());
    }

    public function test_super_admin_bypasses_every_check_via_gate_before(): void
    {
        $user = User::factory()->admin(AdminRole::SuperAdmin)->create();

        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Gate::forUser($user)->allows('admins.manage'));
        // An ability that no role holds and no gate defines still passes for super_admin.
        $this->assertTrue(Gate::forUser($user)->allows('some.future_ability'));
        $this->actingAs($user)->get('/_test/rbac/admins')->assertOk()->assertSee('admins-ok');
    }

    public function test_gate_before_does_not_bypass_for_other_roles(): void
    {
        $admin = User::factory()->admin(AdminRole::Admin)->create();

        $this->assertFalse(Gate::forUser($admin)->allows('some.future_ability'));
        $this->assertFalse($admin->can('admins.manage'));
        $this->actingAs($admin)->get('/_test/rbac/admins')->assertForbidden();
        $this->actingAs($admin)->get('/_test/rbac/refund')->assertOk();
    }

    public function test_a_suspended_super_admin_loses_the_bypass(): void
    {
        $user = User::factory()->admin(AdminRole::SuperAdmin)->create();
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->isSuperAdmin());
        $this->assertFalse(Gate::forUser($fresh)->allows('some.future_ability'));
    }
}
