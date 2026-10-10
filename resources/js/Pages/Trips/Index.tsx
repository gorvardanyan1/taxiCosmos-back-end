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

interface Props {
    trips: Paginated<TripRow>;
    filters: Filters;
    statuses: string[];
}

export default function TripsIndex({ trips, filters, statuses }: Props) {
    const { money, timezone } = useShared();

    return (
        <div>
            <Head title="Trips" />
            <PageHeader title="Trips" description="Real-time and historical trip records" />
            <FilterBar
                filters={filters}
                searchPlaceholder="Trip ID, rider, or driver…"
                selects={[{ key: 'status', allLabel: 'All Statuses', options: statuses.map((s) => ({ value: s, label: label(s) })) }]}
                resultLabel={`${trips.total} trips`}
            />
            <PaginatedTable
                paginator={trips}
                rowKey={(trip) => trip.id}
                onRowClick={(trip) => router.visit(`/admin/trips/${trip.id}`)}
                empty={{ title: 'No trips match your filters.' }}
                columns={[
                    { key: 'code', header: 'Trip ID', render: (trip) => <span className="text-xs font-bold" style={{ color: '#6366f1', fontFamily: 'var(--font-mono)' }}>{trip.code}</span> },
                    { key: 'rider', header: 'Rider', render: (trip) => <span className="text-sm font-medium" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{trip.rider}</span> },
                    { key: 'driver', header: 'Driver', render: (trip) => <span className="text-sm font-medium" style={{ color: '#64748b' }}>{trip.driver ?? '—'}</span> },
                    {
                        key: 'route', header: 'Route', className: 'max-w-[200px]', render: (trip) => (
                            <div className="flex flex-col gap-0.5">
                                <span className="flex items-center gap-1 text-[11px]" style={{ color: '#6366f1' }}><MapPin size={9} /> <span className="truncate">{trip.pickup_address}</span></span>
                                <span className="flex items-center gap-1 text-[11px]" style={{ color: '#22c55e' }}><MapPin size={9} /> <span className="truncate">{trip.dropoff_address}</span></span>
                            </div>
                        ),
                    },
                    { key: 'status', header: 'Status', render: (trip) => <StatusBadge status={trip.status} /> },
                    { key: 'fare', header: 'Fare', align: 'right', render: (trip) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{trip.fare ? money(trip.fare) : '—'}</span> },
                    { key: 'time', header: 'Time', render: (trip) => <span className="text-xs" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{formatDateTime(trip.requested_at, timezone)}</span> },
                    { key: 'menu', header: '', render: () => <MoreHorizontal size={15} style={{ color: '#cbd5e1' }} /> },
                ]}
            />
        </div>
    );
}
