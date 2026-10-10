import { Head, Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Car, Clock, DollarSign, TrendingUp, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { Area, AreaChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import PageHeader from '@/Components/PageHeader';
import { formatBasisPoints, formatDate, formatNumber, formatRelative, toMajorUnits } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery } from '@/lib/query';
import { shadowCard } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Money, NamedOption } from '@/types';

type MetricKey = 'trips_today' | 'active_trips' | 'online_drivers' | 'revenue_today' | 'cancellation_rate' | 'pending_verifications';

interface Props {
    as_of: string;
    zones: NamedOption[];
    filters: { zone: number | null };
    metrics: { key: MetricKey; value: number | Money; change: string; trend: 'up' | 'down' }[];
    alerts: { stuck_trips: number; failed_payments: number; urgent_tickets: number };
    revenue_trend: { change_bp: number; points: { date: string; revenue: Money }[] };
    trip_status: { status: string; count: number }[];
    map: { active_count: number; pins: { x: number; y: number; state: 'en_route' | 'in_trip' }[] };
    activity: { id: number; type: 'signup' | 'trip' | 'flag' | 'cancel'; title: string; detail: string; occurred_at: string }[];
}

const metricStyle: Record<MetricKey, { title: string; icon: ReactNode; gradient: string; glow: string }> = {
    trips_today: { title: 'Trips Today', icon: <TrendingUp size={18} />, gradient: 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)', glow: 'rgba(99,102,241,0.3)' },
    active_trips: { title: 'Active Trips', icon: <Car size={18} />, gradient: 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)', glow: 'rgba(99,102,241,0.3)' },
    online_drivers: { title: 'Online Drivers', icon: <Users size={18} />, gradient: 'linear-gradient(135deg, #0891b2 0%, #0284c7 100%)', glow: 'rgba(8,145,178,0.3)' },
    revenue_today: { title: "Today's Revenue", icon: <DollarSign size={18} />, gradient: 'linear-gradient(135deg, #059669 0%, #0d9488 100%)', glow: 'rgba(5,150,105,0.3)' },
    cancellation_rate: { title: 'Cancellation Rate', icon: <Clock size={18} />, gradient: 'linear-gradient(135deg, #d97706 0%, #dc2626 100%)', glow: 'rgba(217,119,6,0.3)' },
    pending_verifications: { title: 'Pending Verifications', icon: <Clock size={18} />, gradient: 'linear-gradient(135deg, #e11d48 0%, #be123c 100%)', glow: 'rgba(225,29,72,0.3)' },
};

const alertStyle = [
    { key: 'stuck_trips', label: 'Stuck trips', href: '/admin/trips?filter[status]=in_progress', bg: '#fffbeb', color: '#b45309' },
    { key: 'failed_payments', label: 'Failed payments', href: '/admin/transactions?filter[status]=failed', bg: '#fef2f2', color: '#dc2626' },
    { key: 'urgent_tickets', label: 'Urgent tickets', href: '/admin/support-tickets?filter[priority]=urgent', bg: '#f5f3ff', color: '#7c3aed' },
] as const;

const statusColors: Record<string, string> = { completed: '#6366f1', in_progress: '#22c55e', cancelled: '#f87171', requested: '#fbbf24' };
const activityColors: Record<string, string> = { signup: '#6366f1', trip: '#22c55e', flag: '#ef4444', cancel: '#f59e0b' };

