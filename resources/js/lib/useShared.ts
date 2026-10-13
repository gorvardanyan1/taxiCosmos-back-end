import { usePage } from '@inertiajs/react';
import { formatMoney } from '@/lib/format';
import { allows, type PermissionRule } from '@/lib/permissions';
import type { Money } from '@/types';

/** Shared-prop helpers: money formatting with the configured currencies, and the admin timezone. */
export function useShared() {
    const { app, auth } = usePage().props;

    return {
        timezone: app.timezone,
        /** The signed-in admin's id (null outside the admin shell). */
        userId: auth.user?.id ?? null,
        currencies: app.currencies,
        /** Whether the signed-in admin holds the permission (any one of them, for a list). UI visibility only: the server enforces. */
        can: (rule: PermissionRule) => allows(auth.permissions, rule),
        money: (value: Money, options?: { compact?: boolean; signed?: boolean }) => formatMoney(value, app.currencies, options),
    };
}
