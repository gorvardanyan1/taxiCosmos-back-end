import { Head } from '@inertiajs/react';
import { Edit2, History, Plus } from 'lucide-react';
import { useState } from 'react';
import { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import FilterBar from '@/Components/FilterBar';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import { commissionFor } from '@/lib/commission';
import { formatBasisPoints, formatDate, formatDateTime, parseMoneyInput } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery } from '@/lib/query';
import { card, primary } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, CommissionHistory, CommissionRule, CommissionType, Filters, Money, Paginated, VehicleClass } from '@/types';
import CommissionRuleForm from './CommissionRuleForm';
import { t } from '@/lib/i18n';

interface Props {
    rules: Paginated<CommissionRule>;
    filters: Filters;
    sort?: string | null;
    /** The rule the preview calculates with (?preview=<id>); null when no rule is in force. */
    previewRule: CommissionRule | null;
    previewRules: { id: number; zone: string; vehicle_class: VehicleClass | null }[];
    previewFare: Money;
    /** Every version of one rule (?history=<id>). */
    history: CommissionHistory | null;
    zones: { id: number; name: string; currency: string }[];
    vehicleClasses: VehicleClass[];
    types: CommissionType[];
    actions: Actions<'createVersion'>;
}

const className = (value: VehicleClass | null) => (value === null ? t('Every class') : label(value));

