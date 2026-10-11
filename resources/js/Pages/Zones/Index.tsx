import { Head } from '@inertiajs/react';
import { Edit2, History, Map, Plus, Power, X } from 'lucide-react';
import { useState } from 'react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import PageHeader from '@/Components/PageHeader';
import Modal from '@/Components/Modal';
import { formatDateTime } from '@/lib/format';
import { polygonToSvgPoints, type GeoJsonPolygon } from '@/lib/geo';
import { label } from '@/lib/labels';
import { visitQuery, withId } from '@/lib/query';
import { shadowCard } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, FareHistory, FareRule, VehicleClass, Zone } from '@/types';
import FareRuleForm from './FareRuleForm';
import ZoneForm from './ZoneForm';
import { t, tc } from '@/lib/i18n';

interface Props {
    zones: Zone[];
    selectedZoneId: number | null;
    /** The selected zone's shape (the list carries no geometry). */
    selectedPolygon: GeoJsonPolygon | null;
    /** The prices in force for the selected zone, one row per vehicle class. */
    fareRules: FareRule[];
    /** Every version of one class (when ?history=<class> is in the URL). */
    fareHistory: FareHistory | null;
    vehicleClasses: VehicleClass[];
    timezones: string[];
    currencies: string[];
    actions: Actions<'createZone' | 'updateZone' | 'deactivateZone' | 'activateZone' | 'saveFareRule' | 'drawPolygon'>;
}

const zoneColors = ['#6366f1', '#0891b2', '#059669', '#d97706', '#e11d48', '#7c3aed'];
const classColors: Record<string, { bg: string; color: string }> = {
    economy: { bg: '#eef2ff', color: '#4f46e5' },
    comfort: { bg: '#fdf4ff', color: '#9333ea' },
    business: { bg: '#fff7ed', color: '#c2410c' },
};

