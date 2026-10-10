import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Avatar from '@/Components/Avatar';
import EmptyState from '@/Components/EmptyState';
import ReasonModal from '@/Components/ReasonModal';
import StatusBadge from '@/Components/StatusBadge';
import UrlTabs from '@/Components/UrlTabs';
import { formatBasisPoints, formatDate, formatDateTime, formatNumber } from '@/lib/format';
import EditRider from './EditRider';
import { useShared } from '@/lib/useShared';
import type { Actions, RiderDetail } from '@/types';

interface Props {
    rider: RiderDetail;
    tab: string;
    tabs: string[];
    locales: string[];
    actions: Actions<'edit' | 'suspend' | 'reactivate'>;
}

export default function RiderShow({ rider, tab, tabs, locales, actions }: Props) {
    const { money, timezone } = useShared();
    const [acting, setActing] = useState(false);
    const [editing, setEditing] = useState(false);
    const suspended = rider.status === 'suspended';
    // Only active riders can be suspended and only suspended ones reactivated.
    const canToggle = rider.status === 'active' || suspended;
    const records = tab === 'payment-methods' ? [] : rider.records[tab as keyof RiderDetail['records']] ?? [];

    return (
        <div className="-m-6 min-h-full bg-[#f0f2f8]">
            <Head title={rider.name} />
            <div className="sticky top-0 z-10 border-b border-slate-200 bg-white/90 px-6 py-4 backdrop-blur">
                <div className="mx-auto flex max-w-7xl items-center">
                    <Link href="/admin/riders" className="mr-4 rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-500">← Riders</Link>
                    <Avatar name={rider.name} seed={rider.id} size={48} rounded="rounded-2xl" />
                    <div className="ml-3">
                        <h1 className="font-display text-lg font-bold text-slate-900">{rider.name}</h1>
                        <p className="text-xs text-slate-400">{rider.phone} · {rider.email}</p>
                    </div>
                    <div className="ml-4"><StatusBadge status={rider.status} /></div>
                    <div className="ml-auto flex gap-2">
                        <button onClick={() => setEditing(true)} disabled={actions.edit === null} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600 disabled:opacity-40">Edit</button>
                        {canToggle && (
                            <button onClick={() => setActing(true)} className={`rounded-xl px-4 py-2 text-sm font-bold ${suspended ? 'bg-indigo-50 text-indigo-600' : 'bg-red-50 text-red-600'}`}>{suspended ? 'Reactivate' : 'Suspend'}</button>
                        )}
                    </div>
                </div>
            </div>
            <div className="mx-auto max-w-7xl p-6">
                {suspended && (
                    <div className="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
                        Suspended{rider.suspension_reason ? `: ${rider.suspension_reason}` : ''}
                    </div>
                )}
                {rider.status === 'pending_deletion' && rider.deletion_scheduled_for && (
                    <div className="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-800">
                        Account deletion requested — will be anonymised on {formatDate(rider.deletion_scheduled_for, timezone)}
                    </div>
                )}
                <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {([
                        ['Total trips', formatNumber(rider.trips_count)],
                        ['Total spent', money(rider.stats.total_spent)],
                        ['Cancellation rate', formatBasisPoints(rider.stats.cancellation_rate_bp)],
                        ['Open tickets', formatNumber(rider.stats.open_tickets)],
                    ] as const).map(([title, value]) => (
                        <div key={title} className="rounded-2xl bg-white p-5 shadow-[0_1px_3px_rgba(0,0,0,.04)]">
                            <p className="text-xs text-slate-400">{title}</p>
                            <p className="mt-2 font-mono text-xl font-bold text-slate-900">{value}</p>
                        </div>
                    ))}
                </div>
                <div className="rounded-2xl bg-white shadow-[0_1px_3px_rgba(0,0,0,.04)]">
                    <div className="border-b border-slate-100 p-2"><UrlTabs tabs={tabs} active={tab} /></div>
                    <div className="p-5">
                        {tab === 'payment-methods' ? (
                            rider.payment_methods.length === 0 ? <EmptyState title="No payment methods" /> : rider.payment_methods.map((method) => (
                                <div key={method.id} className="max-w-sm rounded-2xl bg-gradient-to-br from-slate-800 to-slate-950 p-5 text-white">
                                    <p className="text-xs text-slate-400">{method.is_default ? 'Primary payment method' : 'Payment method'}</p>
                                    <p className="mt-8 font-mono text-lg tracking-widest">{method.brand} •••• {method.last4}</p>
                                    <p className="mt-5 text-xs text-slate-400">Expires {method.expires}</p>
                                </div>
                            ))
                        ) : records.length === 0 ? (
                            <EmptyState />
                        ) : (
                            <table className="w-full">
                                <thead><tr>{['Reference', 'Description', 'Status', 'Amount / Rating', 'Date'].map((h) => <th key={h} className="px-4 py-3 text-left text-[10px] font-bold uppercase text-slate-400">{h}</th>)}</tr></thead>
                                <tbody>
                                    {records.map((record) => (
                                        <tr key={`${record.reference}-${record.occurred_at}`} className="border-t border-slate-50">
                                            <td className="px-4 py-4 font-mono text-xs font-bold text-indigo-600">{record.reference}</td>
                                            <td className="px-4 py-4 text-sm text-slate-600">{record.description}</td>
                                            <td className="px-4 py-4"><StatusBadge status={record.status} /></td>
                                            <td className="px-4 py-4 font-mono text-xs font-bold">{typeof record.value === 'string' ? record.value : money(record.value)}</td>
                                            <td className="px-4 py-4 text-xs text-slate-400">{formatDateTime(record.occurred_at, timezone)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>
            <EditRider open={editing} onClose={() => setEditing(false)} rider={rider} locales={locales} url={actions.edit} />
            <ReasonModal
                open={acting}
                onClose={() => setActing(false)}
                title={`${suspended ? 'Reactivate' : 'Suspend'} ${rider.name}`}
                description={suspended
                    ? <>This lets <strong style={{ color: '#0f172a' }}>{rider.name}</strong> book trips again.</>
                    : <>This will immediately prevent <strong style={{ color: '#0f172a' }}>{rider.name}</strong> from booking trips and sign them out of the app.</>}
                confirmLabel={suspended ? 'Confirm Reactivation' : 'Confirm Suspension'}
                placeholder={suspended ? 'Why is this rider being reactivated…' : 'Describe why this rider is being suspended…'}
                tone={suspended ? 'primary' : 'danger'}
                url={suspended ? actions.reactivate : actions.suspend}
            />
        </div>
    );
}
