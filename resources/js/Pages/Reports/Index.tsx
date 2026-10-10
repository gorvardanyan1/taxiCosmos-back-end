import { Head } from '@inertiajs/react';
import { Download, Play } from 'lucide-react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import ActionButton from '@/Components/ActionButton';
import PageHeader from '@/Components/PageHeader';
import { formatDate, formatNumber, toMajorUnits } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery } from '@/lib/query';
import { shadowCard } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, Money, NamedOption, ReportMetric, ReportPoint } from '@/types';

interface Props {
    zones: NamedOption[];
    series: ReportPoint[];
    exports: { id: number; name: string; status: 'ready' | 'processing' | 'failed'; file_name: string | null }[];
    metrics: ReportMetric[];
    groups: string[];
    filters: { metric: ReportMetric; from: string; to: string; zone: number | null; vehicle_class: string | null; group: string; currency: string };
    actions: Actions<'export' | 'generate'>;
}

const metricLabels: Record<ReportMetric, string> = {
    revenue: 'Revenue', trips: 'Trips', driver_earnings: 'Driver earnings', commission: 'Commission',
    cancellations: 'Cancellations', refunds: 'Refunds & chargebacks', payouts: 'Payouts', driver_debt: 'Driver debt',
};
const metricColors: Record<ReportMetric, string> = {
    revenue: '#6366f1', trips: '#0891b2', driver_earnings: '#059669', commission: '#7c3aed',
    cancellations: '#e11d48', refunds: '#e11d48', payouts: '#0891b2', driver_debt: '#d97706',
};
const controlStyle = { border: '1px solid #e2e8f0' } as const;
const labelClass = 'mb-2 block text-xs font-bold';

