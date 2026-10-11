<?php

namespace Tests\Feature\Rbac;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_every_admin_permission_is_seeded_on_the_web_guard(): void
    {
        $seeded = Permission::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all();

        $expected = AdminPermission::values();
        sort($expected);

        $this->assertSame($expected, $seeded);

        foreach ([
            'riders.view', 'riders.edit', 'riders.suspend', 'drivers.verify', 'trips.force_cancel', 'trips.adjust_fare',
            'payments.view', 'payments.refund', 'payments.manual', 'payouts.approve', 'zones.manage',
            'fares.manage', 'settings.manage', 'admins.manage', 'activity_log.view', 'live_map.view',
            'reports.view', 'support.manage', 'chargebacks.manage',
        ] as $required) {
            $this->assertContains($required, $seeded, "Permission {$required} named in P2-T1 is missing.");
        }
    }

    public function test_the_five_admin_roles_are_seeded(): void
    {
        $this->assertEqualsCanonicalizing(
            ['super_admin', 'admin', 'support', 'finance', 'dispatcher'],
            Role::query()->where('guard_name', 'web')->pluck('name')->all(),
        );
    }

    public function test_each_role_holds_exactly_its_matrix_permissions(): void
    {
        foreach (AdminRole::cases() as $role) {
            $this->assertEqualsCanonicalizing(
                array_map(fn (AdminPermission $p) => $p->value, $role->defaultPermissions()),
                Role::findByName($role->value, 'web')->permissions->pluck('name')->all(),
                "Role {$role->value} does not match its default permission matrix.",
            );
        }
    }

    public function test_key_matrix_rules_hold(): void
    {
        $permissionsOf = fn (AdminRole $role) => Role::findByName($role->value, 'web')->permissions->pluck('name')->all();

        $this->assertCount(count(AdminPermission::cases()), $permissionsOf(AdminRole::SuperAdmin));
        $this->assertNotContains('admins.manage', $permissionsOf(AdminRole::Admin));
        $this->assertContains('settings.manage', $permissionsOf(AdminRole::Admin));

        $this->assertContains('payments.refund', $permissionsOf(AdminRole::Finance));
        $this->assertContains('payouts.approve', $permissionsOf(AdminRole::Finance));
        $this->assertNotContains('live_map.view', $permissionsOf(AdminRole::Finance));

        $this->assertContains('live_map.view', $permissionsOf(AdminRole::Dispatcher));
        $this->assertNotContains('payments.refund', $permissionsOf(AdminRole::Dispatcher));

        $this->assertContains('support.manage', $permissionsOf(AdminRole::Support));
        $this->assertNotContains('payments.refund', $permissionsOf(AdminRole::Support));
        $this->assertNotContains('live_map.view', $permissionsOf(AdminRole::Support));

        foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::Dispatcher] as $role) {
            $this->assertNotContains('admins.manage', $permissionsOf($role));
            $this->assertNotContains('settings.manage', $permissionsOf($role));
        }
    }

    public function test_reseeding_is_idempotent_and_restores_a_drifted_role(): void
    {
        Role::findByName('dispatcher', 'web')->givePermissionTo('payments.refund');
        Role::findByName('finance', 'web')->revokePermissionTo('payments.refund');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(AdminPermission::cases()), Permission::count());
        $this->assertSame(count(AdminRole::cases()), Role::count());
        $this->assertFalse(Role::findByName('dispatcher', 'web')->hasPermissionTo('payments.refund'));
        $this->assertTrue(Role::findByName('finance', 'web')->hasPermissionTo('payments.refund'));
    }

    public function test_database_seeder_seeds_rbac_and_a_local_super_admin_and_can_rerun(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $admin = User::query()->where('email', 'test@example.com')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertSame(['super_admin'], $admin->getRoleNames()->all());
        $this->assertTrue($admin->isSuperAdmin());
    }

    public function test_docs_permission_matrix_matches_the_seeded_matrix(): void
    {
        $path = base_path('docs/permissions.md');
        $this->assertFileExists($path);

        $roles = null;
        $documented = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (! str_starts_with(trim($line), '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim(trim($line), '|')));

            if ($cells[0] === 'Permission') {
                // Role columns are the backticked headers; descriptive columns are ignored.
                $roles = [];
                foreach (array_slice($cells, 1, null, true) as $i => $cell) {
                    if (preg_match('/^`([a-z_]+)`$/', $cell, $r)) {
                        $roles[$i] = $r[1];
                    }
                }

                continue;
            }

            if ($roles === null || ! preg_match('/^`([a-z_]+\.[a-z_]+)`$/', $cells[0], $m)) {
                continue;
            }

            foreach ($roles as $i => $role) {
                if (($cells[$i] ?? '') === '✓') {
                    $documented[$role][] = $m[1];
                }
            }
        }

        $this->assertEqualsCanonicalizing(AdminRole::values(), array_values($roles ?? []));

        foreach (AdminRole::cases() as $role) {
            $this->assertEqualsCanonicalizing(
                array_map(fn (AdminPermission $p) => $p->value, $role->defaultPermissions()),
                $documented[$role->value] ?? [],
                "docs/permissions.md is out of sync for role {$role->value}.",
            );
        }
    }
}
