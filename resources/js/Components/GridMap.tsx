import { Car } from 'lucide-react';

const availabilityColor: Record<string, string> = { online: 'bg-emerald-500', on_trip: 'bg-blue-500', offline: 'bg-slate-400' };

/** Placeholder map from the template (real map component: P13-T7). */
export default function GridMap({ markers, route, caption }: { markers: { driver_id: number; x: number; y: number; availability: string }[]; route?: string; caption: string }) {
    return (
        <div className="relative h-full min-h-[520px] overflow-hidden rounded-2xl bg-[#e9eef5]">
            <div className="absolute inset-0 opacity-70" style={{ backgroundImage: 'linear-gradient(30deg, transparent 48%, #d7dee9 49%, #d7dee9 51%, transparent 52%), linear-gradient(110deg, transparent 48%, #d7dee9 49%, #d7dee9 51%, transparent 52%)', backgroundSize: '90px 76px' }} />
            {route && <svg className="absolute inset-0 h-full w-full"><path d={route} fill="none" stroke="#6366f1" strokeWidth="4" strokeDasharray="7 7" opacity=".55" /></svg>}
            {markers.map((marker) => (
                <span key={marker.driver_id} className={`absolute grid size-8 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border-[3px] border-white ${availabilityColor[marker.availability] ?? 'bg-slate-400'} text-white shadow-lg`} style={{ left: `${marker.x}%`, top: `${marker.y}%` }}>
                    <Car size={12} />
                </span>
            ))}
            <div className="absolute bottom-3 right-3 rounded-xl bg-white/90 px-3 py-2 text-[11px] font-semibold text-slate-500 shadow">{caption}</div>
        </div>
    );
}