export default function CommissionRulesIndex({ rules, filters, sort = null, previewRule, previewRules, previewFare, history, zones, vehicleClasses, types, actions }: Props) {
    const { money, currencies, timezone } = useShared();
    const decimals = currencies.find((c) => c.code === previewFare.currency)?.decimals ?? 2;
    const [fare, setFare] = useState(String(previewFare.amount / 10 ** decimals));
    const fareMinor = parseMoneyInput(fare, decimals);
    const commission = previewRule && fareMinor !== null ? commissionFor(fareMinor, previewRule) : null;
    const asMoney = (amount: number): Money => ({ amount, currency: previewFare.currency });
    /** The form: a new version of this rule, or (null rule) any zone and class. */
    const [formFor, setFormFor] = useState<{ rule: CommissionRule | null } | null>(null);

    return (
        <div>
            <Head title={t('Commission')} />
            <PageHeader title={t('Commission')} description={t('Versioned platform commission rules by zone')} actions={<button onClick={() => setFormFor({ rule: null })} disabled={actions.createVersion === null || zones.length === 0} title={actions.createVersion === null ? t(UNAVAILABLE_HINT) : zones.length === 0 ? t('Add a zone first.') : undefined} className={`${primary} disabled:opacity-40`}><Plus size={14} />{t('New version')}</button>} />
            <FilterBar filters={filters} selects={[{ key: 'vehicle_class', allLabel: t('All classes'), options: [{ value: 'all', label: t('Every class') }, ...vehicleClasses.map((c) => ({ value: c, label: label(c) }))] }]} searchPlaceholder={t('Search zones…')} />
            <div className="grid gap-5 xl:grid-cols-[1fr_300px]">
                <PaginatedTable
                    paginator={rules}
                    sort={sort}
                    rowKey={(r) => r.id}
                    empty={{ title: t('No commission rules') }}
                    columns={[
                        { key: 'zone', header: t('Zone'), sortKey: 'zone', render: (r) => r.zone },
                        { key: 'class', header: t('Class'), render: (r) => className(r.vehicle_class) },
                        { key: 'type', header: t('Type'), render: (r) => label(r.type) },
                        { key: 'rate', header: t('Rate'), render: (r) => <span className="font-mono">{r.rate_bp === null ? '—' : formatBasisPoints(r.rate_bp)}</span> },
                        { key: 'fixed', header: t('Fixed'), render: (r) => <span className="font-mono">{r.fixed_fee ? money(r.fixed_fee) : money(asMoney(0))}</span> },
                        { key: 'minmax', header: t('Min / Max'), render: (r) => (r.minimum || r.maximum ? `${r.minimum ? money(r.minimum) : '—'} / ${r.maximum ? money(r.maximum) : '—'}` : '—') },
                        { key: 'surge', header: t('Surge'), render: (r) => (r.applies_to_surge ? t('Yes') : t('No')) },
                        { key: 'effective', header: t('Effective'), sortKey: 'effective_from', render: (r) => formatDate(r.effective_from, timezone) },
                        {
                            key: 'actions', header: '', render: (r) => (
                                <div className="flex gap-1">
                                    <button aria-label={t('New version of the :zone rule', { zone: r.zone })} onClick={() => setFormFor({ rule: r })} disabled={actions.createVersion === null} title={t('New version of the :zone rule', { zone: r.zone })} className="rounded-lg p-1.5 hover:bg-slate-100 disabled:opacity-40" style={{ color: '#94a3b8' }}><Edit2 size={13} /></button>
                                    <button aria-label={t('History of the :zone rule', { zone: r.zone })} onClick={() => visitQuery({ history: r.id })} className="rounded-lg p-1.5 hover:bg-slate-100" style={{ color: '#94a3b8' }}><History size={13} /></button>
                                </div>
                            ),
                        },
                    ]}
                />
                <div className={`${card} h-fit p-5`}>
                    <p className="font-display text-sm font-bold">{t('Commission preview')}</p>
                    {previewRules.length > 1 && (
                        <select aria-label={t('Rule to preview')} value={previewRule?.id ?? ''} onChange={(e) => visitQuery({ preview: e.target.value })} className="mt-3 w-full rounded-xl border border-slate-200 px-3 py-2 text-xs">
                            {previewRules.map((r) => <option key={r.id} value={r.id}>{r.zone} · {className(r.vehicle_class)}</option>)}
                        </select>
                    )}
                    <label htmlFor="preview-fare" className="mt-5 block text-xs font-bold text-slate-500">{t('Trip fare')}</label>
                    <div className="mt-2 flex items-center rounded-xl border border-slate-200 px-3">
                        <span className="font-mono text-xs text-slate-400">{previewFare.currency}</span>
                        <input id="preview-fare" inputMode="decimal" value={fare} onChange={(e) => setFare(e.target.value)} className="w-full px-2 py-3 font-mono text-sm outline-none" />
                    </div>
                    {previewRule && commission !== null && fareMinor !== null ? (
                        <div className="mt-5 space-y-3 border-t border-slate-100 pt-4 text-sm">
                            <div className="flex justify-between"><span className="text-slate-500">{t('Commission (:rate)', { rate: previewRule.rate_bp === null ? label(previewRule.type) : formatBasisPoints(previewRule.rate_bp) })}</span><b className="font-mono" data-testid="preview-commission">{money(asMoney(commission))}</b></div>
                            <div className="flex justify-between"><span className="text-slate-500">{t('Driver earnings')}</span><b className="font-mono text-emerald-600" data-testid="preview-earnings">{money(asMoney(fareMinor - commission))}</b></div>
                        </div>
                    ) : (
                        <p className="mt-5 text-xs text-slate-400">{previewRule ? t('Enter a valid fare.') : t('No rule to preview.')}</p>
                    )}
                </div>
            </div>
            <CommissionRuleForm open={formFor !== null} onClose={() => setFormFor(null)} rule={formFor?.rule ?? null} zones={zones} vehicleClasses={vehicleClasses} types={types} url={actions.createVersion} />
            <Modal open={history !== null} wide onClose={() => visitQuery({ history: null })} title={t('History of the :zone rule', { zone: history?.zone ?? '' })}>
                <p className="mb-3 text-xs" style={{ color: '#64748b' }}>{t('Every version of this commission rule (:class). Past versions never change.', { class: className(history?.vehicle_class ?? null) })}</p>
                <div className="max-h-[55vh] overflow-auto">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr style={{ borderBottom: '1px solid #f1f5f9', color: '#94a3b8' }}>
                                {[t('Effective from'), t('Effective to'), t('Type'), t('Rate'), t('Fixed'), t('Min / Max'), t('Surge'), t('Saved by')].map((h) => <th key={h} className="px-2 py-2 font-bold uppercase tracking-wider">{h}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {history?.versions.map((v) => (
                                <tr key={v.id} style={{ borderBottom: '1px solid #f8fafc' }}>
                                    <td className="px-2 py-2.5">{formatDateTime(v.effective_from, timezone)}</td>
                                    <td className="px-2 py-2.5">{v.effective_to === null ? t('Current') : formatDateTime(v.effective_to, timezone)}</td>
                                    <td className="px-2 py-2.5">{label(v.type)}</td>
                                    <td className="px-2 py-2.5 font-mono">{v.rate_bp === null ? '—' : formatBasisPoints(v.rate_bp)}</td>
                                    <td className="px-2 py-2.5 font-mono">{v.fixed_fee ? money(v.fixed_fee) : '—'}</td>
                                    <td className="px-2 py-2.5 font-mono">{v.minimum || v.maximum ? `${v.minimum ? money(v.minimum) : '—'} / ${v.maximum ? money(v.maximum) : '—'}` : '—'}</td>
                                    <td className="px-2 py-2.5">{v.applies_to_surge ? t('Yes') : t('No')}</td>
                                    <td className="px-2 py-2.5">{v.created_by ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Modal>
        </div>
    );
}
