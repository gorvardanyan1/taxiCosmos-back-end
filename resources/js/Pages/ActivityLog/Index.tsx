import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import { formatDateTime } from '@/lib/format';
import { label } from '@/lib/labels';
import { filterKey, visitQuery } from '@/lib/query';
import { card, filterInput, ghost } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { ActivityEntry, Actions, Filters, Paginated } from '@/types';

interface Props {
    entries: Paginated<ActivityEntry>;
    filters: Filters;
    targetTypes: string[];
    actors: { id: number; name: string | null }[];
    actionNames: string[];
    expandedId: number | null;
    /** The entry named by ?entry=, even when it is not on the current page. */
    expanded: ActivityEntry | null;
    /** Export of the current filter (CSV); the URL carries the active filters. */
    actions: Actions<'export'>;
}

const toLines = (values: Record<string, unknown>) => Object.entries(values).map(([key, value]) => `${key}: ${JSON.stringify(value)}`).join('\n');

const setFilter = (key: string, value: string) => visitQuery({ [filterKey(key)]: value }, { resetPage: true });

export default function ActivityLogIndex({ entries, filters, targetTypes, actors, actionNames, expandedId, expanded, actions }: Props) {
    const { timezone } = useShared();

    return (
        <div>
            <Head title="Activity Log" />
            <PageHeader title="Activity Log" description="Immutable audit trail for administrator actions" actions={<a href={actions.export ?? undefined} download aria-disabled={actions.export === null} className={ghost}><Download size={14} />Export CSV</a>} />
            <FilterBar
                filters={filters}
                searchPlaceholder="Search action, actor, target or reason…"
                selects={[
                    { key: 'actor', allLabel: 'All actors', options: actors.map((a) => ({ value: String(a.id), label: a.name ?? `Admin #${a.id}` })) },
                    { key: 'action', allLabel: 'All actions', options: actionNames.map((name) => ({ value: name, label: name })) },
                    { key: 'target_type', allLabel: 'All target types', options: targetTypes.map((t) => ({ value: t, label: label(t) })) },
                ]}
            >
                <input type="number" min={1} aria-label="Target ID" placeholder="Target ID" defaultValue={filters.target_id ?? ''} onBlur={(e) => e.target.value !== (filters.target_id ?? '') && setFilter('target_id', e.target.value)} onKeyDown={(e) => e.key === 'Enter' && setFilter('target_id', e.currentTarget.value)} className={`${filterInput} w-28`} />
                <input type="date" aria-label="From date" value={filters.from ?? ''} onChange={(e) => setFilter('from', e.target.value)} className={`${filterInput} w-40`} />
                <input type="date" aria-label="To date" value={filters.to ?? ''} onChange={(e) => setFilter('to', e.target.value)} className={`${filterInput} w-40`} />
            </FilterBar>
            <PaginatedTable
                paginator={entries}
                rowKey={(e) => e.id}
                onRowClick={(e) => visitQuery({ entry: e.id === expandedId ? null : e.id })}
                empty={{ title: 'No activity recorded' }}
                columns={[
                    { key: 'time', header: 'Time', render: (e) => formatDateTime(e.occurred_at, timezone) },
                    { key: 'actor', header: 'Actor', render: (e) => (e.actor.role ? `${e.actor.name} · ${label(e.actor.role)}` : e.actor.name) },
                    { key: 'action', header: 'Action', render: (e) => <span className="font-mono text-indigo-600">{e.action}</span> },
                    { key: 'target', header: 'Target', render: (e) => e.target },
                    { key: 'reason', header: 'Reason', render: (e) => e.reason ?? '—' },
                    { key: 'ip', header: 'IP', render: (e) => e.ip },
                ]}
            />
            {expanded && (
                <div className={`${card} mt-5`}>
                    <div className="m-5 grid gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/30 p-5 md:grid-cols-2" aria-label={`Changes for ${expanded.action}`}>
                        <div><p className="text-xs font-bold uppercase text-slate-400">Before</p><pre className="mt-3 whitespace-pre-wrap rounded-xl bg-slate-900 p-4 font-mono text-xs text-slate-300">{toLines(expanded.before)}</pre></div>
                        <div><p className="text-xs font-bold uppercase text-slate-400">After</p><pre className="mt-3 whitespace-pre-wrap rounded-xl bg-slate-900 p-4 font-mono text-xs text-emerald-300">{toLines(expanded.after)}</pre></div>
                    </div>
                </div>
            )}
        </div>
    );
}