export default function Dashboard({ as_of, zones, filters, metrics, alerts, revenue_trend, trip_status, map, activity }: Props) {
    const { money, currencies, timezone } = useShared();
    const display = (metric: Props['metrics'][number]) =>
        typeof metric.value === 'object' ? money(metric.value, { compact: true }) : metric.key === 'cancellation_rate' ? formatBasisPoints(metric.value) : formatNumber(metric.value);
    const chart = revenue_trend.points.map((point) => ({ day: formatDate(point.date, timezone).replace(/, \d{4}$/, ''), rev: toMajorUnits(point.revenue, currencies), money: point.revenue }));

    return (
        <div>
            <Head title="Dashboard" />
            <PageHeader
                title="Dashboard"
                description={`Real-time overview of your platform — ${formatDate(as_of, timezone)}`}
                actions={
                    <select aria-label="Zone" value={filters.zone ?? ''} onChange={(e) => visitQuery({ zone: e.target.value })} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600">
                        <option value="">All zones</option>
                        {zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}
                    </select>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
                {metrics.map((metric) => {
                    const style = metricStyle[metric.key];
                    return (
                        <div key={metric.key} className="relative overflow-hidden rounded-2xl p-4" style={{ background: style.gradient, boxShadow: `0 8px 24px ${style.glow}` }}>
                            <div className="absolute -right-4 -top-4 h-20 w-20 rounded-full" style={{ background: 'rgba(255,255,255,0.1)' }} />
                            <div className="absolute -bottom-3 -right-1 h-12 w-12 rounded-full" style={{ background: 'rgba(255,255,255,0.07)' }} />
                            <div className="relative z-10 mb-3 flex items-start justify-between">
                                <div className="rounded-lg p-1.5" style={{ background: 'rgba(255,255,255,0.2)' }}><span className="text-white">{style.icon}</span></div>
                                <span className="flex items-center gap-0.5 text-xs font-medium" style={{ color: metric.trend === 'up' ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.7)' }}>
                                    {metric.trend === 'up' ? <ArrowUpRight size={12} /> : <ArrowDownRight size={12} />}
                                </span>
                            </div>
                            <p className="relative z-10 mb-1 text-2xl font-bold leading-none text-white" style={{ fontFamily: 'var(--font-display)' }}>{display(metric)}</p>
                            <p className="relative z-10 text-xs" style={{ color: 'rgba(255,255,255,0.7)' }}>{style.title}</p>
                            <p className="relative z-10 mt-0.5 text-[10px]" style={{ color: 'rgba(255,255,255,0.5)' }}>{metric.change}</p>
                        </div>
                    );
                })}
            </div>

            <div className="mb-7 grid grid-cols-1 gap-3 md:grid-cols-3">
                {alertStyle.map((alert) => (
                    <div key={alert.key} className="flex items-center rounded-2xl px-4 py-3" style={{ background: alert.bg, border: `1px solid ${alert.color}18` }}>
                        <div>
                            <p className="text-xs font-bold" style={{ color: alert.color }}>{alert.label}</p>
                            <p className="mt-1 text-xl font-bold" style={{ color: alert.color, fontFamily: 'var(--font-mono)' }}>{alerts[alert.key]}</p>
                        </div>
                        <Link href={alert.href} className="ml-auto text-xs font-bold" style={{ color: alert.color }}>View →</Link>
                    </div>
                ))}
            </div>

            <div className="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div className="rounded-2xl p-5 lg:col-span-2" style={shadowCard}>
                    <div className="mb-5 flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Revenue Trend</h2>
                            <p className="mt-0.5 text-xs" style={{ color: '#94a3b8' }}>Last 30 days — daily gross</p>
                        </div>
                        <div className="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold" style={{ background: '#f0fdf4', color: '#15803d' }}>
                            <ArrowUpRight size={12} />+{formatBasisPoints(revenue_trend.change_bp)}
                        </div>
                    </div>
                    <ResponsiveContainer width="100%" height={210}>
                        <AreaChart data={chart} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                            <defs>
                                <linearGradient id="revGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor="#6366f1" stopOpacity={0.18} />
                                    <stop offset="100%" stopColor="#6366f1" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
                            <XAxis dataKey="day" tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                            <YAxis tickFormatter={(v: number) => `${(v / 1000).toFixed(0)}k`} tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} width={38} />
                            <Tooltip
                                content={({ active, payload, label: day }) =>
                                    active && payload?.length ? (
                                        <div className="rounded-xl px-3 py-2.5 text-xs" style={{ background: '#0f172a', color: 'white', boxShadow: '0 8px 30px rgba(0,0,0,0.25)' }}>
                                            <p className="mb-1" style={{ color: '#94a3b8' }}>{day}</p>
                                            <p className="font-semibold" style={{ fontFamily: 'var(--font-mono)' }}>{money((payload[0].payload as { money: Money }).money)}</p>
                                        </div>
                                    ) : null
                                }
                            />
                            <Area type="monotone" dataKey="rev" stroke="#6366f1" strokeWidth={2.5} fill="url(#revGrad)" dot={false} activeDot={{ r: 5, fill: '#6366f1', strokeWidth: 2, stroke: 'white' }} />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>

                <div className="rounded-2xl p-5" style={shadowCard}>
                    <h2 className="mb-1 text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Trips by Status</h2>
                    <p className="mb-3 text-xs" style={{ color: '#94a3b8' }}>All time totals</p>
                    <ResponsiveContainer width="100%" height={160}>
                        <PieChart>
                            <Pie data={trip_status.map((s) => ({ name: label(s.status), value: s.count, status: s.status }))} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={48} outerRadius={72} paddingAngle={3} strokeWidth={0}>
                                {trip_status.map((entry) => <Cell key={entry.status} fill={statusColors[entry.status] ?? '#94a3b8'} />)}
                            </Pie>
                            <Tooltip contentStyle={{ fontSize: 12, borderRadius: 12, border: 'none', boxShadow: '0 8px 30px rgba(0,0,0,0.15)' }} />
                        </PieChart>
                    </ResponsiveContainer>
                    <div className="mt-2 flex flex-col gap-2">
                        {trip_status.map((entry) => (
                            <div key={entry.status} className="flex items-center gap-2">
                                <span className="h-2 w-2 flex-shrink-0 rounded-full" style={{ background: statusColors[entry.status] ?? '#94a3b8' }} />
                                <span className="flex-1 text-xs" style={{ color: '#64748b' }}>{label(entry.status)}</span>
                                <span className="text-xs font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{formatNumber(entry.count)}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div className="overflow-hidden rounded-2xl lg:col-span-2" style={shadowCard}>
                    <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid #f8fafc' }}>
                        <div>
                            <h2 className="text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Live Trip Map</h2>
                            <p className="mt-0.5 text-xs" style={{ color: '#94a3b8' }}>Real-time vehicle locations</p>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-green-400" style={{ boxShadow: '0 0 0 3px rgba(34,197,94,0.2)' }} />
                            <span className="text-xs font-semibold" style={{ color: '#16a34a' }}>{formatNumber(map.active_count)} active</span>
                        </div>
                    </div>
                    <div className="relative" style={{ height: 240, background: 'linear-gradient(160deg, #eef2ff 0%, #e0e7ff 50%, #ede9fe 100%)' }}>
                        {[20, 40, 60, 80].map((p) => <div key={`h${p}`} className="absolute inset-x-0" style={{ top: `${p}%`, borderTop: '1px solid rgba(99,102,241,0.08)' }} />)}
                        {[20, 40, 60, 80].map((p) => <div key={`v${p}`} className="absolute inset-y-0" style={{ left: `${p}%`, borderLeft: '1px solid rgba(99,102,241,0.08)' }} />)}
                        <div className="absolute" style={{ top: '40%', left: 0, right: 0, height: 2, background: 'rgba(255,255,255,0.6)' }} />
                        <div className="absolute" style={{ top: 0, bottom: 0, left: '50%', width: 2, background: 'rgba(255,255,255,0.6)' }} />
                        {map.pins.map((pin, i) => {
                            const enRoute = pin.state === 'en_route';
                            return <div key={i} className="absolute" style={{ top: `${pin.y}%`, left: `${pin.x}%`, transform: 'translate(-50%, -50%)', width: 10, height: 10, background: enRoute ? '#6366f1' : '#22c55e', borderRadius: '50%', border: '2px solid white', boxShadow: `0 0 0 3px ${enRoute ? 'rgba(99,102,241,0.25)' : 'rgba(34,197,94,0.25)'}` }} />;
                        })}
                        <div className="absolute bottom-3 right-3 flex flex-col gap-1.5 rounded-xl px-3 py-2.5 text-xs" style={{ background: 'rgba(255,255,255,0.85)', backdropFilter: 'blur(8px)', boxShadow: '0 2px 12px rgba(0,0,0,0.1)' }}>
                            <div className="flex items-center gap-2" style={{ color: '#4f46e5' }}><span className="h-2 w-2 rounded-full bg-indigo-500" /> En route</div>
                            <div className="flex items-center gap-2" style={{ color: '#16a34a' }}><span className="h-2 w-2 rounded-full bg-green-500" /> In trip</div>
                        </div>
                    </div>
                </div>

                <div className="rounded-2xl p-5" style={shadowCard}>
                    <h2 className="mb-4 text-sm font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>Recent Activity</h2>
                    <div className="flex flex-col gap-3">
                        {activity.map((item) => (
                            <div key={item.id} className="flex items-start gap-3">
                                <div className="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white" style={{ background: activityColors[item.type], fontFamily: 'var(--font-display)' }}>{item.title.charAt(0)}</div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-xs font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{item.title}</p>
                                    <p className="mt-0.5 text-[11px]" style={{ color: '#94a3b8' }}>{item.detail}</p>
                                </div>
                                <span className="flex-shrink-0 text-[10px]" style={{ color: '#cbd5e1', fontFamily: 'var(--font-mono)' }}>{formatRelative(item.occurred_at, new Date(as_of))}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
