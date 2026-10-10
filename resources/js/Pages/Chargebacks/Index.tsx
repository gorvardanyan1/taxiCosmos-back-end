import { Head } from '@inertiajs/react';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import StatusBadge from '@/Components/StatusBadge';
import { daysUntil } from '@/lib/format';
import { label } from '@/lib/labels';
import { useShared } from '@/lib/useShared';
import type { ChargebackRow, Filters, Paginated } from '@/types';

function evidenceDue(iso: string): { text: string; overdue: boolean } {
    const days = daysUntil(iso);
    if (days < 0) return { text: `Overdue ${-days} day${days === -1 ? '' : 's'}`, overdue: true };
    if (days === 0) return { text: 'Due today', overdue: false };
    if (days === 1) return { text: 'Due tomorrow', overdue: false };
    return { text: `Due in ${days} days`, overdue: false };
}

export default function ChargebacksIndex({ chargebacks, filters }: { chargebacks: Paginated<ChargebackRow>; filters: Filters }) {
    const { money } = useShared();

    return (
        <div>
            <Head title="Chargebacks" />
            <PageHeader title="Chargebacks" description="Respond to gateway disputes and submit evidence" />
            <FilterBar filters={filters} selects={[{ key: 'status', allLabel: 'All statuses', options: ['review', 'processing', 'failed', 'won', 'lost'].map((s) => ({ value: s, label: label(s) })) }]} />
            <PaginatedTable
                paginator={chargebacks}
                rowKey={(c) => c.id}
                empty={{ title: 'No chargebacks' }}
                columns={[
                    { key: 'dispute', header: 'Gateway dispute', render: (c) => <span className="font-mono font-bold text-indigo-600">{c.gateway_dispute_id}</span> },
                    { key: 'txn', header: 'Transaction', render: (c) => <span className="font-mono">{c.transaction_code}</span> },
                    { key: 'rider', header: 'Rider', render: (c) => c.rider },
                    { key: 'amount', header: 'Amount', render: (c) => <span className="font-mono font-bold">{money(c.amount)}</span> },
                    { key: 'reason', header: 'Reason', render: (c) => label(c.reason) },
                    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
                    { key: 'due', header: 'Evidence due', render: (c) => { const due = evidenceDue(c.evidence_due_at); return <span className={`font-bold ${due.overdue ? 'text-red-600' : 'text-amber-600'}`}>{due.text}</span>; } },
                ]}
            />
        </div>
    );
}
