import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import ActionButton from '@/Components/ActionButton';
import FilterBar from '@/Components/FilterBar';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import { commissionFor } from '@/lib/commission';
import { formatBasisPoints, formatDate, parseMoneyInput } from '@/lib/format';
import { label } from '@/lib/labels';
import { card, primary } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, CommissionRule, Filters, Money, Paginated } from '@/types';

interface Props {
    rules: Paginated<CommissionRule>;
    filters: Filters;
    previewRule: CommissionRule | null;
    previewFare: Money;
    actions: Actions<'createVersion'>;
}

export default function CommissionRulesIndex({ rules, filters, previewRule, previewFare, actions }: Props) {
    const { money, currencies, timezone } = useShared();
    const decimals = currencies.find((c) => c.code === previewFare.currency)?.decimals ?? 2;
    const [fare, setFare] = useState(String(previewFare.amount / 10 ** decimals));
    const fareMinor = parseMoneyInput(fare, decimals);
    const commission = previewRule && fareMinor !== null ? commissionFor(fareMinor, previewRule) : null;
    const asMoney = (amount: number): Money => ({ amount, currency: previewFare.currency });

    return (
        <div>
            <Head title="Commission" />
            <PageHeader title="Commission" description="Versioned platform commission rules by zone" actions={<ActionButton url={actions.createVersion} className={primary}><Plus size={14} />New version</ActionButton>} />
            <FilterBar filters={filters} selects={[{ key: 'vehicle_class', allLabel: 'All classes', options: ['economy', 'comfort', 'business'].map((c) => ({ value: c, label: label(c) })) }]} />
            <div className="grid gap-5 xl:grid-cols-[1fr_300px]">
                <PaginatedTable
                    paginator={rules}
                    rowKey={(r) => r.id}
                    empty={{ title: 'No commission rules' }}
                    columns={[
                        { key: 'zone', header: 'Zone', render: (r) => r.zone },
                        { key: 'class', header: 'Class', render: (r) => label(r.vehicle_class) },
                        { key: 'type', header: 'Type', render: (r) => label(r.type) },
                        { key: 'rate', header: 'Rate', render: (r) => <span className="font-mono">{r.rate_bp === null ? '—' : formatBasisPoints(r.rate_bp)}</span> },
                        { key: 'fixed', header: 'Fixed', render: (r) => <span className="font-mono">{r.fixed_fee ? money(r.fixed_fee) : money(asMoney(0))}</span> },
                        { key: 'minmax', header: 'Min / Max', render: (r) => (r.minimum || r.maximum ? `${r.minimum ? money(r.minimum) : '—'} / ${r.maximum ? money(r.maximum) : '—'}` : '—') },
                        { key: 'surge', header: 'Surge', render: (r) => (r.applies_to_surge ? 'Yes' : 'No') },
                        { key: 'effective', header: 'Effective', render: (r) => formatDate(r.effective_from, timezone) },
                    ]}
                />
                <div className={`${card} h-fit p-5`}>
                    <p className="font-display text-sm font-bold">Commission preview</p>
                    <label htmlFor="preview-fare" className="mt-5 block text-xs font-bold text-slate-500">Trip fare</label>
                    <div className="mt-2 flex items-center rounded-xl border border-slate-200 px-3">
                        <span className="font-mono text-xs text-slate-400">{previewFare.currency}</span>
                        <input id="preview-fare" inputMode="decimal" value={fare} onChange={(e) => setFare(e.target.value)} className="w-full px-2 py-3 font-mono text-sm outline-none" />
                    </div>
                    {previewRule && commission !== null && fareMinor !== null ? (
                        <div className="mt-5 space-y-3 border-t border-slate-100 pt-4 text-sm">
                            <div className="flex justify-between"><span className="text-slate-500">Commission ({previewRule.rate_bp === null ? label(previewRule.type) : formatBasisPoints(previewRule.rate_bp)})</span><b className="font-mono" data-testid="preview-commission">{money(asMoney(commission))}</b></div>
                            <div className="flex justify-between"><span className="text-slate-500">Driver earnings</span><b className="font-mono text-emerald-600" data-testid="preview-earnings">{money(asMoney(fareMinor - commission))}</b></div>
                        </div>
                    ) : (
                        <p className="mt-5 text-xs text-slate-400">{previewRule ? 'Enter a valid fare.' : 'No rule to preview.'}</p>
                    )}
                </div>
            </div>
        </div>
    );
}
