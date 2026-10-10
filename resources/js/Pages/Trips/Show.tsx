import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import { useState } from 'react';
import ActionButton from '@/Components/ActionButton';
import ReasonModal from '@/Components/ReasonModal';
import StatusBadge from '@/Components/StatusBadge';
import { formatDateTime } from '@/lib/format';
import { useShared } from '@/lib/useShared';
import type { Actions, TripRow } from '@/types';

interface Props {
    trip: TripRow;
    actions: Actions<'forceCancel' | 'adjustFare' | 'reassignDriver'>;
}

const timeline = [
    { label: 'Requested', desc: 'Rider placed the booking' },
    { label: 'Matched', desc: 'Driver accepted the trip' },
    { label: 'In Progress', desc: 'Driver en route / ride ongoing' },
    { label: 'Completed', desc: 'Trip concluded successfully' },
];

/** How many timeline steps are done for a status (full 7-status timeline from Mongo: P7-T1). */
const doneSteps: Record<string, number> = { requested: 1, matched: 2, arrived: 2, in_progress: 3, completed: 4, cancelled: 2, no_driver_found: 1 };
const tile = { background: 'white', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' } as const;

export default function TripShow({ trip, actions }: Props) {
    const { money, timezone } = useShared();
    const [cancelling, setCancelling] = useState(false);
    const done = doneSteps[trip.status] ?? 1;

    return (
        <div className="mx-auto max-w-3xl">
            <Head title={trip.code} />
            <div className="mb-4"><Link href="/admin/trips" className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-500">← Trips</Link></div>
            <div className="overflow-hidden rounded-2xl" style={{ background: '#f0f2f8' }}>
                <div className="flex items-center gap-3 px-6 py-5" style={{ background: 'white', borderBottom: '1px solid #f1f5f9' }}>
                    <div>
                        <h1 className="text-base font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{trip.code}</h1>
                        <p className="text-xs" style={{ color: '#94a3b8' }}>{formatDateTime(trip.requested_at, timezone)}</p>
                    </div>
                    <div className="ml-auto"><StatusBadge status={trip.status} /></div>
                </div>
                <div className="flex flex-col gap-4 p-5">
                    <div className="relative overflow-hidden rounded-2xl" style={{ height: 160, background: 'linear-gradient(160deg, #eef2ff 0%, #e0e7ff 50%, #ede9fe 100%)' }}>
                        {[25, 50, 75].map((p) => <div key={`h${p}`} className="absolute inset-x-0" style={{ top: `${p}%`, borderTop: '1px solid rgba(99,102,241,0.08)' }} />)}
                        {[25, 50, 75].map((p) => <div key={`v${p}`} className="absolute inset-y-0" style={{ left: `${p}%`, borderLeft: '1px solid rgba(99,102,241,0.08)' }} />)}
                        <div className="absolute bottom-6 left-8 h-3 w-3 rounded-full border-2 border-white" style={{ background: '#6366f1', boxShadow: '0 0 0 4px rgba(99,102,241,0.2)' }} />
                        <div className="absolute right-10 top-5 h-3 w-3 rounded-full border-2 border-white" style={{ background: '#22c55e', boxShadow: '0 0 0 4px rgba(34,197,94,0.2)' }} />
                        <div className="absolute bottom-2 left-2 rounded-lg px-2 py-1 text-[10px]" style={{ background: 'rgba(255,255,255,0.8)', color: '#6366f1' }}>Route preview</div>
                    </div>

                    <div className="grid grid-cols-2 gap-2.5">
                        {([
                            ['Rider', trip.rider], ['Driver', trip.driver ?? '—'],
                            ['Pickup', trip.pickup_address], ['Dropoff', trip.dropoff_address],
                            ['Fare', trip.fare ? money(trip.fare) : '—'], ['Date & Time', formatDateTime(trip.requested_at, timezone)],
                        ] as const).map(([title, value]) => (
                            <div key={title} className="rounded-xl p-3" style={tile}>
                                <p className="mb-0.5 text-[10px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>{title}</p>
                                <p className="text-sm font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{value}</p>
                            </div>
                        ))}
                    </div>

                    <div className="rounded-2xl p-4" style={tile}>
                        <p className="mb-4 text-[10px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>Status Timeline</p>
                        {timeline.map((step, i) => {
                            const isDone = i < done;
                            const isCurrent = i === done - 1;
                            return (
                                <div key={step.label} className="mb-3 flex items-start gap-3 last:mb-0">
                                    <div className="flex flex-shrink-0 flex-col items-center">
                                        {isDone ? <CheckCircle2 size={18} style={{ color: '#6366f1' }} /> : <Circle size={18} style={{ color: '#e2e8f0' }} />}
                                        {i < timeline.length - 1 && <div className="mb-1 mt-1 min-h-[16px] w-px flex-1" style={{ background: isDone ? '#c7d2fe' : '#f1f5f9' }} />}
                                    </div>
                                    <div className="pb-2">
                                        <p className="text-sm font-semibold" style={{ color: isDone ? '#0f172a' : '#cbd5e1', fontFamily: 'var(--font-display)' }}>
                                            {step.label}
                                            {isCurrent && trip.status !== 'cancelled' && <span className="ml-2 rounded-full px-2 py-0.5 text-[10px] font-bold" style={{ background: '#eef2ff', color: '#6366f1' }}>Current</span>}
                                        </p>
                                        <p className="text-xs" style={{ color: isDone ? '#94a3b8' : '#e2e8f0' }}>{step.desc}</p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <div className="rounded-2xl p-4" style={tile}>
                        <p className="mb-3 text-[10px] font-bold uppercase tracking-wider" style={{ color: '#94a3b8', fontFamily: 'var(--font-display)' }}>Admin Actions</p>
                        <div className="flex flex-wrap gap-2">
                            <button onClick={() => setCancelling(true)} className="rounded-xl px-3 py-2 text-xs font-bold transition-opacity hover:opacity-80" style={{ background: '#fef2f2', color: '#dc2626', fontFamily: 'var(--font-display)' }}>Force Cancel</button>
                            <ActionButton url={actions.adjustFare} className="rounded-xl px-3 py-2 text-xs font-bold" style={{ background: '#fffbeb', color: '#b45309', fontFamily: 'var(--font-display)' }}>Adjust Fare</ActionButton>
                            <ActionButton url={actions.reassignDriver} className="rounded-xl px-3 py-2 text-xs font-bold" style={{ background: '#eef2ff', color: '#4f46e5', fontFamily: 'var(--font-display)' }}>Reassign Driver</ActionButton>
                        </div>
                    </div>
                </div>
            </div>
            <ReasonModal
                open={cancelling}
                onClose={() => setCancelling(false)}
                title="Force Cancel Trip"
                description={<>Cancel <strong style={{ color: '#0f172a' }}>{trip.code}</strong> immediately?</>}
                confirmLabel="Confirm"
                placeholder="Reason…"
                url={actions.forceCancel}
            />
        </div>
    );
}
