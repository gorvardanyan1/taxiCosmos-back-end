import { useForm } from '@inertiajs/react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import { field } from '@/lib/ui';
import { useSubmitOnce } from '@/lib/useSubmitOnce';
import { t } from '@/lib/i18n';

interface Props {
    open: boolean;
    onClose: () => void;
    title: string;
    description: React.ReactNode;
    confirmLabel: string;
    /** Endpoint for the action; null when the server does not offer it. */
    url: string | null;
    method?: 'post' | 'delete';
    tone?: 'danger' | 'primary';
    /** Also ask why (an action that goes to the audit log with a reason). */
    reasonPlaceholder?: string;
}

/**
 * A sensitive action behind the admin's own password (and, when `reasonPlaceholder` is given, a reason). Submit stays off until
 * everything asked for is filled in; the server's errors ("The password is incorrect.") show next to the field.
 */
export default function PasswordConfirmModal({ open, onClose, title, description, confirmLabel, url, method = 'post', tone = 'primary', reasonPlaceholder }: Props) {
    const form = useForm({ password: '', reason: '' });
    const once = useSubmitOnce();
    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };
    const asksReason = reasonPlaceholder !== undefined;
    const ready = form.data.password !== '' && (!asksReason || form.data.reason.trim() !== '');
    const submit = () => url && once((done) => {
        // Only what was asked for goes to the server (a password-only action sends no empty reason).
        form.transform((data) => (asksReason ? data : { password: data.password }));
        form.submit(method, url, {
            preserveScroll: true,
            onSuccess: close,
            // A wrong password is typed again, never kept on the page.
            onError: () => form.reset('password'),
            onFinish: done,
        });
    });

    return (
        <Modal
            open={open}
            onClose={close}
            title={title}
            danger={tone === 'danger'}
            footer={
                <>
                    <button onClick={close} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>{t('Cancel')}</button>
                    <button
                        onClick={submit}
                        disabled={url === null || !ready || form.processing}
                        title={url === null ? t(UNAVAILABLE_HINT) : undefined}
                        className="rounded-xl px-4 py-2 text-sm font-semibold transition-all hover:opacity-90 disabled:opacity-40"
                        style={{ background: tone === 'danger' ? 'linear-gradient(135deg, #ef4444, #dc2626)' : 'linear-gradient(135deg, #6366f1, #8b5cf6)', color: 'white', fontFamily: 'var(--font-display)' }}
                    >
                        {confirmLabel}
                    </button>
                </>
            }
        >
            <div className="mb-4 text-sm" style={{ color: '#64748b' }}>{description}</div>
            <FormField label={t('Current password')} required error={form.errors.password} htmlFor="confirm-password">
                <input id="confirm-password" type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} className={field} />
            </FormField>
            {asksReason && (
                <div className="mt-4">
                    <FormField label={t('Reason')} required error={form.errors.reason} htmlFor="confirm-reason">
                        <textarea id="confirm-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={3} placeholder={reasonPlaceholder} className={`${field} resize-none`} />
                    </FormField>
                </div>
            )}
        </Modal>
    );
}
