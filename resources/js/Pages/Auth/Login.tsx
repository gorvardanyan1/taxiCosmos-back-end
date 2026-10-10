import { Link, useForm } from '@inertiajs/react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';

export default function Login({ status, submitUrl }: { status: string | null; submitUrl: string | null }) {
    const form = useForm({ email: '', password: '', remember: false });
    const error = form.errors.email ?? form.errors.password;

    return (
        <AuthLayout title="Sign in" subtitle="Login">
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl, { onFinish: () => form.reset('password') }); }}>
                {status && <div className="mb-4 rounded-xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-700">{status}</div>}
                {error && <div role="alert" className="mb-4 rounded-xl bg-red-50 p-3 text-xs font-semibold text-red-700">{error}</div>}
                <input aria-label="Email" type="email" autoComplete="username" className={field} placeholder="Email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                <input aria-label="Password" type="password" autoComplete="current-password" className={`${field} mt-3`} placeholder="Password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                <div className="my-4 flex justify-between text-xs">
                    <label><input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} className="mr-2 accent-indigo-600" />Remember me</label>
                    <Link href="/forgot-password" className="font-bold text-indigo-600">Forgot password?</Link>
                </div>
                <AuthSubmit label="Sign in" url={submitUrl} processing={form.processing} />
            </form>
        </AuthLayout>
    );
}
