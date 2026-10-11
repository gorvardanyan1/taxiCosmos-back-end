import { Link, useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';
import { t } from '@/lib/i18n';

export default function ForgotPassword({ status, submitUrl }: { status: string | null; submitUrl: string | null }) {
    const form = useForm({ email: '' });

    return (
        <AuthLayout title={t('Forgot password')} subtitle={t('Forgot password')}>
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl); }}>
                {status && <div className="mb-4 rounded-xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-700"><Check size={14} className="mr-2 inline" />{status}</div>}
                <input aria-label={t('Email address')} type="email" className={field} placeholder={t('Email address')} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                {form.errors.email && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.email}</p>}
                <AuthSubmit label={t('Send reset link')} url={submitUrl} processing={form.processing} className="mt-4" />
                <Link href="/login" className="mt-4 block text-center text-xs font-bold text-indigo-600">{t('Back to sign in')}</Link>
            </form>
        </AuthLayout>
    );
}
