import { Head, Link, useForm } from '@inertiajs/react';
import { Lock, Paperclip } from 'lucide-react';
import { useState } from 'react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import FilterBar from '@/Components/FilterBar';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import { formatRelative } from '@/lib/format';
import { useSubmitOnce } from '@/lib/useSubmitOnce';
import { label } from '@/lib/labels';
import { visitQuery, withId } from '@/lib/query';
import { useShared } from '@/lib/useShared';
import { card, field, ghost, primary } from '@/lib/ui';
import type { Actions, Filters, Paginated, TicketDetail, TicketRow } from '@/types';
import { t } from '@/lib/i18n';

interface Props {
    tickets: Paginated<TicketRow>;
    filters: Filters;
    selected: TicketDetail | null;
    statuses: string[];
    categories: string[];
    priorities: string[];
    /** Admins who handle support: who a ticket can be assigned to. */
    assignees: { id: number; name: string }[];
    /** The signed-in admin. */
    me: number;
    actions: Actions<'assign' | 'resolve' | 'reply' | 'reopen' | 'refund' | 'adjustFare'>;
}

const messageStyle = {
    requester: 'max-w-lg rounded-2xl bg-slate-100 p-4 text-sm leading-6 text-slate-700',
    staff: 'ml-auto max-w-lg rounded-2xl bg-indigo-500 p-4 text-sm leading-6 text-white',
};

