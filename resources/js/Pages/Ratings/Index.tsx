import { Head } from '@inertiajs/react';
import { Star } from 'lucide-react';
import ActionButton from '@/Components/ActionButton';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import { formatDateTime } from '@/lib/format';
import { label } from '@/lib/labels';
import { withId } from '@/lib/query';
import { ghost } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, Filters, Paginated, RatingRow } from '@/types';

interface Props {
    ratings: Paginated<RatingRow>;
    filters: Filters;
    lowRatedDrivers: number;
    actions: Actions<'hideComment'>;
}

export default function RatingsIndex({ ratings, filters, lowRatedDrivers, actions }: Props) {
    const { timezone } = useShared();

    return (
        <div>
            <Head title="Ratings" />
            <PageHeader title="Ratings" description="Review rider and driver feedback" actions={<span className={ghost}>Low-rated drivers <span className="rounded-full bg-red-100 px-2 text-red-600">{lowRatedDrivers}</span></span>} />
            <FilterBar
                filters={filters}
                selects={[
                    { key: 'direction', allLabel: 'All directions', options: ['rider_to_driver', 'driver_to_rider'].map((d) => ({ value: d, label: label(d) })) },
                    { key: 'stars', allLabel: 'All stars', options: ['5', '4', '3', '2', '1'].map((s) => ({ value: s, label: `${s} ★` })) },
                ]}
            />
            <PaginatedTable
                paginator={ratings}
                rowKey={(rating) => rating.id}
                empty={{ title: 'No ratings found' }}
                columns={[
                    { key: 'trip', header: 'Trip', render: (r) => <span className="font-mono font-bold text-indigo-600">{r.trip_code}</span> },
                    { key: 'direction', header: 'Direction', render: (r) => label(r.direction) },
                    { key: 'stars', header: 'Stars', render: (r) => <span className="flex text-amber-400"><Star size={13} fill="currentColor" /> {r.stars}</span> },
                    { key: 'comment', header: 'Comment', render: (r) => r.comment },
                    { key: 'tags', header: 'Tags', render: (r) => r.tags.join(' · ') },
                    { key: 'date', header: 'Date', render: (r) => formatDateTime(r.created_at, timezone) },
                    { key: 'hide', header: '', render: (r) => <ActionButton url={withId(actions.hideComment, r.id)} className="text-xs font-bold text-slate-500">Hide comment</ActionButton> },
                ]}
            />
        </div>
    );
}
