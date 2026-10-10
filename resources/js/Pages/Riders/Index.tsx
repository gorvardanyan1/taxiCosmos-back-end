import { Head, Link, router } from '@inertiajs/react';
import { Download, Eye, MoreHorizontal, UserX } from 'lucide-react';
import { useState } from 'react';
import ActionButton from '@/Components/ActionButton';
import Avatar from '@/Components/Avatar';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import ReasonModal from '@/Components/ReasonModal';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate, formatNumber } from '@/lib/format';
import { label } from '@/lib/labels';
import { withId } from '@/lib/query';
import { ghost, primary } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, Filters, Paginated, RiderRow } from '@/types';

interface Props {
    riders: Paginated<RiderRow>;
    filters: Filters;
    sort: string | null;
    statuses: string[];
    totalRegistered: number;
    actions: Actions<'export' | 'invite' | 'suspend'>;
}

export default function RidersIndex({ riders, filters, statuses, totalRegistered, actions }: Props) {
    const { timezone } = useShared();
    const [menuOpen, setMenuOpen] = useState<number | null>(null);
    const [suspending, setSuspending] = useState<RiderRow | null>(null);

    return (
        <div>
            <Head title="Riders" />
            <PageHeader
                title="Riders"
                description={`${formatNumber(totalRegistered)} registered accounts`}
                actions={
                    <>
                        <ActionButton url={actions.export} className={ghost}><Download size={14} /> Export</ActionButton>
                        <ActionButton url={actions.invite} className={primary}>+ Invite Rider</ActionButton>
                    </>
                }
            />

            <FilterBar
                filters={filters}
                searchPlaceholder="Search by name, phone, or email…"
                selects={[{ key: 'status', allLabel: 'All Statuses', options: statuses.map((s) => ({ value: s, label: label(s) })) }]}
                resultLabel={`${riders.total} result${riders.total === 1 ? '' : 's'}`}
            />

            <PaginatedTable
                paginator={riders}
                rowKey={(rider) => rider.id}
                empty={{ title: 'No riders found' }}
                columns={[
                    {
                        key: 'rider', header: 'Rider', render: (rider) => (
                            <div className="flex items-center gap-3">
                                <Avatar name={rider.name} seed={rider.id} />
                                <div>
                                    <Link href={`/admin/riders/${rider.id}`} className="text-sm font-semibold hover:text-indigo-600" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{rider.name}</Link>
                                    <p className="text-[11px]" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{rider.code}</p>
                                </div>
                            </div>
                        ),
                    },
                    {
                        key: 'contact', header: 'Contact', render: (rider) => (
                            <>
                                <p className="text-xs" style={{ color: '#64748b', fontFamily: 'var(--font-mono)' }}>{rider.phone}</p>
                                <p className="mt-0.5 text-[11px]" style={{ color: '#94a3b8' }}>{rider.email}</p>
                            </>
                        ),
                    },
                    { key: 'status', header: 'Status', render: (rider) => <StatusBadge status={rider.status} /> },
                    { key: 'trips', header: 'Trips', align: 'right', render: (rider) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{formatNumber(rider.trips_count)}</span> },
                    { key: 'registered', header: 'Registered', render: (rider) => <span className="text-xs" style={{ color: '#64748b' }}>{formatDate(rider.registered_at, timezone)}</span> },
                    {
                        key: 'menu', header: '', render: (rider) => (
                            <div className="relative flex justify-end">
                                <button aria-label={`Actions for ${rider.name}`} onClick={() => setMenuOpen(menuOpen === rider.id ? null : rider.id)} className="rounded-xl p-2 transition-colors hover:bg-slate-50" style={{ color: '#94a3b8' }}>
                                    <MoreHorizontal size={15} />
                                </button>
                                {menuOpen === rider.id && (
                                    <>
                                        <div className="fixed inset-0 z-20" onClick={() => setMenuOpen(null)} />
                                        <div className="absolute right-0 top-full z-30 mt-1.5 w-44 rounded-2xl py-1.5" style={{ background: 'white', border: '1px solid #f1f5f9', boxShadow: '0 10px 40px rgba(0,0,0,0.12)' }}>
                                            <button onClick={() => router.visit(`/admin/riders/${rider.id}`)} className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-slate-50" style={{ color: '#374151', fontFamily: 'var(--font-display)', fontWeight: 500 }}>
                                                <Eye size={13} /> View Profile
                                            </button>
                                            <button onClick={() => { setSuspending(rider); setMenuOpen(null); }} className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-red-50" style={{ color: '#ef4444', fontFamily: 'var(--font-display)', fontWeight: 500 }}>
                                                <UserX size={13} /> {rider.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                                            </button>
                                        </div>
                                    </>
                                )}
                            </div>
                        ),
                    },
                ]}
            />

            <ReasonModal
                open={suspending !== null}
                onClose={() => setSuspending(null)}
                title={`Suspend ${suspending?.name ?? ''}`}
                description={<>This will immediately prevent <strong style={{ color: '#0f172a' }}>{suspending?.name}</strong> from booking trips.</>}
                confirmLabel="Confirm Suspension"
                placeholder="Describe why this rider is being suspended…"
                url={withId(actions.suspend, suspending?.id)}
            />
        </div>
    );
}
