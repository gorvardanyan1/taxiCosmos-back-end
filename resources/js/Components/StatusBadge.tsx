import { label as humanLabel } from '@/lib/labels';

type Tone = { dot: string; bg: string; color: string };

const green: Tone = { dot: '#22c55e', bg: '#f0fdf4', color: '#15803d' };
const amber: Tone = { dot: '#f59e0b', bg: '#fffbeb', color: '#b45309' };
const grey: Tone = { dot: '#94a3b8', bg: '#f8fafc', color: '#64748b' };
const red: Tone = { dot: '#ef4444', bg: '#fef2f2', color: '#dc2626' };
const blue: Tone = { dot: '#3b82f6', bg: '#eff6ff', color: '#1d4ed8' };
const violet: Tone = { dot: '#8b5cf6', bg: '#f5f3ff', color: '#7c3aed' };

const tones: Record<string, Tone> = {
    active: green, verified: green, approved: green, completed: green, resolved: green, paid: green, won: green, ready: green,
    pending: amber, open: amber, requested: amber, waiting: amber, pending_review: amber, pending_deletion: amber,
    inactive: grey, deactivated: grey, offline: grey,
    suspended: red, cancelled: red, failed: red, rejected: red, expired: red, lost: red, no_driver_found: red,
    processing: blue, in_progress: blue, matched: blue, arrived: blue, on_trip: blue, online: green,
    review: violet,
};

const labels: Record<string, string> = { review: 'Under review', in_progress: 'In progress' };

export default function StatusBadge({ status, label }: { status: string; label?: string }) {
    const tone = tones[status] ?? grey;
    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
            style={{ background: tone.bg, color: tone.color, fontFamily: 'var(--font-display)' }}
        >
            <span className="h-1.5 w-1.5 flex-shrink-0 rounded-full" style={{ background: tone.dot, boxShadow: `0 0 0 2px ${tone.dot}33` }} />
            {label ?? labels[status] ?? humanLabel(status)}
        </span>
    );
}
