import { Head, Link } from '@inertiajs/react';
import { primary } from '@/lib/ui';

const states: Record<number, { title: string; message: string }> = {
    403: { title: "You don't have access", message: 'Ask an administrator to grant the required permission.' },
    404: { title: 'Page not found', message: 'The page may have moved or no longer exists.' },
    419: { title: 'Session expired', message: 'Log in again to continue securely.' },
    429: { title: 'Too many requests', message: 'Please wait a moment and try again.' },
    500: { title: 'Something went wrong', message: 'We’ve logged the error and are investigating.' },
    503: { title: 'Maintenance', message: 'TaxiKosmos will be back shortly.' },
};

export default function ErrorShow({ status }: { status: number }) {
    const state = states[status] ?? states[500];

    return (
        <div className="grid min-h-full place-items-center p-6">
            <Head title={state.title} />
            <div className="w-full max-w-sm rounded-2xl border border-slate-100 bg-white p-6 text-center shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
                <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-indigo-50 font-mono text-lg font-bold text-indigo-600">{status}</span>
                <h1 className="mt-4 font-display font-bold">{state.title}</h1>
                <p className="mt-2 text-sm text-slate-400">{state.message}</p>
                <Link href={status === 419 ? '/login' : '/admin'} className={`${primary} mt-5`}>{status === 419 ? 'Log in again' : 'Go to dashboard'}</Link>
            </div>
        </div>
    );
}