export default function SupportTicketsIndex({ tickets, filters, selected, statuses, categories, priorities, assignees, me, actions }: Props) {
    const { can } = useShared();
    const [internal, setInternal] = useState(false);
    const [resolving, setResolving] = useState(false);
    const [assigning, setAssigning] = useState(false);
    const reply = useForm({ body: '', internal: false });
    const resolution = useForm({ resolution: '' });
    const assignment = useForm<{ assignee_id: number | null }>({ assignee_id: selected?.assignee?.id ?? null });
    const once = useSubmitOnce();
    const replyUrl = withId(actions.reply, selected?.id);
    const resolveUrl = withId(actions.resolve, selected?.id);
    const assignUrl = withId(actions.assign, selected?.id);
    const finished = selected?.status === 'resolved' || selected?.status === 'closed';
    // Sending twice (a double-click) must reach the server once.
    const send = () => replyUrl && once((done) => reply.post(replyUrl, { preserveScroll: true, onSuccess: () => reply.reset('body'), onFinish: done }));
    const resolve = () => resolveUrl && once((done) => resolution.post(resolveUrl, { onSuccess: () => { setResolving(false); resolution.reset(); }, onFinish: done }));
    const assign = () => assignUrl && once((done) => assignment.post(assignUrl, { preserveScroll: true, onSuccess: () => setAssigning(false), onFinish: done }));

    return (
        <div>
            <Head title={t('Support Tickets')} />
            <PageHeader title={t('Support Tickets')} description={t('Resolve rider and driver issues across every channel')} />
            <div className="grid gap-5 lg:grid-cols-[380px_1fr]">
                <div>
                    <FilterBar
                        filters={filters}
                        searchPlaceholder={t('Search code, subject or requester…')}
                        selects={[
                            { key: 'status', allLabel: t('All statuses'), options: statuses.map((value) => ({ value, label: label(value) })) },
                            { key: 'category', allLabel: t('All categories'), options: categories.map((value) => ({ value, label: label(value) })) },
                            { key: 'priority', allLabel: t('All priorities'), options: priorities.map((value) => ({ value, label: label(value) })) },
                            { key: 'assignee', allLabel: t('All assignees'), options: [{ value: 'me', label: t('Assigned to me') }, { value: 'unassigned', label: t('Unassigned') }, ...assignees.filter((a) => a.id !== me).map((a) => ({ value: String(a.id), label: a.name }))] },
                        ]}
                    />
                    <div className={`${card} overflow-hidden`}>
                        {tickets.data.length === 0 && <EmptyState title={t('No tickets')} />}
                        {tickets.data.map((ticket) => (
                            <button key={ticket.id} onClick={() => visitQuery({ ticket: ticket.id }, { resetScroll: true })} aria-pressed={selected?.id === ticket.id} className={`w-full border-b border-slate-100 p-4 text-left ${selected?.id === ticket.id ? 'bg-indigo-50/60' : ''}`}>
                                <div className="flex justify-between">
                                    <span className="font-mono text-xs font-bold text-indigo-600">{ticket.code}</span>
                                    <span className={`rounded-full px-2 py-1 text-[9px] font-bold ${ticket.priority === 'urgent' ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500'}`}>{label(ticket.priority)}</span>
                                </div>
                                <p className="mt-2 text-sm font-bold text-slate-900">{ticket.subject}</p>
                                <p className="mt-1 text-xs text-slate-400">{ticket.requester.name} · {formatRelative(ticket.created_at)}</p>
                                <div className="mt-2"><StatusBadge status={ticket.status} /></div>
                            </button>
                        ))}
                        {tickets.last_page > 1 && (
                            <div className="flex items-center justify-between p-3 text-xs text-slate-500">
                                <button aria-label={t('Previous page')} disabled={tickets.current_page <= 1} onClick={() => visitQuery({ page: tickets.current_page - 1 })} className="rounded-lg px-3 py-1.5 font-bold disabled:opacity-30">{t('Previous')}</button>
                                <span>{t(':current of :last', { current: tickets.current_page, last: tickets.last_page })}</span>
                                <button aria-label={t('Next page')} disabled={tickets.current_page >= tickets.last_page} onClick={() => visitQuery({ page: tickets.current_page + 1 })} className="rounded-lg px-3 py-1.5 font-bold disabled:opacity-30">{t('Next')}</button>
                            </div>
                        )}
                    </div>
                </div>

                {selected ? (
                    <div className={`${card} overflow-hidden`}>
                        <div className="flex items-center border-b border-slate-100 p-5">
                            <div>
                                <p className="font-mono text-xs font-bold text-indigo-600">{selected.code}</p>
                                <h2 className="mt-1 font-display text-lg font-bold text-slate-900">{selected.subject}</h2>
                            </div>
                            <div className="ml-auto flex items-center gap-2">
                                <StatusBadge status={selected.status} />
                                {finished ? (
                                    <ActionButton url={withId(actions.reopen, selected.id)} className={ghost}>{t('Reopen')}</ActionButton>
                                ) : (
                                    <>
                                        <button onClick={() => { assignment.setData('assignee_id', selected.assignee?.id ?? null); setAssigning(true); }} disabled={assignUrl === null} title={assignUrl === null ? t(UNAVAILABLE_HINT) : undefined} className={`${ghost} disabled:cursor-not-allowed disabled:opacity-40`}>{t('Assign')}</button>
                                        <button onClick={() => setResolving(true)} disabled={resolveUrl === null} title={resolveUrl === null ? t(UNAVAILABLE_HINT) : undefined} className={`${primary} disabled:cursor-not-allowed disabled:opacity-40`}>{t('Resolve')}</button>
                                    </>
                                )}
                            </div>
                        </div>
                        <div className="grid gap-5 p-5 xl:grid-cols-[1fr_260px]">
                            <div>
                                <div className="space-y-4">
                                    {selected.messages.map((message) => message.kind === 'internal' ? (
                                        <div key={message.id} className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                            <p className="flex items-center gap-2 text-xs font-bold text-amber-800"><Lock size={12} /> {t('Internal note · Only visible to staff')} · {message.author}</p>
                                            <p className="mt-2 text-sm text-amber-900">{message.body}</p>
                                        </div>
                                    ) : (
                                        <div key={message.id} className={messageStyle[message.kind]}>{message.body}</div>
                                    ))}
                                </div>
                                {selected.resolution && (
                                    <div className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                        <p className="text-xs font-bold text-emerald-800">{t('Resolution')}</p>
                                        <p className="mt-2 text-sm text-emerald-900">{selected.resolution}</p>
                                    </div>
                                )}
                                {!finished && (
                                <div className="mt-6 rounded-2xl border border-slate-200 p-3">
                                    <div className="mb-3 flex gap-1 rounded-lg bg-slate-100 p-1 text-xs font-bold">
                                        <button onClick={() => { setInternal(false); reply.setData('internal', false); }} className={`flex-1 rounded-md py-1.5 ${!internal ? 'bg-white text-indigo-600 shadow' : 'text-slate-500'}`}>{t('Reply')}</button>
                                        <button onClick={() => { setInternal(true); reply.setData('internal', true); }} className={`flex-1 rounded-md py-1.5 ${internal ? 'bg-amber-50 text-amber-700 shadow' : 'text-slate-500'}`}>{t('Internal note')}</button>
                                    </div>
                                    <textarea aria-label={internal ? t('Internal note') : t('Reply')} value={reply.data.body} onChange={(e) => reply.setData('body', e.target.value)} className={`${field} resize-none`} rows={4} placeholder={internal ? t('Add an internal note…') : t('Write a reply…')} />
                                    {reply.errors.body && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{reply.errors.body}</p>}
                                    <div className="mt-2 flex justify-between">
                                        <button disabled title={t(UNAVAILABLE_HINT)} className={ghost}><Paperclip size={14} />{t('Attach')}</button>
                                        <button disabled={replyUrl === null || !reply.data.body.trim() || reply.processing} title={replyUrl === null ? t(UNAVAILABLE_HINT) : undefined} onClick={send} className={primary}>{t('Send')}</button>
                                    </div>
                                </div>
                                )}
                            </div>
                            <aside className="space-y-3">
                                <div className="rounded-2xl bg-slate-50 p-4">
                                    <p className="text-[10px] font-bold uppercase text-slate-400">{t('Requester')}</p>
                                    <p className="mt-2 text-sm font-bold text-slate-900">{selected.requester.name}</p>
                                    <p className="text-xs text-slate-500">{label(selected.requester.type)} · {selected.requester.code}</p>
                                </div>
                                <div className="rounded-2xl bg-slate-50 p-4">
                                    <p className="text-[10px] font-bold uppercase text-slate-400">{t('Category')}</p>
                                    <p className="mt-2 text-sm font-bold text-slate-900">{label(selected.category)}</p>
                                    <p className="mt-3 text-[10px] font-bold uppercase text-slate-400">{t('Assignee')}</p>
                                    <p className="mt-2 text-sm font-bold text-slate-900">{selected.assignee?.name ?? t('Unassigned')}</p>
                                </div>
                                {selected.linked_trip && (
                                    <div className="rounded-2xl bg-slate-50 p-4">
                                        <p className="text-[10px] font-bold uppercase text-slate-400">{t('Linked trip')}</p>
                                        <p className="mt-2 font-mono text-sm font-bold text-indigo-600">{selected.linked_trip.code}</p>
                                        <div className="mt-3 flex gap-2">
                                            <Link href={`/admin/trips/${selected.linked_trip.id}`} className="text-xs font-bold text-indigo-600">{t('Open trip')}</Link>
                                            {can('payments.refund') && <ActionButton url={withId(actions.refund, selected.id)} className="text-xs font-bold text-amber-600">{t('Issue refund')}</ActionButton>}
                                            {can('trips.adjust_fare') && <ActionButton url={withId(actions.adjustFare, selected.id)} className="text-xs font-bold text-amber-600">{t('Adjust fare')}</ActionButton>}
                                        </div>
                                    </div>
                                )}
                            </aside>
                        </div>
                    </div>
                ) : (
                    <div className={card}><EmptyState title={t('No ticket selected')} hint={t('Pick a ticket from the list.')} /></div>
                )}
            </div>
            <Modal
                open={resolving}
                onClose={() => setResolving(false)}
                title={t('Resolve ticket')}
                footer={
                    <>
                        <button className={ghost} onClick={() => setResolving(false)}>{t('Cancel')}</button>
                        <button className={primary} disabled={resolveUrl === null || !resolution.data.resolution.trim() || resolution.processing} title={resolveUrl === null ? t(UNAVAILABLE_HINT) : undefined} onClick={resolve}>{t('Resolve')}</button>
                    </>
                }
            >
                <FormField label={t('Resolution')} required error={resolution.errors.resolution} htmlFor="resolution">
                    <textarea id="resolution" value={resolution.data.resolution} onChange={(e) => resolution.setData('resolution', e.target.value)} className={field} rows={4} placeholder={t('Describe the resolution…')} />
                </FormField>
            </Modal>
            <Modal
                open={assigning}
                onClose={() => setAssigning(false)}
                title={t('Assign ticket')}
                footer={
                    <>
                        <button className={ghost} onClick={() => setAssigning(false)}>{t('Cancel')}</button>
                        <button className={primary} disabled={assignUrl === null || assignment.processing} onClick={assign}>{t('Assign')}</button>
                    </>
                }
            >
                <FormField label={t('Assignee')} error={assignment.errors.assignee_id} htmlFor="assignee">
                    <select id="assignee" className={field} value={assignment.data.assignee_id ?? ''} onChange={(e) => assignment.setData('assignee_id', e.target.value === '' ? null : Number(e.target.value))}>
                        <option value="">{t('Unassigned')}</option>
                        {assignees.map((a) => <option key={a.id} value={a.id}>{a.id === me ? t(':name (me)', { name: a.name }) : a.name}</option>)}
                    </select>
                </FormField>
            </Modal>
        </div>
    );
}
