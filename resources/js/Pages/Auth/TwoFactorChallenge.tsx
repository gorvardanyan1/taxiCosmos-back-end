import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';

export default function TwoFactorChallenge({ submitUrl }: { submitUrl: string | null }) {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    return (
        <AuthLayout title="Two-factor challenge" subtitle="Two-factor challenge">
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl); }}>
                {recovery ? (
                    <input aria-label="Recovery code" className={`${field} font-mono`} placeholder="XXXX-XXXX" value={form.data.recovery_code} onChange={(e) => form.setData('recovery_code', e.target.value)} />
                ) : (
                    <input aria-label="Authentication code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} className={`${field} text-center font-mono text-lg font-bold tracking-[0.5em]`} placeholder="000000" value={form.data.code} onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))} />
                )}
                {(form.errors.code ?? form.errors.recovery_code) && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.code ?? form.errors.recovery_code}</p>}
                <AuthSubmit label="Verify code" url={submitUrl} processing={form.processing} className="mt-5" />
                <button type="button" onClick={() => setRecovery(!recovery)} className="mt-4 w-full text-xs font-bold text-indigo-600">{recovery ? 'Use an authentication code instead' : 'Use a recovery code instead'}</button>
            </form>
        </AuthLayout>
    );
}
