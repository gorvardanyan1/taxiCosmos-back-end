import { Head } from '@inertiajs/react';
import { AlertTriangle, Banknote, Download, Users } from 'lucide-react';
import ActionButton from '@/Components/ActionButton';
import Metric from '@/Components/Metric';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate, formatNumber } from '@/lib/format';
import { label } from '@/lib/labels';
import { filterKey, visitQuery, withId } from '@/lib/query';
import { card, field, ghost, primary } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, Filters, Money, Paginated, PayoutRow } from '@/types';

interface Props {
    periods: { start: string; end: string }[];
    summary: { drivers_in_batch: number; total_amount: Money; skipped_count: number };
    payouts: Paginated<PayoutRow>;
    filters: Filters;
    statuses: string[];
    skipped: { driver: string; reason: string }[];
    actions: Actions<'exportBankCsv' | 'approveBatch' | 'approve' | 'reject'>;
}

export default function PayoutsIndex({ periods, summary, payouts, filters, statuses, skipped, actions }: Props) {
    const { money, timezone } = useShared();
    const period = (start: string, end: string) => `${formatDate(start, timezone).replace(/, \d{4}$/, '')} – ${formatDate(end, timezone)}`;

    return (
        <div>
            <Head title="Payouts" />
            <PageHeader
                title="Payouts"
                description="Review and release weekly driver payouts"
                actions={
                    <>
                        <ActionButton url={actions.exportBankCsv} className={ghost}><Download size={14} />Export CSV for bank</ActionButton>
                        <ActionButton url={actions.approveBatch} className={primary}>Approve batch</ActionButton>
                    </>
                }
            />
            <div className="mb-5 grid grid-cols-3 gap-4">
                <Metric label="Drivers in batch" value={formatNumber(summary.drivers_in_batch)} tone="from-indigo-600 to-violet-600" icon={<Users size={16} />} />
                <Metric label="Total amount" value={money(summary.total_amount, { compact: true })} tone="from-emerald-600 to-emerald-700" icon={<Banknote size={16} />} />
                <Metric label="Skipped drivers" value={formatNumber(summary.skipped_count)} tone="from-amber-600 to-amber-700" icon={<AlertTriangle size={16} />} />
            </div>
            <div className="mb-5 flex items-center gap-2">
                <select aria-label="Period" value={filters.period_start ?? ''} onChange={(e) => visitQuery({ [filterKey('period_start')]: e.target.value }, { resetPage: true })} className={`${field} max-w-xs`}>
                    <option value="">All periods</option>
                    {periods.map((p) => <option key={p.start} value={p.start}>Week {period(p.start, p.end)}</option>)}
                </select>
                {statuses.map((status) => {
                    const active = filters.status === status;
                    return (
                        <button key={status} aria-pressed={active} onClick={() => visitQuery({ [filterKey('status')]: active ? null : status }, { resetPage: true })} className={`rounded-xl px-4 py-2 text-xs font-bold shadow-sm ${active ? 'bg-indigo-500 text-white' : 'bg-white text-slate-500'}`}>
                            {label(status)}
                        </button>
                    );
                })}
            </div>
            <PaginatedTable
                paginator={payouts}
                rowKey={(p) => p.id}
                empty={{ title: 'No payouts for this filter' }}
                columns={[
                    { key: 'driver', header: 'Driver', render: (p) => <b className="text-slate-900">{p.driver}</b> },
                    { key: 'bank', header: 'Bank account', render: (p) => `${p.bank_account.bank_name} •••• ${p.bank_account.account_last4}` },
                    { key: 'period', header: 'Period', render: (p) => period(p.period_start, p.period_end) },
                    { key: 'amount', header: 'Amount', render: (p) => <span className="font-mono font-bold text-slate-900">{money(p.amount)}</span> },
                    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
                    {
                        key: 'actions', header: 'Actions', render: (p) => p.status === 'pending' ? (
                            <div className="flex gap-2">
                                <ActionButton url={withId(actions.approve, p.id)} className="text-xs font-bold text-emerald-600">Approve</ActionButton>
                                <ActionButton url={withId(actions.reject, p.id)} className="text-xs font-bold text-red-600">Reject</ActionButton>
                            </div>
                        ) : null,
                    },
                ]}
            />
            <div className={`${card} mt-5 p-5`}>
                <h3 className="font-display text-sm font-bold text-slate-900">Skipped drivers</h3>
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    {skipped.length === 0 && <p className="text-sm text-slate-400">No drivers were skipped.</p>}
                    {skipped.map((s) => (
                        <div key={s.driver} className={`rounded-xl p-3 text-sm ${s.reason === 'negative_balance' ? 'bg-red-50' : 'bg-amber-50'}`}>
                            <b>{s.driver}</b><span className={`ml-2 ${s.reason === 'negative_balance' ? 'text-red-700' : 'text-amber-700'}`}>{label(s.reason)}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
