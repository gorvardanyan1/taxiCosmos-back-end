import { Head, Link, useForm } from '@inertiajs/react';
import { Lock, Paperclip } from 'lucide-react';
import { useState } from 'react';
import ActionButton, { UNAVAILABLE_HINT } from '@/Components/ActionButton';
import EmptyState from '@/Components/EmptyState';
import FilterBar from '@/Components/FilterBar';
import FormField from '@/Components/FormField';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import { formatRelative } from '@/lib/format';
import { label } from '@/lib/labels';
import { visitQuery, withId } from '@/lib/query';
import { card, field, ghost, primary } from '@/lib/ui';
import type { Actions, Filters, Paginated, TicketDetail, TicketRow } from '@/types';

interface Props {
    tickets: Paginated<TicketRow>;
    filters: Filters;
    selected: TicketDetail | null;
    actions: Actions<'assign' | 'resolve' | 'reply' | 'refund'>;
}

const messageStyle = {
    requester: 'max-w-lg rounded-2xl bg-slate-100 p-4 text-sm leading-6 text-slate-700',
    staff: 'ml-auto max-w-lg rounded-2xl bg-indigo-500 p-4 text-sm leading-6 text-white',
};

export default function SupportTicketsIndex({ tickets, filters, selected, actions }: Props) {
    const [internal, setInternal] = useState(false);
    const [resolving, setResolving] = useState(false);
    const reply = useForm({ body: '', internal: false });
    const resolution = useForm({ resolution: '' });
    const replyUrl = withId(actions.reply, selected?.id);
    const resolveUrl = withId(actions.resolve, selected?.id);

    return (
        <div>
            <Head title="Support Tickets" />
            <PageHeader title="Support Tickets" description="Resolve rider and driver issues across every channel" />
            <div className="grid gap-5 lg:grid-cols-[380px_1fr]">
                <div>
                    <FilterBar filters={filters} selects={[{ key: 'priority', allLabel: 'All priorities', options: ['urgent', 'high', 'normal', 'low'].map((p) => ({ value: p, label: label(p) })) }]} />
                    <div className={`${card} overflow-hidden`}>
                        {tickets.data.length === 0 && <EmptyState title="No tickets" />}
                        {tickets.data.map((ticket) => (
                            <button key={ticket.id} onClick={() => visitQuery({ ticket: ticket.id })} aria-pressed={selected?.id === ticket.id} className={`w-full border-b border-slate-100 p-4 text-left ${selected?.id === ticket.id ? 'bg-indigo-50/60' : ''}`}>
                                <div className="flex justify-between">
                                    <span className="font-mono text-xs font-bold text-indigo-600">{ticket.code}</span>
                                    <span className={`rounded-full px-2 py-1 text-[9px] font-bold ${ticket.priority === 'urgent' ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500'}`}>{label(ticket.priority)}</span>
                                </div>
                                <p className="mt-2 text-sm font-bold text-slate-900">{ticket.subject}</p>
                                <p className="mt-1 text-xs text-slate-400">{ticket.requester.name} · {formatRelative(ticket.created_at)}</p>
                            </button>
                        ))}
                    </div>
                </div>

                {selected ? (
                    <div className={`${card} overflow-hidden`}>
                        <div className="flex items-center border-b border-slate-100 p-5">
                            <div>
                                <p className="font-mono text-xs font-bold text-indigo-600">{selected.code}</p>
                                <h2 className="mt-1 font-display text-lg font-bold text-slate-900">{selected.subject}</h2>
                            </div>
                            <div className="ml-auto flex gap-2">
                                <ActionButton url={withId(actions.assign, selected.id)} className={ghost}>Assign</ActionButton>
                                <button onClick={() => setResolving(true)} className={primary}>Resolve</button>
                            </div>
                        </div>
                        <div className="grid gap-5 p-5 xl:grid-cols-[1fr_260px]">
                            <div>
                                <div className="space-y-4">
                                    {selected.messages.map((message) => message.kind === 'internal' ? (
                                        <div key={message.id} className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                            <p className="flex items-center gap-2 text-xs font-bold text-amber-800"><Lock size={12} /> Internal note · Only visible to staff</p>
                                            <p className="mt-2 text-sm text-amber-900">{message.body}</p>
                                        </div>
                                    ) : (
                                        <div key={message.id} className={messageStyle[message.kind]}>{message.body}</div>
                                    ))}
                                </div>
                                <div className="mt-6 rounded-2xl border border-slate-200 p-3">
                                    <div className="mb-3 flex gap-1 rounded-lg bg-slate-100 p-1 text-xs font-bold">
                                        <button onClick={() => { setInternal(false); reply.setData('internal', false); }} className={`flex-1 rounded-md py-1.5 ${!internal ? 'bg-white text-indigo-600 shadow' : 'text-slate-500'}`}>Reply</button>
                                        <button onClick={() => { setInternal(true); reply.setData('internal', true); }} className={`flex-1 rounded-md py-1.5 ${internal ? 'bg-amber-50 text-amber-700 shadow' : 'text-slate-500'}`}>Internal note</button>
                                    </div>
                                    <textarea aria-label={internal ? 'Internal note' : 'Reply'} value={reply.data.body} onChange={(e) => reply.setData('body', e.target.value)} className={`${field} resize-none`} rows={4} placeholder={internal ? 'Add an internal note…' : 'Write a reply…'} />
                                    {reply.errors.body && <p role="alert" className="mt-1.5 text-xs font-semibold text-red-600">{reply.errors.body}</p>}
                                    <div className="mt-2 flex justify-between">
                                        <button disabled title={UNAVAILABLE_HINT} className={ghost}><Paperclip size={14} />Attach</button>
                                        <button disabled={replyUrl === null || !reply.data.body.trim() || reply.processing} title={replyUrl === null ? UNAVAILABLE_HINT : undefined} onClick={() => replyUrl && reply.post(replyUrl, { preserveScroll: true, onSuccess: () => reply.reset('body') })} className={primary}>Send</button>
                                    </div>
                                </div>
                            </div>
                            <aside className="space-y-3">
                                <div className="rounded-2xl bg-slate-50 p-4">
                                    <p className="text-[10px] font-bold uppercase text-slate-400">Requester</p>
                                    <p className="mt-2 text-sm font-bold text-slate-900">{selected.requester.name}</p>
                                    <p className="text-xs text-slate-500">{label(selected.requester.type)} · {selected.requester.code}</p>
                                </div>
                                {selected.linked_trip && (
                                    <div className="rounded-2xl bg-slate-50 p-4">
                                        <p className="text-[10px] font-bold uppercase text-slate-400">Linked trip</p>
                                        <p className="mt-2 font-mono text-sm font-bold text-indigo-600">{selected.linked_trip.code}</p>
                                        <div className="mt-3 flex gap-2">
                                            <Link href={`/admin/trips/${selected.linked_trip.id}`} className="text-xs font-bold text-indigo-600">Open trip</Link>
                                            <ActionButton url={withId(actions.refund, selected.id)} className="text-xs font-bold text-amber-600">Issue refund</ActionButton>
                                        </div>
                                    </div>
                                )}
                            </aside>
                        </div>
                    </div>
                ) : (
                    <div className={card}><EmptyState title="No ticket selected" hint="Pick a ticket from the list." /></div>
                )}
            </div>
            <Modal
                open={resolving}
                onClose={() => setResolving(false)}
                title="Resolve ticket"
                footer={
                    <>
                        <button className={ghost} onClick={() => setResolving(false)}>Cancel</button>
                        <button className={primary} disabled={resolveUrl === null || !resolution.data.resolution.trim() || resolution.processing} title={resolveUrl === null ? UNAVAILABLE_HINT : undefined} onClick={() => resolveUrl && resolution.post(resolveUrl, { onSuccess: () => setResolving(false) })}>Resolve</button>
                    </>
                }
            >
                <FormField label="Resolution" required error={resolution.errors.resolution} htmlFor="resolution">
                    <textarea id="resolution" value={resolution.data.resolution} onChange={(e) => resolution.setData('resolution', e.target.value)} className={field} rows={4} placeholder="Describe the resolution…" />
                </FormField>
            </Modal>
        </div>
    );
}
