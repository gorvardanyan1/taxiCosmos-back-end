import { vi } from 'vitest';
import { PERMISSIONS } from '@/lib/permissions';
import type { SharedProps } from '@/types';

/** Shared props used by component tests; override per test. The default admin holds every permission, so a test of a restricted role says which ones it has. */
export function sharedProps(overrides: Partial<SharedProps> = {}): SharedProps {
    return {
        auth: { user: { id: 1, name: 'Test Admin', email: 'admin@taxikosmos.test', role: 'finance', roleLabel: 'Finance' }, permissions: [...PERMISSIONS] },
        flash: { success: null, error: null },
        app: {
            name: 'TaxiKosmos', locale: 'en', locales: ['en', 'ru', 'hy'], timezone: 'UTC', baseCurrency: 'AMD',
            currencies: [{ code: 'AMD', symbol: '֏', decimals: 0 }, { code: 'USD', symbol: '$', decimals: 2 }],
        },
        navigation: { badges: { drivers: 4 }, queueMonitor: true, notifications: [] },
        errors: {},
        ...overrides,
    };
}

export const page = { props: sharedProps(), url: '/admin' };

/** Sign in as an admin holding exactly these permissions (a restricted role). */
export function grant(permissions: readonly string[], overrides: Partial<SharedProps> = {}) {
    const props = sharedProps(overrides);
    page.props = { ...props, auth: { ...props.auth, permissions: [...permissions] } };
}

/** Module mock for '@inertiajs/react': real useForm/router, controllable usePage, plain Link/Head. */
export async function inertiaMock(importOriginal: () => Promise<Record<string, unknown>>) {
    const actual = await importOriginal();
    const React = await import('react');
    return {
        ...actual,
        usePage: vi.fn(() => page),
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => React.createElement('a', { href, ...rest }, children),
    };
}

export function setLocation(url: string) {
    window.history.replaceState({}, '', url);
}
