import { Head, Link } from '@inertiajs/react';
import { CheckCircle, Edit2, Eye, XCircle } from 'lucide-react';
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
import EditDriver from './EditDriver';
import RevealAccountNumber from './RevealAccountNumber';
import VehicleForm from './VehicleForm';
import { useShared } from '@/lib/useShared';
import type { Actions, DriverDetail, DriverDocument, Vehicle, VehicleClass } from '@/types';
import { t, tc, tk, tn } from '@/lib/i18n';

interface Props {
    driver: DriverDetail;
    tab: string;
    tabs: string[];
    zones: { id: number; name: string }[];
    vehicleClasses: VehicleClass[];
    actions: Actions<'approveDocument' | 'rejectDocument' | 'edit' | 'suspend' | 'reactivate' | 'addVehicle' | 'updateVehicle' | 'setPrimaryVehicle' | 'addAdjustment' | 'recordCashSettlement' | 'revealBankAccount'>;
}

const tile = { background: 'white', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' } as const;
const tabLabels: Record<string, string> = { 'trip-history': tk('Trip History'), 'bank-accounts': tk('Bank accounts') };

export default function DriverShow({ driver, tab, tabs, zones, vehicleClasses, actions }: Props) {
    const { money, timezone } = useShared();
    const [rejecting, setRejecting] = useState<DriverDocument | null>(null);
    const [editing, setEditing] = useState(false);
    const [acting, setActing] = useState(false);
    /** The vehicle form: a vehicle being edited, or (null) a new one. */
    const [vehicleForm, setVehicleForm] = useState<{ vehicle: Vehicle | null } | null>(null);
    const earnings = driver.earnings_detail;
    const suspended = driver.status === 'suspended';
    // Only active drivers can be suspended and only suspended ones reactivated.
    const canToggle = (driver.status === 'active' || suspended) && (suspended ? actions.reactivate : actions.suspend) !== null;

    return (
        <div className="mx-auto max-w-4xl">
            <Head title={driver.name} />
            <div className="mb-4"><Link href="/admin/drivers" className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-500">{t('← Drivers')}</Link></div>
            <div className="overflow-hidden rounded-2xl" style={{ background: '#f0f2f8' }}>
                <div className="flex items-center gap-3 px-6 py-5" style={{ background: 'white', borderBottom: '1px solid #f1f5f9' }}>
                    <Avatar name={driver.name} seed={driver.id} size={44} rounded="rounded-2xl" />
                    <div className="flex-1">
                        <h1 className="text-base font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{driver.name}</h1>
                        <p className="text-xs" style={{ color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{driver.code}</p>
                    </div>
                    <StatusBadge status={driver.verification_status} />
                    {suspended && <StatusBadge status="suspended" />}
                    <button onClick={() => setEditing(true)} disabled={actions.edit === null} className="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 disabled:opacity-40">{t('Edit')}</button>
                    {canToggle && (
                        <button onClick={() => setActing(true)} className={`rounded-xl px-3 py-2 text-xs font-bold ${suspended ? 'bg-indigo-50 text-indigo-600' : 'bg-red-50 text-red-600'}`}>{suspended ? t('Reactivate') : t('Suspend')}</button>
                    )}
                </div>
                {suspended && (
                    <div className="border-b border-red-200 bg-red-50 px-6 py-3 text-sm font-semibold text-red-700">
                        {t('Suspended')}{driver.suspension_reason ? `: ${driver.suspension_reason}` : ''}
                    </div>
                )}
                <div style={{ background: 'white', borderBottom: '1px solid #f1f5f9' }}><UrlTabs tabs={tabs} active={tab} variant="underline" labels={tabLabels} /></div>

                <div className="p-6">
                    {tab === 'profile' && (
                        <div className="grid grid-cols-2 gap-3">
                            {([
                                [t('Phone'), driver.phone],
                                [t('Vehicle'), driver.vehicle ? `${driver.vehicle.label} ’${String(driver.vehicle.year).slice(2)}` : '—'],
                                [t('Total Trips'), formatNumber(driver.trips_count)],
                                [t('Rating'), driver.rating ? `${driver.rating} / 5.0` : '—'],
                                [t('Total Earnings'), money(driver.earnings)],
                                [t('Verification'), label(driver.verification_status)],
                                [t('Availability'), label(driver.availability)],
                                [t('License'), driver.license_last4 ? `•••• ${driver.license_last4}${driver.raw.license_expiry ? ` · ${formatDate(driver.raw.license_expiry, timezone)}` : ''}` : '—'],
                                [t('Home zone'), driver.home_zone ?? '—'],
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
                            {driver.documents.length === 0 && <EmptyState title={t('No documents uploaded')} />}
                            {driver.documents.map((doc) => {
                                const expiringIn = doc.expires_at ? daysUntil(doc.expires_at) : null;
                                return (
                                    <div key={doc.id} className="flex items-center gap-3 rounded-2xl p-4" style={tile}>
                                        <div className="flex h-11 w-11 items-center justify-center rounded-xl text-[10px] font-bold" style={{ background: '#f1f5f9', color: '#94a3b8', fontFamily: 'var(--font-mono)' }}>{doc.mime_type?.startsWith('image/') ? 'IMG' : 'PDF'}</div>
                                        <div className="flex-1">
                                            <p className="text-sm font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{label(doc.type)}</p>
                                            <div className="mt-1 flex items-center gap-2">
                                                <StatusBadge status={doc.status} />
                                                {expiringIn !== null && expiringIn <= 30 && <span className="text-[11px] font-semibold text-amber-600">{expiringIn < 0 ? t('Expired') : tc('Expires in :count day|Expires in :count days', expiringIn)}</span>}
                                            </div>
                                            {doc.rejection_reason && <p className="mt-1 text-xs text-red-600">{doc.rejection_reason}</p>}
                                        </div>
                                        <div className="flex gap-1.5">
                                            {doc.file_url && (
                                                <a href={doc.file_url} target="_blank" rel="noreferrer" className="flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#eef2ff', color: '#4f46e5', fontFamily: 'var(--font-display)' }}>
                                                    <Eye size={11} /> {t('View')}
                                                </a>
                                            )}
                                            {doc.status === 'pending' && <><ActionButton url={withId(actions.approveDocument, doc.id)} className="flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#f0fdf4', color: '#16a34a', fontFamily: 'var(--font-display)' }}>
                                                <CheckCircle size={11} /> {t('Approve')}
                                            </ActionButton>
                                            <button onClick={() => setRejecting(doc)} className="flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#fef2f2', color: '#dc2626', fontFamily: 'var(--font-display)' }}>
                                                <XCircle size={11} /> {t('Reject')}
                                            </button></>}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {tab === 'vehicles' && (
                        <div className="space-y-3">
                            <button onClick={() => setVehicleForm({ vehicle: null })} disabled={actions.addVehicle === null} className="w-full rounded-xl border border-dashed border-indigo-300 bg-indigo-50 py-3 text-xs font-bold text-indigo-600 disabled:cursor-not-allowed disabled:opacity-40">{t('+ Add vehicle')}</button>
                            {driver.vehicles.length === 0 && <EmptyState title={t('No vehicles yet')} />}
                            {driver.vehicles.map((vehicle) => (
                                <div key={vehicle.id} className="rounded-2xl bg-white p-4 shadow-sm">
                                    <div className="flex items-start">
                                        <div>
                                            <p className="font-display text-sm font-bold text-slate-900">{vehicle.make} {vehicle.model} · {vehicle.year}</p>
                                            <p className="mt-1 font-mono text-xs text-slate-500">{vehicle.plate_number}</p>
                                        </div>
                                        <div className="ml-auto flex items-start gap-1">
                                            <button aria-label={t('Edit :vehicle', { vehicle: `${vehicle.make} ${vehicle.model}` })} onClick={() => setVehicleForm({ vehicle })} disabled={actions.updateVehicle === null} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-50 disabled:opacity-40"><Edit2 size={12} /></button>
                                            <span className="rounded-full bg-violet-50 px-2 py-1 text-[10px] font-bold text-violet-700">{label(vehicle.vehicle_class)}</span>
                                            <span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">{vehicle.is_primary ? t('Primary') : label(vehicle.status)}</span>
                                        </div>
                                    </div>
                                    <div className="mt-4 flex items-center text-xs text-slate-500">
                                        <span className="mr-2 size-3 rounded-full border border-slate-200" style={{ background: vehicle.color.toLowerCase() }} />
                                        {vehicle.color}
                                        {!vehicle.is_primary && vehicle.status === 'active' && <ActionButton url={withId(actions.setPrimaryVehicle, vehicle.id)} className="ml-auto font-bold text-indigo-600">{t('Set as primary')}</ActionButton>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === 'earnings' && (
                        <div>
                            <div className={`mb-4 rounded-2xl border p-4 ${earnings.balance.amount < 0 ? 'border-red-100 bg-red-50' : 'border-emerald-100 bg-emerald-50'}`}>
                                <p className={`text-[10px] font-bold uppercase tracking-wider ${earnings.balance.amount < 0 ? 'text-red-500' : 'text-emerald-600'}`}>{t('Current balance')}</p>
                                <p className={`mt-1 font-mono text-xl font-bold ${earnings.balance.amount < 0 ? 'text-red-700' : 'text-emerald-700'}`}>{money(earnings.balance)}</p>
                                {earnings.balance.amount < 0 && (
                                    <p className="mt-1 text-xs text-red-600">{t('Owes platform :amount', { amount: money({ ...earnings.balance, amount: -earnings.balance.amount }) })}{earnings.debt_limit_exceeded ? ` · ${t('Debt limit exceeded')}` : ''}</p>
                                )}
                            </div>
                            {earnings.ledger_pending && <p className="mb-4 text-xs text-slate-400">{t('Showing earnings from completed trips. Payouts, cash settlements and adjustments appear here once the earnings ledger is available.')}</p>}
                            <div className="mb-4 grid grid-cols-3 gap-3">
                                {([[t('This week'), earnings.summary.week], [t('This month'), earnings.summary.month], [t('All time'), earnings.summary.all_time]] as const).map(([title, value]) => (
                                    <div key={title} className="rounded-2xl p-4 text-center" style={tile}>
                                        <p className="mb-1 text-[10px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>{title}</p>
                                        <p className="text-lg font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{money(value, { compact: true })}</p>
                                    </div>
                                ))}
                            </div>
                            <div className="overflow-hidden rounded-2xl bg-white">
                                {earnings.ledger.length === 0 && <EmptyState title={t('No ledger entries')} />}
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
                                <ActionButton url={actions.addAdjustment} className="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-bold text-white">{t('Add adjustment')}</ActionButton>
                                <ActionButton url={actions.recordCashSettlement} className="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600">{t('Record cash settlement')}</ActionButton>
                            </div>
                        </div>
                    )}

                    {tab === 'trip-history' && (
                        <div className="overflow-hidden rounded-2xl bg-white">
                            {driver.trip_history.length === 0 && <EmptyState title={t('No trips yet')} />}
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
                        driver.bank_accounts.length === 0 ? <EmptyState title={t('No bank accounts')} /> : driver.bank_accounts.map((account) => (
                            <div key={account.id} className="mb-3 rounded-2xl bg-white p-5">
                                <div className="flex items-center">
                                    <div>
                                        <p className="font-display text-sm font-bold text-slate-900">{account.bank_name}</p>
                                        <p className="mt-1 font-mono text-sm text-slate-500">•••• {account.account_last4}</p>
                                    </div>
                                    {account.is_default && <span className="ml-auto rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">{t('Default')}</span>}
                                </div>
                                <RevealAccountNumber url={withId(actions.revealBankAccount, account.id)} />
                            </div>
                        ))
                    )}
                </div>
            </div>
            <EditDriver open={editing} onClose={() => setEditing(false)} driver={driver} zones={zones} url={actions.edit} />
            <VehicleForm
                open={vehicleForm !== null}
                onClose={() => setVehicleForm(null)}
                vehicle={vehicleForm?.vehicle ?? null}
                vehicleClasses={vehicleClasses}
                url={vehicleForm?.vehicle ? withId(actions.updateVehicle, vehicleForm.vehicle.id) : actions.addVehicle}
            />
            <ReasonModal
                open={acting}
                onClose={() => setActing(false)}
                title={`${suspended ? t('Reactivate') : t('Suspend')} ${driver.name}`}
                description={suspended
                    ? tn('This lets :name sign in and take trips again.', { name: <strong style={{ color: '#0f172a' }}>{driver.name}</strong> })
                    : tn('This will immediately take :name offline and sign them out of the app. A driver on a trip cannot be suspended.', { name: <strong style={{ color: '#0f172a' }}>{driver.name}</strong> })}
                confirmLabel={suspended ? t('Confirm Reactivation') : t('Confirm Suspension')}
                placeholder={suspended ? t('Why is this driver being reactivated…') : t('Describe why this driver is being suspended…')}
                tone={suspended ? 'primary' : 'danger'}
                url={suspended ? actions.reactivate : actions.suspend}
            />
            <ReasonModal
                open={rejecting !== null}
                onClose={() => setRejecting(null)}
                title={t('Reject :type', { type: label(rejecting?.type) })}
                description={rejecting?.expires_at
                    ? t('What\'s wrong with this document? The driver sees this reason (expires :date).', { date: formatDate(rejecting.expires_at, timezone) })
                    : t('What\'s wrong with this document? The driver sees this reason.')}
                confirmLabel={t('Confirm')}
                placeholder={t('What\'s wrong with this document?')}
                url={withId(actions.rejectDocument, rejecting?.id)}
            />
        </div>
    );
}
