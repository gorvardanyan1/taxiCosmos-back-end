import { X } from 'lucide-react';
import type { ReactNode } from 'react';

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
    footer?: ReactNode;
    danger?: boolean;
}

export default function Modal({ open, onClose, title, children, footer, danger }: ModalProps) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center" role="dialog" aria-modal="true" aria-label={title}>
            <div className="absolute inset-0" style={{ background: 'rgba(15,23,42,0.5)', backdropFilter: 'blur(4px)' }} onClick={onClose} />
            <div className="relative z-10 mx-4 w-full overflow-hidden rounded-2xl" style={{ maxWidth: 440, background: 'white', boxShadow: '0 25px 60px rgba(0,0,0,0.2)' }}>
                <div
                    className="flex items-center justify-between px-6 py-4"
                    style={{ borderBottom: '1px solid #f1f5f9', background: danger ? 'linear-gradient(135deg, #fff1f2 0%, #fff5f5 100%)' : 'white' }}
                >
                    <h3 className="text-base font-bold" style={{ color: danger ? '#dc2626' : '#0f172a', fontFamily: 'var(--font-display)' }}>
                        {title}
                    </h3>
                    <button onClick={onClose} aria-label="Close" className="rounded-lg p-1.5 transition-colors hover:bg-slate-100" style={{ color: '#94a3b8' }}>
                        <X size={15} />
                    </button>
                </div>
                <div className="px-6 py-5">{children}</div>
                {footer && (
                    <div className="flex justify-end gap-2 px-6 py-4" style={{ background: '#f8fafc', borderTop: '1px solid #f1f5f9' }}>
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}
