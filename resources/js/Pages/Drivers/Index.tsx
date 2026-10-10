import { Head, Link, router } from '@inertiajs/react';
import { Download, Eye, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import ActionButton from '@/Components/ActionButton';
import Avatar from '@/Components/Avatar';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import RatingStars from '@/Components/RatingStars';
import StatusBadge from '@/Components/StatusBadge';
import { formatNumber } from '@/lib/format';
import { label } from '@/lib/labels';
import { ghost, primary } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, DriverRow, Filters, Paginated } from '@/types';

interface Props {
    drivers: Paginated<DriverRow>;
    filters: Filters;
    verificationStatuses: string[];
    totalRegistered: number;
    actions: Actions<'export' | 'create'>;
}

export default function DriversIndex({ drivers, filters, verificationStatuses, totalRegistered, actions }: Props) {
    const { money } = useShared();
    const [menuOpen, setMenuOpen] = useState<number | null>(null);

    return (
        <div>
            <Head title="Drivers" />
            <PageHeader
                title="Drivers"
                description={`${formatNumber(totalRegistered)} registered drivers across all zones`}
                actions={
                    <>
                        <ActionButton url={actions.export} className={ghost}><Download size={14} /> Export</ActionButton>
                        <ActionButton url={actions.create} className={primary}>+ Add Driver</ActionButton>
                    </>
                }
            />
            <FilterBar
                filters={filters}
                searchPlaceholder="Search by name or phone…"
                selects={[{ key: 'verification_status', allLabel: 'All Statuses', options: verificationStatuses.map((s) => ({ value: s, label: label(s) })) }]}
                resultLabel={`${drivers.total} drivers`}
            />
            <PaginatedTable
                paginator={drivers}
                rowKey={(driver) => driver.id}
                empty={{ title: 'No drivers found' }}
                columns={[
                    {
                        key: 'driver', header: 'Driver', render: (driver) => (
                            <div className="flex items-center gap-3">
                                <Avatar name={driver.name} seed={driver.id} />
                                <div>
                                    <Link href={`/admin/drivers/${driver.id}`} className="text-sm font-semibold hover:text-indigo-600" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{driver.name}</Link>
                                    <p className="text-[11px]" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{driver.code}</p>
                                </div>
                            </div>
                        ),
                    },
                    { key: 'vehicle', header: 'Vehicle', render: (driver) => <p className="text-xs font-medium" style={{ color: '#64748b' }}>{driver.vehicle.label} ’{String(driver.vehicle.year).slice(2)}</p> },
                    { key: 'verification', header: 'Verification', render: (driver) => <StatusBadge status={driver.verification_status} /> },
                    { key: 'rating', header: 'Rating', render: (driver) => <RatingStars rating={driver.rating} /> },
                    { key: 'trips', header: 'Trips', align: 'right', render: (driver) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{formatNumber(driver.trips_count)}</span> },
                    { key: 'earnings', header: 'Earnings', align: 'right', render: (driver) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{money(driver.earnings)}</span> },
                    {
                        key: 'menu', header: '', render: (driver) => (
                            <div className="relative flex justify-end">
                                <button aria-label={`Actions for ${driver.name}`} onClick={() => setMenuOpen(menuOpen === driver.id ? null : driver.id)} className="rounded-xl p-2 transition-colors hover:bg-slate-50" style={{ color: '#94a3b8' }}><MoreHorizontal size={15} /></button>
                                {menuOpen === driver.id && (
                                    <>
                                        <div className="fixed inset-0 z-20" onClick={() => setMenuOpen(null)} />
                                        <div className="absolute right-0 top-full z-30 mt-1.5 w-44 rounded-2xl py-1.5" style={{ background: 'white', border: '1px solid #f1f5f9', boxShadow: '0 10px 40px rgba(0,0,0,0.12)' }}>
                                            <button onClick={() => router.visit(`/admin/drivers/${driver.id}`)} className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-slate-50" style={{ color: '#374151', fontFamily: 'var(--font-display)', fontWeight: 500 }}>
                                                <Eye size={13} /> View Detail
                                            </button>
                                        </div>
                                    </>
                                )}
                            </div>
                        ),
                    },
                ]}
            />
        </div>
    );
}
