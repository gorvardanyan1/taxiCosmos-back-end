import { FileText } from 'lucide-react';

export default function EmptyState({ title = 'No records yet', hint = 'New records will appear here.' }: { title?: string; hint?: string }) {
    return (
        <div className="flex flex-col items-center gap-2 py-16 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl" style={{ background: '#f8fafc' }}>
                <FileText size={20} style={{ color: '#cbd5e1' }} />
            </div>
            <p className="text-sm font-semibold" style={{ color: '#94a3b8' }}>{title}</p>
            <p className="text-xs" style={{ color: '#cbd5e1' }}>{hint}</p>
        </div>
    );
}
