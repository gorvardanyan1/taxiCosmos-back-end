import { Head, router } from '@inertiajs/react';
import { MapPin, MoreHorizontal } from 'lucide-react';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import StatusBadge from '@/Components/StatusBadge';
import { formatDateTime } from '@/lib/format';
import { label } from '@/lib/labels';
import { useShared } from '@/lib/useShared';
import type { Filters, Paginated, TripRow } from '@/types';
import { t, tc } from '@/lib/i18n';

interface Props {
    trips: Paginated<TripRow>;
    filters: Filters;
    statuses: string[];
}

export default function TripsIndex({ trips, filters, statuses }: Props) {
    const { money, timezone } = useShared();

    return (
        <div>
            <Head title={t('Trips')} />
            <PageHeader title={t('Trips')} description={t('Real-time and historical trip records')} />
            <FilterBar
                filters={filters}
                searchPlaceholder={t('Trip ID, rider, or driver…')}
                selects={[{ key: 'status', allLabel: t('All Statuses'), options: statuses.map((s) => ({ value: s, label: label(s) })) }]}
                resultLabel={tc(':count trip|:count trips', trips.total)}
            />
            <PaginatedTable
                paginator={trips}
                rowKey={(trip) => trip.id}
                onRowClick={(trip) => router.visit(`/admin/trips/${trip.id}`)}
                empty={{ title: t('No trips match your filters.') }}
                columns={[
                    { key: 'code', header: t('Trip ID'), render: (trip) => <span className="text-xs font-bold" style={{ color: '#6366f1', fontFamily: 'var(--font-mono)' }}>{trip.code}</span> },
                    { key: 'rider', header: t('Rider'), render: (trip) => <span className="text-sm font-medium" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{trip.rider}</span> },
                    { key: 'driver', header: t('Driver'), render: (trip) => <span className="text-sm font-medium" style={{ color: '#64748b' }}>{trip.driver ?? '—'}</span> },
                    {
                        key: 'route', header: t('Route'), className: 'max-w-[200px]', render: (trip) => (
                            <div className="flex flex-col gap-0.5">
                                <span className="flex items-center gap-1 text-[11px]" style={{ color: '#6366f1' }}><MapPin size={9} /> <span className="truncate">{trip.pickup_address}</span></span>
                                <span className="flex items-center gap-1 text-[11px]" style={{ color: '#22c55e' }}><MapPin size={9} /> <span className="truncate">{trip.dropoff_address}</span></span>
                            </div>
                        ),
                    },
                    { key: 'status', header: t('Status'), render: (trip) => <StatusBadge status={trip.status} /> },
                    { key: 'fare', header: t('Fare'), align: 'right', render: (trip) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{trip.fare ? money(trip.fare) : '—'}</span> },
                    { key: 'time', header: t('Time'), render: (trip) => <span className="text-xs" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{formatDateTime(trip.requested_at, timezone)}</span> },
                    { key: 'menu', header: '', render: () => <MoreHorizontal size={15} style={{ color: '#cbd5e1' }} /> },
                ]}
            />
        </div>
    );
}
