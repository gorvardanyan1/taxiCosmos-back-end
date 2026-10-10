import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import ActionButton from '@/Components/ActionButton';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import { formatDateTime } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery } from '@/lib/query';
import { card, ghost } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { ActivityEntry, Actions, Filters, Paginated } from '@/types';

interface Props {
    entries: Paginated<ActivityEntry>;
    filters: Filters;
    targetTypes: string[];
    expandedId: number | null;
    actions: Actions<'export'>;
}

const toLines = (values: Record<string, unknown>) => Object.entries(values).map(([key, value]) => `${key}: ${JSON.stringify(value)}`).join('\n');

export default function ActivityLogIndex({ entries, filters, targetTypes, expandedId, actions }: Props) {
    const { timezone } = useShared();
    const expanded = entries.data.find((entry) => entry.id === expandedId) ?? null;

    return (
        <div>
            <Head title="Activity Log" />
            <PageHeader title="Activity Log" description="Immutable audit trail for administrator actions" actions={<ActionButton url={actions.export} className={ghost}><Download size={14} />Export CSV</ActionButton>} />
            <FilterBar filters={filters} selects={[{ key: 'target_type', allLabel: 'All target types', options: targetTypes.map((t) => ({ value: t, label: label(t) })) }]} />
            <PaginatedTable
                paginator={entries}
                rowKey={(e) => e.id}
                onRowClick={(e) => visitQuery({ entry: e.id === expandedId ? null : e.id })}
                empty={{ title: 'No activity recorded' }}
                columns={[
                    { key: 'time', header: 'Time', render: (e) => formatDateTime(e.occurred_at, timezone) },
                    { key: 'actor', header: 'Actor', render: (e) => `${e.actor.name} · ${label(e.actor.role)}` },
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
