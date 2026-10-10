import { usePage } from '@inertiajs/react';
import { formatMoney } from '@/lib/format';
import type { Money } from '@/types';

/** Shared-prop helpers: money formatting with the configured currencies, and the admin timezone. */
export function useShared() {
    const { app, auth } = usePage().props;

    return {
        timezone: app.timezone,
        currencies: app.currencies,
        can: (permission: string) => auth.permissions.includes(permission),
        money: (value: Money, options?: { compact?: boolean; signed?: boolean }) => formatMoney(value, app.currencies, options),
    };
}
