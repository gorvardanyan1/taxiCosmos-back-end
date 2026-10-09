<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every admin permission and the default role → permission matrix
 * (docs/permissions.md). Idempotent: re-running syncs each role to the matrix.
 * Permissions that are no longer in AdminPermission are left in place (remove them with a migration).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'web';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (AdminPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, self::GUARD);
        }

        // Model events (which normally flush spatie's cache) are muted under WithoutModelEvents.
        $registrar->forgetCachedPermissions();

        foreach (AdminRole::cases() as $role) {
            Role::findOrCreate($role->value, self::GUARD)->syncPermissions(
                array_map(fn (AdminPermission $permission) => $permission->value, $role->defaultPermissions()),
            );
        }

        $registrar->forgetCachedPermissions();
    }
}
