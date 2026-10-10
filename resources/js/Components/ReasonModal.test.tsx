import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ReasonModal from '@/Components/ReasonModal';

const props = { open: true, onClose: () => {}, title: 'Suspend Sun Li', description: 'desc', confirmLabel: 'Confirm Suspension', placeholder: 'Why…' };

describe('ReasonModal', () => {
    afterEach(() => vi.restoreAllMocks());

    it('cannot be confirmed with an empty or whitespace reason', () => {
        render(<ReasonModal {...props} url="/admin/riders/80/suspend" />);
        const confirm = screen.getByRole('button', { name: 'Confirm Suspension' });

        expect(confirm).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: '   ' } });
        expect(confirm).toBeDisabled();
    });

    it('stays disabled while the endpoint does not exist yet', () => {
        render(<ReasonModal {...props} url={null} />);

        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Fraud' } });
        expect(screen.getByRole('button', { name: 'Confirm Suspension' })).toBeDisabled();
    });

    it('posts the reason and shows the server validation error next to the field', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation((_url, options) => {
            options?.onError?.({ reason: 'The reason must be at least 10 characters.' });
            options?.onFinish?.({} as never);
        });
        render(<ReasonModal {...props} url="/admin/riders/80/suspend" />);

        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Fraud' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm Suspension' }));

        expect(visit).toHaveBeenCalledTimes(1);
        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/admin/riders/80/suspend');
        expect(options).toMatchObject({ method: 'post', data: { reason: 'Fraud' } });
        expect(screen.getByRole('alert')).toHaveTextContent('The reason must be at least 10 characters.');
    });
});
