import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DriverShow from '@/Pages/Drivers/Show';
import { page, sharedProps } from '@/test/inertia';
import type { DriverDetail, DriverDocument } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const doc = (overrides: Partial<DriverDocument>): DriverDocument => ({
    id: 1, type: 'license', status: 'pending', expires_at: null, rejection_reason: null, ...overrides,
});

function renderTab(documents: DriverDocument[], actions: { approveDocument: string | null; rejectDocument: string | null }) {
    const driver = {
        id: 9, code: 'DRV-0009', name: 'Gor Hakobyan', verification_status: 'pending', documents,
        phone: '+374 99 661 009', vehicle: { label: 'Hyundai Elantra', year: 2021 }, trips_count: 0, rating: null, earnings: { amount: 0, currency: 'AMD' },
        vehicles: [], bank_accounts: [], trip_history: [],
        earnings_detail: { balance: { amount: 0, currency: 'AMD' }, debt_limit_exceeded: false, summary: { week: { amount: 0, currency: 'AMD' }, month: { amount: 0, currency: 'AMD' }, all_time: { amount: 0, currency: 'AMD' } }, ledger: [] },
    } as unknown as DriverDetail;

    return render(
        <DriverShow
            driver={driver}
            tab="documents"
            tabs={['profile', 'documents']}
            actions={{ ...actions, addVehicle: null, setPrimaryVehicle: null, addAdjustment: null, recordCashSettlement: null, revealBankAccount: null }}
        />,
    );
}

const urls = { approveDocument: '/admin/drivers/9/documents/{id}/approve', rejectDocument: '/admin/drivers/9/documents/{id}/reject' };

describe('Driver documents tab', () => {
    beforeEach(() => { page.props = sharedProps(); });
    afterEach(() => vi.restoreAllMocks());

    it('approves a pending document by posting to that document’s endpoint', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        renderTab([doc({ id: 41 }), doc({ id: 42, type: 'id_card' })], urls);

        // Rows keep the server's order, so the second Approve button belongs to document 42.
        fireEvent.click(screen.getAllByRole('button', { name: /Approve/ })[1]);

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9/documents/42/approve');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post' });
    });

    it('rejects with a required reason sent to the right document', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        renderTab([doc({ id: 41 })], urls);

        fireEvent.click(screen.getByRole('button', { name: /Reject/ }));
        const confirm = screen.getByRole('button', { name: 'Confirm' });
        expect(confirm).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: '   ' } });
        expect(confirm).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Photo is blurry' } });
        fireEvent.click(confirm);

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toBe('/admin/drivers/9/documents/41/reject');
        expect(visit.mock.calls[0][1]).toMatchObject({ method: 'post', data: { reason: 'Photo is blurry' } });
    });

    it('offers review buttons only for pending documents and shows why a document was rejected', () => {
        renderTab([
            doc({ id: 1, status: 'approved' }),
            doc({ id: 2, type: 'id_card', status: 'rejected', rejection_reason: 'Name does not match' }),
            doc({ id: 3, type: 'insurance', status: 'expired' }),
            doc({ id: 4, type: 'background_check' }),
        ], urls);

        expect(screen.getAllByRole('button', { name: /Approve/ })).toHaveLength(1);
        expect(screen.getAllByRole('button', { name: /Reject/ })).toHaveLength(1);
        expect(screen.getByText('Name does not match')).toBeInTheDocument();
    });

    it('opens the file through its signed link in a new tab', () => {
        renderTab([doc({ id: 5, file_url: '/admin/drivers/9/documents/5/file?expires=1&signature=abc', mime_type: 'image/png' })], urls);

        const link = screen.getByRole('link', { name: /View/ });
        expect(link).toHaveAttribute('href', '/admin/drivers/9/documents/5/file?expires=1&signature=abc');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', expect.stringContaining('noreferrer'));
        expect(screen.getByText('IMG')).toBeInTheDocument();
    });

    it('shows no view link when the document has no file link (fixture data)', () => {
        renderTab([doc({ id: 5 })], { approveDocument: null, rejectDocument: null });

        expect(screen.queryByRole('link', { name: /View/ })).toBeNull();
        expect(screen.getByRole('button', { name: /Approve/ })).toBeDisabled();
    });

    it('warns when a document expires soon or has expired', () => {
        const soon = new Date(Date.now() + 12 * 86_400_000).toISOString().slice(0, 10);
        const past = new Date(Date.now() - 5 * 86_400_000).toISOString().slice(0, 10);
        renderTab([doc({ id: 1, expires_at: soon }), doc({ id: 2, type: 'id_card', expires_at: past })], urls);

        expect(screen.getByText(/Expires in 1[12] days/)).toBeInTheDocument();
        expect(screen.getByText('Expired')).toBeInTheDocument();
    });

    it('shows the empty state without documents', () => {
        renderTab([], urls);

        expect(screen.getByText('No documents uploaded')).toBeInTheDocument();
    });
});