export default function ZonesIndex({ zones, selectedZoneId, selectedPolygon, fareRules, fareHistory, vehicleClasses, timezones, currencies: zoneCurrencies, actions }: Props) {
    const { money, timezone } = useShared();
    const [formMode, setFormMode] = useState<'create' | 'edit' | null>(null);
    /** The fare form: a new version of this rule, or (null rule) the first rule of a class. */
    const [fareForm, setFareForm] = useState<{ rule: FareRule | null } | null>(null);
    const zoneIndex = zones.findIndex((z) => z.id === selectedZoneId);
    const zone = zones[zoneIndex];
    const rings = polygonToSvgPoints(selectedPolygon, 300, 330);
    const color = zone?.status === 'active' ? zoneColors[zoneIndex % zoneColors.length] : '#94a3b8';
    const freeClasses = vehicleClasses.filter((c) => !fareRules.some((rule) => rule.vehicle_class === c));
    const saveUrl = withId(actions.saveFareRule, zone?.id);
    /** "Open" means this version is the one in force now. */
    const until = (rule: FareRule) => (rule.effective_to === null ? t('Current') : formatDateTime(rule.effective_to, timezone));

    return (
        <div>
            <Head title={t('Zones & Fares')} />
            <PageHeader title={t('Zones & Fares')} description={t('Configure service zones and pricing rules')} actions={<button onClick={() => setFormMode('create')} disabled={actions.createZone === null} className="rounded-xl px-4 py-2 text-sm font-bold text-white hover:opacity-90 disabled:opacity-40" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>{t('+ Add Zone')}</button>} />
            {zones.length === 0 && (
                <div className="rounded-2xl bg-white p-12 text-center" style={shadowCard}>
                    <p className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{t('No zones yet')}</p>
                    <p className="mt-1 text-xs" style={{ color: '#94a3b8' }}>{t('Add the service area riders can book in. Fares, surge and reports hang off zones.')}</p>
                </div>
            )}
            {zones.length > 0 && (
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-4">
                <div className="flex flex-col gap-3">
                    <div className="overflow-hidden rounded-2xl" style={shadowCard}>
                        {zones.map((z, i) => {
                            const selected = z.id === selectedZoneId;
                            const zColor = z.status === 'active' ? zoneColors[i % zoneColors.length] : '#cbd5e1';
                            return (
                                <button key={z.id} aria-pressed={selected} onClick={() => visitQuery({ zone: z.id })} className="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-all" style={{ borderBottom: '1px solid #f8fafc', background: selected ? '#fafcff' : 'transparent', borderLeft: selected ? `3px solid ${zColor}` : '3px solid transparent' }}>
                                    <div className="h-2 w-2 flex-shrink-0 rounded-full" style={{ background: zColor }} />
                                    <span className="flex-1 text-sm font-semibold" style={{ color: selected ? '#0f172a' : '#64748b', fontFamily: 'var(--font-display)' }}>{z.name}<span className="ml-2 font-mono text-[10px] font-medium" style={{ color: '#94a3b8' }}>{z.code}</span></span>
                                    <span className="rounded-full px-2 py-0.5 text-[10px] font-bold" style={{ background: z.status === 'active' ? '#f0fdf4' : '#f8fafc', color: z.status === 'active' ? '#16a34a' : '#94a3b8', fontFamily: 'var(--font-display)' }}>{label(z.status)}</span>
                                </button>
                            );
                        })}
                    </div>
                    <div className="overflow-hidden rounded-2xl" style={{ height: 360, ...shadowCard }}>
                        <div className="relative h-full" style={{ background: 'linear-gradient(160deg, #eef2ff 0%, #e0e7ff 60%, #ede9fe 100%)' }}>
                            {[25, 50, 75].map((p) => <div key={`h${p}`} className="absolute inset-x-0" style={{ top: `${p}%`, borderTop: '1px solid rgba(99,102,241,0.08)' }} />)}
                            {[25, 50, 75].map((p) => <div key={`v${p}`} className="absolute inset-y-0" style={{ left: `${p}%`, borderLeft: '1px solid rgba(99,102,241,0.08)' }} />)}
                            <svg className="absolute inset-0 h-full w-full" viewBox="0 0 300 330" preserveAspectRatio="xMidYMid meet" role="img" aria-label={zone ? t(':name service area', { name: zone.name }) : t('No zone selected')}>
                                {rings.map((points, i) => <polygon key={i} points={points} fill={`${color}22`} stroke={color} strokeWidth="3" strokeDasharray="6 4" />)}
                            </svg>
                            <div className="absolute left-3 top-3 flex gap-1 rounded-xl bg-white p-1 shadow-lg">
                                <ActionButton url={actions.drawPolygon} className="rounded-lg bg-indigo-50 p-2 text-indigo-600"><Map size={13} /></ActionButton>
                                <button aria-label={t('Edit zone')} onClick={() => setFormMode('edit')} disabled={!zone || actions.updateZone === null} title={actions.updateZone === null ? t(UNAVAILABLE_HINT) : t('Edit zone')} className="rounded-lg p-2 text-slate-500 disabled:opacity-40"><Edit2 size={13} /></button>
                                {zone?.status === 'inactive' ? (
                                    <ActionButton url={withId(actions.activateZone, zone.id)} confirm={t('Reactivate :name? Pickups inside it are accepted again.', { name: zone.name })} className="rounded-lg p-2 text-emerald-600"><Power size={13} aria-label={t('Reactivate zone')} /></ActionButton>
                                ) : (
                                    <ActionButton url={withId(actions.deactivateZone, zone?.id)} confirm={t('Deactivate :name? Pickups inside it are refused until it is reactivated.', { name: zone?.name })} className="rounded-lg p-2 text-red-500"><X size={13} aria-label={t('Deactivate zone')} /></ActionButton>
                                )}
                            </div>
                            {zone && !zone.polygon_valid && <div className="absolute bottom-10 left-3 right-3 rounded-xl bg-red-50 p-2 text-center text-xs font-bold text-red-600">{t('Polygon intersects itself')}</div>}
                            <div className="absolute bottom-2 left-0 right-0 flex justify-center">
                                <div className="rounded-lg px-2.5 py-1 text-[10px] font-semibold" style={{ background: 'rgba(255,255,255,0.9)', color, fontFamily: 'var(--font-display)' }}>{zone?.name}</div>
                            </div>
                        </div>
                    </div>
                    {zone && <p className="px-1 text-[11px]" style={{ color: '#94a3b8' }}>{zone.timezone} · {zone.currency} · {t('priority :priority', { priority: zone.priority })} · {tc(':count point|:count points', zone.points)}</p>}
                </div>

                <div className="overflow-hidden rounded-2xl lg:col-span-3" style={shadowCard}>
                    <div className="flex items-center gap-3 px-5 py-4" style={{ borderBottom: '1px solid #f8fafc' }}>
                        <div className="h-2 w-2 rounded-full" style={{ background: color }} />
                        <h2 className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{t('Fare Rules — :zone', { zone: zone?.name ?? '' })}</h2>
                        <span className="ml-auto text-xs" style={{ color: '#94a3b8' }}>{tc(':count vehicle class|:count vehicle classes', fareRules.length)}</span>
                        <button onClick={() => setFareForm({ rule: null })} disabled={saveUrl === null || freeClasses.length === 0} title={saveUrl === null ? t(UNAVAILABLE_HINT) : freeClasses.length === 0 ? t('Every vehicle class has a fare rule.') : undefined} className="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-bold text-indigo-600 hover:bg-indigo-50 disabled:opacity-40"><Plus size={12} />{t('Add fare rule')}</button>
                    </div>
                    <div className="overflow-x-auto">
                    <table className="w-full" style={{ minWidth: 760 }}>
                        <thead>
                            <tr style={{ borderBottom: '1px solid #f8fafc' }}>
                                {[t('Class'), t('Base Fare'), t('Per km'), t('Per min'), t('Min Fare'), t('Currency'), t('Effective from'), t('Effective to'), ''].map((h, i) => <th key={i} className="px-4 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>{h}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {fareRules.length === 0 && <tr><td colSpan={9} className="py-12 text-center text-sm" style={{ color: '#94a3b8' }}>{t('No fare rules for this zone.')}</td></tr>}
                            {fareRules.map((rule) => {
                                const vc = classColors[rule.vehicle_class] ?? { bg: '#f8fafc', color: '#64748b' };
                                return (
                                    <tr key={rule.id} style={{ borderBottom: '1px solid #f8fafc' }}>
                                        <td className="px-4 py-4"><span className="rounded-lg px-2.5 py-1 text-xs font-bold" style={{ background: vc.bg, color: vc.color, fontFamily: 'var(--font-display)' }}>{label(rule.vehicle_class)}</span></td>
                                        {([rule.base_fare, rule.per_km, rule.per_minute, rule.min_fare] as const).map((amount, i) => (
                                            <td key={i} className="px-4 py-4"><span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{money(amount)}</span></td>
                                        ))}
                                        <td className="px-4 py-4"><span className="text-xs font-medium" style={{ color: '#64748b' }}>{rule.base_fare.currency}</span></td>
                                        <td className="px-4 py-4 text-xs" style={{ color: '#64748b' }}>{formatDateTime(rule.effective_from, timezone)}</td>
                                        <td className="px-4 py-4 text-xs" style={{ color: '#64748b' }}>{until(rule)}</td>
                                        <td className="px-4 py-4">
                                            <div className="flex gap-1">
                                                <button aria-label={t('New version of :class', { class: label(rule.vehicle_class) })} onClick={() => setFareForm({ rule })} disabled={saveUrl === null} title={saveUrl === null ? t(UNAVAILABLE_HINT) : t('New version of :class', { class: label(rule.vehicle_class) })} className="rounded-lg p-1.5 transition-colors hover:bg-slate-100 disabled:opacity-40" style={{ color: '#94a3b8' }}><Edit2 size={13} /></button>
                                                <button aria-label={t('History of :class', { class: label(rule.vehicle_class) })} onClick={() => visitQuery({ history: rule.vehicle_class })} className="rounded-lg p-1.5 transition-colors hover:bg-slate-100" style={{ color: '#94a3b8' }}><History size={13} /></button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            )}
            <ZoneForm
                open={formMode !== null}
                onClose={() => setFormMode(null)}
                zone={formMode === 'edit' ? (zone ?? null) : null}
                polygon={selectedPolygon}
                timezones={timezones}
                currencies={zoneCurrencies}
                url={formMode === 'edit' ? withId(actions.updateZone, zone?.id) : actions.createZone}
            />
            <FareRuleForm
                open={fareForm !== null}
                onClose={() => setFareForm(null)}
                rule={fareForm?.rule ?? null}
                freeClasses={freeClasses}
                zoneCurrency={zone?.currency ?? 'AMD'}
                url={saveUrl}
            />
            <Modal open={fareHistory !== null} wide onClose={() => visitQuery({ history: null })} title={t('History of :class', { class: label(fareHistory?.vehicle_class) })}>
                <p className="mb-3 text-xs" style={{ color: '#64748b' }}>{t('Every price list this class has had in :zone. Past versions never change.', { zone: zone?.name ?? '' })}</p>
                <div className="max-h-[55vh] overflow-auto">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr style={{ borderBottom: '1px solid #f1f5f9', color: '#94a3b8' }}>
                                {[t('Effective from'), t('Effective to'), t('Base Fare'), t('Per km'), t('Per min'), t('Min Fare'), t('Saved by')].map((h) => <th key={h} className="px-2 py-2 font-bold uppercase tracking-wider">{h}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {fareHistory?.versions.map((version) => (
                                <tr key={version.id} style={{ borderBottom: '1px solid #f8fafc' }}>
                                    <td className="px-2 py-2.5">{formatDateTime(version.effective_from, timezone)}</td>
                                    <td className="px-2 py-2.5">{until(version)}</td>
                                    <td className="px-2 py-2.5 font-mono font-bold">{money(version.base_fare)}</td>
                                    <td className="px-2 py-2.5 font-mono">{money(version.per_km)}</td>
                                    <td className="px-2 py-2.5 font-mono">{money(version.per_minute)}</td>
                                    <td className="px-2 py-2.5 font-mono">{money(version.min_fare)}</td>
                                    <td className="px-2 py-2.5">{version.created_by ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Modal>
        </div>
    );
}
