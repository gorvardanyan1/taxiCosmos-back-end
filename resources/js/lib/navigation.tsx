import {
    BarChart2, Car, CreditCard, Landmark, LayoutDashboard, LifeBuoy, Map, Percent, Radio, Route,
    ScrollText, Settings, ShieldAlert, Star, TrendingUp, Users, WalletCards,
} from 'lucide-react';
import type { ReactNode } from 'react';

export interface NavItem {
    key: string;
    label: string;
    href: string;
    icon: ReactNode;
    /** Permission the route requires (sidebar hiding by permission is P13-T2). */
    permission: string;
}

export const navGroups: { label: string; items: NavItem[] }[] = [
    {
        label: 'Overview',
        items: [
            { key: 'dashboard', label: 'Dashboard', href: '/admin', icon: <LayoutDashboard size={15} />, permission: 'dashboard.view' },
            { key: 'live-map', label: 'Live Map', href: '/admin/live-map', icon: <Radio size={15} />, permission: 'live_map.view' },
        ],
    },
    {
        label: 'Operations',
        items: [
            { key: 'riders', label: 'Riders', href: '/admin/riders', icon: <Users size={15} />, permission: 'riders.view' },
            { key: 'drivers', label: 'Drivers', href: '/admin/drivers', icon: <Car size={15} />, permission: 'drivers.view' },
            { key: 'trips', label: 'Trips', href: '/admin/trips', icon: <Route size={15} />, permission: 'trips.view' },
            { key: 'support-tickets', label: 'Support Tickets', href: '/admin/support-tickets', icon: <LifeBuoy size={15} />, permission: 'support.manage' },
            { key: 'ratings', label: 'Ratings', href: '/admin/ratings', icon: <Star size={15} />, permission: 'ratings.view' },
        ],
    },
    {
        label: 'Finance',
        items: [
            { key: 'transactions', label: 'Payments', href: '/admin/transactions', icon: <CreditCard size={15} />, permission: 'payments.view' },
            { key: 'payouts', label: 'Payouts', href: '/admin/payouts', icon: <Landmark size={15} />, permission: 'payouts.view' },
            { key: 'chargebacks', label: 'Chargebacks', href: '/admin/chargebacks', icon: <ShieldAlert size={15} />, permission: 'chargebacks.manage' },
            { key: 'driver-balances', label: 'Driver Balances', href: '/admin/driver-balances', icon: <WalletCards size={15} />, permission: 'driver_balances.view' },
            { key: 'reports', label: 'Reports', href: '/admin/reports', icon: <BarChart2 size={15} />, permission: 'reports.view' },
        ],
    },
    {
        label: 'Platform',
        items: [
            { key: 'zones', label: 'Zones & Fares', href: '/admin/zones', icon: <Map size={15} />, permission: 'zones.manage' },
            { key: 'surge', label: 'Surge', href: '/admin/surge', icon: <TrendingUp size={15} />, permission: 'fares.manage' },
            { key: 'commission-rules', label: 'Commission', href: '/admin/commission-rules', icon: <Percent size={15} />, permission: 'fares.manage' },
            { key: 'settings', label: 'Settings', href: '/admin/settings', icon: <Settings size={15} />, permission: 'settings.manage' },
            { key: 'activity-logs', label: 'Activity Log', href: '/admin/activity-logs', icon: <ScrollText size={15} />, permission: 'activity_log.view' },
        ],
    },
];

const extraLabels: { prefix: string; label: string }[] = [
    { prefix: '/admin/transactions', label: 'Payments & Transactions' },
    { prefix: '/admin/account', label: 'My Account' },
];

/** The nav item matching a URL (longest prefix wins; the dashboard matches only exactly). */
export function activeNavKey(url: string): string | null {
    const path = url.split('?')[0];
    let match: NavItem | null = null;
    for (const item of navGroups.flatMap((group) => group.items)) {
        const hit = item.href === '/admin' ? path === '/admin' || path === '/admin/' : path === item.href || path.startsWith(`${item.href}/`);
        if (hit && (!match || item.href.length > match.href.length)) match = item;
    }
    return match?.key ?? null;
}

export function pageLabel(url: string): string {
    const path = url.split('?')[0];
    const extra = extraLabels.find((entry) => path.startsWith(entry.prefix));
    if (extra) return extra.label;
    const key = activeNavKey(url);
    return navGroups.flatMap((group) => group.items).find((item) => item.key === key)?.label ?? 'Admin';
}
