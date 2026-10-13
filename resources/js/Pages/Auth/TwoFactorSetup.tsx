import { router, useForm } from '@inertiajs/react';
import AuthSubmit from '@/Components/AuthSubmit';
import RecoveryCodesList from '@/Components/RecoveryCodesList';
import AuthLayout from '@/Layouts/AuthLayout';
import { field } from '@/lib/ui';
import { useSubmitOnce } from '@/lib/useSubmitOnce';
import { t } from '@/lib/i18n';

interface Props {
    /** Present while a setup is pending: the QR code, the secret to type in by hand, and the recovery codes. */
    setup: { qr_svg: string; secret: string; recovery_codes: string[] } | null;
    /** The admin's role must have two-factor authentication: nothing else opens until it is on. */
    required: boolean;
    /** Step 1 (password) and step 2 (code) endpoints. */
    startUrl: string | null;
    submitUrl: string | null;
}

export default function TwoFactorSetup({ setup, required, startUrl, submitUrl }: Props) {
    const start = useForm({ password: '' });
    const confirm = useForm({ code: '', confirmed_saved_codes: false });
    const once = useSubmitOnce();

    return (
        <AuthLayout title={t('Two-factor setup')} subtitle={t('Two-factor setup')}>
            <div className="mb-4 rounded-xl bg-indigo-50 p-3 text-xs font-semibold text-indigo-700">
                {required ? t('Your role requires two-factor authentication.') : t('Protect your account with a code from an authenticator app.')}
            </div>
            {setup === null ? (
                <form onSubmit={(e) => { e.preventDefault(); if (startUrl) once((done) => start.post(startUrl, { onError: () => start.reset('password'), onFinish: done })); }}>
                    <p className="mb-3 text-xs text-slate-500">{t('Confirm your password to generate the QR code and your recovery codes.')}</p>
                    <input aria-label={t('Current password')} type="password" autoComplete="current-password" className={field} placeholder={t('Current password')} value={start.data.password} onChange={(e) => start.setData('password', e.target.value)} />
                    {start.errors.password && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{start.errors.password}</p>}
                    <AuthSubmit label={t('Generate QR code')} url={startUrl} processing={start.processing || start.data.password === ''} className="mt-4" />
                </form>
            ) : (
                <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) once((done) => confirm.post(submitUrl, { onFinish: done })); }}>
                    <div className="mx-auto grid size-40 place-items-center bg-white" dangerouslySetInnerHTML={{ __html: setup.qr_svg }} />
                    <p className="mt-2 text-center font-mono text-xs text-slate-500">{setup.secret}</p>
                    <p className="mt-4 mb-2 text-xs font-bold text-slate-700">{t('Recovery codes')}</p>
                    <RecoveryCodesList codes={setup.recovery_codes} />
                    <input aria-label={t('Authentication code')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} className={`${field} mt-4 text-center font-mono`} placeholder={t('Enter the 6-digit code')} value={confirm.data.code} onChange={(e) => confirm.setData('code', e.target.value.replace(/\D/g, ''))} />
                    {confirm.errors.code && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{confirm.errors.code}</p>}
                    <label className="mt-4 block text-xs"><input type="checkbox" checked={confirm.data.confirmed_saved_codes} onChange={(e) => confirm.setData('confirmed_saved_codes', e.target.checked)} className="mr-2 accent-indigo-600" />{t('I have saved these codes')}</label>
                    {confirm.errors.confirmed_saved_codes && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{confirm.errors.confirmed_saved_codes}</p>}
                    <AuthSubmit label={t('Enable two-factor')} url={submitUrl} processing={confirm.processing} className="mt-4" />
                </form>
            )}
            <button type="button" onClick={() => router.post('/logout')} className="mt-4 w-full text-xs font-bold text-slate-400 hover:text-slate-600">{t('Sign out')}</button>
        </AuthLayout>
    );
}
