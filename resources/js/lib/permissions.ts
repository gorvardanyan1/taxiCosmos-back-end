/**
 * The admin permissions (app/Enums/AdminPermission.php), so a typo in a `can()` call is a compile error instead of a
 * button hidden from everyone. tests/Feature/Admin/PermissionNavigationTest keeps this list equal to the PHP enum.
 */
export const PERMISSIONS = [
    'dashboard.view',
    'riders.view', 'riders.edit', 'riders.suspend',
    'drivers.view', 'drivers.edit', 'drivers.suspend', 'drivers.verify', 'drivers.reveal_bank_account',
    'trips.view', 'trips.force_cancel', 'trips.adjust_fare',
    'payments.view', 'payments.refund', 'payments.manual',
    'payouts.view', 'payouts.approve',
    'driver_balances.view',
    'chargebacks.manage',
    'zones.manage', 'fares.manage', 'settings.manage', 'admins.manage',
    'activity_log.view', 'live_map.view', 'reports.view', 'support.manage', 'ratings.view',
] as const;

export type Permission = (typeof PERMISSIONS)[number];

/** One permission, or several of which any one is enough. */
export type PermissionRule = Permission | readonly Permission[];

/** Whether the permissions held (shared prop `auth.permissions`) satisfy the rule. */
export function allows(held: readonly string[], rule: PermissionRule): boolean {
    return typeof rule === 'string' ? held.includes(rule) : rule.some((permission) => held.includes(permission));
}
