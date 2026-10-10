import { useForm } from '@inertiajs/react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import { field } from '@/lib/ui';

interface Props {
    open: boolean;
    onClose: () => void;
    title: string;
    description: React.ReactNode;
    confirmLabel: string;
    placeholder: string;
    /** Endpoint for the action; null until the owning domain task builds it. */
    url: string | null;
    tone?: 'danger' | 'primary';
}

/**
 * A destructive action that requires a reason: submit is blocked while the reason is empty,
 * and server validation errors show next to the field (useForm).
 */
export default function ReasonModal({ open, onClose, title, description, confirmLabel, placeholder, url, tone = 'danger' }: Props) {
    const form = useForm({ reason: '' });
    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };
    const submit = () => url && form.post(url, { preserveScroll: true, onSuccess: close });

    return (
        <Modal
            open={open}
            onClose={close}
            title={title}
            danger={tone === 'danger'}
            footer={
                <>
                    <button onClick={close} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>Cancel</button>
                    <button
                        onClick={submit}
                        disabled={url === null || !form.data.reason.trim() || form.processing}
                        title={url === null ? UNAVAILABLE_HINT : undefined}
                        className="rounded-xl px-4 py-2 text-sm font-semibold transition-all hover:opacity-90 disabled:opacity-40"
                        style={{ background: tone === 'danger' ? 'linear-gradient(135deg, #ef4444, #dc2626)' : 'linear-gradient(135deg, #6366f1, #8b5cf6)', color: 'white', fontFamily: 'var(--font-display)' }}
                    >
                        {confirmLabel}
                    </button>
                </>
            }
        >
            <div className="mb-4 text-sm" style={{ color: '#64748b' }}>{description}</div>
            <FormField label="Reason" required error={form.errors.reason} htmlFor="reason">
                <textarea id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={3} placeholder={placeholder} className={`${field} resize-none`} />
            </FormField>
        </Modal>
    );
}
