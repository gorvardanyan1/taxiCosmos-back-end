import { visitQuery } from '@/lib/query';
import { label as humanLabel } from '@/lib/labels';

interface Props {
    tabs: string[];
    active: string;
    param?: string;
    labels?: Record<string, string>;
    variant?: 'pill' | 'underline' | 'soft';
}

/** Tabs whose selection lives in the URL (?tab=…), so it survives reloads, deep links and back/forward. */
export default function UrlTabs({ tabs, active, param = 'tab', labels = {}, variant = 'soft' }: Props) {
    const text = (tab: string) => labels[tab] ?? humanLabel(tab);

    return (
        <div role="tablist" className={variant === 'underline' ? 'flex gap-1 overflow-x-auto px-6' : 'flex w-fit gap-1 overflow-x-auto rounded-2xl border border-slate-100 bg-white p-1'}>
            {tabs.map((tab) => {
                const selected = tab === active;
                const classes = {
                    pill: `rounded-xl px-5 py-2 text-sm font-bold ${selected ? 'bg-indigo-500 text-white' : 'text-slate-400'}`,
                    soft: `rounded-xl px-4 py-2 text-xs font-bold ${selected ? 'bg-indigo-50 text-indigo-600' : 'text-slate-400'}`,
                    underline: `mr-3 border-b-2 px-1 py-3 text-xs font-bold ${selected ? 'border-indigo-500 text-indigo-500' : 'border-transparent text-slate-400'}`,
                }[variant];

                return (
                    <button key={tab} role="tab" aria-selected={selected} onClick={() => !selected && visitQuery({ [param]: tab })} className={classes}>
                        {text(tab)}
                    </button>
                );
            })}
        </div>
    );
}
