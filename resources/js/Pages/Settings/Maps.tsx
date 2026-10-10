import { Head, useForm } from '@inertiajs/react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import SettingsTabs from '@/Components/SettingsTabs';
import type { Actions } from '@/types';

interface Props {
    maps: { provider: string; providers: { key: string; name: string }[]; masked_key: string };
    actions: Actions<'save' | 'testConnection'>;
}

export default function SettingsMaps({ maps, actions }: Props) {
    const form = useForm({ provider: maps.provider });

    return (
        <SettingsTabs active="maps">
            <Head title="Settings · Maps" />
            <div className="max-w-2xl rounded-2xl bg-white p-6 shadow-[0_1px_3px_rgba(0,0,0,.04)]">
                <label className="block text-xs font-bold text-slate-600">Map provider
                    <select value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        {maps.providers.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                    </select>
                </label>
                {form.errors.provider && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.provider}</p>}
                <label className="mt-5 block text-xs font-bold text-slate-600">API key<input value={maps.masked_key} readOnly className="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 font-mono text-sm" /></label>
                <div className="mt-5 flex gap-2">
                    <ActionButton url={actions.testConnection} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-indigo-600">Test connection</ActionButton>
                    <button onClick={() => actions.save && form.patch(actions.save)} disabled={actions.save === null || form.processing} title={actions.save === null ? UNAVAILABLE_HINT : undefined} className="rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 px-4 py-2 text-sm font-bold text-white disabled:opacity-40">Save</button>
                </div>
            </div>
        </SettingsTabs>
    );
}
