import { Head } from '@inertiajs/react';
import SettingsTabs from '@/Components/SettingsTabs';
import type { Actions, Currency } from '@/types';

export default function SettingsCurrencies({ currencies, baseCurrency }: { currencies: Currency[]; baseCurrency: string; actions: Actions<'update'> }) {
    return (
        <SettingsTabs active="currencies">
            <Head title="Settings · Currencies" />
            <div className="overflow-hidden rounded-2xl" style={{ background: 'white', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}>
                <table className="w-full">
                    <thead><tr style={{ borderBottom: '1px solid #f1f5f9' }}>{['Code', 'Symbol', 'Decimals', 'Active', 'Base currency'].map((h) => <th key={h} className="px-5 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider text-slate-400">{h}</th>)}</tr></thead>
                    <tbody>
                        {currencies.map((c) => (
                            <tr key={c.code} className="border-b border-slate-50">
                                <td className="px-5 py-4 font-mono text-sm font-bold text-slate-900">{c.code}</td>
                                <td className="px-5 py-4">{c.symbol}</td>
                                <td className="px-5 py-4 font-mono">{c.decimals}</td>
                                <td className="px-5 py-4"><input type="checkbox" aria-label={`${c.code} active`} checked={c.active ?? false} readOnly disabled className="accent-indigo-600" /></td>
                                <td className="px-5 py-4"><input type="radio" aria-label={`${c.code} base`} name="base" checked={c.code === baseCurrency} readOnly disabled className="accent-indigo-600" /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <p className="px-5 py-3 text-xs text-slate-400">Currencies become editable with multi-currency support.</p>
            </div>
        </SettingsTabs>
    );
}
