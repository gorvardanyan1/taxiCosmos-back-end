import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RiderShow from '@/Pages/Riders/Show';
import { page, setLocation, sharedProps } from '@/test/inertia';
import type { RiderDetail } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const zero = { amount: 0, currency: 'AMD' };
const detail = (overrides: Partial<RiderDetail> = {}): RiderDetail => ({
    id: 7, code: 'R-00007', name: 'Sun Li', phone: '+37491210443', email: 'sun@example.com', status: 'active', trips_count: 0, registered_at: '2026-01-12T10:00:00+00:00',
    raw: { name: 'Sun Li', email: 'sun@example.com', phone: '+37491210443', locale: 'en' }, locale: 'en', suspension_reason: null, last_login_at: null,
    stats: { total_spent: zero, cancellation_rate_bp: 0, open_tickets: 0 }, deletion_scheduled_for: null, payment_methods: [],
    records: { trips: [], payments: [], tickets: [], ratings: [], activity: [] }, ...overrides,
});
const tabs = ['trips', 'payments', 'payment-methods', 'tickets', 'ratings', 'activity'];
const actions = { edit: '/admin/riders/7', suspend: '/admin/riders/7/suspend', reactivate: '/admin/riders/7/reactivate' };
const props = { tab: 'trips', tabs, locales: ['en', 'hy', 'ru'], actions };

describe('Rider detail', () => {
    let visit: ReturnType<typeof vi.spyOn>;
    beforeEach(() => { page.props = sharedProps(); visit = vi.spyOn(router, 'visit').mockImplementation(() => {}); setLocation('/admin/riders/7'); });
    afterEach(() => vi.restoreAllMocks());

    it('renders a rider with nothing yet (no trips, payments or methods) without crashing', () => {
        render(<RiderShow {...props} rider={detail()} />);

        expect(screen.getByRole('heading', { name: 'Sun Li' })).toBeInTheDocument();
        expect(screen.getByText('Total trips')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Suspend' })).toBeInTheDocument();
    });

    it('shows the suspension reason and offers reactivation for a suspended rider', () => {
        render(<RiderShow {...props} rider={detail({ status: 'suspended', suspension_reason: 'Repeated chargebacks' })} />);

        expect(screen.getByText('Suspended: Repeated chargebacks')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Suspend' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Reactivate' }));
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Appeal accepted' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm Reactivation' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/riders/7/reactivate');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { reason: 'Appeal accepted' } });
    });

    it('suspends with a reason', () => {
        render(<RiderShow {...props} rider={detail()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Suspend' }));
        expect(screen.getByRole('button', { name: 'Confirm Suspension' })).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Fraud' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm Suspension' }));

        expect(visit.mock.calls[0][0]).toBe('/admin/riders/7/suspend');
    });

    it('has no suspend or reactivate for a deactivated rider', () => {
        render(<RiderShow {...props} rider={detail({ status: 'deactivated' })} />);

        expect(screen.queryByRole('button', { name: /Suspend|Reactivate/ })).toBeNull();
    });

    it('lists what admins did on the activity tab', () => {
        render(<RiderShow {...props} tab="activity" rider={detail({ records: { trips: [], payments: [], tickets: [], ratings: [], activity: [{ reference: 'ACT-5', description: 'rider.suspended by Riley Chen: Fraud', status: 'completed', value: '—', occurred_at: '2026-10-05T14:00:00+00:00' }] } })} />);

        expect(screen.getByText('rider.suspended by Riley Chen: Fraud')).toBeInTheDocument();
        expect(screen.getByText('ACT-5')).toBeInTheDocument();
    });
});
