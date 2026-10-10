import { useForm } from '@inertiajs/react';
import { Copy, Download } from 'lucide-react';
import AuthSubmit from '@/Components/AuthSubmit';
import AuthLayout from '@/Layouts/AuthLayout';
import { field, ghost } from '@/lib/ui';

interface Props {
    setup: { qr_svg: string; secret: string; recovery_codes: string[] } | null;
    submitUrl: string | null;
}

export default function TwoFactorSetup({ setup, submitUrl }: Props) {
    const form = useForm({ code: '', confirmed_saved_codes: false });

    return (
        <AuthLayout title="Two-factor setup" subtitle="Two-factor setup">
            <form onSubmit={(e) => { e.preventDefault(); if (submitUrl) form.post(submitUrl); }}>
                <div className="mb-4 rounded-xl bg-indigo-50 p-3 text-xs font-semibold text-indigo-700">Your role requires two-factor authentication.</div>
                {setup ? (
                    <>
                        <div className="mx-auto grid size-40 place-items-center bg-white" dangerouslySetInnerHTML={{ __html: setup.qr_svg }} />
                        <p className="mt-2 text-center font-mono text-xs text-slate-500">{setup.secret}</p>
                        <div className="mt-4 grid grid-cols-2 gap-2">{setup.recovery_codes.map((code) => <code key={code} className="rounded-lg bg-slate-50 p-2 text-center text-xs">{code}</code>)}</div>
                        <div className="mt-4 flex gap-2">
                            <button type="button" className={ghost} onClick={() => navigator.clipboard?.writeText(setup.recovery_codes.join('\n'))}><Copy size={13} />Copy</button>
                            <a className={ghost} download="taxikosmos-recovery-codes.txt" href={`data:text/plain;charset=utf-8,${encodeURIComponent(setup.recovery_codes.join('\n'))}`}><Download size={13} />Download</a>
                        </div>
                    </>
                ) : (
                    <div className="mx-auto grid size-40 place-items-center rounded-xl bg-slate-100 text-center text-xs text-slate-400">QR code appears here</div>
                )}
                <input aria-label="Authentication code" inputMode="numeric" maxLength={6} className={`${field} mt-4 text-center font-mono`} placeholder="Enter the 6-digit code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))} />
                {form.errors.code && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.code}</p>}
                <label className="mt-4 block text-xs"><input type="checkbox" checked={form.data.confirmed_saved_codes} onChange={(e) => form.setData('confirmed_saved_codes', e.target.checked)} className="mr-2 accent-indigo-600" />I have saved these codes</label>
                <AuthSubmit label="Enable two-factor" url={submitUrl} processing={form.processing} className="mt-4" />
            </form>
        </AuthLayout>
    );
}
