import { Head, Link } from '@inertiajs/react';
import { CheckCircle, Eye, XCircle } from 'lucide-react';
import { useState } from 'react';
import ActionButton from '@/Components/ActionButton';
import Avatar from '@/Components/Avatar';
import EmptyState from '@/Components/EmptyState';
import ReasonModal from '@/Components/ReasonModal';
import StatusBadge from '@/Components/StatusBadge';
import UrlTabs from '@/Components/UrlTabs';
import { daysUntil, formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { label } from '@/lib/labels';
import { withId } from '@/lib/query';
import { useShared } from '@/lib/useShared';
import type { Actions, DriverDetail, DriverDocument } from '@/types';

interface Props {
    driver: DriverDetail;
    tab: string;
    tabs: string[];
    actions: Actions<'approveDocument' | 'rejectDocument' | 'addVehicle' | 'setPrimaryVehicle' | 'addAdjustment' | 'recordCashSettlement' | 'revealBankAccount'>;
}

const tile = { background: 'white', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' } as const;
const tabLabels: Record<string, string> = { 'trip-history': 'Trip History', 'bank-accounts': 'Bank accounts' };

export default function DriverShow({ driver, tab, tabs, actions }: Props) {
    const { money, timezone } = useShared();
    const [rejecting, setRejecting] = useState<DriverDocument | null>(null);
    const earnings = driver.earnings_detail;

    return (
        <div className="mx-auto max-w-4xl">
            <Head title={driver.name} />
            <div className="mb-4"><Link href="/admin/drivers" className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-500">← Drivers</Link></div>
            <div className="overflow-hidden rounded-2xl" style={{ background: '#f0f2f8' }}>
                <div className="flex items-center gap-3 px-6 py-5" style={{ background: 'white', borderBottom: '1px solid #f1f5f9' }}>
                    <Avatar name={driver.name} seed={driver.id} size={44} rounded="rounded-2xl" />
                    <div className="flex-1">
                        <h1 className="text-base font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{driver.name}</h1>
                        <p className="text-xs" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{driver.code}</p>
                    </div>
                    <StatusBadge status={driver.verification_status} />
                </div>
                <div style={{ background: 'white', borderBottom: '1px solid #f1f5f9' }}><UrlTabs tabs={tabs} active={tab} variant="underline" labels={tabLabels} /></div>

                <div className="p-6">
                    {tab === 'profile' && (
                        <div className="grid grid-cols-2 gap-3">
                            {([
                                ['Phone', driver.phone],
                                ['Vehicle', `${driver.vehicle.label} ’${String(driver.vehicle.year).slice(2)}`],
                                ['Total Trips', formatNumber(driver.trips_count)],
                                ['Rating', driver.rating ? `${driver.rating} / 5.0` : '—'],
                                ['Total Earnings', money(driver.earnings)],
                                ['Verification', label(driver.verification_status)],
                            ] as const).map(([title, value]) => (
                                <div key={title} className="rounded-2xl p-4" style={tile}>
                                    <p className="mb-1 text-[10px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>{title}</p>
                                    <p className="text-sm font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{value}</p>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === 'documents' && (
                        <div className="flex flex-col gap-3">
                            {driver.documents.length === 0 && <EmptyState title="No documents uploaded" />}
                            {driver.documents.map((doc) => {
                                const expiringIn = doc.expires_at ? daysUntil(doc.expires_at) : null;
                                return (
                                    <div key={doc.id} className="flex items-center gap-3 rounded-2xl p-4" style={tile}>
                                        <div className="flex h-11 w-11 items-center justify-center rounded-xl text-[10px] font-bold" style={{ background: '#f1f5f9', color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{doc.mime_type?.startsWith('image/') ? 'IMG' : 'PDF'}</div>
                                        <div className="flex-1">
                                            <p className="text-sm font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{label(doc.type)}</p>
                                            <div className="mt-1 flex items-center gap-2">
                                                <StatusBadge status={doc.status} />
                                                {expiringIn !== null && expiringIn <= 30 && <span className="text-[11px] font-semibold text-amber-600">{expiringIn < 0 ? 'Expired' : `Expires in ${expiringIn} days`}</span>}
                                            </div>
                                            {doc.rejection_reason && <p className="mt-1 text-xs text-red-600">{doc.rejection_reason}</p>}
                                        </div>
                                        <div className="flex gap-1.5">
                                            {doc.file_url && (
                                                <a href={doc.file_url} target="_blank" rel="noreferrer" className="flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#eef2ff', color: '#4f46e5', fontFamily: 'var(--font-display)' }}>
                                                    <Eye size={11} /> View
                                                </a>
                                            )}
                                            {doc.status === 'pending' && <><ActionButton url={withId(actions.approveDocument, doc.id)} className="flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#f0fdf4', color: '#16a34a', fontFamily: 'var(--font-display)' }}>
                                                <CheckCircle size={11} /> Approve
                                            </ActionButton>
                                            <button onClick={() => setRejecting(doc)} className="flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#fef2f2', color: '#dc2626', fontFamily: 'var(--font-display)' }}>
                                                <XCircle size={11} /> Reject
                                            </button></>}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {tab === 'vehicles' && (
                        <div className="space-y-3">
                            <ActionButton url={actions.addVehicle} className="w-full rounded-xl border border-dashed border-indigo-300 bg-indigo-50 py-3 text-xs font-bold text-indigo-600">+ Add vehicle</ActionButton>
                            {driver.vehicles.map((vehicle) => (
                                <div key={vehicle.id} className="rounded-2xl bg-white p-4 shadow-sm">
                                    <div className="flex items-start">
                                        <div>
                                            <p className="font-display text-sm font-bold text-slate-900">{vehicle.make} {vehicle.model} · {vehicle.year}</p>
                                            <p className="mt-1 font-mono text-xs text-slate-500">{vehicle.plate_number}</p>
                                        </div>
                                        <div className="ml-auto flex gap-1">
                                            <span className="rounded-full bg-violet-50 px-2 py-1 text-[10px] font-bold text-violet-700">{label(vehicle.vehicle_class)}</span>
                                            <span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">{vehicle.is_primary ? 'Primary' : label(vehicle.status)}</span>
                                        </div>
                                    </div>
                                    <div className="mt-4 flex items-center text-xs text-slate-500">
                                        <span className="mr-2 size-3 rounded-full border border-slate-200" style={{ background: vehicle.color.toLowerCase() }} />
                                        {vehicle.color}
                                        {!vehicle.is_primary && <ActionButton url={withId(actions.setPrimaryVehicle, vehicle.id)} className="ml-auto font-bold text-indigo-600">Set as primary</ActionButton>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === 'earnings' && (
                        <div>
                            <div className={`mb-4 rounded-2xl border p-4 ${earnings.balance.amount < 0 ? 'border-red-100 bg-red-50' : 'border-emerald-100 bg-emerald-50'}`}>
                                <p className={`text-[10px] font-bold uppercase tracking-wider ${earnings.balance.amount < 0 ? 'text-red-500' : 'text-emerald-600'}`}>Current balance</p>
                                <p className={`mt-1 font-mono text-xl font-bold ${earnings.balance.amount < 0 ? 'text-red-700' : 'text-emerald-700'}`}>{money(earnings.balance)}</p>
                                {earnings.balance.amount < 0 && (
                                    <p className="mt-1 text-xs text-red-600">Owes platform {money({ ...earnings.balance, amount: -earnings.balance.amount })}{earnings.debt_limit_exceeded ? ' · Debt limit exceeded' : ''}</p>
                                )}
                            </div>
                            <div className="mb-4 grid grid-cols-3 gap-3">
                                {([['This week', earnings.summary.week], ['This month', earnings.summary.month], ['All time', earnings.summary.all_time]] as const).map(([title, value]) => (
                                    <div key={title} className="rounded-2xl p-4 text-center" style={tile}>
                                        <p className="mb-1 text-[10px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>{title}</p>
                                        <p className="text-lg font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{money(value, { compact: true })}</p>
                                    </div>
                                ))}
                            </div>
                            <div className="overflow-hidden rounded-2xl bg-white">
                                {earnings.ledger.length === 0 && <EmptyState title="No ledger entries" />}
                                {earnings.ledger.map((entry) => (
                                    <div key={entry.id} className="flex items-center border-b border-slate-50 p-3 text-xs">
                                        <span className="rounded-lg bg-slate-100 px-2 py-1 font-bold text-slate-600">{label(entry.type)}</span>
                                        <span className="ml-3 font-mono text-indigo-600">{entry.reference ?? '—'}</span>
                                        <span className={`ml-auto font-mono font-bold ${entry.amount.amount >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{money(entry.amount, { signed: true })}</span>
                                        <span className="ml-3 text-slate-400">{formatDateTime(entry.occurred_at, timezone)}</span>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-4 flex gap-2">
                                <ActionButton url={actions.addAdjustment} className="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-bold text-white">Add adjustment</ActionButton>
                                <ActionButton url={actions.recordCashSettlement} className="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600">Record cash settlement</ActionButton>
                            </div>
                        </div>
                    )}

                    {tab === 'trip-history' && (
                        <div className="overflow-hidden rounded-2xl bg-white">
                            {driver.trip_history.length === 0 && <EmptyState title="No trips yet" />}
                            {driver.trip_history.map((trip) => (
                                <div key={trip.id} className="border-b border-slate-50 p-4">
                                    <div className="flex justify-between">
                                        <Link href={`/admin/trips/${trip.id}`} className="font-mono text-xs font-bold text-indigo-600">{trip.code}</Link>
                                        <span className="font-mono text-xs font-bold text-slate-900">{money(trip.fare)}</span>
                                    </div>
                                    <p className="mt-2 text-xs text-slate-600">{trip.route}</p>
                                    <div className="mt-2 flex text-[10px] text-slate-400">
                                        <span>{formatDateTime(trip.occurred_at, timezone)}</span>
                                        <span className="ml-auto">{label(trip.status)} · ★ {trip.rating ?? '—'}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === 'bank-accounts' && (
                        driver.bank_accounts.length === 0 ? <EmptyState title="No bank accounts" /> : driver.bank_accounts.map((account) => (
                            <div key={account.id} className="mb-3 rounded-2xl bg-white p-5">
                                <div className="flex items-center">
                                    <div>
                                        <p className="font-display text-sm font-bold text-slate-900">{account.bank_name}</p>
                                        <p className="mt-1 font-mono text-sm text-slate-500">•••• {account.account_last4}</p>
                                    </div>
                                    {account.is_default && <span className="ml-auto rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">Default</span>}
                                </div>
                                <ActionButton url={withId(actions.revealBankAccount, account.id)} className="mt-5 w-full rounded-xl border border-slate-200 py-2 text-xs font-bold text-indigo-600">Reveal account · This action is logged</ActionButton>
                            </div>
                        ))
                    )}
                </div>
            </div>
            <ReasonModal
                open={rejecting !== null}
                onClose={() => setRejecting(null)}
                title={`Reject ${label(rejecting?.type)}`}
                description={<>What's wrong with this document? The driver sees this reason{rejecting?.expires_at ? ` (expires ${formatDate(rejecting.expires_at, timezone)})` : ''}.</>}
                confirmLabel="Confirm"
                placeholder="What's wrong with this document?"
                url={withId(actions.rejectDocument, rejecting?.id)}
            />
        </div>
    );
}
