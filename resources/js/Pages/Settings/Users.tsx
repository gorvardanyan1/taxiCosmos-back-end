import { Head } from '@inertiajs/react';
import ActionButton from '@/Components/ActionButton';
import Avatar from '@/Components/Avatar';
import PaginatedTable from '@/Components/PaginatedTable';
import SettingsTabs from '@/Components/SettingsTabs';
import StatusBadge from '@/Components/StatusBadge';
import { formatDateTime } from '@/lib/format';
import { withId } from '@/lib/query';
import { shadowCard } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, AdminUserRow, Paginated } from '@/types';

interface Props {
    users: Paginated<AdminUserRow>;
    roles: { name: string; label: string }[];
    matrix: Record<string, string[]>;
    actions: Actions<'invite' | 'remove'>;
}

function Tick({ on }: { on: boolean }) {
    return (
        <div className="flex justify-center">
            <div className="flex h-5 w-5 items-center justify-center rounded-full text-[10px]" style={{ background: on ? '#eef2ff' : '#f8fafc', color: on ? '#6366f1' : '#cbd5e1' }} aria-label={on ? 'granted' : 'not granted'}>{on ? '✓' : '—'}</div>
        </div>
    );
}

export default function SettingsUsers({ users, roles, matrix, actions }: Props) {
    const { timezone } = useShared();
    const roleLabel = (name: string) => roles.find((r) => r.name === name)?.label ?? name;
    const permissions = [...new Set(Object.values(matrix).flat())].sort();

    return (
        <SettingsTabs active="users">
            <Head title="Settings · Users & Roles" />
            <div className="flex flex-col gap-5">
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Admin Users</h2>
                        <ActionButton url={actions.invite} className="rounded-xl px-3.5 py-2 text-xs font-bold text-white hover:opacity-90" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>+ Invite User</ActionButton>
                    </div>
                    <PaginatedTable
                        paginator={users}
                        rowKey={(u) => u.id}
                        minWidth={640}
                        empty={{ title: 'No admin users yet' }}
                        columns={[
                            {
                                key: 'user', header: 'User', render: (u) => (
                                    <div className="flex items-center gap-3">
                                        <Avatar name={u.name} seed={u.id} size={32} />
                                        <div>
                                            <p className="text-sm font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{u.name}</p>
                                            <p className="text-[11px]" style={{ color: '#94a3b8' }}>{u.email}</p>
                                        </div>
                                    </div>
                                ),
                            },
                            { key: 'role', header: 'Role', render: (u) => u.roles.map((r) => <span key={r} className="mr-1 rounded-lg px-2.5 py-1 text-xs font-bold" style={{ background: '#eef2ff', color: '#6366f1', fontFamily: 'var(--font-display)' }}>{roleLabel(r)}</span>) },
                            { key: 'status', header: 'Status', render: (u) => <StatusBadge status={u.status} /> },
                            { key: 'login', header: 'Last Login', render: (u) => <span className="text-xs" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{u.last_login_at ? formatDateTime(u.last_login_at, timezone) : 'Never'}</span> },
                            { key: 'remove', header: '', render: (u) => <ActionButton url={withId(actions.remove, u.id)} className="text-xs font-semibold hover:opacity-80" style={{ color: '#ef4444' }}>Remove</ActionButton> },
                        ]}
                    />
                </div>

                <div className="overflow-hidden rounded-2xl" style={shadowCard}>
                    <div className="px-5 py-4" style={{ borderBottom: '1px solid #f8fafc' }}>
                        <h2 className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Role & Permission Matrix</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr style={{ borderBottom: '1px solid #f8fafc' }}>
                                    <th className="w-48 px-5 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8' }}>Permission</th>
                                    {roles.map((r) => <th key={r.name} className="px-4 py-3.5 text-center text-[11px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8' }}>{r.label}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {permissions.map((permission, i) => (
                                    <tr key={permission} style={{ borderBottom: i < permissions.length - 1 ? '1px solid #f8fafc' : 'none' }}>
                                        <td className="px-5 py-3 font-mono text-xs font-medium" style={{ color: '#374151' }}>{permission}</td>
                                        {roles.map((r) => <td key={r.name} className="px-4 py-3"><Tick on={(matrix[r.name] ?? []).includes(permission)} /></td>)}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </SettingsTabs>
    );
}
