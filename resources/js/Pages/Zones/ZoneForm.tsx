import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import { field } from '@/lib/ui';
import type { GeoJsonPolygon } from '@/lib/geo';
import type { Zone } from '@/types';

interface Props {
    open: boolean;
    onClose: () => void;
    /** The zone being edited, or null to create a new one. */
    zone: Zone | null;
    polygon: GeoJsonPolygon | null;
    timezones: string[];
    currencies: string[];
    /** Endpoint: POST /admin/zones to create, PATCH /admin/zones/{id} to edit. */
    url: string | null;
}

interface FormData {
    name: string;
    code: string;
    timezone: string;
    currency: string;
    priority: string;
    polygon: string;
}

const empty = (currencies: string[]): FormData => ({ name: '', code: '', timezone: 'Asia/Yerevan', currency: currencies[0] ?? 'AMD', priority: '0', polygon: '' });

/**
 * Create / edit a zone. The polygon is pasted as GeoJSON until the map editor (P13-T7) lets admins
 * draw it; the server validates it (ST_IsValid) and its error shows under the field.
 */
export default function ZoneForm({ open, onClose, zone, polygon, timezones, currencies, url }: Props) {
    const form = useForm<FormData>(empty(currencies));
    const editing = zone !== null;

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(zone
            ? { name: zone.name, code: zone.code, timezone: zone.timezone, currency: zone.currency, priority: String(zone.priority), polygon: polygon ? JSON.stringify(polygon, null, 1) : '' }
            : empty(currencies));
    }, [open, zone?.id]);

    const submit = () => {
        if (!url) return;
        form.transform((data) => {
            const { code, ...rest } = data;
            return { ...(editing ? rest : { ...rest, code }), priority: Number(rest.priority) };
        });
        const options = { preserveScroll: true, onSuccess: onClose };
        editing ? form.patch(url, options) : form.post(url, options);
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? `Edit ${zone.name}` : 'Add zone'}
            footer={
                <>
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-semibold transition-colors hover:bg-slate-100" style={{ color: '#64748b', fontFamily: 'var(--font-display)' }}>Cancel</button>
                    <button onClick={submit} disabled={url === null || form.processing} className="rounded-xl px-4 py-2 text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-40" style={{ background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>
                        {editing ? 'Save changes' : 'Create zone'}
                    </button>
                </>
            }
        >
            <div className="flex max-h-[65vh] flex-col gap-4 overflow-y-auto pr-1">
                <FormField label="Name" required error={form.errors.name} htmlFor="zone-name">
                    <input id="zone-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={field} />
                </FormField>
                <FormField label="Code" required error={form.errors.code} htmlFor="zone-code">
                    <input id="zone-code" value={form.data.code} disabled={editing} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={`${field} font-mono disabled:opacity-60`} placeholder="YEREVAN" />
                </FormField>
                <div className="grid grid-cols-2 gap-3">
                    <FormField label="Timezone" required error={form.errors.timezone} htmlFor="zone-timezone">
                        <select id="zone-timezone" value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} className={field}>
                            {timezones.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Currency" required error={form.errors.currency} htmlFor="zone-currency">
                        <select id="zone-currency" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)} className={field}>
                            {currencies.map((c) => <option key={c} value={c}>{c}</option>)}
                        </select>
                    </FormField>
                </div>
                <FormField label="Priority (higher wins where zones overlap)" error={form.errors.priority} htmlFor="zone-priority">
                    <input id="zone-priority" type="number" min={0} max={1000} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)} className={field} />
                </FormField>
                <FormField label="Polygon (GeoJSON)" required error={form.errors.polygon} htmlFor="zone-polygon">
                    <textarea id="zone-polygon" value={form.data.polygon} onChange={(e) => form.setData('polygon', e.target.value)} rows={6} spellCheck={false} placeholder='{"type":"Polygon","coordinates":[[[44.4,40.1],[44.6,40.1],[44.6,40.3],[44.4,40.1]]]}' className={`${field} resize-y font-mono text-xs`} />
                    <p className="mt-1 text-[11px]" style={{ color: '#94a3b8' }}>Paste a GeoJSON Polygon or MultiPolygon ([longitude, latitude] pairs, closed rings). Drawing on the map arrives with the map editor.</p>
                </FormField>
            </div>
        </Modal>
    );
}
