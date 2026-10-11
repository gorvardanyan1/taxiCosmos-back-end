import { useForm } from '@inertiajs/react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';
import { t, tn } from '@/lib/i18n';

interface Props {
    token: string;
    invitation: { role_label: string; invited_by: string } | null;
    submitUrl: string | null;
}

export default function AcceptInvitation({ token, invitation, submitUrl }: Props) {
    const form = useForm({ token, name: '', password: '', password_confirmation: '' });

    return (
        <AuthLayout title={t('Accept invitation')} subtitle={t('Accept invitation')}>
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl); }}>
                <p className="mb-4 text-sm text-slate-600">
                    {invitation ? tn('You\'ve been invited as :role by :inviter.', { role: <b>{invitation.role_label}</b>, inviter: invitation.invited_by }) : t('Set your name and password to activate your admin account.')}
                </p>
                <input aria-label={t('Full name')} className={field} placeholder={t('Full name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                {form.errors.name && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.name}</p>}
                <input aria-label={t('Set password')} type="password" className={`${field} mt-3`} placeholder={t('Set password')} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                {form.errors.password && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.password}</p>}
                <input aria-label={t('Confirm password')} type="password" className={`${field} mt-3`} placeholder={t('Confirm password')} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                <AuthSubmit label={t('Accept invitation')} url={submitUrl} processing={form.processing} className="mt-4" />
            </form>
        </AuthLayout>
    );
}
