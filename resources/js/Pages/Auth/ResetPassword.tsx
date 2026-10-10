import { useForm } from '@inertiajs/react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';

export default function ResetPassword({ token, email, submitUrl }: { token: string; email: string; submitUrl: string | null }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    return (
        <AuthLayout title="Reset password" subtitle="Reset password">
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl, { onFinish: () => form.reset('password', 'password_confirmation') }); }}>
                <input aria-label="Email" type="email" className={field} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} placeholder="Email" />
                {form.errors.email && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.email}</p>}
                <input aria-label="New password" type="password" className={`${field} mt-3`} placeholder="New password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                {form.errors.password && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.password}</p>}
                <input aria-label="Confirm password" type="password" className={`${field} mt-3`} placeholder="Confirm password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                <div className="my-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-500">Minimum 12 characters · uppercase · number · symbol</div>
                <AuthSubmit label="Reset password" url={submitUrl} processing={form.processing} />
            </form>
        </AuthLayout>
    );
}
