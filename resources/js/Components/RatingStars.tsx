import { Star } from 'lucide-react';

export default function RatingStars({ rating }: { rating: string | null }) {
    if (rating === null) return <span style={{ color: '#cbd5e1', fontSize: 12 }}>—</span>;
    const value = Number(rating);
    return (
        <div className="flex items-center gap-1.5">
            <div className="flex">
                {[1, 2, 3, 4, 5].map((s) => <Star key={s} size={11} className={s <= Math.round(value) ? 'fill-amber-400 text-amber-400' : 'text-slate-200'} />)}
            </div>
            <span className="text-xs font-bold" style={{ color: '#0f172a', fontFamily: 'var(--font-mono)' }}>{rating}</span>
        </div>
    );
}
