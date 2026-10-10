import { router } from '@inertiajs/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TransactionsIndex from '@/Pages/Transactions/Index';
import { page, sharedProps } from '@/test/inertia';
import type { Paginated, TransactionRow } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => (await import('@/test/inertia')).inertiaMock(importOriginal));

const txn: TransactionRow = {
    id: 12, code: 'TXN-1', trip_code: 'TK-1', gateway: 'stripe', type: 'capture', amount: { amount: 12500, currency: 'AMD' },
    status: 'completed', created_at: '2026-10-05T14:16:00Z', gateway_reference: null, rider: 'R', payment_method: 'Visa',
    parent_code: null, failure_reason: null, metadata: {},
};
const transactions: Paginated<TransactionRow> = { data: [txn], current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1, path: '/admin/transactions', links: [] };
const base = {
    summary: { volume_today: { amount: 1, currency: 'AMD' }, transactions_count: 1, pending_payouts: { amount: 1, currency: 'AMD' }, refunds_today: { amount: 1, currency: 'AMD' } },
    transactions, filters: {}, selected: null, gateways: ['stripe'],
};

describe('Transactions refund form', () => {
    beforeEach(() => { page.props = sharedProps(); });
    afterEach(() => vi.restoreAllMocks());

    const openRefund = () => fireEvent.click(screen.getByRole('button', { name: /Refund/ }));

    it('formats money from minor units and blocks a refund above the original amount', () => {
        render(<TransactionsIndex {...base} actions={{ recordManual: null, refund: '/admin/transactions/{id}/refund', confirmManual: null, rejectManual: null }} />);
        expect(screen.getByText('AMD 12,500')).toBeInTheDocument();
        openRefund();

        fireEvent.change(screen.getByLabelText(/Refund Amount/), { target: { value: '12501' } });
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Overcharged' } });

        expect(screen.getByRole('alert')).toHaveTextContent('Refund cannot exceed the remaining amount.');
        expect(screen.getByRole('button', { name: 'Issue Refund' })).toBeDisabled();
    });

    it('requires a reason', () => {
        render(<TransactionsIndex {...base} actions={{ recordManual: null, refund: '/admin/transactions/{id}/refund', confirmManual: null, rejectManual: null }} />);
        openRefund();

        fireEvent.change(screen.getByLabelText(/Refund Amount/), { target: { value: '500' } });
        expect(screen.getByRole('button', { name: 'Issue Refund' })).toBeDisabled();
    });

    it('posts an integer minor-unit amount with an idempotency key to the row’s refund URL', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        render(<TransactionsIndex {...base} actions={{ recordManual: null, refund: '/admin/transactions/{id}/refund', confirmManual: null, rejectManual: null }} />);
        openRefund();

        fireEvent.change(screen.getByLabelText(/Refund Amount/), { target: { value: '3,500' } });
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Overcharged' } });
        fireEvent.click(screen.getByRole('button', { name: 'Issue Refund' }));

        expect(visit).toHaveBeenCalledTimes(1);
        const [url, options] = visit.mock.calls[0];
        expect(url).toBe('/admin/transactions/12/refund');
        expect(options?.method).toBe('post');
        expect(options?.data).toMatchObject({ amount: 3500, reason: 'Overcharged', reverse_driver_earnings: false });
        expect(typeof (options?.data as Record<string, unknown>).amount).toBe('number');
        expect((options?.data as Record<string, string>).idempotency_key).toMatch(/.{16,}/);
    });

    it('keeps the refund button disabled until the endpoint exists', () => {
        render(<TransactionsIndex {...base} actions={{ recordManual: null, refund: null, confirmManual: null, rejectManual: null }} />);
        openRefund();

        fireEvent.change(screen.getByLabelText(/Refund Amount/), { target: { value: '100' } });
        fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Overcharged' } });
        expect(screen.getByRole('button', { name: 'Issue Refund' })).toBeDisabled();
    });
});
