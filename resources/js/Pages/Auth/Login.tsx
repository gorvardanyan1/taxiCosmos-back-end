import { Link, useForm } from '@inertiajs/react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';
import { t } from '@/lib/i18n';

export default function Login({ status, submitUrl }: { status: string | null; submitUrl: string | null }) {
    const form = useForm({ email: '', password: '' });
    const error = form.errors.email ?? form.errors.password;

    return (
        <AuthLayout title={t('Sign in')} subtitle={t('Login')}>
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl, { onFinish: () => form.reset('password') }); }}>
                {status && <div className="mb-4 rounded-xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-700">{status}</div>}
                {error && <div role="alert" className="mb-4 rounded-xl bg-red-50 p-3 text-xs font-semibold text-red-700">{error}</div>}
                <input aria-label={t('Email')} type="email" autoComplete="username" className={field} placeholder={t('Email')} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                <input aria-label={t('Password')} type="password" autoComplete="current-password" className={`${field} mt-3`} placeholder={t('Password')} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                <div className="my-4 flex justify-end text-xs">
                    <Link href="/forgot-password" className="font-bold text-indigo-600">{t('Forgot password?')}</Link>
                </div>
                <AuthSubmit label={t('Sign in')} url={submitUrl} processing={form.processing} />
            </form>
        </AuthLayout>
    );
}
