import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Sidebar from '@/Components/Sidebar';
import { navGroups } from '@/lib/navigation';
import { PERMISSIONS, type Permission } from '@/lib/permissions';
import { t } from '@/lib/i18n';
import { grant, page } from '@/test/inertia';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const items = navGroups.flatMap((group) => group.items);
const rulePermissions = (permission: (typeof items)[number]['permission']): Permission[] => (typeof permission === 'string' ? [permission] : [...permission]);
const link = (name: string) => screen.queryByRole('link', { name: new RegExp(`^${name}`) });

beforeEach(() => { page.url = '/admin'; });

describe('Sidebar by permission', () => {
    it('lists only the pages the admin may open', () => {
        grant(['dashboard.view', 'riders.view', 'trips.view']);
        render(<Sidebar collapsed={false} />);

        for (const name of ['Dashboard', 'Riders', 'Trips']) expect(link(name)).toBeInTheDocument();
        for (const name of ['Drivers', 'Live Map', 'Payments', 'Payouts', 'Zones & Fares', 'Settings', 'Activity Log', 'Reports']) expect(link(name)).toBeNull();
    });

    it('drops a whole group when none of its pages is allowed, and keeps the others', () => {
        grant(['dashboard.view', 'riders.view']);
        render(<Sidebar collapsed={false} />);
        const nav = screen.getByRole('navigation', { name: 'Main' });

        expect(within(nav).getByText('Overview')).toBeInTheDocument();
        expect(within(nav).getByText('Operations')).toBeInTheDocument();
        expect(within(nav).queryByText('Finance')).toBeNull();
        expect(within(nav).queryByText('Platform')).toBeNull();
    });

    it('shows no page at all to an admin who holds no permission', () => {
        grant([]);
        render(<Sidebar collapsed={false} />);

        expect(within(screen.getByRole('navigation', { name: 'Main' })).queryAllByRole('link')).toHaveLength(0);
    });

    it.each(items.map((item) => [item.key, item] as const))('%s: shown with its permission, hidden with every other', (_key, item) => {
        const needed = rulePermissions(item.permission);

        for (const only of needed) {
            grant([only]);
            const { unmount } = render(<Sidebar collapsed={false} />);
            expect(link(t(item.label)), `${item.key} with only ${only}`).toBeInTheDocument();
            unmount();
        }

        grant(PERMISSIONS.filter((permission) => !needed.includes(permission)));
        render(<Sidebar collapsed={false} />);
        expect(link(t(item.label)), `${item.key} without ${needed.join(' / ')}`).toBeNull();
    });

    it('opens Settings for either settings permission', () => {
        grant(['settings.manage']);
        const first = render(<Sidebar collapsed={false} />);
        expect(link('Settings')).toBeInTheDocument();
        first.unmount();

        grant(['admins.manage']);
        render(<Sidebar collapsed={false} />);
        expect(link('Settings')).toBeInTheDocument();
    });
});

describe('Sidebar badges', () => {
    it('shows the count on the page it belongs to', () => {
        grant(['drivers.view'], { navigation: { badges: { drivers: 63 }, queueMonitor: false, notifications: [] } });
        render(<Sidebar collapsed={false} />);

        expect(within(link('Drivers')!).getByText('63')).toBeInTheDocument();
    });

    it('shows no count on a page the admin cannot open (the page is not listed at all)', () => {
        grant(['riders.view'], { navigation: { badges: { drivers: 63 }, queueMonitor: false, notifications: [] } });
        render(<Sidebar collapsed={false} />);

        expect(link('Drivers')).toBeNull();
        expect(screen.queryByText('63')).toBeNull();
    });

    it('shows nothing for a zero or missing count', () => {
        grant(['drivers.view', 'payouts.view'], { navigation: { badges: { drivers: 0 }, queueMonitor: false, notifications: [] } });
        render(<Sidebar collapsed={false} />);

        expect(link('Drivers')).toHaveTextContent(/^Drivers$/);
        expect(link('Payouts')).toHaveTextContent(/^Payouts$/);
    });
});

describe('Sidebar queue monitor', () => {
    it.each([[true, 1], [false, 0]])('queueMonitor=%s shows the Horizon link %i time(s)', (queueMonitor, count) => {
        grant(['dashboard.view'], { navigation: { badges: {}, queueMonitor, notifications: [] } });
        render(<Sidebar collapsed={false} />);

        expect(screen.queryAllByText('Queue Monitor')).toHaveLength(count);
    });
});
