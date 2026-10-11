import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RidersIndex from '@/Pages/Riders/Index';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { Paginated, RiderRow } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const rider = (overrides: Partial<RiderRow>): RiderRow => ({ id: 7, code: 'R-00007', name: 'Sun Li', phone: '+37491210443', email: 'sun@example.com', status: 'active', trips_count: 0, registered_at: '2026-01-12T10:00:00+00:00', ...overrides });
const paginator = (data: RiderRow[]): Paginated<RiderRow> => ({ data, current_page: 1, last_page: 1, per_page: 10, total: data.length, from: 1, to: data.length, path: '/admin/riders', links: [] });
const actions = { export: null, suspend: '/admin/riders/{id}/suspend', reactivate: '/admin/riders/{id}/reactivate' };
const base = { filters: {}, sort: null, statuses: ['active', 'suspended'], totalRegistered: 2, actions };

describe('Riders list', () => {
    let get: ReturnType<typeof vi.spyOn>;
    let visit: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); get = vi.spyOn(router, 'get').mockImplementation(() => {}); visit = vi.spyOn(router, 'visit').mockImplementation(() => {}); setLocation('/admin/riders?page=2'); });
    afterEach(() => vi.restoreAllMocks());

    it('shows riders with contact, status and registration, and no invite button (riders sign up in the app)', () => {
        render(<RidersIndex {...base} riders={paginator([rider({}), rider({ id: 8, name: 'Ana', code: 'R-00008', phone: '+37499000000', status: 'suspended' })])} />);

        expect(screen.getByText('Sun Li')).toBeInTheDocument();
        expect(screen.getByText('R-00007')).toBeInTheDocument();
        expect(screen.getByText('+37491210443')).toBeInTheDocument();
        expect(screen.getByText('2 registered accounts')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Invite/ })).toBeNull();
    });

    it('filters by registration date range through the URL and goes back to page 1', () => {
        render(<RidersIndex {...base} riders={paginator([rider({})])} />);

        fireEvent.change(screen.getByLabelText('Registered from'), { target: { value: '2026-01-01' } });
        expect(get).toHaveBeenLastCalledWith('/admin/riders?filter%5Bregistered_from%5D=2026-01-01', {}, { preserveState: true, preserveScroll: true });

        fireEvent.change(screen.getByLabelText('Registered to'), { target: { value: '2026-02-01' } });
        expect(get).toHaveBeenLastCalledWith('/admin/riders?filter%5Bregistered_to%5D=2026-02-01', {}, { preserveState: true, preserveScroll: true });
    });

    it('shows the active filters and keeps them compact', () => {
        render(<RidersIndex {...base} filters={{ registered_from: '2026-01-01' }} riders={paginator([rider({})])} />);

        expect(screen.getByLabelText('Registered from')).toHaveValue('2026-01-01');
        expect(screen.getByLabelText('Registered from')).not.toHaveClass('w-full');
    });

    it('sorts by clicking a header: ascending, descending, then off', () => {
        const { rerender } = render(<RidersIndex {...base} riders={paginator([rider({})])} />);

        fireEvent.click(screen.getByRole('button', { name: 'Registered' }));
        expect(get).toHaveBeenLastCalledWith('/admin/riders?sort=registered_at', {}, expect.anything());

        rerender(<RidersIndex {...base} sort="registered_at" riders={paginator([rider({})])} />);
        fireEvent.click(screen.getByRole('button', { name: /Registered/ }));
        expect(get).toHaveBeenLastCalledWith('/admin/riders?sort=-registered_at', {}, expect.anything());

        rerender(<RidersIndex {...base} sort="-registered_at" riders={paginator([rider({})])} />);
        fireEvent.click(screen.getByRole('button', { name: /Registered/ }));
        expect(get).toHaveBeenLastCalledWith('/admin/riders', {}, expect.anything());

        fireEvent.click(screen.getByRole('button', { name: 'Trips' }));
        expect(get).toHaveBeenLastCalledWith('/admin/riders?sort=trips_count', {}, expect.anything());
    });

    it('suspends from the row menu with a required reason', () => {
        render(<RidersIndex {...base} riders={paginator([rider({})])} />);

        fireEvent.click(screen.getByLabelText('Actions for Sun Li'));
        fireEvent.click(screen.getByRole('button', { name: /Suspend/ }));
        const confirm = screen.getByRole('button', { name: 'Confirm Suspension' });
        expect(confirm).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Repeated chargebacks' } });
        fireEvent.click(confirm);

        expect(visit.mock.calls[0][0]).toBe('/admin/riders/7/suspend');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { reason: 'Repeated chargebacks' } });
    });

    it('reactivates a suspended rider through the same menu with its own wording', () => {
        render(<RidersIndex {...base} riders={paginator([rider({ id: 8, name: 'Ana', status: 'suspended' })])} />);

        fireEvent.click(screen.getByLabelText('Actions for Ana'));
        fireEvent.click(screen.getByRole('button', { name: /Reactivate/ }));
        expect(screen.getByRole('dialog', { name: 'Reactivate Ana' })).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Appeal accepted' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm Reactivation' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/riders/8/reactivate');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { reason: 'Appeal accepted' } });
    });

    it('offers no suspend or reactivate for deactivated or pending-deletion riders', () => {
        render(<RidersIndex {...base} riders={paginator([rider({ id: 9, name: 'Gone', status: 'deactivated' })])} />);

        fireEvent.click(screen.getByLabelText('Actions for Gone'));

        expect(screen.getByRole('button', { name: /View Profile/ })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Suspend|Reactivate/ })).toBeNull();
    });

    it('says when no rider matches', () => {
        render(<RidersIndex {...base} riders={paginator([])} />);

        expect(screen.getByText('No riders found')).toBeInTheDocument();
    });
});
