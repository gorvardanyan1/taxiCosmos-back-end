import { router } from '@inertiajs/core';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SupportTicketsIndex from '@/Pages/SupportTickets/Index';
import { grant, page, sharedProps } from '@/test/inertia';
import type { Paginated, TicketDetail, TicketRow } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const actions = {
    assign: '/admin/support-tickets/{id}/assign', reply: '/admin/support-tickets/{id}/messages', resolve: '/admin/support-tickets/{id}/resolve',
    reopen: '/admin/support-tickets/{id}/reopen', refund: null, adjustFare: null,
};
const row = (id: number, over: Partial<TicketRow> = {}): TicketRow => ({
    id, code: `TKT-0000${id}`, subject: `Subject ${id}`, category: 'fare', priority: 'normal', status: 'open', assignee: null,
    requester: { name: 'Ana Sargsyan', type: 'rider', code: 'R-00007' }, created_at: '2026-10-14T10:00:00Z', last_message_at: '2026-10-14T10:00:00Z', ...over,
});
const detail = (over: Partial<TicketDetail> = {}): TicketDetail => ({
    ...row(1), resolution: null, resolved_at: null, linked_trip: { id: 5, code: 'TK-AB12CD34' },
    messages: [
        { id: 1, kind: 'requester', author: 'Ana', body: 'I was overcharged.', created_at: '2026-10-14T10:00:00Z' },
        { id: 2, kind: 'internal', author: 'Sam Staff', body: 'Checked the meter.', created_at: '2026-10-14T10:05:00Z' },
    ],
    ...over,
});
const paginator = (data: TicketRow[], lastPage = 1): Paginated<TicketRow> => ({ data, current_page: 1, last_page: lastPage, per_page: 15, total: data.length, from: 1, to: data.length, path: '/admin/support-tickets', links: [] });
const base = { tickets: paginator([row(1), row(2)]), filters: {}, selected: detail(), statuses: ['open', 'resolved'], categories: ['fare', 'safety'], priorities: ['urgent', 'low'], assignees: [{ id: 1, name: 'Me Admin' }, { id: 2, name: 'Sam Staff' }], me: 1, actions };

