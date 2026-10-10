import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EditRider from '@/Pages/Riders/EditRider';
import { page, sharedProps } from '@/test/inertia';
import type { RiderDetail } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const zero = { amount: 0, currency: 'AMD' };
const rider: RiderDetail = {
    id: 7, code: 'R-00007', name: 'Sun Li', phone: '+37491210443', email: 'sun@example.com', status: 'active', trips_count: 0, registered_at: '2026-01-12T10:00:00+00:00',
    raw: { name: 'Sun Li', email: 'sun@example.com', phone: '+37491210443', locale: 'en' }, locale: 'en', suspension_reason: null, last_login_at: null,
    stats: { total_spent: zero, cancellation_rate_bp: 0, open_tickets: 0 }, deletion_scheduled_for: null, payment_methods: [],
    records: { trips: [], payments: [], tickets: [], ratings: [], activity: [] },
};
const props = { open: true, onClose: vi.fn(), rider, locales: ['en', 'hy', 'ru'], url: '/admin/riders/7' };

describe('Edit rider', () => {
    beforeEach(() => { page.props = sharedProps(); props.onClose = vi.fn(); });
    afterEach(() => vi.restoreAllMocks());

    it('is prefilled with the rider’s current details', () => {
        render(<EditRider {...props} />);

        expect(screen.getByLabelText('Name')).toHaveValue('Sun Li');
        expect(screen.getByLabelText('Email')).toHaveValue('sun@example.com');
        expect(screen.getByLabelText('Phone')).toHaveValue('+37491210443');
        expect(screen.getByLabelText('Language')).toHaveValue('en');
    });

    it('cannot be saved until something changed and a reason is given', () => {
        render(<EditRider {...props} />);
        const save = screen.getByRole('button', { name: 'Save changes' });

        expect(save).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Sun L.' } });
        expect(save).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: '   ' } });
        expect(save).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Rider asked' } });
        expect(save).toBeEnabled();
    });

    it('sends only the changed fields and the reason, with PATCH', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<EditRider {...props} />);

        fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '091 555 666' } });
        fireEvent.change(screen.getByLabelText('Language'), { target: { value: 'hy' } });
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Rider asked' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(visit).toHaveBeenCalledTimes(1);
        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/admin/riders/7');
        expect(options?.method).toBe('patch');
        expect(options?.data).toEqual({ phone: '091 555 666', locale: 'hy', reason: 'Rider asked' });
    });

    it('clearing the email or language sends null', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<EditRider {...props} />);

        fireEvent.change(screen.getByLabelText('Email'), { target: { value: '' } });
        fireEvent.change(screen.getByLabelText('Language'), { target: { value: '' } });
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'No email on file' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(visit.mock.calls[0][1]?.data).toEqual({ email: null, locale: null, reason: 'No email on file' });
    });

    it('shows server errors next to their fields and stays open', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ phone: 'Another account already uses this phone number.', email: 'The email has already been taken.', reason: 'The reason field is required.' });
            options?.onFinish?.({} as never);
        });
        render(<EditRider {...props} />);
        fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '099 11 22 33' } });
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'x' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(screen.getByLabelText('Phone').closest('div')).toHaveTextContent('Another account already uses this phone number.');
        expect(screen.getByLabelText('Email').closest('div')).toHaveTextContent('The email has already been taken.');
        expect(props.onClose).not.toHaveBeenCalled();
    });

    it('closes after a successful save', () => {
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => { options?.onSuccess?.({} as never); });
        render(<EditRider {...props} />);
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'New' } });
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'x' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(props.onClose).toHaveBeenCalledTimes(1);
    });
});
