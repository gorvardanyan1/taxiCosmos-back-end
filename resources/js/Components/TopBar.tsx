import { Link, usePage } from '@inertiajs/react';
import { Bell, Car, CheckCheck, ChevronDown, CreditCard, LogOut, Menu, Search, ShieldAlert, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import { formatRelative, initials } from '@/lib/format';
import { pageLabel } from '@/lib/navigation';

const notificationIcons = { safety: ShieldAlert, document: Car, payment: CreditCard } as const;
const notificationTones: Record<string, string> = {
    safety: 'bg-red-50 text-red-600',
    document: 'bg-amber-50 text-amber-600',
    payment: 'bg-indigo-50 text-indigo-600',
};

export default function TopBar({ onToggleSidebar }: { onToggleSidebar: () => void }) {
    const { url, props } = usePage();
    const user = props.auth.user;
    const notifications = props.navigation?.notifications ?? [];
    const [profileOpen, setProfileOpen] = useState(false);
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const [commandOpen, setCommandOpen] = useState(false);
    const [query, setQuery] = useState('');

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setCommandOpen(true);
            }
            if (event.key === 'Escape') setCommandOpen(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <header className="flex flex-shrink-0 items-center gap-4 px-5 py-3" style={{ background: 'rgba(240,242,248,0.85)', backdropFilter: 'blur(12px)', WebkitBackdropFilter: 'blur(12px)', borderBottom: '1px solid rgba(226,232,240,0.8)', zIndex: 20 }}>
            <button onClick={onToggleSidebar} aria-label="Toggle sidebar" className="rounded-lg p-2 transition-colors hover:bg-slate-100" style={{ color: '#64748b' }}>
                <Menu size={17} />
            </button>

            <div className="relative max-w-sm flex-1">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: '#94a3b8' }} />
                <input
                    type="text"
                    readOnly
                    placeholder="Search riders, trips, drivers…"
                    className="w-full rounded-xl py-2 pl-9 pr-4 text-sm transition"
                    style={{ background: 'white', border: '1px solid #e2e8f0', color: '#0f172a', outline: 'none', fontFamily: 'var(--font-body)' }}
                    onFocus={() => setCommandOpen(true)}
                />
                <kbd className="absolute right-3 top-1/2 -translate-y-1/2 rounded px-1.5 py-0.5 text-[10px]" style={{ background: '#f1f5f9', color: '#94a3b8', border: '1px solid #e2e8f0', fontFamily: 'var(--font-mono)' }}>⌘K</kbd>
            </div>

            <div className="hidden items-center gap-1.5 text-xs md:flex">
                <span style={{ color: '#94a3b8' }}>Admin</span>
                <span style={{ color: '#cbd5e1' }}>/</span>
                <span className="font-semibold" style={{ color: '#475569', fontFamily: 'var(--font-display)' }}>{pageLabel(url)}</span>
            </div>

            <div className="ml-auto flex items-center gap-1.5">
                <button onClick={() => setNotificationsOpen(!notificationsOpen)} aria-label="Notifications" className="relative rounded-xl p-2 transition-colors hover:bg-white" style={{ color: '#64748b' }}>
                    <Bell size={17} />
                    {notifications.some((n) => !n.read) && <span className="absolute right-1.5 top-1.5 h-1.5 w-1.5 rounded-full" style={{ background: '#ef4444', boxShadow: '0 0 0 2px #f0f2f8' }} />}
                </button>
                {notificationsOpen && (
                    <div className="absolute right-20 top-14 z-50 w-[380px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                            <p className="font-display text-sm font-bold text-slate-900">Notifications</p>
                            <button disabled title={UNAVAILABLE_HINT} className="flex items-center gap-1 text-xs font-semibold text-indigo-600 disabled:opacity-40"><CheckCheck size={13} /> Mark all as read</button>
                        </div>
                        {notifications.length === 0 && <p className="px-4 py-8 text-center text-xs text-slate-400">You're all caught up.</p>}
                        {notifications.map((item) => {
                            const Icon = notificationIcons[item.kind as keyof typeof notificationIcons] ?? Bell;
                            return (
                                <div key={item.id} className="flex gap-3 border-b border-slate-50 px-4 py-3 hover:bg-slate-50">
                                    <span className={`grid size-9 shrink-0 place-items-center rounded-xl ${notificationTones[item.kind] ?? 'bg-slate-50 text-slate-600'}`}><Icon size={15} /></span>
                                    <div className="min-w-0 flex-1"><p className="text-xs font-bold text-slate-900">{item.title}</p><p className="mt-1 text-xs text-slate-500">{item.body}</p></div>
                                    <div className="text-right"><span className="text-[10px] text-slate-400">{formatRelative(item.created_at).replace(' ago', '')}</span>{!item.read && <span className="ml-auto mt-2 block size-1.5 rounded-full bg-indigo-500" />}</div>
                                </div>
                            );
                        })}
                        <button disabled title={UNAVAILABLE_HINT} className="w-full py-3 text-xs font-bold text-indigo-600 disabled:opacity-40">View all notifications</button>
                    </div>
                )}

                <div className="mx-1 h-5 w-px" style={{ background: '#e2e8f0' }} />

                {user && (
                    <div className="relative">
                        <button onClick={() => setProfileOpen(!profileOpen)} className="flex items-center gap-2 rounded-xl px-2 py-1.5 transition-colors hover:bg-white">
                            <div className="flex flex-shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style={{ width: 28, height: 28, background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>{initials(user.name)}</div>
                            <div className="hidden text-left sm:block">
                                <p className="text-xs font-semibold leading-tight" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{user.name}</p>
                                <p className="text-[10px] leading-tight" style={{ color: '#94a3b8' }}>{user.roleLabel}</p>
                            </div>
                            <ChevronDown size={12} style={{ color: '#94a3b8' }} />
                        </button>
                        {profileOpen && (
                            <>
                                <div className="fixed inset-0 z-40" onClick={() => setProfileOpen(false)} />
                                <div className="absolute right-0 top-full z-50 mt-1.5 w-48 rounded-2xl py-1" style={{ background: 'white', border: '1px solid #e2e8f0', boxShadow: '0 10px 40px rgba(0,0,0,0.12)' }}>
                                    <div className="px-3 py-2.5" style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <p className="text-xs font-semibold" style={{ color: '#0f172a', fontFamily: 'var(--font-display)' }}>{user.name}</p>
                                        <p className="text-[11px]" style={{ color: '#94a3b8' }}>{user.email}</p>
                                    </div>
                                    <Link href="/admin/account" onClick={() => setProfileOpen(false)} className="flex w-full items-center gap-2.5 px-3 py-2 text-sm transition-colors hover:bg-slate-50" style={{ color: '#64748b' }}>
                                        <User size={13} /> My Account
                                    </Link>
                                    <div style={{ borderTop: '1px solid #f1f5f9', margin: '4px 0' }} />
                                    <button disabled title={UNAVAILABLE_HINT} className="flex w-full items-center gap-2.5 px-3 py-2 text-sm transition-colors hover:bg-red-50 disabled:opacity-40" style={{ color: '#ef4444' }}>
                                        <LogOut size={13} /> Sign out
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                )}
            </div>

            {commandOpen && (
                <div className="fixed inset-0 z-[80] flex justify-center bg-slate-950/35 pt-[12vh] backdrop-blur-sm" onMouseDown={() => setCommandOpen(false)}>
                    <div className="h-fit w-full max-w-2xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" onMouseDown={(event) => event.stopPropagation()}>
                        <div className="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                            <Search size={18} className="text-slate-400" />
                            <input autoFocus value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search riders, drivers, trips, transactions, tickets…" className="flex-1 border-0 text-sm outline-none" />
                            <kbd className="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] text-slate-400">ESC</kbd>
                        </div>
                        <div className="max-h-[430px] overflow-y-auto p-2">
                            <div className="py-16 text-center text-sm text-slate-400">
                                {query === '' ? 'Type to search across the platform.' : 'Global search is not connected yet.'}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </header>
    );
}
