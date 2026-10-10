import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, ExternalLink, Radio, Zap } from 'lucide-react';
import { initials } from '@/lib/format';
import { activeNavKey, navGroups } from '@/lib/navigation';

export default function Sidebar({ collapsed }: { collapsed: boolean }) {
    const { url, props } = usePage();
    const current = activeNavKey(url);
    const user = props.auth.user;
    const badges = props.navigation?.badges ?? {};

    return (
        <aside
            style={{
                width: collapsed ? 60 : 232,
                background: 'linear-gradient(180deg, #0b0f1e 0%, #0d1228 100%)',
                flexShrink: 0,
                display: 'flex',
                flexDirection: 'column',
                minHeight: '100%',
                transition: 'width 0.25s cubic-bezier(0.4,0,0.2,1)',
                borderRight: '1px solid rgba(255,255,255,0.04)',
            }}
        >
            <div className="flex items-center gap-3 px-4 py-5" style={{ borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                <div className="flex flex-shrink-0 items-center justify-center rounded-xl" style={{ width: 32, height: 32, background: 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)', boxShadow: '0 4px 14px rgba(99,102,241,0.45)' }}>
                    <Zap size={15} className="text-white" />
                </div>
                {!collapsed && (
                    <div>
                        <p className="text-sm font-bold tracking-tight text-white" style={{ fontFamily: 'var(--font-display)', lineHeight: 1.1 }}>{props.app.name}</p>
                        <p className="text-[10px]" style={{ color: 'rgba(148,163,184,0.6)' }}>Admin Console</p>
                    </div>
                )}
            </div>

            <nav className="sidebar-nav flex-1 overflow-y-auto py-4" style={{ overflowX: 'hidden' }} aria-label="Main">
                {navGroups.map((group) => (
                    <div key={group.label} className="mb-5">
                        {!collapsed && (
                            <p className="mb-1.5 px-4 text-[10px] font-semibold uppercase tracking-widest" style={{ color: 'rgba(148,163,184,0.35)', fontFamily: 'var(--font-display)' }}>
                                {group.label}
                            </p>
                        )}
                        {group.items.map((item) => {
                            const active = current === item.key;
                            const badge = badges[item.key];
                            return (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    title={collapsed ? item.label : undefined}
                                    aria-current={active ? 'page' : undefined}
                                    className={`group relative flex w-full items-center gap-2.5 px-4 py-2.5 text-left transition-all duration-150 ${active ? 'nav-active-glow' : ''}`}
                                >
                                    <span className="flex flex-shrink-0 items-center justify-center rounded-lg transition-all duration-150" style={{ width: 28, height: 28, background: active ? 'rgba(99,102,241,0.25)' : 'transparent', color: active ? '#818cf8' : 'rgba(148,163,184,0.6)' }}>
                                        {item.icon}
                                    </span>
                                    {!collapsed && (
                                        <>
                                            <span className="flex-1 text-[13px] font-medium" style={{ color: active ? '#e2e8f0' : 'rgba(148,163,184,0.7)', fontFamily: 'var(--font-display)' }}>{item.label}</span>
                                            {badge ? (
                                                <span className="rounded-full px-1.5 py-0.5 text-[10px] font-semibold" style={{ background: 'rgba(239,68,68,0.15)', color: '#f87171', fontFamily: 'var(--font-mono)' }}>{badge}</span>
                                            ) : null}
                                            {active && <ChevronRight size={11} style={{ color: '#6366f1', opacity: 0.7 }} />}
                                        </>
                                    )}
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </nav>

            {!collapsed && (
                <a href="/horizon" target="_blank" rel="noreferrer" className="mx-3 mb-3 flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-xs font-semibold text-slate-400 hover:bg-white/5 hover:text-slate-200">
                    <Radio size={14} /> Queue Monitor <ExternalLink size={11} className="ml-auto" />
                </a>
            )}

            {!collapsed && user && (
                <div className="px-3 py-4" style={{ borderTop: '1px solid rgba(255,255,255,0.05)' }}>
                    <div className="flex items-center gap-2.5 rounded-xl px-2 py-2" style={{ background: 'rgba(255,255,255,0.04)' }}>
                        <div className="flex flex-shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style={{ width: 30, height: 30, background: 'linear-gradient(135deg, #6366f1, #8b5cf6)', fontFamily: 'var(--font-display)' }}>
                            {initials(user.name)}
                        </div>
                        <div className="min-w-0">
                            <p className="truncate text-xs font-semibold text-white" style={{ fontFamily: 'var(--font-display)' }}>{user.name}</p>
                            <p className="truncate text-[10px]" style={{ color: 'rgba(148,163,184,0.5)' }}>{user.roleLabel}</p>
                        </div>
                    </div>
                </div>
            )}
        </aside>
    );
}
