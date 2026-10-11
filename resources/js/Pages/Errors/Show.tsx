import { Head, Link } from '@inertiajs/react';
import { primary } from '@/lib/ui';
import { t, tk } from '@/lib/i18n';

const states: Record<number, { title: string; message: string }> = {
    403: { title: tk('You don\'t have access'), message: tk('Ask an administrator to grant the required permission.') },
    404: { title: tk('Page not found'), message: tk('The page may have moved or no longer exists.') },
    419: { title: tk('Session expired'), message: tk('Log in again to continue securely.') },
    429: { title: tk('Too many requests'), message: tk('Please wait a moment and try again.') },
    500: { title: tk('Something went wrong'), message: tk('We’ve logged the error and are investigating.') },
    503: { title: tk('Maintenance'), message: tk('TaxiKosmos will be back shortly.') },
};

export default function ErrorShow({ status }: { status: number }) {
    const state = states[status] ?? states[500];

    return (
        <div className="grid min-h-full place-items-center p-6">
            <Head title={t(state.title)} />
            <div className="w-full max-w-sm rounded-2xl border border-slate-100 bg-white p-6 text-center shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-indigo-50 font-mono text-lg font-bold text-indigo-600">{status}</span>
                <h1 className="mt-4 font-display font-bold">{t(state.title)}</h1>
                <p className="mt-2 text-sm text-slate-400">{t(state.message)}</p>
                <Link href={status === 419 ? '/login' : '/admin'} className={`${primary} mt-5`}>{status === 419 ? t('Log in again') : t('Go to dashboard')}</Link>
            </div>
        </div>
    );
}
