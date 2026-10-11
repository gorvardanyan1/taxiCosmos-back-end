import { Head, useForm } from '@inertiajs/react';
import { Clock, CreditCard, DollarSign, Plus, RefreshCcw, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import FilterBar from '@/Components/FilterBar';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate, formatNumber, newIdempotencyKey, parseMoneyInput } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery, withId } from '@/lib/query';
import { field } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, Filters, Money, Paginated, TransactionRow } from '@/types';
import { t } from '@/lib/i18n';

interface Props {
    summary: { volume_today: Money; transactions_count: number; pending_payouts: Money; refunds_today: Money };
    transactions: Paginated<TransactionRow>;
    filters: Filters;
    selected: TransactionRow | null;
    gateways: string[];
    actions: Actions<'recordManual' | 'refund' | 'confirmManual' | 'rejectManual'>;
}

const gatewayStyle: Record<string, { bg: string; color: string }> = {
    stripe: { bg: '#f0f0ff', color: '#5851d8' },
    authorize_net: { bg: '#fff7ed', color: '#c2410c' },
    manual: { bg: '#f8fafc', color: '#64748b' },
    wallet: { bg: '#f0fdfa', color: '#0d9488' },
};

const primaryButton = { background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', color: 'white', fontFamily: 'var(--font-display)' } as const;

export default function TransactionsIndex({ summary, transactions, filters, selected, gateways, actions }: Props) {
    const { money, currencies, timezone } = useShared();
    const [recording, setRecording] = useState(false);
    const [refunding, setRefunding] = useState<TransactionRow | null>(null);

    const manual = useForm({ amount: '', currency: currencies[0]?.code ?? 'AMD', reference: '', method: 'cash', note: '', idempotency_key: '' });
    const refund = useForm({ amount: '', reason: '', reverse_driver_earnings: false, idempotency_key: '' });

    const decimalsOf = (code: string) => currencies.find((c) => c.code === code)?.decimals ?? 2;
    const manualMinor = parseMoneyInput(manual.data.amount, decimalsOf(manual.data.currency));
    const refundMinor = refunding ? parseMoneyInput(refund.data.amount, decimalsOf(refunding.amount.currency)) : null;
    const refundTooLarge = refunding !== null && refundMinor !== null && refundMinor > refunding.amount.amount;
    const refundUrl = withId(actions.refund, refunding?.id);

    const openManual = () => { manual.reset(); manual.setData('idempotency_key', newIdempotencyKey()); setRecording(true); };
    const openRefund = (txn: TransactionRow) => { refund.reset(); refund.setData('idempotency_key', newIdempotencyKey()); setRefunding(txn); };

    const submitManual = () => {
        if (!actions.recordManual || manualMinor === null) return;
        manual.transform((data) => ({ ...data, amount: manualMinor }));
        manual.post(actions.recordManual, { onSuccess: () => setRecording(false) });
    };
    const submitRefund = () => {
        if (!refundUrl || refundMinor === null || refundTooLarge) return;
        refund.transform((data) => ({ ...data, amount: refundMinor }));
        refund.post(refundUrl, { onSuccess: () => setRefunding(null) });
    };

    const cards = [
        { label: t('Today\'s Volume'), value: money(summary.volume_today, { compact: true }), icon: <TrendingUp size={16} />, gradient: 'linear-gradient(135deg, #6366f1, #8b5cf6)', glow: 'rgba(99,102,241,0.25)' },
        { label: t('Total Transactions'), value: formatNumber(summary.transactions_count), icon: <CreditCard size={16} />, gradient: 'linear-gradient(135deg, #0891b2, #0284c7)', glow: 'rgba(8,145,178,0.25)' },
        { label: t('Pending Payouts'), value: money(summary.pending_payouts, { compact: true }), icon: <Clock size={16} />, gradient: 'linear-gradient(135deg, #d97706, #b45309)', glow: 'rgba(217,119,6,0.25)' },
        { label: t('Refunds Today'), value: money(summary.refunds_today), icon: <DollarSign size={16} />, gradient: 'linear-gradient(135deg, #e11d48, #be123c)', glow: 'rgba(225,29,72,0.25)' },
    ];

    return (
        <div>
            <Head title={t('Payments & Transactions')} />
            <PageHeader
                title={t('Payments & Transactions')}
                description={t('Financial activity across all gateways')}
                actions={<button onClick={openManual} className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold hover:opacity-90" style={primaryButton}><Plus size={14} /> {t('Record Manual Payment')}</button>}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {cards.map((c) => (
                    <div key={c.label} className="relative overflow-hidden rounded-2xl p-4" style={{ background: c.gradient, boxShadow: `0 6px 20px ${c.glow}` }}>
                        <div className="absolute -right-3 -top-3 h-16 w-16 rounded-full" style={{ background: 'rgba(255,255,255,0.1)' }} />
                        <div className="mb-3 w-fit rounded-lg p-1.5" style={{ background: 'rgba(255,255,255,0.2)' }}><span className="text-white">{c.icon}</span></div>
                        <p className="text-xl font-bold text-white" style={{ fontFamily: 'var(--font-display)' }}>{c.value}</p>
                        <p className="mt-0.5 text-xs" style={{ color: 'rgba(255,255,255,0.65)' }}>{c.label}</p>
                    </div>
                ))}
            </div>

            <FilterBar
                filters={filters}
                searchPlaceholder={t('Transaction or trip ID…')}
                selects={[
                    { key: 'gateway', allLabel: t('All Gateways'), options: gateways.map((g) => ({ value: g, label: label(g) })) },
                    { key: 'status', allLabel: t('All Statuses'), options: ['completed', 'pending', 'failed'].map((s) => ({ value: s, label: label(s) })) },
                ]}
            />

            <PaginatedTable
                paginator={transactions}
                rowKey={(txn) => txn.id}
                onRowClick={(txn) => visitQuery({ txn: txn.id })}
                empty={{ title: t('No transactions found') }}
                columns={[
                    { key: 'code', header: t('Transaction'), render: (tx) => <span className="text-xs font-bold" style={{ color: '#6366f1', fontFamily: 'var(--font-mono)' }}>{tx.code}</span> },
                    { key: 'trip', header: t('Trip'), render: (tx) => <span className="text-xs" style={{ color: '#64748b', fontFamily: 'var(--font-mono)' }}>{tx.trip_code}</span> },
                    { key: 'gateway', header: t('Gateway'), render: (tx) => <span className="rounded-lg px-2.5 py-1 text-xs font-bold" style={{ background: (gatewayStyle[tx.gateway] ?? gatewayStyle.manual).bg, color: (gatewayStyle[tx.gateway] ?? gatewayStyle.manual).color, fontFamily: 'var(--font-display)' }}>{label(tx.gateway)}</span> },
                    { key: 'type', header: t('Type'), render: (tx) => <span className="text-xs font-medium" style={{ color: '#64748b' }}>{label(tx.type)}</span> },
                    { key: 'amount', header: t('Amount'), align: 'right', render: (tx) => <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{money(tx.amount)}</span> },
                    { key: 'status', header: t('Status'), render: (tx) => <StatusBadge status={tx.status} /> },
                    { key: 'date', header: t('Date'), render: (tx) => <span className="text-xs" style={{ color: '#94a3b8' }}>{formatDate(tx.created_at, timezone)}</span> },
                    {
                        key: 'actions', header: '', render: (tx) => (
                            <div onClick={(e) => e.stopPropagation()}>
                                {tx.status === 'completed' && (tx.type === 'charge' || tx.type === 'capture') && (
                                    <button onClick={() => openRefund(tx)} className="flex items-center gap-1 rounded-xl px-3 py-1.5 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#fffbeb', color: '#b45309', fontFamily: 'var(--font-display)' }}><RefreshCcw size={11} /> {t('Refund')}</button>
                                )}
                                {tx.status === 'pending' && (
                                    <div className="flex gap-1.5">
                                        <ActionButton url={withId(actions.confirmManual, tx.id)} className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">{t('Confirm')}</ActionButton>
                                        <ActionButton url={withId(actions.rejectManual, tx.id)} className="rounded-lg bg-red-50 px-2 py-1 text-[10px] font-bold text-red-600">{t('Reject')}</ActionButton>
                                    </div>
                                )}
                            </div>
                        ),
                    },
                ]}
            />

            <Modal
                open={recording}
                onClose={() => setRecording(false)}
                title={t('Record Manual Payment')}
                footer={
                    <>
                        <button onClick={() => setRecording(false)} className="rounded-xl px-4 py-2 text-sm font-semibold hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>{t('Cancel')}</button>
                        <button onClick={submitManual} disabled={actions.recordManual === null || manualMinor === null || !manual.data.note.trim() || manual.processing} title={actions.recordManual === null ? t(UNAVAILABLE_HINT) : undefined} className="rounded-xl px-4 py-2 text-sm font-bold hover:opacity-90 disabled:opacity-40" style={primaryButton}>{t('Record')}</button>
                    </>
                }
            >
                <div className="flex flex-col gap-3">
                    <div className="flex gap-2">
                        <div className="flex-1">
                            <FormField label={t('Amount')} required error={manual.errors.amount ?? (manual.data.amount && manualMinor === null ? t('Enter a valid amount.') : undefined)} htmlFor="manual-amount">
                                <input id="manual-amount" inputMode="decimal" value={manual.data.amount} onChange={(e) => manual.setData('amount', e.target.value)} placeholder="0" className={`${field} font-mono`} />
                            </FormField>
                        </div>
                        <div className="w-24">
                            <FormField label={t('Currency')} error={manual.errors.currency} htmlFor="manual-currency">
                                <select id="manual-currency" value={manual.data.currency} onChange={(e) => manual.setData('currency', e.target.value)} className={field}>
                                    {currencies.map((c) => <option key={c.code}>{c.code}</option>)}
                                </select>
                            </FormField>
                        </div>
                    </div>
                    <FormField label={t('Trip or rider')} required error={manual.errors.reference} htmlFor="manual-reference">
                        <input id="manual-reference" value={manual.data.reference} onChange={(e) => manual.setData('reference', e.target.value)} className={field} placeholder={t('Search trip code or rider…')} />
                    </FormField>
                    <FormField label={t('Method')} error={manual.errors.method} htmlFor="manual-method">
                        <select id="manual-method" value={manual.data.method} onChange={(e) => manual.setData('method', e.target.value)} className={field}>
                            <option value="cash">{t('Cash')}</option>
                            <option value="bank_transfer">{t('Bank transfer')}</option>
                        </select>
                    </FormField>
                    <FormField label={t('Note / Reason')} required error={manual.errors.note} htmlFor="manual-note">
                        <textarea id="manual-note" value={manual.data.note} onChange={(e) => manual.setData('note', e.target.value)} rows={3} className={`${field} resize-none`} placeholder={t('Reason for manual payment…')} />
                    </FormField>
                    <div className="rounded-xl border-2 border-dashed border-slate-200 p-5 text-center text-xs text-slate-400">{t('Receipt upload is added with manual payments')}</div>
                    <p className="rounded-xl bg-blue-50 p-3 text-xs font-semibold text-blue-700">{t('Requires confirmation by Finance. The user who records it cannot confirm it.')}</p>
                </div>
            </Modal>

            <Modal
                open={refunding !== null}
                onClose={() => setRefunding(null)}
                title={t('Refund :code', { code: refunding?.code ?? '' })}
                danger
                footer={
                    <>
                        <button onClick={() => setRefunding(null)} className="rounded-xl px-4 py-2 text-sm font-semibold hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>{t('Cancel')}</button>
                        <button onClick={submitRefund} disabled={refundUrl === null || refundMinor === null || refundMinor === 0 || refundTooLarge || !refund.data.reason.trim() || refund.processing} title={refundUrl === null ? t(UNAVAILABLE_HINT) : undefined} className="rounded-xl px-4 py-2 text-sm font-bold hover:opacity-90 disabled:opacity-40" style={{ background: 'linear-gradient(135deg, #f59e0b, #d97706)', color: 'white', fontFamily: 'var(--font-display)' }}>{t('Issue Refund')}</button>
                    </>
                }
            >
                {refunding && (
                    <div className="flex flex-col gap-3">
                        <div className="grid grid-cols-3 gap-2 text-center text-xs">
                            <div className="rounded-xl bg-slate-50 p-2"><span className="block text-slate-400">{t('Original')}</span><b className="font-mono">{money(refunding.amount)}</b></div>
                            <div className="rounded-xl bg-slate-50 p-2"><span className="block text-slate-400">{t('Refunded')}</span><b className="font-mono">{money({ ...refunding.amount, amount: 0 })}</b></div>
                            <div className="rounded-xl bg-emerald-50 p-2 text-emerald-700"><span className="block">{t('Remaining')}</span><b className="font-mono">{money(refunding.amount)}</b></div>
                        </div>
                        <FormField label={t('Refund Amount (max :amount)', { amount: money(refunding.amount) })} required error={refund.errors.amount ?? (refundTooLarge ? t('Refund cannot exceed the remaining amount.') : refund.data.amount && refundMinor === null ? t('Enter a valid amount.') : undefined)} htmlFor="refund-amount">
                            <input id="refund-amount" inputMode="decimal" value={refund.data.amount} onChange={(e) => refund.setData('amount', e.target.value)} className={`${field} font-mono`} />
                        </FormField>
                        <FormField label={t('Reason')} required error={refund.errors.reason} htmlFor="refund-reason">
                            <textarea id="refund-reason" value={refund.data.reason} onChange={(e) => refund.setData('reason', e.target.value)} rows={2} className={`${field} resize-none`} placeholder={t('Refund reason…')} />
                        </FormField>
                        <label className="flex items-center gap-2 text-xs font-semibold text-slate-600"><input type="checkbox" checked={refund.data.reverse_driver_earnings} onChange={(e) => refund.setData('reverse_driver_earnings', e.target.checked)} className="accent-indigo-600" />{t('Also reverse driver earnings')}</label>
                    </div>
                )}
            </Modal>

            {selected && (
                <div className="fixed inset-0 z-50 flex justify-end">
                    <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" onClick={() => visitQuery({ txn: null })} />
                    <aside className="relative z-10 h-full w-full max-w-lg overflow-y-auto bg-[#f0f2f8]" aria-label={t('Transaction :code', { code: selected.code })}>
                        <div className="flex items-center border-b border-slate-100 bg-white p-6">
                            <div><p className="font-mono text-sm font-bold text-indigo-600">{selected.code}</p><p className="mt-1 text-xs text-slate-400">{formatDate(selected.created_at, timezone)}</p></div>
                            <button onClick={() => visitQuery({ txn: null })} aria-label={t('Close')} className="ml-auto text-slate-400">✕</button>
                        </div>
                        <div className="space-y-4 p-5">
                            <div className="rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 p-5 text-white">
                                <p className="text-xs text-white/60">{t('Transaction amount')}</p>
                                <p className="mt-2 font-mono text-2xl font-bold">{money(selected.amount)}</p>
                                <div className="mt-4"><StatusBadge status={selected.status} /></div>
                            </div>
                            <div className="rounded-2xl bg-white p-5">
                                {([
                                    [t('Gateway'), label(selected.gateway)], [t('Gateway reference'), selected.gateway_reference ?? '—'], [t('Type'), label(selected.type)],
                                    [t('Related trip'), selected.trip_code], [t('Rider'), selected.rider], [t('Payment method'), selected.payment_method],
                                    [t('Parent transaction'), selected.parent_code ?? '—'], [t('Failure reason'), selected.failure_reason ?? '—'],
                                ] as const).map(([title, value]) => (
                                    <div key={title} className="flex border-b border-slate-50 py-3 text-sm"><span className="text-slate-400">{title}</span><span className="ml-auto font-mono font-semibold text-slate-700">{value}</span></div>
                                ))}
                            </div>
                            <div className="rounded-2xl bg-white p-5">
                                <p className="text-xs font-bold uppercase text-slate-400">{t('Metadata')}</p>
                                <pre className="mt-3 rounded-xl bg-slate-950 p-4 font-mono text-xs text-slate-300">{JSON.stringify(selected.metadata, null, 2)}</pre>
                            </div>
                        </div>
                    </aside>
                </div>
            )}
        </div>
    );
}
