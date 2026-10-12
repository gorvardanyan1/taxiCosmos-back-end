import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { parseMoneyInput, parsePercentInput } from '@/lib/format';
import { t } from '@/lib/i18n';
import { label } from '@/lib/labels';
import { field } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import { useSubmitOnce } from '@/lib/useSubmitOnce';
import type { CommissionRule, CommissionType, Money, VehicleClass } from '@/types';

interface ZoneChoice {
    id: number;
    name: string;
    currency: string;
}

interface Props {
    open: boolean;
    onClose: () => void;
    /** The rule being changed (its zone and class are fixed), or null to pick any zone and class. */
    rule: CommissionRule | null;
    zones: ZoneChoice[];
    vehicleClasses: VehicleClass[];
    types: CommissionType[];
    /** POST /admin/commission-rules; null when the admin may not change commission. */
    url: string | null;
}

interface FormData {
    zone_id: string;
    vehicle_class: string;
    type: string;
    rate: string;
    fixed_amount: string;
    min_commission: string;
    max_commission: string;
    applies_to_surge: boolean;
}

/**
 * Saving never edits the old row: the server closes the current terms and starts a new version
 * from now. Only the terms the chosen type uses are shown (a fixed rule has no rate).
 */
export default function CommissionRuleForm({ open, onClose, rule, zones, vehicleClasses, types, url }: Props) {
    const { currencies } = useShared();
    const form = useForm<FormData>({} as FormData);
    const once = useSubmitOnce();
    const editing = rule !== null;
    const zone = zones.find((z) => String(z.id) === form.data.zone_id);
    const decimalsOf = (currency: string | undefined) => currencies.find((c) => c.code === currency)?.decimals ?? 2;
    const decimals = decimalsOf(zone?.currency);
    const type = (form.data.type || 'percentage') as CommissionType;
    const usesRate = type !== 'fixed';
    const usesFixed = type !== 'percentage';

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        // The amounts are shown in the rule's own currency, before the form's zone is set.
        const major = (m: Money | null) => (m === null ? '' : String(m.amount / 10 ** decimalsOf(m.currency)));
        form.setData(rule
            ? {
                zone_id: String(rule.zone_id), vehicle_class: rule.vehicle_class ?? '', type: rule.type, rate: rule.rate_bp === null ? '' : String(rule.rate_bp / 100),
                fixed_amount: major(rule.fixed_fee), min_commission: major(rule.minimum), max_commission: major(rule.maximum), applies_to_surge: rule.applies_to_surge,
            }
            : { zone_id: zones[0] ? String(zones[0].id) : '', vehicle_class: '', type: 'percentage', rate: '', fixed_amount: '', min_commission: '', max_commission: '', applies_to_surge: true });
    }, [open, rule?.id]);

    const submit = () => {
        if (!url) return;
        form.clearErrors();
        let valid = true;
        const reject = (name: keyof FormData, message: string) => { form.setError(name, message); valid = false; };
        const rate = usesRate ? parsePercentInput(form.data.rate) : 0;
        const fixed = usesFixed ? parseMoneyInput(form.data.fixed_amount, decimals) : 0;
        const minimum = form.data.min_commission.trim() === '' ? 0 : parseMoneyInput(form.data.min_commission, decimals);
        const maximum = form.data.max_commission.trim() === '' ? null : parseMoneyInput(form.data.max_commission, decimals);
        if (rate === null) reject('rate', t('Enter a percentage from 0 to 100, like 18 or 17.5.'));
        if (fixed === null) reject('fixed_amount', t('Enter a valid amount.'));
        if (minimum === null) reject('min_commission', t('Enter a valid amount.'));
        if (form.data.max_commission.trim() !== '' && maximum === null) reject('max_commission', t('Enter a valid amount.'));
        if (!valid) return;
        form.transform((data) => ({
            zone_id: Number(data.zone_id), vehicle_class: data.vehicle_class === '' ? null : data.vehicle_class, type: data.type,
            rate_bps: rate, fixed_amount: fixed, min_commission: minimum, max_commission: maximum, applies_to_surge: data.applies_to_surge,
        }));
        once((done) => form.post(url, { preserveScroll: true, onSuccess: onClose, onFinish: done }));
    };

    const currencySuffix = zone ? ` (${zone.currency})` : '';

    return (
        <Modal
            open={open}
            wide
            onClose={onClose}
            title={editing ? t('New version of the :zone rule', { zone: rule.zone }) : t('New commission rule')}
            footer={
                <>
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>{t('Cancel')}</button>
                    <button onClick={submit} disabled={url === null || form.processing || !zone} className="rounded-xl px-4 py-2 text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-40" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>
                        {t('Save new version')}
                    </button>
                </>
            }
        >
            <div className="flex max-h-[65vh] flex-col gap-4 overflow-y-auto pr-1">
                <p className="text-xs" style={{ color: '#64748b' }}>{t('Saving keeps the current terms in the history and starts a new version from now. Trips already completed keep the commission they were charged.')}</p>
                <div className="grid grid-cols-2 gap-3">
                    <FormField label={t('Zone')} required error={form.errors.zone_id} htmlFor="commission-zone">
                        <select id="commission-zone" value={form.data.zone_id ?? ''} disabled={editing} onChange={(e) => form.setData('zone_id', e.target.value)} className={`${field} disabled:opacity-60`}>
                            {zones.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('Vehicle class')} error={form.errors.vehicle_class} htmlFor="commission-class">
                        <select id="commission-class" value={form.data.vehicle_class ?? ''} disabled={editing} onChange={(e) => form.setData('vehicle_class', e.target.value)} className={`${field} disabled:opacity-60`}>
                            <option value="">{t('Every class')}</option>
                            {vehicleClasses.map((c) => <option key={c} value={c}>{label(c)}</option>)}
                        </select>
                    </FormField>
                </div>
                <FormField label={t('Type')} required error={form.errors.type} htmlFor="commission-type">
                    <select id="commission-type" value={form.data.type ?? 'percentage'} onChange={(e) => form.setData('type', e.target.value)} className={field}>
                        {types.map((c) => <option key={c} value={c}>{label(c)}</option>)}
                    </select>
                </FormField>
                <div className="grid grid-cols-2 gap-3">
                    {usesRate && (
                        <FormField label={t('Rate (%)')} required error={form.errors.rate ?? (form.errors as Record<string, string>).rate_bps} htmlFor="commission-rate">
                            <input id="commission-rate" inputMode="decimal" value={form.data.rate ?? ''} onChange={(e) => form.setData('rate', e.target.value)} className={`${field} font-mono`} />
                        </FormField>
                    )}
                    {usesFixed && (
                        <FormField label={`${t('Fixed amount')}${currencySuffix}`} required error={form.errors.fixed_amount} htmlFor="commission-fixed">
                            <input id="commission-fixed" inputMode="decimal" value={form.data.fixed_amount ?? ''} onChange={(e) => form.setData('fixed_amount', e.target.value)} className={`${field} font-mono`} />
                        </FormField>
                    )}
                    <FormField label={`${t('Minimum commission')}${currencySuffix}`} error={form.errors.min_commission} htmlFor="commission-min">
                        <input id="commission-min" inputMode="decimal" value={form.data.min_commission ?? ''} onChange={(e) => form.setData('min_commission', e.target.value)} className={`${field} font-mono`} />
                    </FormField>
                    <FormField label={`${t('Maximum commission')}${currencySuffix}`} error={form.errors.max_commission} htmlFor="commission-max">
                        <input id="commission-max" inputMode="decimal" value={form.data.max_commission ?? ''} onChange={(e) => form.setData('max_commission', e.target.value)} placeholder={t('No limit')} className={`${field} font-mono`} />
                    </FormField>
                </div>
                <label className="flex items-center gap-2 text-xs font-semibold" style={{ color: '#374151' }}>
                    <input type="checkbox" checked={form.data.applies_to_surge ?? true} onChange={(e) => form.setData('applies_to_surge', e.target.checked)} className="accent-indigo-600" />
                    {t('Commission applies to the surge part of the fare')}
                </label>
            </div>
        </Modal>
    );
}