export default function ReportsIndex({ zones, series, exports, metrics, groups, filters, actions }: Props) {
    const { money, currencies, timezone } = useShared();
    const metric = filters.metric;
    const color = metricColors[metric];
    const monetary = metric !== 'trips' && metric !== 'cancellations';
    const valueOf = (point: ReportPoint): number => (monetary ? toMajorUnits(point[metric] as Money, currencies) : (point[metric] as number));
    const display = (point: ReportPoint): string => (monetary ? money(point[metric] as Money) : formatNumber(point[metric] as number));
    const chart = series.map((point) => ({ label: point.label, value: valueOf(point), text: display(point) }));
    const gradId = `grad-${metric}`;
    const tooltip = { formatter: (_: unknown, __: unknown, item: { payload?: { text: string } }) => [item.payload?.text ?? '', metricLabels[metric]] } as const;

    return (
        <div>
            <Head title="Reports" />
            <PageHeader title="Reports" description="Build, preview, and export operational data" actions={<ActionButton url={actions.export} className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold text-white hover:opacity-90" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}><Download size={14} /> Export CSV</ActionButton>} />

            <div className="mb-6 rounded-2xl p-5" style={shadowCard}>
                <p className="mb-4 text-[10px] font-bold uppercase tracking-widest" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>Report Builder</p>
                <div className="flex flex-wrap items-end gap-3">
                    <div>
                        <span className={labelClass} style={{ color: '#374151', fontFamily: 'var(--font-display)' }}>Metric</span>
                        <div role="tablist" className="flex gap-1 rounded-xl p-1" style={{ background: '#f8fafc', border: '1px solid #e2e8f0' }}>
                            {metrics.map((m) => (
                                <button key={m} role="tab" aria-selected={metric === m} onClick={() => visitQuery({ metric: m })} className="rounded-lg px-3 py-1.5 text-xs font-bold transition-all" style={{ background: metric === m ? color : 'transparent', color: metric === m ? 'white' : '#94a3b8', fontFamily: 'var(--font-display)' }}>
                                    {metricLabels[m]}
                                </button>
                            ))}
                        </div>
                    </div>
                    <label><span className={labelClass} style={{ color: '#374151' }}>Zone</span>
                        <select value={filters.zone ?? ''} onChange={(e) => visitQuery({ zone: e.target.value })} className="rounded-xl px-3 py-2 text-sm" style={controlStyle}>
                            <option value="">All zones</option>
                            {zones.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
                        </select>
                    </label>
                    <label><span className={labelClass} style={{ color: '#374151' }}>Vehicle class</span>
                        <select value={filters.vehicle_class ?? ''} onChange={(e) => visitQuery({ vehicle_class: e.target.value })} className="rounded-xl px-3 py-2 text-sm" style={controlStyle}>
                            <option value="">All classes</option>
                            {['economy', 'comfort', 'business'].map((c) => <option key={c} value={c}>{label(c)}</option>)}
                        </select>
                    </label>
                    <label><span className={labelClass} style={{ color: '#374151' }}>Group by</span>
                        <select value={filters.group} onChange={(e) => visitQuery({ group: e.target.value })} className="rounded-xl px-3 py-2 text-sm" style={controlStyle}>
                            {groups.map((g) => <option key={g} value={g}>{label(g)}</option>)}
                        </select>
                    </label>
                    <label><span className={labelClass} style={{ color: '#374151' }}>Currency</span>
                        <select value={filters.currency} onChange={(e) => visitQuery({ currency: e.target.value })} className="rounded-xl px-3 py-2 text-sm" style={controlStyle}>
                            {currencies.map((c) => <option key={c.code} value={c.code}>{c.code}</option>)}
                        </select>
                        <p className="mt-1 text-[10px] text-amber-600">Converted amounts are approximate</p>
                    </label>
                    <label><span className={labelClass} style={{ color: '#374151' }}>From</span>
                        <input type="date" value={filters.from} onChange={(e) => visitQuery({ from: e.target.value })} className="rounded-xl px-3 py-2 text-sm" style={controlStyle} />
                    </label>
                    <label><span className={labelClass} style={{ color: '#374151' }}>To</span>
                        <input type="date" value={filters.to} onChange={(e) => visitQuery({ to: e.target.value })} className="rounded-xl px-3 py-2 text-sm" style={controlStyle} />
                    </label>
                    <ActionButton url={actions.generate} className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold text-white hover:opacity-90" style={{ background: color, fontFamily: 'var(--font-display)' }}><Play size={13} /> Generate</ActionButton>
                </div>
            </div>

            <div className="mt-5 overflow-hidden rounded-2xl" style={{ background: 'white', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}>
                <div className="px-5 py-4 text-sm font-bold" style={{ fontFamily: 'var(--font-display)', borderBottom: '1px solid #f1f5f9' }}>Exports</div>
                {exports.length === 0 && <p className="px-5 py-4 text-sm text-slate-400">No exports yet.</p>}
                {exports.map((row) => (
                    <div key={row.id} className="flex items-center border-b border-slate-50 px-5 py-3 text-sm">
                        <span className="font-semibold text-slate-700">{row.name}</span>
                        <span className={`ml-auto mr-6 rounded-full px-2.5 py-1 text-xs font-bold ${row.status === 'ready' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'}`}>{label(row.status)}</span>
                        <span className="text-xs font-bold text-indigo-600">{row.file_name ?? 'Preparing file…'}</span>
                    </div>
                ))}
            </div>

            <div className="my-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div className="rounded-2xl p-5 lg:col-span-2" style={shadowCard}>
                    <h2 className="mb-1 text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{metricLabels[metric]} — Weekly Trend</h2>
                    <p className="mb-5 text-xs" style={{ color: '#94a3b8' }}>{formatDate(filters.from, timezone)} – {formatDate(filters.to, timezone)}</p>
                    <ResponsiveContainer width="100%" height={220}>
                        <AreaChart data={chart} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                            <defs>
                                <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={color} stopOpacity={0.2} />
                                    <stop offset="100%" stopColor={color} stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
                            <XAxis dataKey="label" tick={{ fontSize: 10, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                            <YAxis tickFormatter={(v: number) => (monetary ? `${(v / 1000).toFixed(0)}k` : String(v))} tick={{ fontSize: 10, fill: '#94a3b8' }} axisLine={false} tickLine={false} width={42} />
                            <Tooltip {...tooltip} contentStyle={{ fontSize: 12, borderRadius: 12, border: 'none', boxShadow: '0 8px 30px rgba(0,0,0,0.15)' }} />
                            <Area type="monotone" dataKey="value" stroke={color} strokeWidth={2.5} fill={`url(#${gradId})`} dot={false} activeDot={{ r: 5, fill: color, strokeWidth: 2, stroke: 'white' }} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
                <div className="rounded-2xl p-5" style={shadowCard}>
                    <h2 className="mb-1 text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Bar View</h2>
                    <p className="mb-5 text-xs" style={{ color: '#94a3b8' }}>Period comparison</p>
                    <ResponsiveContainer width="100%" height={220}>
                        <BarChart data={chart} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
                            <XAxis dataKey="label" tick={{ fontSize: 9, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                            <YAxis tickFormatter={(v: number) => (monetary ? `${(v / 1000).toFixed(0)}k` : String(v))} tick={{ fontSize: 9, fill: '#94a3b8' }} axisLine={false} tickLine={false} width={42} />
                            <Tooltip {...tooltip} contentStyle={{ fontSize: 12, borderRadius: 12, border: 'none', boxShadow: '0 8px 30px rgba(0,0,0,0.15)' }} />
                            <Bar dataKey="value" fill={color} radius={[6, 6, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            </div>

            <div className="overflow-hidden rounded-2xl" style={shadowCard}>
                <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid #f8fafc' }}>
                    <h2 className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Data Preview — {metricLabels[metric]}</h2>
                    <span className="text-xs" style={{ color: '#94a3b8' }}>{series.length} periods</span>
                </div>
                <table className="w-full">
                    <thead>
                        <tr style={{ borderBottom: '1px solid #f8fafc' }}>
                            <th className="px-5 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8' }}>Period</th>
                            <th className="px-5 py-3.5 text-right text-[11px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8' }}>{metricLabels[metric]}</th>
                            <th className="hidden px-5 py-3.5 text-right text-[11px] font-bold uppercase tracking-wider md:table-cell" style={{ color: '#94a3b8' }}>vs Prior</th>
                        </tr>
                    </thead>
                    <tbody>
                        {series.map((point, i) => {
                            const value = valueOf(point);
                            const previous = i > 0 ? valueOf(series[i - 1]) : null;
                            const change = previous ? ((value - previous) / previous) * 100 : null;
                            return (
                                <tr key={point.label} style={{ borderBottom: i < series.length - 1 ? '1px solid #f8fafc' : 'none' }}>
                                    <td className="px-5 py-4"><span className="text-sm font-medium" style={{ color: '#374151' }}>{point.label}</span></td>
                                    <td className="px-5 py-4 text-right"><span className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{display(point)}</span></td>
                                    <td className="hidden px-5 py-4 text-right md:table-cell">
                                        {change !== null && (
                                            <span className="rounded-full px-2 py-1 text-xs font-bold" style={{ background: change >= 0 ? '#f0fdf4' : '#fef2f2', color: change >= 0 ? '#16a34a' : '#dc2626', fontFamily: 'var(--font-mono)' }}>
                                                {change >= 0 ? '+' : ''}{change.toFixed(1)}%
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
