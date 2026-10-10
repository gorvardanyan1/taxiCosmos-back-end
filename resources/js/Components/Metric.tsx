import type { ReactNode } from 'react';

export default function Metric({ label, value, tone, icon }: { label: string; value: ReactNode; tone: string; icon: ReactNode }) {
    return (
        <div className={`relative overflow-hidden rounded-2xl bg-gradient-to-br ${tone} p-4 text-white shadow-lg`}>
            <div className="mb-4 grid size-8 place-items-center rounded-lg bg-white/15">{icon}</div>
            <p className="font-display text-xl font-bold">{value}</p>
            <p className="mt-1 text-xs text-white/65">{label}</p>
        </div>
    );
}
