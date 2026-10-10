import { Head, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import FormField from '@/Components/FormField';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import UrlTabs from '@/Components/UrlTabs';
import { formatRelative } from '@/lib/format';
import { label } from '@/lib/labels';
import { card, field, ghost, primary } from '@/lib/ui';
import type { Actions } from '@/types';

interface Props {
    tab: string;
    tabs: string[];
    profile: { name: string; email: string; locale: string; timezone: string };
    two_factor_enabled: boolean;
    sessions: { id: string; device: string; ip: string; last_active_at: string; is_current: boolean }[];
    notification_preferences: { event: string; in_app: boolean; email: boolean }[];
    actions: Actions<'updateProfile' | 'updatePassword' | 'regenerateRecoveryCodes' | 'logoutOtherSessions' | 'updateNotifications'>;
}

export default function AccountShow({ tab, tabs, profile, two_factor_enabled, sessions, notification_preferences, actions }: Props) {
    const profileForm = useForm(profile);
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });
    const notifications = useForm({ preferences: notification_preferences });

    const toggle = (event: string, channel: 'in_app' | 'email') =>
        notifications.setData('preferences', notifications.data.preferences.map((p) => (p.event === event ? { ...p, [channel]: !p[channel] } : p)));

    return (
        <div>
            <Head title="My Account" />
            <PageHeader title="My Account" description="Manage your profile, security, and notification preferences" />
            <div className="mb-6"><UrlTabs tabs={tabs} active={tab} variant="pill" /></div>

            {tab === 'profile' && (
                <div className={`${card} max-w-3xl p-6`}>
                    <div className="grid gap-5 md:grid-cols-2">
                        <FormField label="Full name" error={profileForm.errors.name} htmlFor="name"><input id="name" className={field} value={profileForm.data.name} onChange={(e) => profileForm.setData('name', e.target.value)} /></FormField>
                        <FormField label="Email address" error={profileForm.errors.email} htmlFor="email"><input id="email" type="email" className={field} value={profileForm.data.email} onChange={(e) => profileForm.setData('email', e.target.value)} /></FormField>
                        <FormField label="Language" error={profileForm.errors.locale} htmlFor="locale"><input id="locale" className={field} value={profileForm.data.locale} onChange={(e) => profileForm.setData('locale', e.target.value)} /></FormField>
                        <FormField label="Timezone" error={profileForm.errors.timezone} htmlFor="timezone"><input id="timezone" className={field} value={profileForm.data.timezone} onChange={(e) => profileForm.setData('timezone', e.target.value)} /></FormField>
                    </div>
                    <button onClick={() => actions.updateProfile && profileForm.patch(actions.updateProfile)} disabled={actions.updateProfile === null || profileForm.processing} title={actions.updateProfile === null ? UNAVAILABLE_HINT : undefined} className={`${primary} mt-6`}>Save changes</button>
                </div>
            )}

            {tab === 'security' && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <div className={`${card} p-6`}>
                        <h3 className="font-display font-bold">Change password</h3>
                        {(['current_password', 'password', 'password_confirmation'] as const).map((key) => (
                            <div key={key} className="mt-4">
                                <input type="password" aria-label={label(key)} placeholder={{ current_password: 'Current password', password: 'New password', password_confirmation: 'Confirm password' }[key]} className={field} value={passwordForm.data[key]} onChange={(e) => passwordForm.setData(key, e.target.value)} />
                                {passwordForm.errors[key] && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{passwordForm.errors[key]}</p>}
                            </div>
                        ))}
                        <button onClick={() => actions.updatePassword && passwordForm.put(actions.updatePassword, { onSuccess: () => passwordForm.reset() })} disabled={actions.updatePassword === null || passwordForm.processing} title={actions.updatePassword === null ? UNAVAILABLE_HINT : undefined} className={`${primary} mt-4`}>Update password</button>
                    </div>
                    <div className={`${card} p-6`}>
                        <div className="flex items-start">
                            <span className="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><KeyRound size={18} /></span>
                            <div className="ml-3"><h3 className="font-display font-bold">Two-factor authentication</h3><StatusBadge status={two_factor_enabled ? 'active' : 'inactive'} label={two_factor_enabled ? 'Enabled' : 'Disabled'} /></div>
                        </div>
                        <ActionButton url={actions.regenerateRecoveryCodes} className={`${ghost} mt-5`}>Regenerate recovery codes</ActionButton>
                        <h4 className="mt-7 text-xs font-bold uppercase text-slate-400">Active sessions</h4>
                        {sessions.map((s) => (
                            <div key={s.id} className="mt-3 flex items-center border-t border-slate-100 pt-3 text-xs">
                                <div><b>{s.device} · {s.ip}</b><p className="text-slate-400">{s.is_current ? 'Current session' : formatRelative(s.last_active_at)}</p></div>
                            </div>
                        ))}
                        <ActionButton url={actions.logoutOtherSessions} className="mt-4 text-xs font-bold text-red-600">Log out other sessions</ActionButton>
                    </div>
                </div>
            )}

            {tab === 'notifications' && (
                <div className={`${card} max-w-4xl overflow-hidden`}>
                    {notifications.data.preferences.map((p) => (
                        <div key={p.event} className="flex items-center border-b border-slate-100 px-5 py-4">
                            <div className="flex-1"><p className="text-sm font-bold text-slate-800">{label(p.event)}</p><p className="text-xs text-slate-400">Notify me when this event needs attention.</p></div>
                            {(['in_app', 'email'] as const).map((channel) => (
                                <label key={channel} className="ml-6 flex items-center gap-2 text-xs text-slate-500">
                                    <input type="checkbox" checked={p[channel]} onChange={() => toggle(p.event, channel)} className="accent-indigo-600" />{channel === 'in_app' ? 'In-app' : 'Email'}
                                </label>
                            ))}
                        </div>
                    ))}
                    <div className="flex justify-end px-5 py-4">
                        <button onClick={() => actions.updateNotifications && notifications.put(actions.updateNotifications)} disabled={actions.updateNotifications === null || notifications.processing} title={actions.updateNotifications === null ? UNAVAILABLE_HINT : undefined} className={primary}>Save Preferences</button>
                    </div>
                </div>
            )}
        </div>
    );
}
