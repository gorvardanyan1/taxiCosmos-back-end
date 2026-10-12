import { useEffect, useState } from 'react';
import { t } from '@/lib/i18n';

/** How long a revealed number stays on screen. */
const VISIBLE_MS = 30_000;

interface Props {
    /** POST /admin/drivers/{id}/bank-accounts/{accountId}/reveal; null for admins who may not reveal. */
    url: string | null;
}

const xsrf = (): string => decodeURIComponent(document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/**
 * "Reveal account": asks the server for the full number (which logs the reveal), shows it for a short
 * while, then hides it again. The number is never part of the page's props.
 */
export default function RevealAccountNumber({ url }: Props) {
    const [number, setNumber] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (number === null) return;
        const timer = setTimeout(() => setNumber(null), VISIBLE_MS);
        return () => clearTimeout(timer);
    }, [number]);

    const reveal = async () => {
        if (!url || loading) return;
        setLoading(true);
        setError(null);
        try {
            const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(String(response.status));
            setNumber((await response.json()).account_number);
        } catch {
            setError(t('The account number could not be revealed.'));
        } finally {
            setLoading(false);
        }
    };

    if (number !== null) {
        return (
            <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3">
                <p className="font-mono text-sm font-bold text-slate-900" data-testid="revealed-account-number">{number}</p>
                <button onClick={() => setNumber(null)} className="mt-2 text-xs font-bold text-indigo-600">{t('Hide')}</button>
            </div>
        );
    }

    return (
        <div className="mt-5">
            <button onClick={reveal} disabled={url === null || loading} title={url === null ? t('Only finance can reveal full account numbers.') : undefined} className="w-full rounded-xl border border-slate-200 py-2 text-xs font-bold text-indigo-600 disabled:cursor-not-allowed disabled:opacity-40">
                {t('Reveal account · This action is logged')}
            </button>
            {error && <p role="alert" className="mt-2 text-xs font-semibold text-red-600">{error}</p>}
        </div>
    );
}
