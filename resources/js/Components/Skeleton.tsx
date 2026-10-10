export default function Skeleton({ rows = 5 }: { rows?: number }) {
    return (
        <div aria-busy="true" aria-label="Loading">
            {Array.from({ length: rows }).map((_, i) => (
                <div key={i} className="mb-3 flex animate-pulse gap-3">
                    <div className="size-9 rounded-xl bg-slate-100" />
                    <div className="flex-1">
                        <div className="h-3 w-1/3 rounded bg-slate-100" />
                        <div className="mt-2 h-2 w-2/3 rounded bg-slate-100" />
                    </div>
                </div>
            ))}
        </div>
    );
}
