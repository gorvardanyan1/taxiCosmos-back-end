import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { field } from '@/lib/ui';
import { useSubmitOnce } from '@/lib/useSubmitOnce';
import type { DriverDetail } from '@/types';
import { t } from '@/lib/i18n';

interface Props {
    open: boolean;
    onClose: () => void;
    driver: DriverDetail;
    zones: { id: number; name: string }[];
    /** PATCH /admin/drivers/{id}; null without the permission. */
    url: string | null;
}

interface FormData {
    name: string;
    email: string;
    phone: string;
    license_number: string;
    license_expiry: string;
    home_zone_id: string;
    reason: string;
}

const initial = (driver: DriverDetail): FormData => ({
    name: driver.raw.name ?? '', email: driver.raw.email ?? '', phone: driver.raw.phone ?? '',
    license_number: '', license_expiry: driver.raw.license_expiry ?? '', home_zone_id: driver.raw.home_zone_id === null ? '' : String(driver.raw.home_zone_id), reason: '',
});

/**
 * Edit a driver's contact and licence details. Only the fields that changed are sent, together with the
 * reason (required, goes to the audit log); the licence number is never shown, so leaving it empty keeps it.
 * Server validation errors show next to each field.
 */
export default function EditDriver({ open, onClose, driver, zones, url }: Props) {
    const form = useForm<FormData>(initial(driver));
    const once = useSubmitOnce();

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(initial(driver));
    }, [open, driver.id]);

    const original = initial(driver);
    const changed = (['name', 'email', 'phone', 'license_number', 'license_expiry', 'home_zone_id'] as const).filter((key) => form.data[key] !== original[key]);

    const submit = () => {
        if (!url) return;
        form.transform((data) => ({
            ...Object.fromEntries(changed.map((key) => [key, key === 'email' ? (data[key] === '' ? null : data[key]) : key === 'home_zone_id' ? (data[key] === '' ? null : Number(data[key])) : data[key]])),
            reason: data.reason,
        }));
        once((done) => form.patch(url, { preserveScroll: true, onSuccess: onClose, onFinish: done }));
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={t('Edit :name', { name: driver.name })}
            footer={
                <>
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>{t('Cancel')}</button>
                    <button
                        onClick={submit}
                        disabled={url === null || form.processing || changed.length === 0 || !form.data.reason.trim()}
                        className="rounded-xl px-4 py-2 text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-40"
                        style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}
                    >
                        {t('Save changes')}
                    </button>
                </>
            }
        >
            <div className="flex max-h-[65vh] flex-col gap-4 overflow-y-auto pr-1">
                <FormField label={t('Name')} error={form.errors.name} htmlFor="driver-name">
                    <input id="driver-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={field} />
                </FormField>
                <FormField label={t('Email')} error={form.errors.email} htmlFor="driver-email">
                    <input id="driver-email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={field} />
                </FormField>
                <FormField label={t('Phone')} error={form.errors.phone} htmlFor="driver-phone">
                    <input id="driver-phone" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className={`${field} font-mono`} />
                </FormField>
                <FormField label={t('License number')} error={form.errors.license_number} htmlFor="driver-license">
                    <input id="driver-license" value={form.data.license_number} onChange={(e) => form.setData('license_number', e.target.value)} placeholder={driver.license_last4 ? t('Current: •••• :last4 (leave empty to keep it)', { last4: driver.license_last4 }) : ''} className={`${field} font-mono`} />
                </FormField>
                <div className="grid grid-cols-2 gap-3">
                    <FormField label={t('License expiry')} error={form.errors.license_expiry} htmlFor="driver-license-expiry">
                        <input id="driver-license-expiry" type="date" value={form.data.license_expiry} onChange={(e) => form.setData('license_expiry', e.target.value)} className={field} />
                    </FormField>
                    <FormField label={t('Home zone')} error={form.errors.home_zone_id} htmlFor="driver-zone">
                        <select id="driver-zone" value={form.data.home_zone_id} onChange={(e) => form.setData('home_zone_id', e.target.value)} className={field}>
                            <option value="">{t('Not set')}</option>
                            {zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}
                        </select>
                    </FormField>
                </div>
                <FormField label={t('Reason for the change')} required error={form.errors.reason} htmlFor="driver-reason">
                    <textarea id="driver-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={3} placeholder={t('e.g. Driver renewed their license')} className={`${field} resize-none`} />
                </FormField>
            </div>
        </Modal>
    );
}
