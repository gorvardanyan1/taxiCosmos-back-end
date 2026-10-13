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
import { filterInput } from '@/lib/ui';
import { label } from '@/lib/labels';
import { filterKey, visitQuery, withId } from '@/lib/query';
import { ghost } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, Filters, Paginated, RiderRow } from '@/types';
import { t, tc, tn } from '@/lib/i18n';

interface Props {
    riders: Paginated<RiderRow>;
    filters: Filters;
    sort: string | null;
    statuses: string[];
    totalRegistered: number;
    /** `suspend` / `reactivate` are URL templates with an {id} placeholder. */
    actions: Actions<'export' | 'suspend' | 'reactivate'>;
}

const setFilter = (key: string, value: string) => visitQuery({ [filterKey(key)]: value }, { resetPage: true });

export default function RidersIndex({ riders, filters, sort, statuses, totalRegistered, actions }: Props) {
    const { timezone, can } = useShared();
    const [menuOpen, setMenuOpen] = useState<number | null>(null);
    const [acting, setActing] = useState<RiderRow | null>(null);
    const reactivating = acting?.status === 'suspended';

    return (
        <div>
            <Head title={t('Riders')} />
            <PageHeader
                title={t('Riders')}
                description={tc(':count registered account|:count registered accounts', totalRegistered)}
                actions={
                    <>
                        <ActionButton url={actions.export} className={ghost}><Download size={14} /> {t('Export')}</ActionButton>
                    </>
                }
            />

            <FilterBar
                filters={filters}
                searchPlaceholder={t('Search by name, phone, or email…')}
                selects={[{ key: 'status', allLabel: t('All Statuses'), options: statuses.map((s) => ({ value: s, label: label(s) })) }]}
                resultLabel={tc(':count result|:count results', riders.total)}
            >
                <label className="flex items-center gap-1.5 text-xs font-semibold text-slate-400">
                    {t('Registered')}
                    <input type="date" aria-label={t('Registered from')} value={filters.registered_from ?? ''} onChange={(e) => setFilter('registered_from', e.target.value)} className={`${filterInput} w-40`} />
                    –
                    <input type="date" aria-label={t('Registered to')} value={filters.registered_to ?? ''} onChange={(e) => setFilter('registered_to', e.target.value)} className={`${filterInput} w-40`} />
                </label>
            </FilterBar>

            <PaginatedTable
                paginator={riders}
                sort={sort}
                rowKey={(rider) => rider.id}
                empty={{ title: t('No riders found') }}
                columns={[
                    {
                        key: 'rider', header: t('Rider'), sortKey: 'name', render: (rider) => (
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
                        key: 'contact', header: t('Contact'), render: (rider) => (
                            <>
                                <p className="text-xs" style={{ color: '#64748b', fontFamily: 'var(--font-mono)' }}>{rider.phone}</p>
                                <p className="mt-0.5 text-[11px]" style={{ color: '#94a3b8' }}>{rider.email}</p>
                            </>
                        ),
                    },
                    { key: 'status', header: t('Status'), render: (rider) => <StatusBadge status={rider.status} /> },
                    { key: 'trips', header: t('Trips'), align: 'right', sortKey: 'trips_count', render: (rider) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{formatNumber(rider.trips_count)}</span> },
                    { key: 'registered', header: t('Registered'), sortKey: 'registered_at', render: (rider) => <span className="text-xs" style={{ color: '#64748b' }}>{formatDate(rider.registered_at, timezone)}</span> },
                    {
                        key: 'menu', header: '', render: (rider) => (
                            <div className="relative flex justify-end">
                                <button aria-label={t('Actions for :name', { name: rider.name })} onClick={() => setMenuOpen(menuOpen === rider.id ? null : rider.id)} className="rounded-xl p-2 transition-colors hover:bg-slate-50" style={{ color: '#94a3b8' }}>
                                    <MoreHorizontal size={15} />
                                </button>
                                {menuOpen === rider.id && (
                                    <>
                                        <div className="fixed inset-0 z-20" onClick={() => setMenuOpen(null)} />
                                        <div className="absolute right-0 top-full z-30 mt-1.5 w-44 rounded-2xl py-1.5" style={{ background: 'white', border: '1px solid #f1f5f9', boxShadow: '0 10px 40px rgba(0,0,0,0.12)' }}>
                                            <button onClick={() => router.visit(`/admin/riders/${rider.id}`)} className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-slate-50" style={{ color: '#374151', fontFamily: 'var(--font-display)', fontWeight: 500 }}>
                                                <Eye size={13} /> {t('View Profile')}
                                            </button>
                                            {can('riders.suspend') && (rider.status === 'active' || rider.status === 'suspended') && (
                                                <button onClick={() => { setActing(rider); setMenuOpen(null); }} className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-red-50" style={{ color: rider.status === 'suspended' ? '#4f46e5' : '#ef4444', fontFamily: 'var(--font-display)', fontWeight: 500 }}>
                                                    <UserX size={13} /> {rider.status === 'suspended' ? t('Reactivate') : t('Suspend')}
                                                </button>
                                            )}
                                        </div>
                                    </>
                                )}
                            </div>
                        ),
                    },
                ]}
            />

            <ReasonModal
                open={acting !== null}
                onClose={() => setActing(null)}
                title={`${reactivating ? t('Reactivate') : t('Suspend')} ${acting?.name ?? ''}`}
                description={reactivating
                    ? tn('This lets :name book trips again.', { name: <strong style={{ color: '#0f172a' }}>{acting?.name}</strong> })
                    : tn('This will immediately prevent :name from booking trips and sign them out of the app.', { name: <strong style={{ color: '#0f172a' }}>{acting?.name}</strong> })}
                confirmLabel={reactivating ? t('Confirm Reactivation') : t('Confirm Suspension')}
                placeholder={reactivating ? t('Why is this rider being reactivated…') : t('Describe why this rider is being suspended…')}
                tone={reactivating ? 'primary' : 'danger'}
                url={withId(reactivating ? actions.reactivate : actions.suspend, acting?.id)}
            />
        </div>
    );
}
