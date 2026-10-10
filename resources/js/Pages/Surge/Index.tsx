import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import FilterBar from '@/Components/FilterBar';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PaginatedTable from '@/Components/PaginatedTable';
import { formatDateTime } from '@/lib/format';
import { label } from '@/lib/labels';
import { withId } from '@/lib/query';
import { field, ghost, primary } from '@/lib/ui';
import { useShared } from '@/lib/useShared';
import type { Actions, Filters, NamedOption, Paginated, SurgeRow } from '@/types';

interface Props {
    surges: Paginated<SurgeRow>;
    filters: Filters;
    zones: NamedOption[];
    vehicleClasses: string[];
    maxMultiplier: string;
    actions: Actions<'create' | 'end'>;
}

export default function SurgeIndex({ surges, filters, zones, vehicleClasses, maxMultiplier, actions }: Props) {
    const { timezone } = useShared();
    const [open, setOpen] = useState(false);
    const form = useForm({ zone_id: String(zones[0]?.id ?? ''), vehicle_class: '', multiplier: '1.50', reason: '' });
    const schedule = (s: SurgeRow) => s.recurrence ?? (s.starts_at && s.ends_at ? `${formatDateTime(s.starts_at, timezone)} → ${formatDateTime(s.ends_at, timezone).split(' · ')[1]}` : '—');
    const submit = () => actions.create && form.post(actions.create, { onSuccess: () => { setOpen(false); form.reset(); } });

    return (
        <div>
            <Head title="Surge" />
            <PageHeader title="Surge" description="Control dynamic pricing by zone and vehicle class" actions={<button onClick={() => setOpen(true)} className={primary}><Plus size={14} />Add surge</button>} />
            <FilterBar filters={filters} selects={[{ key: 'vehicle_class', allLabel: 'All vehicle classes', options: vehicleClasses.map((c) => ({ value: c, label: label(c) })) }]} />
            <PaginatedTable
                paginator={surges}
                rowKey={(s) => s.id}
                empty={{ title: 'No surge pricing active' }}
                columns={[
                    { key: 'zone', header: 'Zone', render: (s) => <b>{s.zone}</b> },
                    { key: 'multiplier', header: 'Multiplier', render: (s) => <span className="rounded-lg bg-violet-50 px-2.5 py-1 font-mono font-bold text-violet-700">×{s.multiplier}</span> },
                    { key: 'class', header: 'Vehicle class', render: (s) => (s.vehicle_class ? label(s.vehicle_class) : 'All classes') },
                    { key: 'schedule', header: 'Schedule', render: schedule },
                    { key: 'reason', header: 'Reason', render: (s) => s.reason },
                    { key: 'by', header: 'Created by', render: (s) => s.created_by ?? 'System' },
                    { key: 'end', header: '', render: (s) => <ActionButton url={withId(actions.end, s.id)} className="text-xs font-bold text-red-600">End now</ActionButton> },
                ]}
            />
            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="Add surge"
                footer={
                    <>
                        <button className={ghost} onClick={() => setOpen(false)}>Cancel</button>
                        <button className={primary} onClick={submit} disabled={actions.create === null || !form.data.reason.trim() || form.processing} title={actions.create === null ? UNAVAILABLE_HINT : undefined}>Schedule surge</button>
                    </>
                }
            >
                <div className="space-y-4">
                    <FormField label="Zone" error={form.errors.zone_id} htmlFor="surge-zone">
                        <select id="surge-zone" value={form.data.zone_id} onChange={(e) => form.setData('zone_id', e.target.value)} className={field}>
                            {zones.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Vehicle class" error={form.errors.vehicle_class} htmlFor="surge-class">
                        <select id="surge-class" value={form.data.vehicle_class} onChange={(e) => form.setData('vehicle_class', e.target.value)} className={field}>
                            <option value="">All vehicle classes</option>
                            {vehicleClasses.map((c) => <option key={c} value={c}>{label(c)}</option>)}
                        </select>
                    </FormField>
                    <div>
                        <div className="flex justify-between text-xs font-bold"><span>Multiplier</span><span className="font-mono text-indigo-600">×{form.data.multiplier} / max ×{maxMultiplier}</span></div>
                        <input aria-label="Multiplier" type="range" min="1" max={maxMultiplier} step="0.1" value={form.data.multiplier} onChange={(e) => form.setData('multiplier', Number(e.target.value).toFixed(2))} className="mt-3 w-full accent-indigo-600" />
                        {form.errors.multiplier && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.multiplier}</p>}
                    </div>
                    <FormField label="Reason" required error={form.errors.reason} htmlFor="surge-reason">
                        <textarea id="surge-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={field} placeholder="Reason *" />
                    </FormField>
                </div>
            </Modal>
        </div>
    );
}
