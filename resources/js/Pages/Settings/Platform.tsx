import { Head, useForm } from '@inertiajs/react';
import { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import SettingsTabs from '@/Components/SettingsTabs';
import type { Actions } from '@/types';

interface Setting {
    key: string;
    label: string;
    value: string;
}

export default function SettingsPlatform({ settings, actions }: { settings: Setting[]; actions: Actions<'save'> }) {
    const form = useForm<Record<string, string>>(Object.fromEntries(settings.map((s) => [s.key, s.value])));

    return (
        <SettingsTabs active="platform">
            <Head title="Settings · Platform" />
            <div className="grid gap-5 lg:grid-cols-2">
                {settings.map((s) => (
                    <label key={s.key} className="rounded-2xl bg-white p-5 text-xs font-bold text-slate-600 shadow-[0_1px_3px_rgba(0,0,0,.04)]">
                        {s.label}
                        <input value={form.data[s.key] ?? ''} onChange={(e) => form.setData(s.key, e.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-normal text-slate-900" />
                        {form.errors[s.key] && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors[s.key]}</p>}
                    </label>
                ))}
                <button onClick={() => actions.save && form.patch(actions.save)} disabled={actions.save === null || form.processing} title={actions.save === null ? UNAVAILABLE_HINT : undefined} className="w-fit rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-40">Save platform settings</button>
            </div>
        </SettingsTabs>
    );
}
