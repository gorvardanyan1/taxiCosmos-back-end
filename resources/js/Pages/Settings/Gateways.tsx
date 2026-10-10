import { Head } from '@inertiajs/react';
import ActionButton from '@/Components/ActionButton';
import SettingsTabs from '@/Components/SettingsTabs';
import { shadowCard } from '@/lib/ui';
import type { Actions } from '@/types';

interface Gateway {
    key: string;
    name: string;
    description: string;
    connected: boolean;
    masked_key: string;
    mode: string;
}

const gatewayColor: Record<string, string> = { stripe: '#6366f1', authorize_net: '#ea580c' };

/** Secrets are write-only: only a masked preview ever reaches the browser. */
export default function SettingsGateways({ gateways, actions }: { gateways: Gateway[]; actions: Actions<'replaceKey' | 'testConnection'> }) {
    return (
        <SettingsTabs active="gateways">
            <Head title="Settings · Gateways" />
            <div className="flex flex-col gap-4">
                {gateways.map((g) => (
                    <div key={g.key} className="rounded-2xl p-5" style={shadowCard}>
                        <div className="mb-4 flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl text-sm font-bold" style={{ background: '#f8fafc', color: gatewayColor[g.key] ?? '#64748b' }}>{g.name.charAt(0)}</div>
                            <div>
                                <p className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{g.name}</p>
                                <p className="text-xs" style={{ color: '#94a3b8' }}>{g.description} · {g.mode} mode</p>
                            </div>
                            <span className="ml-auto rounded-full px-2.5 py-1 text-xs font-bold" style={{ background: g.connected ? '#f0fdf4' : '#fef2f2', color: g.connected ? '#16a34a' : '#dc2626', fontFamily: 'var(--font-display)' }}>{g.connected ? 'Connected' : 'Not connected'}</span>
                        </div>
                        <p className="mb-2 block text-xs font-bold" style={{ color: '#374151', fontFamily: 'var(--font-display)' }}>API Key</p>
                        <div className="flex gap-2">
                            <input aria-label={`${g.name} API key`} readOnly value={g.masked_key} className="flex-1 rounded-xl px-4 py-2.5 text-sm" style={{ background: '#f8fafc', border: '1px solid #e2e8f0', color: '#0f172a', outline: 'none', fontFamily: 'var(--font-mono)' }} />
                            <ActionButton url={actions.testConnection} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600">Test</ActionButton>
                            <ActionButton url={actions.replaceKey} className="rounded-xl px-4 py-2.5 text-sm font-bold text-white hover:opacity-90" style={{ background: gatewayColor[g.key] ?? '#6366f1', fontFamily: 'var(--font-display)' }}>Replace</ActionButton>
                        </div>
                    </div>
                ))}
            </div>
        </SettingsTabs>
    );
}
