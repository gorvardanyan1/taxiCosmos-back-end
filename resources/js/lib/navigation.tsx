import {
    BarChart2, Car, CreditCard, Landmark, LayoutDashboard, LifeBuoy, Map, Percent, Radio, Route,
    ScrollText, Settings, ShieldAlert, Star, TrendingUp, Users, WalletCards,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { t, tk } from '@/lib/i18n';
import type { PermissionRule } from '@/lib/permissions';

export interface NavItem {
    key: string;
    label: string;
    href: string;
    icon: ReactNode;
    /** Permission the page's route requires (any one of a list); the sidebar hides the item without it. tests/Feature/Admin/PermissionNavigationTest compares it with the route. */
    permission: PermissionRule;
}

export const navGroups: { label: string; items: NavItem[] }[] = [
    {
        label: tk('Overview'),
        items: [
            { key: 'dashboard', label: tk('Dashboard'), href: '/admin', icon: <LayoutDashboard size={15} />, permission: 'dashboard.view' },
            { key: 'live-map', label: tk('Live Map'), href: '/admin/live-map', icon: <Radio size={15} />, permission: 'live_map.view' },
        ],
    },
    {
        label: tk('Operations'),
        items: [
            { key: 'riders', label: tk('Riders'), href: '/admin/riders', icon: <Users size={15} />, permission: 'riders.view' },
            { key: 'drivers', label: tk('Drivers'), href: '/admin/drivers', icon: <Car size={15} />, permission: 'drivers.view' },
            { key: 'trips', label: tk('Trips'), href: '/admin/trips', icon: <Route size={15} />, permission: 'trips.view' },
            { key: 'support-tickets', label: tk('Support Tickets'), href: '/admin/support-tickets', icon: <LifeBuoy size={15} />, permission: 'support.manage' },
            { key: 'ratings', label: tk('Ratings'), href: '/admin/ratings', icon: <Star size={15} />, permission: 'ratings.view' },
        ],
    },
    {
        label: tk('Finance'),
        items: [
            { key: 'transactions', label: tk('Payments'), href: '/admin/transactions', icon: <CreditCard size={15} />, permission: 'payments.view' },
            { key: 'payouts', label: tk('Payouts'), href: '/admin/payouts', icon: <Landmark size={15} />, permission: 'payouts.view' },
            { key: 'chargebacks', label: tk('Chargebacks'), href: '/admin/chargebacks', icon: <ShieldAlert size={15} />, permission: 'chargebacks.manage' },
            { key: 'driver-balances', label: tk('Driver Balances'), href: '/admin/driver-balances', icon: <WalletCards size={15} />, permission: 'driver_balances.view' },
            { key: 'reports', label: tk('Reports'), href: '/admin/reports', icon: <BarChart2 size={15} />, permission: 'reports.view' },
        ],
    },
    {
        label: tk('Platform'),
        items: [
            { key: 'zones', label: tk('Zones & Fares'), href: '/admin/zones', icon: <Map size={15} />, permission: 'zones.manage' },
            { key: 'surge', label: tk('Surge'), href: '/admin/surge', icon: <TrendingUp size={15} />, permission: 'fares.manage' },
            { key: 'commission-rules', label: tk('Commission'), href: '/admin/commission-rules', icon: <Percent size={15} />, permission: 'fares.manage' },
            { key: 'settings', label: tk('Settings'), href: '/admin/settings', icon: <Settings size={15} />, permission: ['admins.manage', 'settings.manage'] },
            { key: 'activity-logs', label: tk('Activity Log'), href: '/admin/activity-logs', icon: <ScrollText size={15} />, permission: 'activity_log.view' },
        ],
    },
];

const extraLabels: { prefix: string; label: string }[] = [
    { prefix: '/admin/transactions', label: tk('Payments & Transactions') },
    { prefix: '/admin/account', label: tk('My Account') },
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
    if (extra) return t(extra.label);
    const key = activeNavKey(url);
    const item = navGroups.flatMap((group) => group.items).find((item) => item.key === key);
    return item ? t(item.label) : t('Admin');
}
