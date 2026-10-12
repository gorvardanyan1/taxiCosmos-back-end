import { Head, Link, router } from '@inertiajs/react';
import { Download, Eye, MoreHorizontal, UserX } from 'lucide-react';
import { useState } from 'react';
import ActionButton from '@/Components/ActionButton';
import Avatar from '@/Components/Avatar';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import RatingStars from '@/Components/RatingStars';
import ReasonModal from '@/Components/ReasonModal';
import StatusBadge from '@/Components/StatusBadge';
import { formatNumber } from '@/lib/format';
import { label } from '@/lib/labels';
import { filterKey, visitQuery, withId } from '@/lib/query';
import { filterInput, ghost, primary } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, DriverRow, Filters, Paginated, VehicleClass } from '@/types';
import { t, tc, tn } from '@/lib/i18n';

interface Props {
    drivers: Paginated<DriverRow>;
    filters: Filters;
    sort?: string | null;
    verificationStatuses: string[];
    availabilities: string[];
    vehicleClasses: VehicleClass[];
    zones: { id: number; name: string }[];
    totalRegistered: number;
    /** `suspend` / `reactivate` are URL templates with an {id} placeholder; null without the permission. */
    actions: Actions<'export' | 'create' | 'suspend' | 'reactivate'>;
}

/** "Documents expiring within …" choices, in days. */
const EXPIRY_DAYS = [7, 14, 30, 60, 90] as const;

const setFilter = (key: string, value: string) => visitQuery({ [filterKey(key)]: value }, { resetPage: true });

export default function DriversIndex({ drivers, filters, sort = null, verificationStatuses, availabilities, vehicleClasses, zones, totalRegistered, actions }: Props) {
    const { money } = useShared();
    const [menuOpen, setMenuOpen] = useState<number | null>(null);
    const [acting, setActing] = useState<DriverRow | null>(null);
    const reactivating = acting?.status === 'suspended';

    return (
        <div>
            <Head title={t('Drivers')} />
            <PageHeader
                title={t('Drivers')}
                description={tc(':count registered driver across all zones|:count registered drivers across all zones', totalRegistered)}
                actions={
                    <>
                        <ActionButton url={actions.export} className={ghost}><Download size={14} /> {t('Export')}</ActionButton>
                        <ActionButton url={actions.create} className={primary}>{t('+ Add Driver')}</ActionButton>
                    </>
                }
            />
            <FilterBar
                filters={filters}
                searchPlaceholder={t('Search by name, phone, code or exact license…')}
                selects={[
                    { key: 'verification_status', allLabel: t('All Statuses'), options: verificationStatuses.map((s) => ({ value: s, label: label(s) })) },
                    { key: 'availability', allLabel: t('Any availability'), options: availabilities.map((s) => ({ value: s, label: label(s) })) },
                    { key: 'zone', allLabel: t('All zones'), options: zones.map((z) => ({ value: String(z.id), label: z.name })) },
                    { key: 'vehicle_class', allLabel: t('All vehicle classes'), options: vehicleClasses.map((c) => ({ value: c, label: label(c) })) },
                ]}
                resultLabel={tc(':count driver|:count drivers', drivers.total)}
            >
                <select aria-label={t('Documents expiring')} value={filters.documents_expiring ?? ''} onChange={(e) => setFilter('documents_expiring', e.target.value)} className={filterInput}>
                    <option value="">{t('Any document expiry')}</option>
                    {EXPIRY_DAYS.map((days) => <option key={days} value={days}>{tc('Documents expiring within :count day|Documents expiring within :count days', days)}</option>)}
                </select>
            </FilterBar>
            <PaginatedTable
                paginator={drivers}
                sort={sort}
                rowKey={(driver) => driver.id}
                empty={{ title: t('No drivers found') }}
                columns={[
                    {
                        key: 'driver', header: t('Driver'), sortKey: 'name', render: (driver) => (
                            <div className="flex items-center gap-3">
                                <Avatar name={driver.name} seed={driver.id} />
                                <div>
                                    <Link href={`/admin/drivers/${driver.id}`} className="text-sm font-semibold hover:text-indigo-600" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{driver.name}</Link>
                                    <p className="text-[11px]" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{driver.code}</p>
                                </div>
                            </div>
                        ),
                    },
                    { key: 'vehicle', header: t('Vehicle'), render: (driver) => <p className="text-xs font-medium" style={{ color: '#64748b' }}>{driver.vehicle ? `${driver.vehicle.label} ’${String(driver.vehicle.year).slice(2)}` : '—'}</p> },
                    {
                        key: 'verification', header: t('Verification'), render: (driver) => (
                            <div className="flex flex-wrap items-center gap-1.5">
                                <StatusBadge status={driver.verification_status} />
                                {driver.status === 'suspended' && <StatusBadge status="suspended" />}
                            </div>
                        ),
                    },
                    { key: 'availability', header: t('Availability'), render: (driver) => <StatusBadge status={driver.availability} /> },
                    { key: 'rating', header: t('Rating'), sortKey: 'rating', render: (driver) => <RatingStars rating={driver.rating} /> },
                    { key: 'trips', header: t('Trips'), align: 'right', sortKey: 'trips_count', render: (driver) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{formatNumber(driver.trips_count)}</span> },
                    { key: 'earnings', header: t('Earnings'), align: 'right', render: (driver) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{money(driver.earnings)}</span> },
                    {
                        key: 'menu', header: '', render: (driver) => (
                            <div className="relative flex justify-end">
                                <button aria-label={t('Actions for :name', { name: driver.name })} onClick={() => setMenuOpen(menuOpen === driver.id ? null : driver.id)} className="rounded-xl p-2 transition-colors hover:bg-slate-50" style={{ color: '#94a3b8' }}><MoreHorizontal size={15} /></button>
                                {menuOpen === driver.id && (
                                    <>
                                        <div className="fixed inset-0 z-20" onClick={() => setMenuOpen(null)} />
                                        <div className="absolute right-0 top-full z-30 mt-1.5 w-44 rounded-2xl py-1.5" style={{ background: 'white', border: '1px solid #f1f5f9', boxShadow: '0 10px 40px rgba(0,0,0,0.12)' }}>
                                            <button onClick={() => router.visit(`/admin/drivers/${driver.id}`)} className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-slate-50" style={{ color: '#374151', fontFamily: 'var(--font-display)', fontWeight: 500 }}>
                                                <Eye size={13} /> {t('View Detail')}
                                            </button>
                                            {(driver.status === 'active' || driver.status === 'suspended') && actions.suspend !== null && (
                                                <button onClick={() => { setActing(driver); setMenuOpen(null); }} className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-red-50" style={{ color: driver.status === 'suspended' ? '#4f46e5' : '#ef4444', fontFamily: 'var(--font-display)', fontWeight: 500 }}>
                                                    <UserX size={13} /> {driver.status === 'suspended' ? t('Reactivate') : t('Suspend')}
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
                    ? tn('This lets :name sign in and take trips again.', { name: <strong style={{ color: '#0f172a' }}>{acting?.name}</strong> })
                    : tn('This will immediately take :name offline and sign them out of the app. A driver on a trip cannot be suspended.', { name: <strong style={{ color: '#0f172a' }}>{acting?.name}</strong> })}
                confirmLabel={reactivating ? t('Confirm Reactivation') : t('Confirm Suspension')}
                placeholder={reactivating ? t('Why is this driver being reactivated…') : t('Describe why this driver is being suspended…')}
                tone={reactivating ? 'primary' : 'danger'}
                url={withId(reactivating ? actions.reactivate : actions.suspend, acting?.id)}
            />
        </div>
    );
}