describe('Support tickets page', () => {
    beforeEach(() => { grant(['support.manage']); });
    afterEach(() => { vi.restoreAllMocks(); page.props = sharedProps(); });

    it('lists tickets with their status, shows the conversation with internal notes marked staff-only, and the requester and trip', () => {
        render(<SupportTicketsIndex {...base} />);

        expect(screen.getByText('Subject 2')).toBeInTheDocument();
        expect(screen.getByText('I was overcharged.')).toBeInTheDocument();
        expect(screen.getByText(/Internal note · Only visible to staff/)).toHaveTextContent('Sam Staff');
        expect(screen.getByText('Checked the meter.')).toBeInTheDocument();
        expect(screen.getByText('TK-AB12CD34')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open trip' })).toHaveAttribute('href', '/admin/trips/5');
        expect(screen.getByText('R-00007', { exact: false })).toBeInTheDocument();
    });

    it('sends a reply to the ticket\'s own URL as a public message, once for a double-click, and clears the box', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => { options?.onSuccess?.({} as never); });
        render(<SupportTicketsIndex {...base} />);

        fireEvent.change(screen.getByLabelText('Reply'), { target: { value: '  We are checking.  ' } });
        const send = screen.getByRole('button', { name: 'Send' });
        fireEvent.click(send);
        fireEvent.click(send);

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toBe('/admin/support-tickets/1/messages');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { body: '  We are checking.  ', internal: false } });
        expect(screen.getByLabelText('Reply')).toHaveValue('');
    });

    it('turns the box into an internal note and sends it flagged internal', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<SupportTicketsIndex {...base} />);

        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
        fireEvent.change(screen.getByLabelText('Internal note'), { target: { value: 'Escalated.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));

        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { body: 'Escalated.', internal: true } });
    });

    it('keeps Send off for an empty message and shows the server\'s error under the box', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => { options?.onError?.({ body: 'The body field is required.' }); });
        render(<SupportTicketsIndex {...base} />);
        expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Reply'), { target: { value: 'x' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        expect(screen.getByRole('alert')).toHaveTextContent('The body field is required.');
    });

    it('resolves only with a resolution: the modal button is off while it is empty, then posts it', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<SupportTicketsIndex {...base} />);

        fireEvent.click(screen.getByRole('button', { name: 'Resolve' }));
        const dialog = screen.getByRole('dialog', { name: 'Resolve ticket' });
        expect(within(dialog).getByRole('button', { name: 'Resolve' })).toBeDisabled();
        fireEvent.change(within(dialog).getByLabelText(/Resolution/), { target: { value: '   ' } });
        expect(within(dialog).getByRole('button', { name: 'Resolve' })).toBeDisabled();
        fireEvent.change(within(dialog).getByLabelText(/Resolution/), { target: { value: 'Refunded the difference.' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Resolve' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/support-tickets/1/resolve');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { resolution: 'Refunded the difference.' } });
    });

    it('assigns to the chosen admin, or to nobody', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<SupportTicketsIndex {...base} />);

        fireEvent.click(screen.getByRole('button', { name: 'Assign' }));
        const dialog = screen.getByRole('dialog', { name: 'Assign ticket' });
        const select = within(dialog).getByLabelText('Assignee') as HTMLSelectElement;
        expect(Array.from(select.options).map((o) => o.text)).toEqual(['Unassigned', 'Me Admin (me)', 'Sam Staff']);
        fireEvent.change(select, { target: { value: '2' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Assign' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/support-tickets/1/assign');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { assignee_id: 2 } });
    });

    it('shows a resolved ticket read-only: the resolution, Reopen, and no reply box, Assign or Resolve', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<SupportTicketsIndex {...base} selected={detail({ status: 'resolved', resolution: 'Refunded the difference.', resolved_at: '2026-10-14T11:00:00Z' })} />);

        expect(screen.getByText('Refunded the difference.')).toBeInTheDocument();
        expect(screen.queryByLabelText('Reply')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Assign' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Resolve' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        expect(visit.mock.calls[0][0]).toBe('/admin/support-tickets/1/reopen');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post' });
    });

    it('offers refund and fare adjustment from a linked trip only to roles that have those permissions, and only once their endpoints exist', () => {
        const live = { ...actions, refund: '/admin/support-tickets/{id}/refund', adjustFare: '/admin/support-tickets/{id}/adjust-fare' };
        const { unmount } = render(<SupportTicketsIndex {...base} actions={live} />);
        expect(screen.queryByRole('button', { name: 'Issue refund' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Adjust fare' })).toBeNull();
        unmount();

        grant(['support.manage', 'payments.refund', 'trips.adjust_fare']);
        render(<SupportTicketsIndex {...base} />);
        expect(screen.getByRole('button', { name: 'Issue refund' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Adjust fare' })).toBeDisabled();
    });

    it('selects a ticket by putting it in the URL, and pages through the list', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        window.history.replaceState({}, '', '/admin/support-tickets');
        render(<SupportTicketsIndex {...base} tickets={{ ...paginator([row(1), row(2)], 3), current_page: 2 }} />);

        fireEvent.click(screen.getByRole('button', { name: /Subject 2/ }));
        expect(visit.mock.calls[0][0]).toBe('/admin/support-tickets?ticket=2');
        expect(visit.mock.calls[0][1]).toMatchObject({ preserveScroll: false });
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(String(visit.mock.calls[1][0])).toContain('page=3');
        fireEvent.click(screen.getByRole('button', { name: 'Previous page' }));
        expect(String(visit.mock.calls[2][0])).toContain('page=1');
    });

    it('shows an empty state when there are no tickets', () => {
        render(<SupportTicketsIndex {...base} tickets={paginator([])} selected={null} />);

        expect(screen.getByText('No tickets')).toBeInTheDocument();
        expect(screen.getByText('No ticket selected')).toBeInTheDocument();
    });
});
