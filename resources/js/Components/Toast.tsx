import { CheckCircle2, X, XCircle } from 'lucide-react';
import { useEffect } from 'react';

export interface ToastData {
    id: number;
    type: 'success' | 'error';
    message: string;
}

function ToastItem({ toast, onRemove }: { toast: ToastData; onRemove: (id: number) => void }) {
    useEffect(() => {
        const timer = setTimeout(() => onRemove(toast.id), 4000);
        return () => clearTimeout(timer);
    }, [toast.id, onRemove]);

    const isSuccess = toast.type === 'success';
    return (
        <div
            role="status"
            className="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold"
            style={{
                background: isSuccess ? 'linear-gradient(135deg, #059669, #047857)' : 'linear-gradient(135deg, #dc2626, #b91c1c)',
                color: 'white',
                boxShadow: `0 8px 30px ${isSuccess ? 'rgba(5,150,105,0.35)' : 'rgba(220,38,38,0.35)'}`,
                fontFamily: 'var(--font-display)',
                minWidth: 280,
            }}
        >
            {isSuccess ? <CheckCircle2 size={16} /> : <XCircle size={16} />}
            <span className="flex-1">{toast.message}</span>
            <button onClick={() => onRemove(toast.id)} aria-label="Dismiss" className="opacity-70 transition-opacity hover:opacity-100">
                <X size={14} />
            </button>
        </div>
    );
}

export default function Toast({ toasts, onRemove }: { toasts: ToastData[]; onRemove: (id: number) => void }) {
    return (
        <div className="fixed bottom-6 right-6 z-[100] flex flex-col gap-2">
            {toasts.map((toast) => <ToastItem key={toast.id} toast={toast} onRemove={onRemove} />)}
        </div>
    );
}
