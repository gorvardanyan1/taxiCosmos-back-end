import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import Sidebar from '@/Components/Sidebar';
import Toast, { type ToastData } from '@/Components/Toast';
import TopBar from '@/Components/TopBar';

/**
 * Persistent admin shell (Sidebar + TopBar + toasts). Laravel flash messages
 * (session 'success' / 'error') become toasts after every visit.
 */
export default function AdminLayout({ children }: { children: ReactNode }) {
    const { flash } = usePage().props;
    const [collapsed, setCollapsed] = useState(false);
    const [toasts, setToasts] = useState<ToastData[]>([]);
    const sequence = useRef(0);

    useEffect(() => {
        const next: ToastData[] = [];
        if (flash.success) next.push({ id: ++sequence.current, type: 'success', message: flash.success });
        if (flash.error) next.push({ id: ++sequence.current, type: 'error', message: flash.error });
        if (next.length) setToasts((current) => [...current, ...next]);
    }, [flash]);

    const removeToast = useCallback((id: number) => setToasts((current) => current.filter((toast) => toast.id !== id)), []);

    return (
        <div className="flex h-full overflow-hidden">
            <Sidebar collapsed={collapsed} />
            <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                <TopBar onToggleSidebar={() => setCollapsed(!collapsed)} />
                <main className="flex-1 overflow-y-auto p-6">{children}</main>
            </div>
            <Toast toasts={toasts} onRemove={removeToast} />
        </div>
    );
}
