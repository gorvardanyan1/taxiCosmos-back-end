import type { ReactNode } from 'react';

export default function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="mb-7 flex items-start justify-between">
            <div>
                <h1 className="text-2xl font-bold tracking-tight" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>
                    {title}
                </h1>
                {description && <p className="mt-1 text-sm" style={{ color: '#94a3b8' }}>{description}</p>}
            </div>
            {actions && <div className="ml-6 flex flex-shrink-0 items-center gap-2">{actions}</div>}
        </div>
    );
}
