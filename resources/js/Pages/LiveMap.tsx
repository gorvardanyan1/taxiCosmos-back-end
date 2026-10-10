import { Head, Link } from '@inertiajs/react';
import { Radio } from 'lucide-react';
import GridMap from '@/Components/GridMap';
import PageHeader from '@/Components/PageHeader';
import { formatNumber, formatRelative, initials } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery } from '@/lib/query';
import { card } from '@/lib/ui';
import type { NamedOption } from '@/types';

interface Props {
    zones: NamedOption[];
    stats: { online: number; on_trip: number; waiting: number };
    updated_at: string;
    markers: { driver_id: number; x: number; y: number; availability: string }[];
    route: string;
    focused_driver: { id: number; name: string; vehicle: string; plate: string; rating: string; trip_code: string } | null;
    filters: { zone: number | null; vehicle_class: string | null; availability: string | null };
}

const selectClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-600';

export default function LiveMap({ zones, stats, updated_at, markers, route, focused_driver, filters }: Props) {
    return (
        <div className="h-full">
            <Head title="Live Map" />
            <PageHeader title="Live Map" description="Real-time driver availability and active trips" />
            <div className="relative min-h-[calc(100vh-160px)]">
                <GridMap markers={markers} route={route} caption={`Updated ${formatRelative(updated_at)}`} />
                <div className={`absolute left-4 top-4 w-72 ${card} p-4`}>
                    <div className="mb-4 flex items-center justify-between">
                        <p className="font-display text-sm font-bold text-slate-900">Map controls</p>
                        <Radio size={14} className="text-emerald-500" />
                    </div>
                    <div className="space-y-2">
                        <select aria-label="Zone" value={filters.zone ?? ''} onChange={(e) => visitQuery({ zone: e.target.value })} className={selectClass}>
                            <option value="">All zones</option>
                            {zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}
                        </select>
                        <select aria-label="Vehicle class" value={filters.vehicle_class ?? ''} onChange={(e) => visitQuery({ vehicle_class: e.target.value })} className={selectClass}>
                            <option value="">All vehicle classes</option>
                            {['economy', 'comfort', 'business'].map((value) => <option key={value} value={value}>{label(value)}</option>)}
                        </select>
                        <select aria-label="Status" value={filters.availability ?? ''} onChange={(e) => visitQuery({ availability: e.target.value })} className={selectClass}>
                            <option value="">All statuses</option>
                            {['online', 'on_trip', 'offline'].map((value) => <option key={value} value={value}>{label(value)}</option>)}
                        </select>
                    </div>
                    <div className="mt-4 grid grid-cols-3 gap-2 text-center">
                        {([[stats.online, 'Online'], [stats.on_trip, 'On trip'], [stats.waiting, 'Waiting']] as const).map(([value, text]) => (
                            <div key={text} className="rounded-xl bg-slate-50 p-2"><p className="font-mono text-sm font-bold text-slate-900">{formatNumber(value)}</p><p className="text-[9px] text-slate-400">{text}</p></div>
                        ))}
                    </div>
                    {focused_driver && (
                        <div className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3">
                            <div className="flex items-center gap-3">
                                <span className="grid size-10 place-items-center rounded-xl bg-indigo-500 text-xs font-bold text-white">{initials(focused_driver.name)}</span>
                                <div><p className="text-xs font-bold text-slate-900">{focused_driver.name}</p><p className="text-[10px] text-slate-500">{focused_driver.vehicle} · {focused_driver.plate}</p></div>
                            </div>
                            <div className="mt-3 flex items-center justify-between text-[10px] text-slate-500">
                                <span>★ {focused_driver.rating} · {focused_driver.trip_code}</span>
                                <Link href={`/admin/drivers/${focused_driver.id}`} className="font-bold text-indigo-600">Open driver</Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
