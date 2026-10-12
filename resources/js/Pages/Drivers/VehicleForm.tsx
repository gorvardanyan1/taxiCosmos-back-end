import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { label } from '@/lib/labels';
import { field } from '@/lib/ui';
import { useSubmitOnce } from '@/lib/useSubmitOnce';
import type { Vehicle, VehicleClass } from '@/types';
import { t } from '@/lib/i18n';

interface Props {
    open: boolean;
    onClose: () => void;
    /** The vehicle being edited, or null to add one. */
    vehicle: Vehicle | null;
    vehicleClasses: VehicleClass[];
    /** POST /admin/drivers/{id}/vehicles to add, PATCH /admin/drivers/{id}/vehicles/{vehicleId} to edit; null without the permission. */
    url: string | null;
}

interface FormData {
    make: string;
    model: string;
    year: string;
    color: string;
    plate_number: string;
    vehicle_class: string;
    status: string;
}

const STATUSES = ['active', 'inactive', 'pending_review'] as const;

const empty = (vehicleClasses: VehicleClass[]): FormData => ({ make: '', model: '', year: String(new Date().getFullYear()), color: '', plate_number: '', vehicle_class: vehicleClasses[0] ?? 'economy', status: 'active' });

const initial = (vehicle: Vehicle): FormData => ({
    make: vehicle.make, model: vehicle.model, year: String(vehicle.year), color: vehicle.color, plate_number: vehicle.plate_number, vehicle_class: vehicle.vehicle_class, status: vehicle.status,
});

/**
 * Add or edit a vehicle. A vehicle an admin adds is active (and primary when the driver has none); an
 * edit sends only what changed. The server validates (a plate belongs to one vehicle) and its error shows under the field.
 */
export default function VehicleForm({ open, onClose, vehicle, vehicleClasses, url }: Props) {
    const form = useForm<FormData>(empty(vehicleClasses));
    const once = useSubmitOnce();
    const editing = vehicle !== null;

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(vehicle ? initial(vehicle) : empty(vehicleClasses));
    }, [open, vehicle?.id]);

    const submit = () => {
        if (!url) return;
        const original = vehicle ? initial(vehicle) : null;
        form.transform((data) => {
            const keys = (Object.keys(data) as (keyof FormData)[]).filter((key) => original === null || data[key] !== original[key]);

            return Object.fromEntries(keys.map((key) => [key, key === 'year' ? Number(data[key]) : data[key]]));
        });
        once((done) => {
            const options = { preserveScroll: true, onSuccess: onClose, onFinish: done };
            editing ? form.patch(url, options) : form.post(url, options);
        });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? t('Edit :vehicle', { vehicle: `${vehicle.make} ${vehicle.model}` }) : t('Add vehicle')}
            footer={
                <>
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>{t('Cancel')}</button>
                    <button onClick={submit} disabled={url === null || form.processing} className="rounded-xl px-4 py-2 text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-40" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>
                        {editing ? t('Save changes') : t('Add vehicle')}
                    </button>
                </>
            }
        >
            <div className="flex max-h-[65vh] flex-col gap-4 overflow-y-auto pr-1">
                <div className="grid grid-cols-2 gap-3">
                    <FormField label={t('Make')} required error={form.errors.make} htmlFor="vehicle-make">
                        <input id="vehicle-make" value={form.data.make} onChange={(e) => form.setData('make', e.target.value)} className={field} />
                    </FormField>
                    <FormField label={t('Model')} required error={form.errors.model} htmlFor="vehicle-model">
                        <input id="vehicle-model" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} className={field} />
                    </FormField>
                    <FormField label={t('Year')} required error={form.errors.year} htmlFor="vehicle-year">
                        <input id="vehicle-year" type="number" value={form.data.year} onChange={(e) => form.setData('year', e.target.value)} className={`${field} font-mono`} />
                    </FormField>
                    <FormField label={t('Color')} required error={form.errors.color} htmlFor="vehicle-color">
                        <input id="vehicle-color" value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} className={field} />
                    </FormField>
                </div>
                <FormField label={t('Plate number')} required error={form.errors.plate_number} htmlFor="vehicle-plate">
                    <input id="vehicle-plate" value={form.data.plate_number} onChange={(e) => form.setData('plate_number', e.target.value.toUpperCase())} className={`${field} font-mono`} />
                </FormField>
                <div className="grid grid-cols-2 gap-3">
                    <FormField label={t('Vehicle class')} required error={form.errors.vehicle_class} htmlFor="vehicle-class">
                        <select id="vehicle-class" value={form.data.vehicle_class} onChange={(e) => form.setData('vehicle_class', e.target.value)} className={field}>
                            {vehicleClasses.map((c) => <option key={c} value={c}>{label(c)}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('Status')} error={form.errors.status} htmlFor="vehicle-status">
                        <select id="vehicle-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={field}>
                            {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                        </select>
                    </FormField>
                </div>
            </div>
        </Modal>
    );
}
