import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { field } from '@/lib/ui';
import type { RiderDetail } from '@/types';

interface Props {
    open: boolean;
    onClose: () => void;
    rider: RiderDetail;
    locales: string[];
    /** PATCH /admin/riders/{id} */
    url: string | null;
}

interface FormData {
    name: string;
    email: string;
    phone: string;
    locale: string;
    reason: string;
}

const localeNames: Record<string, string> = { en: 'English', hy: 'Հայերեն', ru: 'Русский' };

const initial = (rider: RiderDetail): FormData => ({
    name: rider.raw.name ?? '', email: rider.raw.email ?? '', phone: rider.raw.phone ?? '', locale: rider.raw.locale ?? '', reason: '',
});

/**
 * Edit a rider's contact details. Only the fields that changed are sent, together with the
 * reason (required, goes to the audit log); server validation errors show next to each field.
 */
export default function EditRider({ open, onClose, rider, locales, url }: Props) {
    const form = useForm<FormData>(initial(rider));

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(initial(rider));
    }, [open, rider.id]);

    const original = initial(rider);
    const changed = (['name', 'email', 'phone', 'locale'] as const).filter((key) => form.data[key] !== original[key]);

    const submit = () => {
        if (!url) return;
        form.transform((data) => ({
            ...Object.fromEntries(changed.map((key) => [key, key === 'email' || key === 'locale' ? (data[key] === '' ? null : data[key]) : data[key]])),
            reason: data.reason,
        }));
        form.patch(url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`Edit ${rider.name}`}
            footer={
                <>
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>Cancel</button>
                    <button
                        onClick={submit}
                        disabled={url === null || form.processing || changed.length === 0 || !form.data.reason.trim()}
                        className="rounded-xl px-4 py-2 text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-40"
                        style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}
                    >
                        Save changes
                    </button>
                </>
            }
        >
            <div className="flex max-h-[65vh] flex-col gap-4 overflow-y-auto pr-1">
                <FormField label="Name" error={form.errors.name} htmlFor="rider-name">
                    <input id="rider-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={field} />
                </FormField>
                <FormField label="Email" error={form.errors.email} htmlFor="rider-email">
                    <input id="rider-email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={field} />
                </FormField>
                <FormField label="Phone" error={form.errors.phone} htmlFor="rider-phone">
                    <input id="rider-phone" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className={`${field} font-mono`} />
                </FormField>
                <FormField label="Language" error={form.errors.locale} htmlFor="rider-locale">
                    <select id="rider-locale" value={form.data.locale} onChange={(e) => form.setData('locale', e.target.value)} className={field}>
                        <option value="">Not set</option>
                        {locales.map((code) => <option key={code} value={code}>{localeNames[code] ?? code}</option>)}
                    </select>
                </FormField>
                <FormField label="Reason for the change" required error={form.errors.reason} htmlFor="rider-reason">
                    <textarea id="rider-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={3} placeholder="e.g. Rider asked to correct their phone number" className={`${field} resize-none`} />
                </FormField>
            </div>
        </Modal>
    );
}
