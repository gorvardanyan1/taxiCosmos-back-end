import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { parseMoneyInput } from '@/lib/format';
import { t } from '@/lib/i18n';
import { label } from '@/lib/labels';
import { field } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import { useSubmitOnce } from '@/lib/useSubmitOnce';
import type { FareRule, Money, VehicleClass } from '@/types';

const MONEY_FIELDS = ['base_fare', 'per_km', 'per_minute', 'min_fare', 'waiting_per_minute', 'cancellation_fee', 'booking_fee'] as const;
const NUMBER_FIELDS = ['waiting_free_minutes', 'cancellation_free_seconds', 'tax_rate_bps'] as const;

type FormData = Record<(typeof MONEY_FIELDS)[number] | (typeof NUMBER_FIELDS)[number], string> & { vehicle_class: string; tax_inclusive: boolean };

interface Props {
    open: boolean;
    onClose: () => void;
    /** The prices in force for the class being changed, or null to add the first rule of a class. */
    rule: FareRule | null;
    /** Classes that have no rule yet in this zone (the choices when adding). */
    freeClasses: VehicleClass[];
    zoneCurrency: string;
    /** POST /admin/zones/{id}/fare-rules; null when the admin may not change prices. */
    url: string | null;
}

/**
 * Saving never edits the old row: the server closes the current prices and starts a new version from
 * now. The form starts from the prices in force, so only what changes has to be typed.
 */
export default function FareRuleForm({ open, onClose, rule, freeClasses, zoneCurrency, url }: Props) {
    const { currencies } = useShared();
    const decimals = currencies.find((c) => c.code === zoneCurrency)?.decimals ?? 2;
    const major = (m: Money) => String(m.amount / 10 ** decimals);
    const form = useForm<FormData>({} as FormData);
    const once = useSubmitOnce();
    const editing = rule !== null;

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(rule
            ? {
                vehicle_class: rule.vehicle_class, base_fare: major(rule.base_fare), per_km: major(rule.per_km), per_minute: major(rule.per_minute), min_fare: major(rule.min_fare),
                waiting_per_minute: major(rule.waiting_per_minute), cancellation_fee: major(rule.cancellation_fee), booking_fee: major(rule.booking_fee),
                waiting_free_minutes: String(rule.waiting_free_minutes), cancellation_free_seconds: String(rule.cancellation_free_seconds), tax_rate_bps: String(rule.tax_rate_bps), tax_inclusive: rule.tax_inclusive,
            }
            : {
                vehicle_class: freeClasses[0] ?? '', base_fare: '', per_km: '', per_minute: '', min_fare: '', waiting_per_minute: '0', cancellation_fee: '0', booking_fee: '0',
                waiting_free_minutes: '3', cancellation_free_seconds: '120', tax_rate_bps: '0', tax_inclusive: false,
            });
    }, [open, rule?.id]);

    const submit = () => {
        if (!url) return;
        form.clearErrors();
        const minor: Record<string, number> = {};
        let valid = true;
        for (const name of MONEY_FIELDS) {
            const parsed = parseMoneyInput(form.data[name], decimals);
            if (parsed === null) { form.setError(name, t('Enter a valid amount.')); valid = false; } else minor[name] = parsed;
        }
        if (!valid) return;
        form.transform((data) => ({
            vehicle_class: data.vehicle_class, ...minor, tax_inclusive: data.tax_inclusive,
            waiting_free_minutes: Number(data.waiting_free_minutes), cancellation_free_seconds: Number(data.cancellation_free_seconds), tax_rate_bps: Number(data.tax_rate_bps),
        }));
        once((done) => form.post(url, { preserveScroll: true, onSuccess: onClose, onFinish: done }));
    };

    const money = (name: (typeof MONEY_FIELDS)[number], text: string) => (
        <FormField label={`${text} (${zoneCurrency})`} required error={form.errors[name]} htmlFor={`fare-${name}`}>
            <input id={`fare-${name}`} inputMode="decimal" value={form.data[name] ?? ''} onChange={(e) => form.setData(name, e.target.value)} className={`${field} font-mono`} />
        </FormField>
    );
    const number = (name: (typeof NUMBER_FIELDS)[number], text: string) => (
        <FormField label={text} required error={form.errors[name]} htmlFor={`fare-${name}`}>
            <input id={`fare-${name}`} type="number" min={0} value={form.data[name] ?? ''} onChange={(e) => form.setData(name, e.target.value)} className={`${field} font-mono`} />
        </FormField>
    );

    return (
        <Modal
            open={open}
            wide
            onClose={onClose}
            title={editing ? t('New version of :class', { class: label(rule.vehicle_class) }) : t('Add fare rule')}
            footer={
                <>
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>{t('Cancel')}</button>
                    <button onClick={submit} disabled={url === null || form.processing} className="rounded-xl px-4 py-2 text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-40" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>
                        {editing ? t('Save new version') : t('Add fare rule')}
                    </button>
                </>
            }
        >
            <div className="flex max-h-[65vh] flex-col gap-4 overflow-y-auto pr-1">
                <p className="text-xs" style={{ color: '#64748b' }}>
                    {editing
                        ? t('Saving keeps the current prices in the history and starts a new version from now. Trips already priced keep the prices they were charged.')
                        : t('The prices start applying as soon as you save.')}
                </p>
                <FormField label={t('Vehicle class')} required error={form.errors.vehicle_class} htmlFor="fare-vehicle_class">
                    <select id="fare-vehicle_class" value={form.data.vehicle_class ?? ''} disabled={editing} onChange={(e) => form.setData('vehicle_class', e.target.value)} className={`${field} disabled:opacity-60`}>
                        {(editing ? [rule.vehicle_class] : freeClasses).map((c) => <option key={c} value={c}>{label(c)}</option>)}
                    </select>
                </FormField>
                <div className="grid grid-cols-2 gap-3">
                    {money('base_fare', t('Base Fare'))}
                    {money('min_fare', t('Min Fare'))}
                    {money('per_km', t('Per km'))}
                    {money('per_minute', t('Per min'))}
                    {number('waiting_free_minutes', t('Free waiting (minutes)'))}
                    {money('waiting_per_minute', t('Waiting per minute'))}
                    {money('cancellation_fee', t('Cancellation fee'))}
                    {number('cancellation_free_seconds', t('Free cancellation window (seconds)'))}
                    {money('booking_fee', t('Booking fee'))}
                    {number('tax_rate_bps', t('Tax rate (basis points, 1800 = 18%)'))}
                </div>
                <label className="flex items-center gap-2 text-xs font-semibold" style={{ color: '#374151' }}>
                    <input type="checkbox" checked={form.data.tax_inclusive ?? false} onChange={(e) => form.setData('tax_inclusive', e.target.checked)} className="accent-indigo-600" />
                    {t('Prices already include tax')}
                </label>
                {form.errors.tax_inclusive && <p role="alert" className="text-xs font-semibold text-red-600">{form.errors.tax_inclusive}</p>}
            </div>
        </Modal>
    );
}
