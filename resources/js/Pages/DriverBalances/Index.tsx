import { Head } from '@inertiajs/react';
import { AlertTriangle, TrendingUp } from 'lucide-react';
import ActionButton from '@/Components/ActionButton';
import FilterBar from '@/Components/FilterBar';
import Metric from '@/Components/Metric';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import { formatNumber } from '@/lib/format';
import { withId } from '@/lib/query';
import { useShared } from '@/lib/useShared';
import type { Actions, DriverBalanceRow, Filters, Money, Paginated } from '@/types';

interface Props {
    summary: { owed_to_drivers: Money; owed_by_drivers: Money; over_limit_count: number };
    balances: Paginated<DriverBalanceRow>;
    filters: Filters;
    actions: Actions<'recordSettlement'>;
}

export default function DriverBalancesIndex({ summary, balances, filters, actions }: Props) {
    const { money } = useShared();

    return (
        <div>
            <Head title="Driver Balances" />
            <PageHeader title="Driver Balances" description="Monitor driver earnings, cash commission debt, and ageing" />
            <div className="mb-5 grid grid-cols-3 gap-4">
                <Metric label="Owed to drivers" value={money(summary.owed_to_drivers, { compact: true })} tone="from-emerald-600 to-emerald-700" icon={<TrendingUp size={16} />} />
                <Metric label="Owed by drivers" value={money(summary.owed_by_drivers, { compact: true })} tone="from-rose-600 to-rose-700" icon={<TrendingUp size={16} />} />
                <Metric label="Drivers over limit" value={formatNumber(summary.over_limit_count)} tone="from-amber-600 to-amber-700" icon={<AlertTriangle size={16} />} />
            </div>
            <FilterBar filters={filters} searchPlaceholder="Search drivers…" />
            <PaginatedTable
                paginator={balances}
                rowKey={(b) => b.id}
                empty={{ title: 'No driver balances' }}
                columns={[
                    { key: 'driver', header: 'Driver', render: (b) => <b className="text-slate-900">{b.driver}</b> },
                    { key: 'balance', header: 'Balance', render: (b) => <span className={`font-mono font-bold ${b.balance.amount < 0 ? 'text-red-600' : 'text-emerald-600'}`}>{money(b.balance)}</span> },
                    { key: 'd0', header: '0–7 days', render: (b) => money(b.ageing.days_0_7) },
                    { key: 'd8', header: '8–30 days', render: (b) => money(b.ageing.days_8_30) },
                    { key: 'd30', header: '30+ days', render: (b) => money(b.ageing.days_30_plus) },
                    {
                        key: 'limit', header: 'Debt limit', render: (b) => (
                            <div className="w-28">
                                <div className="h-1.5 rounded-full bg-slate-100"><div className={`h-full rounded-full ${b.debt_limit_used_percent > 80 ? 'bg-red-500' : 'bg-indigo-500'}`} style={{ width: `${Math.min(100, b.debt_limit_used_percent)}%` }} /></div>
                                <span className="mt-1 block text-[10px]">{b.debt_limit_used_percent}%</span>
                            </div>
                        ),
                    },
                    { key: 'settle', header: '', render: (b) => <ActionButton url={withId(actions.recordSettlement, b.id)} className="text-xs font-bold text-indigo-600">Record settlement</ActionButton> },
                ]}
            />
        </div>
    );
}
