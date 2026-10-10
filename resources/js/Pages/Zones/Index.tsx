import { Head, useForm } from '@inertiajs/react';
import { Check, Edit2, Map, X } from 'lucide-react';
import { useState } from 'react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import PageHeader from '@/Components/PageHeader';
import { parseMoneyInput } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery } from '@/lib/query';
import { shadowCard } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, FareRule, Money, Zone } from '@/types';

interface Props {
    zones: Zone[];
    selectedZoneId: number;
    fareRules: FareRule[];
    actions: Actions<'createZone' | 'updateFareRule' | 'drawPolygon'>;
}

const zoneColors = ['#6366f1', '#0891b2', '#059669', '#d97706', '#e11d48', '#7c3aed'];
const classColors: Record<string, { bg: string; color: string }> = {
    economy: { bg: '#eef2ff', color: '#4f46e5' },
    comfort: { bg: '#fdf4ff', color: '#9333ea' },
    business: { bg: '#fff7ed', color: '#c2410c' },
};
const moneyFields = ['base_fare', 'per_km', 'per_minute', 'minimum_fare'] as const;
type MoneyField = (typeof moneyFields)[number];

export default function ZonesIndex({ zones, selectedZoneId, fareRules, actions }: Props) {
    const { money, currencies } = useShared();
    const [editing, setEditing] = useState<string | null>(null);
    const form = useForm<Record<MoneyField, string>>({ base_fare: '', per_km: '', per_minute: '', minimum_fare: '' });
    const zoneIndex = zones.findIndex((z) => z.id === selectedZoneId);
    const zone = zones[zoneIndex];
    const color = zone?.status === 'active' ? zoneColors[zoneIndex % zoneColors.length] : '#94a3b8';
    const decimalsOf = (m: Money) => currencies.find((c) => c.code === m.currency)?.decimals ?? 2;
    const major = (m: Money) => String(m.amount / 10 ** decimalsOf(m));

    const startEdit = (rule: FareRule) => {
        setEditing(rule.vehicle_class);
        form.setData({ base_fare: major(rule.base_fare), per_km: major(rule.per_km), per_minute: major(rule.per_minute), minimum_fare: major(rule.minimum_fare) });
    };
    const save = (rule: FareRule) => {
        if (!actions.updateFareRule) return;
        const parsed = Object.fromEntries(moneyFields.map((f) => [f, parseMoneyInput(form.data[f], decimalsOf(rule[f]))]));
        if (Object.values(parsed).some((v) => v === null)) return;
        form.transform(() => ({ zone_id: rule.zone_id, vehicle_class: rule.vehicle_class, currency: rule.base_fare.currency, ...parsed }));
        form.patch(actions.updateFareRule, { preserveScroll: true, onSuccess: () => setEditing(null) });
    };

    return (
        <div>
            <Head title="Zones & Fares" />
            <PageHeader title="Zones & Fares" description="Configure service zones and pricing rules" actions={<ActionButton url={actions.createZone} className="rounded-xl px-4 py-2 text-sm font-bold text-white hover:opacity-90" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>+ Add Zone</ActionButton>} />
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-4">
                <div className="flex flex-col gap-3">
                    <div className="overflow-hidden rounded-2xl" style={shadowCard}>
                        {zones.map((z, i) => {
                            const selected = z.id === selectedZoneId;
                            const zColor = z.status === 'active' ? zoneColors[i % zoneColors.length] : '#cbd5e1';
                            return (
                                <button key={z.id} aria-pressed={selected} onClick={() => visitQuery({ zone: z.id })} className="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-all" style={{ borderBottom: '1px solid #f8fafc', background: selected ? '#fafcff' : 'transparent', borderLeft: selected ? `3px solid ${zColor}` : '3px solid transparent' }}>
                                    <div className="h-2 w-2 flex-shrink-0 rounded-full" style={{ background: zColor }} />
                                    <span className="flex-1 text-sm font-semibold" style={{ color: selected ? '#0f172a' : '#64748b', fontFamily: 'var(--font-display)' }}>{z.name}</span>
                                    <span className="rounded-full px-2 py-0.5 text-[10px] font-bold" style={{ background: z.status === 'active' ? '#f0fdf4' : '#f8fafc', color: z.status === 'active' ? '#16a34a' : '#94a3b8', fontFamily: 'var(--font-display)' }}>{z.status}</span>
                                </button>
                            );
                        })}
                    </div>
                    <div className="overflow-hidden rounded-2xl" style={{ height: 360, ...shadowCard }}>
                        <div className="relative h-full" style={{ background: 'linear-gradient(160deg, #eef2ff 0%, #e0e7ff 60%, #ede9fe 100%)' }}>
                            {[25, 50, 75].map((p) => <div key={`h${p}`} className="absolute inset-x-0" style={{ top: `${p}%`, borderTop: '1px solid rgba(99,102,241,0.08)' }} />)}
                            {[25, 50, 75].map((p) => <div key={`v${p}`} className="absolute inset-y-0" style={{ left: `${p}%`, borderLeft: '1px solid rgba(99,102,241,0.08)' }} />)}
                            <svg className="absolute inset-0 h-full w-full">
                                <polygon points="42,78 188,34 260,126 218,270 74,294 24,186" fill={`${color}22`} stroke={color} strokeWidth="3" strokeDasharray="6 4" />
                            </svg>
                            <div className="absolute left-3 top-3 flex gap-1 rounded-xl bg-white p-1 shadow-lg">
                                <ActionButton url={actions.drawPolygon} className="rounded-lg bg-indigo-50 p-2 text-indigo-600"><Map size={13} /></ActionButton>
                                <button disabled title={UNAVAILABLE_HINT} className="rounded-lg p-2 text-slate-500 disabled:opacity-40"><Edit2 size={13} /></button>
                                <button disabled title={UNAVAILABLE_HINT} className="rounded-lg p-2 text-red-500 disabled:opacity-40"><X size={13} /></button>
                            </div>
                            {zone && !zone.polygon_valid && <div className="absolute bottom-10 left-3 right-3 rounded-xl bg-red-50 p-2 text-center text-xs font-bold text-red-600">Polygon intersects itself</div>}
                            <div className="absolute bottom-2 left-0 right-0 flex justify-center">
                                <div className="rounded-lg px-2.5 py-1 text-[10px] font-semibold" style={{ background: 'rgba(255,255,255,0.9)', color, fontFamily: 'var(--font-display)' }}>{zone?.name}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-2xl lg:col-span-3" style={shadowCard}>
                    <div className="flex items-center gap-3 px-5 py-4" style={{ borderBottom: '1px solid #f8fafc' }}>
                        <div className="h-2 w-2 rounded-full" style={{ background: color }} />
                        <h2 className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Fare Rules — {zone?.name}</h2>
                        <span className="ml-auto text-xs" style={{ color: '#94a3b8' }}>{fareRules.length} vehicle classes</span>
                    </div>
                    <table className="w-full">
                        <thead>
                            <tr style={{ borderBottom: '1px solid #f8fafc' }}>
                                {['Class', 'Base Fare', 'Per km', 'Per min', 'Min Fare', 'Currency', ''].map((h) => <th key={h} className="px-5 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>{h}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {fareRules.length === 0 && <tr><td colSpan={7} className="py-12 text-center text-sm" style={{ color: '#94a3b8' }}>No fare rules for this zone.</td></tr>}
                            {fareRules.map((rule) => {
                                const isEditing = editing === rule.vehicle_class;
                                const vc = classColors[rule.vehicle_class] ?? { bg: '#f8fafc', color: '#64748b' };
                                return (
                                    <tr key={rule.vehicle_class} style={{ borderBottom: '1px solid #f8fafc' }}>
                                        <td className="px-5 py-4"><span className="rounded-lg px-2.5 py-1 text-xs font-bold" style={{ background: vc.bg, color: vc.color, fontFamily: 'var(--font-display)' }}>{label(rule.vehicle_class)}</span></td>
                                        {moneyFields.map((f) => (
                                            <td key={f} className="px-5 py-4">
                                                {isEditing ? (
                                                    <div>
                                                        <input aria-label={label(f)} inputMode="decimal" value={form.data[f]} onChange={(e) => form.setData(f, e.target.value)} className="w-24 rounded-lg px-2 py-1.5 text-sm" style={{ border: `1px solid ${color}`, color: '#0f172a', outline: 'none', fontFamily: 'var(--font-mono)' }} />
                                                        {form.errors[f] && <p role="alert" className="mt-1 text-[10px] font-semibold text-red-600">{form.errors[f]}</p>}
                                                    </div>
                                                ) : (
                                                    <span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{money(rule[f])}</span>
                                                )}
                                            </td>
                                        ))}
                                        <td className="px-5 py-4"><span className="text-xs font-medium" style={{ color: '#64748b' }}>{rule.base_fare.currency}</span></td>
                                        <td className="px-5 py-4">
                                            {isEditing ? (
                                                <div className="flex gap-1">
                                                    <button aria-label="Save" onClick={() => save(rule)} disabled={actions.updateFareRule === null || form.processing} title={actions.updateFareRule === null ? UNAVAILABLE_HINT : undefined} className="rounded-lg p-1.5 hover:opacity-80 disabled:opacity-40" style={{ background: '#f0fdf4', color: '#16a34a' }}><Check size={13} /></button>
                                                    <button aria-label="Cancel" onClick={() => setEditing(null)} className="rounded-lg p-1.5 hover:opacity-80" style={{ background: '#f8fafc', color: '#94a3b8' }}><X size={13} /></button>
                                                </div>
                                            ) : (
                                                <button aria-label={`Edit ${label(rule.vehicle_class)}`} onClick={() => startEdit(rule)} className="rounded-lg p-1.5 transition-colors hover:bg-slate-100" style={{ color: '#94a3b8' }}><Edit2 size={13} /></button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
