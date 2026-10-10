import { Link } from '@inertiajs/react';
import { Coins, CreditCard, Map, Shield, SlidersHorizontal } from 'lucide-react';
import PageHeader from '@/Components/PageHeader';
import type { ReactNode } from 'react';

const tabs = [
    { id: 'users', label: 'Users & Roles', icon: <Shield size={14} /> },
    { id: 'gateways', label: 'Gateways', icon: <CreditCard size={14} /> },
    { id: 'currencies', label: 'Currencies', icon: <Coins size={14} /> },
    { id: 'platform', label: 'Platform', icon: <SlidersHorizontal size={14} /> },
    { id: 'maps', label: 'Maps', icon: <Map size={14} /> },
];

/** Settings header + tab bar; each tab is its own route (/admin/settings/{tab}). */
export default function SettingsTabs({ active, children }: { active: string; children: ReactNode }) {
    return (
        <div>
            <PageHeader title="Settings" description="Configure users, integrations, and platform behavior" />
            <nav className="mb-6 flex w-fit gap-1 rounded-2xl p-1" style={{ background: 'white', border: '1px solid #f1f5f9', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }} aria-label="Settings">
                {tabs.map((tab) => (
                    <Link
                        key={tab.id}
                        href={`/admin/settings/${tab.id}`}
                        aria-current={active === tab.id ? 'page' : undefined}
                        className="flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-bold transition-all"
                        style={{ background: active === tab.id ? 'linear-gradient(135deg, #6366f1, #8b5cf6)' : 'transparent', color: active === tab.id ? 'white' : '#94a3b8', fontFamily: 'var(--font-display)' }}
                    >
                        {tab.icon} {tab.label}
                    </Link>
                ))}
            </nav>
            {children}
        </div>
    );
}
