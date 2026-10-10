import { useForm } from '@inertiajs/react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';

interface Props {
    token: string;
    invitation: { role_label: string; invited_by: string } | null;
    submitUrl: string | null;
}

export default function AcceptInvitation({ token, invitation, submitUrl }: Props) {
    const form = useForm({ token, name: '', password: '', password_confirmation: '' });

    return (
        <AuthLayout title="Accept invitation" subtitle="Accept invitation">
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl); }}>
                <p className="mb-4 text-sm text-slate-600">
                    {invitation ? <>You've been invited as <b>{invitation.role_label}</b> by {invitation.invited_by}.</> : 'Set your name and password to activate your admin account.'}
                </p>
                <input aria-label="Full name" className={field} placeholder="Full name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                {form.errors.name && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.name}</p>}
                <input aria-label="Set password" type="password" className={`${field} mt-3`} placeholder="Set password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                {form.errors.password && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.password}</p>}
                <input aria-label="Confirm password" type="password" className={`${field} mt-3`} placeholder="Confirm password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                <AuthSubmit label="Accept invitation" url={submitUrl} processing={form.processing} className="mt-4" />
            </form>
        </AuthLayout>
    );
}
